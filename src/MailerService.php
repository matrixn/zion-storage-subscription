<?php
namespace Zion;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
final class MailerService {
 public function __construct(private Database $db) {}
 public static function encode(array $data): string {
  return EncryptedPayload::encode($data);
 }
 public static function decode(string $encoded): array {
  return EncryptedPayload::decode($encoded);
 }
 public function queue(string $to,string $locale,string $kind,array $data,string $key): void {
  $this->db->run('INSERT OR IGNORE INTO outbox(dedupe_key,recipient,locale,kind,payload,created_at) VALUES(?,?,?,?,?,?)',[$key,$to,$locale,$kind,self::encode($data),time()]);
 }
 public function deliver(): int {
  $count=0;
  foreach($this->db->run('SELECT * FROM outbox WHERE sent_at IS NULL ORDER BY id LIMIT 50')->fetchAll() as $row) {
   $lock=fopen(dirname(__DIR__).'/storage/mail.lock','c'); flock($lock,LOCK_EX);
   try {
    if($this->db->one('SELECT sent_at FROM outbox WHERE id=?',[$row['id']])['sent_at']) continue;
    $this->db->run('UPDATE outbox SET attempts=attempts+1 WHERE id=?',[$row['id']]);
    $tr=new TranslationService($row['locale']); $data=self::decode($row['payload']);
    $subject=$tr->get('email.'.$row['kind'].'.subject');
    $body=$tr->get('email.'.$row['kind'].'.body',$data);
    $html='<html lang="'.e($row['locale']).'"><body style="background:#0b1117;color:#e5eee9;font:16px Arial;padding:32px"><div style="max-width:560px;margin:auto"><h2>Zion Storage</h2><h1>'.e($subject).'</h1><p>'.nl2br(e($body)).'</p>';
    if(!empty($data['url'])) $html.='<p><a style="color:#bafa73" href="'.e($data['url']).'">'.e($tr->get('email.action')).'</a></p>';
    $html.='<p>'.e($tr->get('email.footer')).'</p></div></body></html>';
    if(env('MAIL_TRANSPORT','log')==='log' && !production()) {
     $dir=env('MAIL_LOG_DIRECTORY',dirname(__DIR__).'/storage/mail'); if(!is_dir($dir)) mkdir($dir,0700,true);
     file_put_contents($dir.'/'.$row['id'].'.html',$html,LOCK_EX); chmod($dir.'/'.$row['id'].'.html',0600);
    } else {
     $scheme=env('MAIL_ENCRYPTION','tls')==='smtps'?'smtps':'smtp';
     $dsn=$scheme.'://'.rawurlencode(env('MAIL_USERNAME')).':'.rawurlencode(env('MAIL_PASSWORD')).'@'.env('MAIL_HOST').':'.env('MAIL_PORT','587');
     if($scheme==='smtp') $dsn.='?require_tls=true';
     $mailer=new Mailer(Transport::fromDsn($dsn));
     $mailer->send((new Email())->from(new \Symfony\Component\Mime\Address(env('MAIL_FROM_ADDRESS'), 'Zion Storage'))->to($row['recipient'])->subject($subject)->text($body.(!empty($data['url'])?"\n".$data['url']:''))->html($html));
    }
    $this->db->transaction(function() use($row,$data) {
     $this->db->run('UPDATE outbox SET sent_at=?,payload=? WHERE id=?',[time(),'{}',$row['id']]);
     if($row['kind']==='fiscal_invoice' && isset($data['fiscal_invoice_id'])) {
      $this->db->run("UPDATE fiscal_invoices SET notification_sent_at=?,completed_at=?,updated_at=? WHERE id=? AND status='issued'",[time(),time(),time(),$data['fiscal_invoice_id']]);
     }
    }); $count++;
   } catch(\Throwable $e) { error_log('Mail delivery pending; outbox_id='.$row['id']); }
   finally { flock($lock,LOCK_UN); fclose($lock); }
  } return $count;
 }
}
