<?php
namespace Zion;
final class Security {
 public static function csrf(): string { return $_SESSION['csrf']??=$_SESSION['csrf']=bin2hex(random_bytes(32)); }
 public static function checkCsrf(): bool { return is_string($_POST['_csrf']??null)&&hash_equals(self::csrf(),$_POST['_csrf']); }
 public static function clientIp(): string {
  $ip=$_SERVER['REMOTE_ADDR']??'cli';
  $trusted=array_filter(array_map('trim',explode(',',env('TRUSTED_PROXIES'))));
  if(in_array($ip,$trusted,true)) {
   $chain=array_map('trim',explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']??''));
   $chain[]=$ip;
   while(count($chain)>1 && in_array(end($chain),$trusted,true)) array_pop($chain);
   $candidate=end($chain); if(filter_var($candidate,FILTER_VALIDATE_IP)) $ip=$candidate;
  }
  return $ip;
 }
 public static function limit(Database $db,string $action,int $max,int $seconds): bool {
  $key=hash('sha256',$action);
  return $db->transaction(function() use($db,$key,$max,$seconds) {
   $db->run('DELETE FROM rate_limits WHERE expires_at < ?',[time()]);
   $db->run('INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1',[$key,time()+$seconds]);
   return $db->one('SELECT hits FROM rate_limits WHERE bucket=?',[$key])['hits']<=$max;
  });
 }
 public static function captcha(string $token): bool {
  if(env('CAPTCHA_ENABLED')!=='true') return true;
  if(!$token || !str_starts_with(env('CAPTCHA_VERIFY_URL'),'https://')) return false;
  $ch=curl_init(env('CAPTCHA_VERIFY_URL'));
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['secret'=>env('CAPTCHA_SECRET'),'response'=>$token,'remoteip'=>self::clientIp()]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);
  $started=microtime(true);$raw=curl_exec($ch);$data=json_decode($raw?:'{}',true);
  $details=ApiLog::responseDetails($raw,(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),curl_errno($ch),curl_error($ch),[$token,env('CAPTCHA_SECRET'),self::clientIp()]);
  if(($data['success']??false)!==true){$details['failed']=true;if(!$details['code'])$details['code']='captcha_rejected';$details['message']='Verificarea CAPTCHA a fost respinsă.';}
  ApiLog::record('captcha','POST',env('CAPTCHA_VERIFY_URL'),(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),$started,$details);curl_close($ch);
  return ($data['success']??false)===true;
 }
}
