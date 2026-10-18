<?php
declare(strict_types=1);
namespace Zion;

/** Independent of billing transactions: a rollback must not erase transport failures. */
final class ApiLog {
 private static function database(): \PDO {
  $appPath=env('DB_PATH','storage/app.sqlite');
  $path=env('API_LOG_PATH',dirname($appPath).'/api-communication.sqlite');
  if(!str_starts_with($path,'/'))$path=dirname(__DIR__).'/'.$path;
  if(!is_dir(dirname($path)) && !@mkdir(dirname($path),0700,true) && !is_dir(dirname($path)))throw new \RuntimeException('API log directory unavailable');
  $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
  @chmod($path,0600);
  $db->exec('PRAGMA busy_timeout=1000; CREATE TABLE IF NOT EXISTS api_log(id INTEGER PRIMARY KEY AUTOINCREMENT,created_at INTEGER NOT NULL,provider TEXT NOT NULL,mode TEXT NOT NULL,direction TEXT NOT NULL,method TEXT NOT NULL,endpoint TEXT NOT NULL,status TEXT NOT NULL,http_status INTEGER NOT NULL,duration_ms INTEGER NOT NULL,error_code TEXT NOT NULL,message TEXT NOT NULL,request_id TEXT NOT NULL)');
  $db->exec('CREATE INDEX IF NOT EXISTS api_log_created ON api_log(created_at)');
  return $db;
 }
 public static function redact(string $message,array $sensitive=[]): string {
  $values=[];
  array_walk_recursive($sensitive,static function($value)use(&$values){if(is_string($value)&&strlen($value)>=4)$values[]=$value;});
  usort($values,static fn($a,$b)=>strlen($b)<=>strlen($a));
  if($values)$message=str_replace($values,'[redacted]',$message);
  $message=preg_replace('~https?://[^\s<>]+~i','[url]',$message);
  $message=preg_replace('/\b(?:sk|rk|pk)_(?:test|live)_[a-zA-Z0-9]+|\bwhsec_[a-zA-Z0-9]+|\bBearer\s+[^\s,;]+/i','[redacted]',$message);
  $message=preg_replace('/[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/','[email]',$message);
  $message=preg_replace('/\b(?:\d[ -]?){13,19}\b/','[number]',$message);
  $message=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',$message);
  return mb_substr($message,0,2000);
 }
 public static function record(string $provider,string $method,string $url,int $httpStatus,float $started,array $details=[]): void {
  try{
   $host=parse_url($url,PHP_URL_HOST)?:'';$path=rawurldecode(parse_url($url,PHP_URL_PATH)?:'/');
   // Keep route structure, never customer IDs, query parameters or credentials.
   $path=preg_replace('~/(?:cs_(?:test|live)_[a-zA-Z0-9]+|(?:cus|sub|price|prod|cs|in|pi|pm|seti|bps|bpc|si|il|evt|plink|txr|ch|re)_[a-zA-Z0-9]+|[a-f0-9-]{20,}|\d+)(?=/|$)~','/{id}',$path);
   $endpoint=mb_substr($host.$path,0,300);
   $status=$details['failed']??($httpStatus<200||$httpStatus>=300);
   $sensitive=$details['sensitive']??[];
   $rawCode=$details['code']??'';
   $code=preg_replace('/[^a-zA-Z0-9_.-]/','',substr(self::redact(is_scalar($rawCode)?(string)$rawCode:'',$sensitive),0,100));
   $requestId=preg_match('/^req_[a-zA-Z0-9]+$/D',(string)($details['request_id']??''))?$details['request_id']:'';
   $row=[time(),$provider,in_array($details['mode']??'',['test','live'],true)?$details['mode']:'',($details['direction']??'')==='in'?'in':'out',strtoupper($method),self::redact($endpoint,$sensitive),$status?'error':'ok',$httpStatus,max(0,(int)round((microtime(true)-$started)*1000)),$code,self::redact((string)($details['message']??''),$sensitive),self::redact($requestId,$sensitive)];
   $db=self::database();
   $db->prepare('INSERT INTO api_log(created_at,provider,mode,direction,method,endpoint,status,http_status,duration_ms,error_code,message,request_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute($row);
   $db->prepare('DELETE FROM api_log WHERE created_at < ? OR id <= (SELECT COALESCE(MAX(id),0)-10000 FROM api_log)')->execute([time()-30*86400]);
  }catch(\Throwable $e){error_log('API diagnostic log unavailable');}
 }
 public static function responseDetails(string|false $body,int $status,int $curlCode,string $curlError,array $sensitive=[]): array {
  $result=is_string($body)?json_decode($body,true):null;
  $semanticStatus=isset($result['status']) && is_numeric($result['status'])?(int)$result['status']:null;
  $failed=$body===false || $status<200 || $status>=300 || (!is_array($result) && $status!==204) || ($semanticStatus!==null && ($semanticStatus<200 || $semanticStatus>=300));
  $error=is_array($result['error']??null)?$result['error']:[];
  $message=$curlCode?$curlError:($failed?($error['message']??$result['error_description']??$result['message']??$result['statusMessage']??'Răspuns API invalid sau cerere respinsă.'):'Cerere finalizată.');
  if(!is_string($message))$message='Cerere respinsă; detaliile răspunsului nu sunt înregistrate.';
  $providerCode=$error['code']??$error['type']??(is_string($result['error']??null)?$result['error']:null);
  return ['failed'=>$failed,'code'=>$curlCode?'curl_'.$curlCode:($providerCode??($failed?'http_or_response_error':'')),'message'=>$message,'sensitive'=>$sensitive];
 }
 public static function curlResult(string $provider,string $method,string $url,\CurlHandle $handle,string|false $body,float $started,array $sensitive=[]): void {
  self::record($provider,$method,$url,(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),$started,self::responseDetails($body,(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),curl_errno($handle),curl_error($handle),$sensitive));
 }
 public static function page(array $filters,int $page=1): array {
  $where=[];$params=[];
  foreach(['provider'=>['stripe','oblio','immich','captcha'],'status'=>['ok','error'],'mode'=>['test','live'],'direction'=>['in','out']] as $key=>$allowed)if(in_array($filters[$key]??'',$allowed,true)){$where[]=$key.'=?';$params[]=$filters[$key];}
  $condition=$where?' WHERE '.implode(' AND ',$where):'';
  try{
   $db=self::database();$count=$db->prepare('SELECT COUNT(*) FROM api_log'.$condition);$count->execute($params);$total=(int)$count->fetchColumn();
   $page=max(1,min($page,max(1,(int)ceil($total/50))));
   $query=$db->prepare('SELECT * FROM api_log'.$condition.' ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50));$query->execute($params);
   return ['rows'=>$query->fetchAll(),'total'=>$total,'page'=>$page,'available'=>true];
  }catch(\Throwable $e){return ['rows'=>[],'total'=>0,'page'=>1,'available'=>false];}
 }
}
