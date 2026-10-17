<?php
namespace Zion;
final class AccountService {
 public function __construct(private Database $db,private Plans $plans,private MailerService $mail,private StorageProvisioningService $storage) {}
 public static function passwordValid(string $p): bool { return mb_strlen($p)>=12 && strlen($p)<=200; }
 public function register(array $data): ?string {
  $id=bin2hex(random_bytes(16));
  try {
   $this->db->transaction(function() use($id,$data) {
    $this->db->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[
     $id,$data['name'],strtolower($data['email']),password_hash($data['password'],PASSWORD_ARGON2ID),$data['locale'],$data['plan'],time(),time(),time(),time()]);
    if($data['plan']!=='free' && isset($data['billing'])) (new BillingProfileService($this->db))->save($id,$data['billing']);
   });
  } catch(\PDOException $e) { if($this->db->one('SELECT id FROM users WHERE email=?',[$data['email']])) return null; throw $e; }
  $this->issueVerification($id); return $id;
 }
 public function issueVerification(string $id): void {
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
  if(!$u || $u['email_verified_at']) return;
  $token=bin2hex(random_bytes(32));
  $this->db->transaction(function() use($id,$u,$token) {
   $this->db->run('DELETE FROM email_verifications WHERE user_id=?',[$id]);
   $this->db->run('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)',[hash('sha256',$token),$id,time()+3600]);
   $this->mail->queue($u['email'],$u['locale'],'verify',['url'=>base_url().Router::path($u['locale'],'verify').'?token='.$token],'verify:'.hash('sha256',$token));
  });
 }
 public function verify(string $token): ?string {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
  return $this->db->transaction(function() use($token) {
   $r=$this->db->one('SELECT * FROM email_verifications WHERE token_hash=? AND used_at IS NULL AND expires_at>?',[hash('sha256',$token),time()]);
   if(!$r) return null;
   $this->db->run('UPDATE email_verifications SET used_at=? WHERE token_hash=?',[time(),$r['token_hash']]);
   $this->db->run("UPDATE users SET status=CASE WHEN email_verified_at IS NULL THEN 'verified' ELSE status END,email_verified_at=COALESCE(email_verified_at,?),updated_at=? WHERE id=?",[time(),time(),$r['user_id']]);
   return $r['user_id'];
  });
 }
 public function eligible(array $u): bool {
  if(!$u['email_verified_at']) return false;
  $controls=new AccountControl($this->db);
  if($controls->banned($u['id'])) return false;
  if($controls->manual($u['id'])===$u['selected_plan_id']) return true;
  if($u['selected_plan_id']==='free') return true;
  $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
  return $s && $s['paid_confirmed'] && $s['status']==='active';
 }
 public function provision(string $id,string $password): bool {
  $lock=fopen(dirname(__DIR__).'/storage/provision-'.$id.'.lock','c'); flock($lock,LOCK_EX);
  try {
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
   if(!$u || !$this->eligible($u) || !password_verify($password,$u['password_hash'])) return false;
   if($u['storage_user_id']) return true;
   $p=$this->plans->get($u['selected_plan_id']); if(!$p) return false;
   $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','started',?)",[$id,time()]);
   $external=$this->storage->createUser($u,$password,$p['storage_bytes']);
   $this->storage->enableUser($external,$p['storage_bytes']);
   $this->db->transaction(function() use($id,$u,$p,$external) {
    $this->db->run("UPDATE users SET storage_user_id=?,storage_quota_bytes=?,status='active',updated_at=? WHERE id=?",[$external,$p['storage_bytes'],time(),$id]);
    $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','complete',?)",[$id,time()]);
    $this->mail->queue($u['email'],$u['locale'],'activated',[], 'activated:'.$id);
   });
   return true;
  } catch(\Throwable $e) {
   $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','retry_required',?)",[$id,time()]);
   throw new \RuntimeException('provisioning_pending');
  } finally { flock($lock,LOCK_UN); fclose($lock); sodium_memzero($password); }
 }
}
