<?php
namespace Zion;
/** Private server-side adapter. Never return upstream responses to the browser. */
class StorageProvisioningService {
 protected function request(string $method,string $path,?array $body=null): array {
  $base=rtrim(env('STORAGE_API_URL'),'/');
  if(!$base || !env('STORAGE_API_KEY')) throw new \RuntimeException('storage_unconfigured');
  if(production() && !str_starts_with($base,'https://')) throw new \RuntimeException('storage_requires_tls');
  $ch=curl_init($base.$path);
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['x-api-key: '.env('STORAGE_API_KEY'),'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
  if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
  $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($raw===false || $code<200 || $code>=300) throw new \RuntimeException('storage_request_failed');
  return json_decode($raw,true,512,JSON_THROW_ON_ERROR)?:[];
 }
 public function createUser(array $user,string $password,int $quota): string {
  $label='zs-'.$user['id'];
  // Reconcile timeouts after remote creation without adopting unrelated accounts.
  foreach($this->request('GET','/admin/users?withDeleted=true') as $existing) {
   if(($existing['storageLabel']??'')===$label && strtolower($existing['email'])===strtolower($user['email'])) return $existing['id'];
   if(strtolower($existing['email'])===strtolower($user['email'])) throw new \RuntimeException('storage_identity_conflict');
  }
  $result=$this->request('POST','/admin/users',['email'=>$user['email'],'name'=>$user['name'],'password'=>$password,'quotaSizeInBytes'=>$quota,'storageLabel'=>$label,'isAdmin'=>false,'shouldChangePassword'=>false,'notify'=>false]);
  return $result['id']??throw new \RuntimeException('storage_missing_id');
 }
 public function updateQuota(string $id,int $quota): void { $this->request('PUT','/admin/users/'.rawurlencode($id),['quotaSizeInBytes'=>max(1,$quota)]); }
 public function getUsage(string $id): array { $u=$this->request('GET','/admin/users/'.rawurlencode($id)); return ['used'=>(int)($u['quotaUsageInBytes']??0),'quota'=>(int)($u['quotaSizeInBytes']??0)]; }
 public function getIdentity(string $id): array { $u=$this->request('GET','/admin/users/'.rawurlencode($id));return ['label'=>$u['storageLabel']??'','email'=>$u['email']??'']; }
 /** Suspend new uploads while retaining access to existing files. No deletion. */
 public function disableUser(string $id): void { $usage=$this->getUsage($id); $this->updateQuota($id,max(1,$usage['used'])); }
 public function enableUser(string $id,int $quota): void { $this->updateQuota($id,$quota); }
 /** Explicit operator-only destructive API; never called by billing. */
 public function deleteUser(string $id): void { $this->request('DELETE','/admin/users/'.rawurlencode($id),['force'=>false]); }
}
