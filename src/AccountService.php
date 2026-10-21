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
    $interval=$data['billing_interval']??'month';if(!in_array($interval,['month','year'],true))throw new \InvalidArgumentException('invalid_billing_interval');
    $this->db->run('UPDATE users SET billing_interval=? WHERE id=?',[$interval,$id]);
    $this->db->run('UPDATE users SET stripe_mode=? WHERE id=?',[(new IntegrationSettings($this->db))->stripe()['mode'],$id]);
    $this->db->run('UPDATE users SET terms_version=?,privacy_version=? WHERE id=?',[LegalDocuments::VERSION,LegalDocuments::VERSION,$id]);
    $this->rememberPassword($id,$data['password']);
    if($data['plan']!=='free' && isset($data['billing'])) (new BillingProfileService($this->db))->save($id,$data['billing']);
    $plan=$this->plans->get($data['plan'],$interval);
    if($data['plan']==='free')$this->db->run('UPDATE users SET storage_quota_bytes=? WHERE id=?',[$plan['storage_bytes'],$id]);
    foreach($this->db->run('SELECT email FROM administrators')->fetchAll() as $administrator){
     $recipient=strtolower($administrator['email']);
     $this->mail->queue($recipient,'ro','admin_signup',['name'=>$data['name'],'email'=>strtolower($data['email']),'plan'=>$plan['name'],'capacity'=>$plan['capacity'],'interval'=>$interval==='year'?'Anual':'Lunar','created'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest')))->format('d.m.Y H:i'),'url'=>base_url().'/admin?section=clients&q='.rawurlencode(strtolower($data['email']))],'admin-signup:'.$id.':'.hash('sha256',$recipient));
    }
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
 /** Keep the onboarding password encrypted for at most 24 hours, never in session/logs. */
 private function rememberPassword(string $id,string $password): void {
  $this->db->run('INSERT INTO activation_credentials(user_id,payload,expires_at,next_attempt_at) VALUES(?,?,?,0) ON CONFLICT(user_id) DO UPDATE SET payload=excluded.payload,expires_at=excluded.expires_at,next_attempt_at=0',[$id,EncryptedPayload::encode(['password'=>$password]),time()+86400]);
 }
 public function automaticPending(string $id): bool {
  return (bool)$this->db->one('SELECT user_id FROM activation_credentials WHERE user_id=? AND expires_at>?',[$id,time()]);
 }
 private function locked(string $id,callable $fn): mixed {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))return false;
  // Match billing/admin/reset lock order so entitlements cannot change mid-creation.
  $global=fopen(dirname(__DIR__).'/storage/billing.lock','c');flock($global,LOCK_EX);
  $lock=fopen(dirname(__DIR__).'/storage/provision-'.$id.'.lock','c');flock($lock,LOCK_EX);
  try{return $fn();}finally{flock($lock,LOCK_UN);fclose($lock);flock($global,LOCK_UN);fclose($global);}
 }
 public function automatic(string $id): bool {
  return $this->locked($id,function()use($id){
   $credential=$this->db->one('SELECT * FROM activation_credentials WHERE user_id=?',[$id]);
   if(!$credential)return false;
   if($credential['expires_at']<=time()){$this->db->run('DELETE FROM activation_credentials WHERE user_id=?',[$id]);return false;}
   if($credential['next_attempt_at']>time())return false;
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
   if(!$u || !$u['terms_at'] || !$u['privacy_at'] || !$this->eligible($u))return false;
   // Durable backoff applies to worker and browser requests alike.
   $this->db->run('UPDATE activation_credentials SET next_attempt_at=? WHERE user_id=?',[time()+60,$id]);
   $password='';
   try {
    $password=EncryptedPayload::decode($credential['payload'])['password'];
    return $this->provisionLocked($id,$password);
   }catch(\Throwable $e){
    if($e->getMessage()!=='provisioning_pending')ApiLog::record('immich','POST','/activation',503,microtime(true),['code'=>'activation_credential_unavailable','message'=>'Datele temporare de activare nu pot fi citite. Verifică APP_KEY și reia confirmarea parolei dacă este necesar.','context'=>['user_id'=>$id,'stage'=>'activation']]);
    return false;
   }
   finally{if($password!=='')sodium_memzero($password);}
  });
 }
 public function retryAutomatic(): array {
  $this->db->run('DELETE FROM activation_credentials WHERE expires_at<=?',[time()]);
  $ready=0;$pending=0;
  foreach($this->db->run("SELECT c.user_id FROM activation_credentials c JOIN users u ON u.id=c.user_id LEFT JOIN account_controls a ON a.user_id=u.id LEFT JOIN subscriptions s ON s.user_id=u.id WHERE c.next_attempt_at<=? AND u.email_verified_at IS NOT NULL AND u.terms_at>0 AND u.privacy_at>0 AND u.storage_user_id IS NULL AND a.banned_at IS NULL AND (u.selected_plan_id='free' OR a.manual_plan=u.selected_plan_id OR (s.paid_confirmed=1 AND s.status='active')) ORDER BY c.next_attempt_at LIMIT 100",[time()])->fetchAll() as $r){
   if($this->automatic($r['user_id']))$ready++;else $pending++;
  }
  return ['ready'=>$ready,'pending'=>$pending];
 }
 public function provision(string $id,string $password): bool {
  try{return $this->locked($id,function()use($id,$password){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
   if(!$u || !$this->eligible($u) || !$u['terms_at'] || !$u['privacy_at'] || !password_verify($password,$u['password_hash']))return false;
   if(!$u['storage_user_id'])$this->rememberPassword($id,$password);
   return $this->provisionLocked($id,$password);
  });}finally{if($password!=='')sodium_memzero($password);}
 }
 private function provisionLocked(string $id,string $password): bool {
  $previous=ApiLog::context(['user_id'=>$id,'stage'=>'activation']);
  try {
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
   if(!$u || !$this->eligible($u) || !$u['terms_at'] || !$u['privacy_at'] || !password_verify($password,$u['password_hash'])) return false;
   if($u['storage_user_id']){$this->db->run('DELETE FROM activation_credentials WHERE user_id=?',[$id]);return true;}
   $p=$this->plans->forUser($u); if(!$p) return false;
   $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','started',?)",[$id,time()]);
   $external=$this->storage->createUser($u,$password,$p['storage_bytes']);
   $this->storage->enableUser($external,$p['storage_bytes']);
   $this->db->transaction(function() use($id,$u,$p,$external) {
    $this->db->run("UPDATE users SET storage_user_id=?,storage_quota_bytes=?,status='active',updated_at=? WHERE id=?",[$external,$p['storage_bytes'],time(),$id]);
    $this->db->run('DELETE FROM activation_credentials WHERE user_id=?',[$id]);
    $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','complete',?)",[$id,time()]);
    $this->mail->queue($u['email'],$u['locale'],'activated',[], 'activated:'.$id);
   });
   return true;
  } catch(\Throwable $e) {
   $this->db->run('UPDATE activation_credentials SET next_attempt_at=? WHERE user_id=?',[time()+60,$id]);
   $this->db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','retry_required',?)",[$id,time()]);
   $known=['storage_unconfigured','storage_requires_tls','storage_identity_conflict','storage_missing_id'];
   ApiLog::record('immich','POST','/activation',503,microtime(true),['code'=>in_array($e->getMessage(),$known,true)?$e->getMessage():'activation_pending','message'=>'Activarea automată necesită reîncercare. Verifică și cererile Immich asociate.','context'=>['user_id'=>$id,'stage'=>'activation']]);
   throw new \RuntimeException('provisioning_pending');
  } finally { ApiLog::context($previous);if($password!=='')sodium_memzero($password); }
 }
}
