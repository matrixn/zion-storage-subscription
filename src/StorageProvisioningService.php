<?php
namespace Zion;
/** Private server-side adapter. Never return upstream responses to the browser. */
class StorageProvisioningService {
 public function __construct(private ?Database $db=null) {}
 protected function request(string $method,string $path,?array $body=null): array {
  return $this->transport($method,$path,$body);
 }
 /** Explicit user session or anonymous login; never mix in the administrator key. */
 protected function userRequest(string $method,string $path,?array $body,?string $token): array {
  return $this->transport($method,$path,$body,$token===null?[]:['Authorization: Bearer '.$token],[$token??'']);
 }
 private function transport(string $method,string $path,?array $body=null,?array $authorization=null,array $secrets=[]): array {
  $settings=(new IntegrationSettings($this->db??new Database()))->immich();$base=rtrim($settings['url'],'/');
  if(!$base || ($authorization===null && !$settings['api_key'])) throw new \RuntimeException('storage_unconfigured');
  if(production() && !str_starts_with($base,'https://')) throw new \RuntimeException('storage_requires_tls');
  $authorization??=['x-api-key: '.$settings['api_key']];
  $ch=curl_init($base.$path);
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge($authorization,['Content-Type: application/json']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
  if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
  $started=microtime(true);$raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
  $response=is_string($raw)?json_decode($raw,true):null;
  ApiLog::curlResult('immich',$method,$base.$path,$ch,$raw,$started,[$body??[],$settings['api_key'],$authorization,$secrets,$response['accessToken']??'']);curl_close($ch);
  if($raw===false || $code<200 || $code>=300) throw new StorageApiException((int)$code);
  if($code===204)return [];
  return json_decode($raw,true,512,JSON_THROW_ON_ERROR)?:[];
 }
 public function statistics(): array {return $this->request('GET','/server/statistics');}
 /** Server-side configuration only: callers must never expose this response to HTML/logs. */
 public function configuration(): array {return $this->request('GET','/system-config');}
 public function updateConfiguration(array $config): array {return $this->request('PUT','/system-config',$config);}
 public function createUser(array $user,string $password,int $quota): string {
  $label='zs-'.$user['id'];
  // Reconcile timeouts after remote creation without adopting unrelated accounts.
  foreach($this->request('GET','/admin/users?withDeleted=true') as $existing) {
   if(($existing['storageLabel']??'')===$label && strtolower($existing['email'])===strtolower($user['email'])) {
    $this->configureAccount($existing['id'],$user,$password);return $existing['id'];
   }
   if(strtolower($existing['email'])===strtolower($user['email'])) throw new \RuntimeException('storage_identity_conflict');
  }
  $result=$this->request('POST','/admin/users',['email'=>$user['email'],'name'=>$user['name'],'password'=>$password,'quotaSizeInBytes'=>$quota,'storageLabel'=>$label,'isAdmin'=>false,'shouldChangePassword'=>false,'notify'=>false]);
  $id=$result['id']??throw new \RuntimeException('storage_missing_id');
  $this->configureAccount($id,$user,$password);return $id;
 }
 /** Only called before the portal links an account as active. Retries preserve completed accounts. */
 private function configureAccount(string $id,array $user,string $password): void {
  $session=$this->userRequest('POST','/auth/login',['email'=>$user['email'],'password'=>$password],null);
  $token=$session['accessToken']??null;
  if(!is_string($token) || !preg_match('/^[a-zA-Z0-9._~+\/=-]{20,4096}$/D',$token))throw new \RuntimeException('storage_invalid_session');
  $complete=false;
  try{
   if(($session['userId']??null)!==$id || strtolower($session['userEmail']??'')!==strtolower($user['email']) || ($session['isAdmin']??null)!==false)throw new \RuntimeException('storage_session_identity_mismatch');
   $onboarding=$this->userRequest('GET','/users/me/onboarding',null,$token);
   if(($onboarding['isOnboarded']??null)===true){$complete=true;return;}
   if(($onboarding['isOnboarded']??null)!==false)throw new \RuntimeException('storage_invalid_onboarding');
   // Account preferences supported by Immich 3.3.3. Language/theme are device-local.
   // people.enabled controls the UI, not the server's facial-recognition processing.
   $defaults=(new ImmichConfiguration($this->db??new Database(),$this))->preferences();
   $preferences=$this->userRequest('PUT','/users/me/preferences',$defaults,$token);
   foreach($defaults as $group=>$values)foreach($values as $key=>$value)if(($preferences[$group][$key]??null)!==$value)throw new \RuntimeException('storage_preferences_not_confirmed');
   $result=$this->userRequest('PUT','/users/me/onboarding',['isOnboarded'=>true],$token);
   if(($result['isOnboarded']??null)!==true)throw new \RuntimeException('storage_onboarding_not_confirmed');
   $complete=true;
  }finally{
   // Revoke only this transient session; never invalidate the customer's other devices.
   try{if(($this->userRequest('POST','/auth/logout',null,$token)['successful']??null)!==true)throw new \RuntimeException('storage_logout_not_confirmed');}catch(\Throwable $e){if($complete)throw $e;}
  }
 }
 public function updateQuota(string $id,int $quota): void { $this->request('PUT','/admin/users/'.rawurlencode($id),['quotaSizeInBytes'=>max(1,$quota)]); }
 public function getUsage(string $id): array { $u=$this->request('GET','/admin/users/'.rawurlencode($id)); return ['used'=>(int)($u['quotaUsageInBytes']??0),'quota'=>(int)($u['quotaSizeInBytes']??0)]; }
 public function getIdentity(string $id): array { $u=$this->request('GET','/admin/users/'.rawurlencode($id));return ['label'=>$u['storageLabel']??'','email'=>$u['email']??'']; }
 /** Suspend new uploads while retaining access to existing files. No deletion. */
 public function disableUser(string $id): void { $usage=$this->getUsage($id); $this->updateQuota($id,max(1,$usage['used'])); }
 public function enableUser(string $id,int $quota): void { $this->updateQuota($id,$quota); }
 /** Explicit operator-only destructive API; never called by billing. */
 public function deleteUser(string $id): void { $this->request('DELETE','/admin/users/'.rawurlencode($id),['force'=>false]); }
 private function resetIdentity(string $id,string $uid,string $email): ?array {
  try{$remote=$this->request('GET','/admin/users/'.rawurlencode($id));}
  catch(StorageApiException $e){if(in_array($e->httpStatus,[404,410],true))return null;throw $e;}
  if(($remote['storageLabel']??'')!=='zs-'.$uid || ($remote['isAdmin']??null)!==false || !in_array(strtolower($remote['email']??''),[strtolower($email),'reset-'.$uid.'@deleted.invalid'],true))throw new \DomainException('Identitatea Immich nu corespunde contului TEST sau este administrator. Resetarea a fost oprită.');
  return $remote;
 }
 public function assertTestResetIdentity(string $id,string $uid,string $email): void {$this->resetIdentity($id,$uid,$email);}
 public function purgeTestUser(string $id,string $uid,string $email): void {
  $remote=$this->resetIdentity($id,$uid,$email);if(!$remote)return;
  // Release the email immediately; Immich's removal job completes asynchronously.
  $replacement='reset-'.$uid.'@deleted.invalid';
  if(($remote['email']??'')!==$replacement)$this->request('PUT','/admin/users/'.rawurlencode($id),['email'=>$replacement]);
  if(!in_array($remote['status']??'',['removing','deleted'],true))$this->request('DELETE','/admin/users/'.rawurlencode($id),['force'=>true]);
 }
}
