<?php
namespace Zion;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
final class MailerService {
 public function __construct(private Database $db,private ?ListmonkClient $listmonkClient=null,private ?FiscalDocuments $documents=null) {}
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
 public function deliverRegistrationNotifications(string $userId): void {
  try{
   if(!(new IntegrationSettings($this->db))->listmonk()['enabled'])return;
   foreach($this->db->run("SELECT id FROM outbox WHERE sent_at IS NULL AND kind='admin_signup' AND dedupe_key LIKE ? LIMIT 10",['admin-signup:'.$userId.':%'])->fetchAll() as $row)$this->deliver(1,(int)$row['id'],true);
  }catch(\Throwable $e){error_log('Administrator signup notification pending');}
 }
 public function dispatch(string $key): string {
  $row=$this->db->one('SELECT id,sent_at FROM outbox WHERE dedupe_key=?',[$key]);
  if(!$row)return 'pending';
  if(!$row['sent_at'])try{$this->deliver(1,(int)$row['id'],true);}catch(\Throwable $e){error_log('Targeted mail dispatch pending; outbox_id='.$row['id']);return 'pending';}
  $sent=$this->db->one('SELECT sent_at FROM outbox WHERE id=?',[$row['id']]);
  if(empty($sent['sent_at']))return 'pending';
  return !(new IntegrationSettings($this->db))->listmonk()['enabled'] && env('MAIL_TRANSPORT','log')==='log' && !production()?'simulated':'accepted';
 }
 public static function dispatchNotice(string $state): string {
  return match($state){'accepted'=>'Emailul către client a fost trimis cu succes (acceptat de serviciul email).','simulated'=>'Emailul a fost simulat local; nu a fost trimis clientului.',default=>'Emailul nu a fost trimis încă; a rămas în coadă. Verifică jurnalul API și configurația email.'};
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
    if($row['kind']==='onboarding_access' && !(new OnboardingLinks($this->db,$this))->resolveHash(substr($row['dedupe_key'],11))){
     $this->db->run('DELETE FROM outbox WHERE id=? AND sent_at IS NULL',[$row['id']]);continue;
    }
    $this->db->run('UPDATE outbox SET attempts=attempts+1 WHERE id=?',[$row['id']]);
    $tr=new TranslationService($row['locale']); $data=self::decode($row['payload']);
    $pdf=null;
    if($row['kind']==='invoice_copy')$pdf=($this->documents??new FiscalDocuments($this->db))->pdf($data['attachment_invoice_id'],$data['user_id']);
    $subject=$tr->get('email.'.$row['kind'].'.subject',$data);
    $body=$tr->get('email.'.$row['kind'].'.body',$data);
    if(isset($data['legal_receipt']))$body.="\n\n".$data['legal_receipt'];
    $html='<!doctype html><html lang="'.e($row['locale']).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($subject).'</title></head><body style="margin:0;padding:0;background-color:#0b1115;color:#edf2e9;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#0b1115"><tr><td align="center" style="padding:28px 16px"><table role="presentation" width="560" cellpadding="0" cellspacing="0" bgcolor="#111b20" style="width:100%;max-width:560px;border:1px solid #27342f;border-radius:16px"><tr><td style="padding:30px 24px"><p style="margin:0 0 30px;color:#edf2e9;font-size:23px;letter-spacing:-1px;font-weight:bold">zion <span style="font-weight:normal">storage</span><span style="color:#c4f582">.</span></p><h1 style="margin:0 0 20px;font-size:28px;line-height:1.3;font-weight:600;letter-spacing:-0.6px;color:#edf2e9">'.e($subject).'</h1><p style="margin:0 0 24px;font-size:15px;line-height:1.8;color:#b9c6be">'.nl2br(e($body)).'</p>';
    if(!empty($data['url'])) {
     $action=$tr->get(match($row['kind']){'verify'=>'email.verify.action','admin_signup'=>'email.admin_signup.action',default=>'email.action'});
     $html.='<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 26px"><tr><td bgcolor="#c4f582" style="border-radius:8px;background-color:#c4f582"><a href="'.e($data['url']).'" style="display:inline-block;padding:16px 23px;border:1px solid #c4f582;border-radius:8px;background-color:#c4f582;color:#172014!important;text-decoration:none;font-size:15px;font-weight:bold;line-height:1.4">'.e($action).' <span aria-hidden="true">↗</span></a></td></tr></table><p style="margin:0 0 10px;font-size:12px;line-height:1.7;color:#9ba8a1">'.e($tr->get('email.copy_link')).'</p><p style="margin:0 0 28px;font-size:12px;line-height:1.8;word-break:break-all;overflow-wrap:anywhere"><a href="'.e($data['url']).'" style="color:#c4f582;text-decoration:underline;word-break:break-all;overflow-wrap:anywhere">'.e($data['url']).'</a></p>';
    }
    $html.='<p style="border-top:1px solid #27342f;padding-top:22px;margin:0;color:#9ba8a1;font-size:12px;line-height:1.7">'.e($tr->get('email.footer')).'</p></td></tr></table></td></tr></table></body></html>';
    $listmonk=(new IntegrationSettings($this->db))->listmonk();
    if($listmonk['enabled']) {
     $client=$this->listmonkClient??new ListmonkClient($listmonk);
     $args=[$row['recipient'],$subject,$html,$body.(!empty($data['url'])?"\n".$data['url']:''),$row['locale'],$row['kind']];
     if($pdf)$client->sendWithAttachment(...[...$args,$pdf]);else $client->send(...$args);
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
     $message=(new Email())->from(new \Symfony\Component\Mime\Address(LegalDocuments::operator()['email'], 'Zion Storage'))->to($row['recipient'])->subject($subject)->text($body.(!empty($data['url'])?"\n".$data['url']:''))->html($html);
     if($pdf)$message->attach($pdf['bytes'],$pdf['filename'],'application/pdf');
     $mailer->send($message);
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
