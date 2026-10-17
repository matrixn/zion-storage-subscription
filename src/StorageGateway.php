<?php
namespace Zion;
/** nginx auth_request gate. Every Immich API request must pass this check. */
class StorageGateway {
 public function __construct(private Database $db) {}
 public function allowed(string $uri,array $headers): bool {
  $path=parse_url($uri,PHP_URL_PATH)?:'';
  // Public discovery/login only. Public share APIs are intentionally not exempt.
  if(in_array($path,['/api/server/ping','/api/server/config','/api/server/features','/api/server/version','/api/auth/login','/api/oauth/authorize','/api/oauth/callback'],true))return true;
  $id=$this->identity($headers);
  if(!$id)return false;
  $u=$this->db->one('SELECT id FROM users WHERE storage_user_id=?',[$id]);
  if(!$u){
   // A provisioning response may have been lost before storage_user_id was saved.
   $remote=(new StorageProvisioningService())->getIdentity($id);
   if(str_starts_with($remote['label'],'zs-'))$u=$this->db->one('SELECT id FROM users WHERE id=? AND email=?',[substr($remote['label'],3),$remote['email']]);
  }
  return !$u || !(new AccountControl($this->db))->banned($u['id']);
 }
 protected function identity(array $headers): ?string {
  $forward=[];
  foreach(['Authorization','Cookie','x-api-key'] as $key)if(!empty($headers[$key]) && !preg_match('/[\r\n]/',$headers[$key]))$forward[]=$key.': '.$headers[$key];
  if(!$forward)return null;
  $base=rtrim(env('STORAGE_API_URL'),'/');
  if(!$base || (production() && !str_starts_with($base,'https://')))return null;
  $ch=curl_init($base.'/users/me');
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$forward,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false]);
  $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($code!==200 || !is_string($raw))return null;
  $result=json_decode($raw,true);return is_string($result['id']??null)?$result['id']:null;
 }
 public function handle(): void {
  header('Cache-Control: no-store');
  $secret=env('STORAGE_GATEWAY_KEY');
  if($_SERVER['REQUEST_METHOD']!=='GET' || strlen($secret)<32 || !hash_equals($secret,(string)($_SERVER['HTTP_X_ZION_GATEWAY_KEY']??''))){http_response_code(403);return;}
  try{
   $allowed=$this->allowed((string)($_SERVER['HTTP_X_ORIGINAL_URI']??''),['Authorization'=>$_SERVER['HTTP_AUTHORIZATION']??'','Cookie'=>$_SERVER['HTTP_COOKIE']??'','x-api-key'=>$_SERVER['HTTP_X_API_KEY']??'']);
   http_response_code($allowed?204:403);
  }catch(\Throwable $e){http_response_code(403);}
 }
}
