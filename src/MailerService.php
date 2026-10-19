<?php
namespace Zion;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
final class MailerService {
 public function __construct(private Database $db,private ?ListmonkClient $listmonkClient=null) {}
 public static function encode(array $data): string {
  return EncryptedPayload::encode($data);
 }
 public static function decode(string $encoded): array {
  return EncryptedPayload::decode($encoded);
 }
 public function queue(string $to,string $locale,string $kind,array $data,string $key): void {
  $this->db->run('INSERT OR IGNORE INTO outbox(dedupe_key,recipient,locale,kind,payload,created_at) VALUES(?,?,?,?,?,?)',[$key,$to,$locale,$kind,self::encode($data),time()]);
 }
 /** Send only this customer's current confirmation, after the account transaction commits. */
 public function deliverVerification(string $userId): int {
  try{
  if(!(new IntegrationSettings($this->db))->listmonk()['enabled'])return 0;
  $row=$this->db->one("SELECT o.id FROM outbox o JOIN users u ON u.email=o.recipient JOIN email_verifications v ON v.user_id=u.id WHERE u.id=? AND o.kind='verify' AND o.sent_at IS NULL AND v.used_at IS NULL AND v.expires_at>? AND o.dedupe_key IN ('verify:'||v.token_hash,'access:'||v.token_hash) ORDER BY o.id DESC LIMIT 1",[$userId,time()]);
  return $row?$this->deliver(1,(int)$row['id'],true):0;
  }catch(\Throwable $e){
   ApiLog::record('listmonk','POST','/outbox',0,microtime(true),['failed'=>true,'code'=>'confirmation_dispatch_pending','message'=>'Confirmarea a rămas în coadă; verifică configurația, APP_KEY și permisiunile storage/mail.lock.','context'=>['stage'=>'outbox']]);return 0;
  }
 }
 public function diagnostics(): array {
  $pending=$this->db->one('SELECT COUNT(*) total,COALESCE(SUM(attempts=0),0) unattempted,COALESCE(SUM(attempts>0),0) failed,MIN(created_at) oldest FROM outbox WHERE sent_at IS NULL');
  $row=$this->db->one("SELECT value FROM integration_settings WHERE key='mail:worker'");
  $worker=$row?json_decode($row['value'],true):[];
  return ['pending'=>$pending,'worker'=>$worker?:[],'worker_recent'=>(int)($worker['started_at']??0)>time()-180];
 }
 /** Heartbeat belongs only to the actual CLI worker, never connection tests or web sends. */
 public function work(): int {
  $this->workerState(['started_at'=>time(),'finished_at'=>null]);
  $count=$this->deliver();
  $this->workerState(['started_at'=>time(),'finished_at'=>time(),'accepted'=>$count]);
  return $count;
 }
 private function workerState(array $state): void {
  $this->db->run("INSERT INTO integration_settings(key,value) VALUES('mail:worker',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[json_encode($state,JSON_THROW_ON_ERROR)]);
 }
 public function deliver(int $limit=50,?int $onlyId=null,bool $nonBlocking=false): int {
  $count=0;
  $limit=max(1,min(50,$limit));
  $rows=$onlyId===null?$this->db->run('SELECT * FROM outbox WHERE sent_at IS NULL ORDER BY id LIMIT '.$limit)->fetchAll():$this->db->run('SELECT * FROM outbox WHERE sent_at IS NULL AND id=?',[$onlyId])->fetchAll();
  foreach($rows as $row) {
   $lock=fopen(dirname(__DIR__).'/storage/mail.lock','c');
   if(!$lock)throw new \RuntimeException('mail_lock_unavailable');
   if(!flock($lock,LOCK_EX|($nonBlocking?LOCK_NB:0))){fclose($lock);break;}
   try {
    $current=$this->db->one('SELECT * FROM outbox WHERE id=?',[$row['id']]);
    if(!$current || $current['sent_at'])continue;
    $row=$current;
    if($row['kind']==='verify' && preg_match('/^(?:verify|access):([a-f0-9]{64})$/D',$row['dedupe_key'],$match) && !$this->db->one('SELECT token_hash FROM email_verifications WHERE token_hash=? AND used_at IS NULL AND expires_at>?',[$match[1],time()])){
     // Expired/replaced/consumed links cannot activate an account. Remove their encrypted payloads.
     $this->db->run('DELETE FROM outbox WHERE id=? AND sent_at IS NULL',[$row['id']]);continue;
    }
    $this->db->run('UPDATE outbox SET attempts=attempts+1 WHERE id=?',[$row['id']]);
    $tr=new TranslationService($row['locale']); $data=self::decode($row['payload']);
    $subject=$tr->get('email.'.$row['kind'].'.subject');
    $body=$tr->get('email.'.$row['kind'].'.body',$data);
    if(isset($data['legal_receipt']))$body.="\n\n".$data['legal_receipt'];
    $html='<html lang="'.e($row['locale']).'"><body style="background:#0b1117;color:#e5eee9;font:16px Arial;padding:32px"><div style="max-width:560px;margin:auto"><h2>Zion Storage</h2><h1>'.e($subject).'</h1><p>'.nl2br(e($body)).'</p>';
    if(!empty($data['url'])) $html.='<p><a style="color:#bafa73" href="'.e($data['url']).'">'.e($tr->get('email.action')).'</a></p>';
    $html.='<p>'.e($tr->get('email.footer')).'</p></div></body></html>';
    $listmonk=(new IntegrationSettings($this->db))->listmonk();
    if($listmonk['enabled']) {
     ($this->listmonkClient??new ListmonkClient($listmonk))->send($row['recipient'],$subject,$html,$body.(!empty($data['url'])?"\n".$data['url']:''),$row['locale'],$row['kind']);
    } elseif(env('MAIL_TRANSPORT')==='listmonk') {
     throw new \RuntimeException('listmonk_disabled');
    } elseif(env('MAIL_TRANSPORT','log')==='log' && !production()) {
     $dir=env('MAIL_LOG_DIRECTORY',dirname(__DIR__).'/storage/mail'); if(!is_dir($dir)) mkdir($dir,0700,true);
     file_put_contents($dir.'/'.$row['id'].'.html',$html,LOCK_EX); chmod($dir.'/'.$row['id'].'.html',0600);
    } else {
     $scheme=env('MAIL_ENCRYPTION','tls')==='smtps'?'smtps':'smtp';
     $dsn=$scheme.'://'.rawurlencode(env('MAIL_USERNAME')).':'.rawurlencode(env('MAIL_PASSWORD')).'@'.env('MAIL_HOST').':'.env('MAIL_PORT','587');
     if($scheme==='smtp') $dsn.='?require_tls=true';
     $mailer=new Mailer(Transport::fromDsn($dsn));
     $mailer->send((new Email())->from(new \Symfony\Component\Mime\Address(LegalDocuments::operator()['email'], 'Zion Storage'))->to($row['recipient'])->subject($subject)->text($body.(!empty($data['url'])?"\n".$data['url']:''))->html($html));
    }
    $this->db->transaction(function() use($row,$data) {
     $this->db->run('UPDATE outbox SET sent_at=?,payload=? WHERE id=?',[time(),'{}',$row['id']]);
     if($row['kind']==='fiscal_invoice' && isset($data['fiscal_invoice_id'])) {
      $this->db->run("UPDATE fiscal_invoices SET notification_sent_at=?,completed_at=?,updated_at=? WHERE id=? AND status='issued'",[time(),time(),time(),$data['fiscal_invoice_id']]);
     }
    }); $count++;
   } catch(\Throwable $e) { error_log('Mail delivery pending; outbox_id='.$row['id']);if($nonBlocking)break; }
   finally { flock($lock,LOCK_UN); fclose($lock); }
  } return $count;
 }
}
