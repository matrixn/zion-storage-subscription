<?php
namespace Zion;
final class AdminService {
 public function __construct(private Database $db,private Plans $plans,private MailerService $mail,private StorageProvisioningService $storage,private SubscriptionChanges $changes) {}
 public function create(array $d,string $admin): string {
  $name=trim((string)($d['name']??''));$email=strtolower(trim((string)($d['email']??'')));$password=(string)($d['password']??'');$plan=(string)($d['plan']??'');$locale=(string)($d['locale']??'ro');
  if(mb_strlen($name)<2 || mb_strlen($name)>150 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || !AccountService::passwordValid($password) || !$this->plans->get($plan) || !in_array($locale,TranslationService::LOCALES,true))throw new \DomainException('Verifică numele, emailul, planul și parola (minimum 12 caractere).');
  if($this->db->one('SELECT id FROM users WHERE email=?',[$email]))throw new \DomainException('Există deja un client cu acest email.');
  $id=bin2hex(random_bytes(16));
  $this->db->transaction(function()use($id,$name,$email,$password,$plan,$locale,$admin){
   // The customer must personally accept the terms before storage activation.
   $this->db->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,0,0,?,?)',[$id,$name,$email,password_hash($password,PASSWORD_ARGON2ID),$locale,$plan,time(),time()]);
   $this->db->run('INSERT INTO account_controls(user_id,manual_plan,updated_at) VALUES(?,?,?)',[$id,$plan,time()]);
   $this->changes->audit($admin,$id,'create_customer','Plan manual: '.$plan.'; verificare email necesară');
  });
  (new AccountService($this->db,$this->plans,$this->mail,$this->storage))->issueVerification($id);
  return $id;
 }
 public function ban(string $uid,bool $ban,string $reason,string $admin): void {
  $this->changes->serialized($uid,function()use($uid,$ban,$reason,$admin){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);if(!$u)throw new \DomainException('Client inexistent.');
   $control=new AccountControl($this->db);$old=$control->get($uid);
   if($ban){
    $this->db->transaction(function()use($uid,$reason,$admin){
     $this->db->run("INSERT INTO account_controls(user_id,banned_at,reason,access_sync,updated_at) VALUES(?,?,?,'pending',?) ON CONFLICT(user_id) DO UPDATE SET banned_at=COALESCE(banned_at,excluded.banned_at),reason=excluded.reason,access_sync='pending',updated_at=excluded.updated_at",[$uid,time(),$reason,time()]);
     $this->db->run("UPDATE users SET status='banned',updated_at=? WHERE id=?",[time(),$uid]);
     $this->changes->audit($admin,$uid,'ban',$reason.'; fișiere și abonament păstrate');
    });
    try{
     if($u['storage_user_id'])$this->storage->disableUser($u['storage_user_id']);
     $sync=(!$u['storage_user_id'] || AccountControl::gatewayConfigured())?'ready':'gateway_required';
     $this->db->run('UPDATE account_controls SET access_sync=?,updated_at=? WHERE user_id=?',[$sync,time(),$uid]);
    }catch(\Throwable $e){$this->db->run("UPDATE account_controls SET access_sync='retry',updated_at=? WHERE user_id=?",[time(),$uid]);throw new \DomainException('Ban local activ. Immich nu a confirmat limitarea cotei; reîncearcă suspendarea.');}
   }else{
    if(empty($old['banned_at']))throw new \DomainException('Contul nu este suspendat.');
    $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$uid]);
    $entitled=$u['email_verified_at'] && ($u['selected_plan_id']==='free' || $control->manual($uid)===$u['selected_plan_id'] || ($s && $s['status']==='active' && $s['paid_confirmed']));
    if($u['storage_user_id']){
     if($entitled)$this->storage->enableUser($u['storage_user_id'],$this->plans->get($u['selected_plan_id'])['storage_bytes']);
     else $this->storage->disableUser($u['storage_user_id']);
    }
    $this->db->transaction(function()use($uid,$u,$entitled,$reason,$admin){
     $this->db->run("UPDATE account_controls SET banned_at=NULL,reason=NULL,access_sync='ready',updated_at=? WHERE user_id=?",[time(),$uid]);
     $this->db->run('UPDATE users SET status=?,updated_at=? WHERE id=?',[$u['storage_user_id']?($entitled?'active':'restricted'):($u['email_verified_at']?'verified':'pending'),time(),$uid]);
     $this->changes->audit($admin,$uid,'unban',$reason);
    });
   }
  });
 }
 public function syncQuota(string $uid,?string $admin=null): void {
  $this->changes->serialized($uid,function()use($uid,$admin){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);if(!$u)throw new \DomainException('Client inexistent.');
   $eligible=(new AccountService($this->db,$this->plans,$this->mail,$this->storage))->eligible($u);
   $p=$this->plans->get($u['selected_plan_id']);
   if($u['storage_user_id']){
    if($eligible)$this->storage->enableUser($u['storage_user_id'],$p['storage_bytes']);else $this->storage->disableUser($u['storage_user_id']);
   }
   $this->db->run("UPDATE account_controls SET quota_sync='ready',updated_at=? WHERE user_id=?",[time(),$uid]);
   if($eligible && $u['storage_user_id'])$this->db->run("UPDATE users SET status='active',storage_quota_bytes=?,updated_at=? WHERE id=?",[$p['storage_bytes'],time(),$uid]);
   if($admin)$this->changes->audit($admin,$uid,'quota_sync','Sincronizare cotă conform planului și stării curente');
  });
 }
}
