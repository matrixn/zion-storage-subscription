<?php
declare(strict_types=1);
namespace Zion;

final class OnboardingLinks {
 public function __construct(private Database $db,private MailerService $mail) {}
 public function allowed(array $u): bool {
  return (bool)$u['email_verified_at'] && !(new AccountControl($this->db))->banned($u['id']) && (new AccountService($this->db,new Plans($this->db),$this->mail,new StorageProvisioningService($this->db)))->eligible($u);
 }
 public function current(string $uid): ?array {
  $row=$this->db->one('SELECT * FROM onboarding_links WHERE user_id=?',[$uid]);
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
  if(!$row || !$u)return null;
  $row['valid']=$row['expires_at']>time() && $row['email']===$u['email'] && $this->allowed($u);
  $row['url']=$row['valid']?base_url().Router::path($u['locale'],'onboarding').'#token='.EncryptedPayload::decode($row['token'])['token']:null;
  return $row;
 }
 public function resolve(string $token): ?array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
  return $this->resolveHash(hash('sha256',$token));
 }
 public function resolveHash(string $hash): ?array {
  $u=$this->db->one('SELECT u.*,l.expires_at AS link_expires_at FROM onboarding_links l JOIN users u ON u.id=l.user_id WHERE l.token_hash=? AND l.expires_at>? AND l.email=u.email',[$hash,time()]);
  return $u && $this->allowed($u)?$u:null;
 }
 public function issue(string $uid,bool $replace=false): array {
  return $this->db->transaction(function()use($uid,$replace){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   if(!$u || !$this->allowed($u))throw new \DomainException('Linkul necesită un client confirmat, cu acces activ și nesuspendat.');
   if(!$replace && $this->db->one('SELECT user_id FROM onboarding_links WHERE user_id=?',[$uid]))return $this->current($uid);
   $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expires=time()+3*86400;
   $this->db->run("DELETE FROM outbox WHERE kind='onboarding_access' AND sent_at IS NULL AND dedupe_key IN (SELECT 'onboarding:'||token_hash FROM onboarding_links WHERE user_id=?)",[$uid]);
   $this->db->run('INSERT INTO onboarding_links(user_id,token_hash,token,email,expires_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET token_hash=excluded.token_hash,token=excluded.token,email=excluded.email,expires_at=excluded.expires_at,created_at=excluded.created_at',[$uid,$hash,EncryptedPayload::encode(['token'=>$token]),$u['email'],$expires,time()]);
   $url=base_url().Router::path($u['locale'],'onboarding').'#token='.$token;
   $this->mail->queue($u['email'],$u['locale'],'onboarding_access',['url'=>$url,'name'=>$u['name']],'onboarding:'.$hash);
   return ['url'=>$url,'expires_at'=>$expires,'token_hash'=>$hash,'valid'=>true];
  });
 }
 /** Called only after an authoritative positive first payment has been persisted. */
 public function firstPayment(string $uid,string $invoiceId): void {
  $rows=$this->db->run('SELECT id FROM invoices WHERE user_id=? AND amount>0 ORDER BY created_at,id',[$uid])->fetchAll();
  if(count($rows)!==1 || $rows[0]['id']!==$invoiceId)return;
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
  if($u && $this->allowed($u))$this->issue($uid);
 }
}
