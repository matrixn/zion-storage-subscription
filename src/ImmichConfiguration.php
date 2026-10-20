<?php
namespace Zion;

/** Only allowlisted booleans leave the server configuration boundary. */
final class ImmichConfiguration {
 private const KEY='immich:account-defaults:v1';
 private const DEFAULTS=['cast'=>false,'people'=>false,'memories'=>true];
 private const SERVER=[
  'machine_learning'=>['machineLearning','enabled'],
  'facial_recognition'=>['machineLearning','facialRecognition','enabled'],
  'smart_search'=>['machineLearning','clip','enabled'],
  'map'=>['map','enabled'],
 ];
 public function __construct(private Database $db,private ?StorageProvisioningService $storage=null){$this->storage??=new StorageProvisioningService($db);}
 public function accountDefaults(): array {
  $row=$this->db->one('SELECT value FROM integration_settings WHERE key=?',[self::KEY]);
  if(!$row)return self::DEFAULTS;
  $saved=json_decode($row['value'],true,512,JSON_THROW_ON_ERROR);
  foreach(self::DEFAULTS as $key=>$default)if(!is_bool($saved[$key]??null))throw new \RuntimeException('immich_invalid_account_defaults');
  return array_intersect_key($saved,self::DEFAULTS);
 }
 public function preferences(): array {
  $p=$this->accountDefaults();
  return ['cast'=>['gCastEnabled'=>$p['cast']],'people'=>['enabled'=>$p['people'],'sidebarWeb'=>$p['people']],'memories'=>['enabled'=>$p['memories']]];
 }
 private static function parse(array $input,array $keys): array {
  if(array_diff(array_keys($input),$keys))throw new \DomainException('Opțiuni Immich necunoscute. Reîncarcă formularul.');
  $flags=[];
  foreach($keys as $key){
   if(!in_array($input[$key]??null,['0','1'],true))throw new \DomainException('Completează toate opțiunile Immich cu valori valide.');
   $flags[$key]=$input[$key]==='1';
  }
  return $flags;
 }
 private function audit(string $admin,string $action,array $flags): void {
  $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,$action,json_encode($flags,JSON_THROW_ON_ERROR),time()]);
 }
 public function saveAccountDefaults(array $input,string $admin): void {
  $flags=self::parse($input,array_keys(self::DEFAULTS));
  $this->db->transaction(function()use($flags,$admin){
   $this->db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[self::KEY,json_encode($flags,JSON_THROW_ON_ERROR)]);
   $this->audit($admin,'immich_account_defaults',$flags);
  });
 }
 private static function flags(array $config): array {
  $flags=[];
  foreach(self::SERVER as $name=>$path){
   $value=$config;foreach($path as $part)$value=$value[$part]??null;
   if(!is_bool($value))throw new \RuntimeException('immich_configuration_unsupported');
   $flags[$name]=$value;
  }
  return $flags;
 }
 private static function canonical(array $data): string {
  $sort=static function(array $a)use(&$sort):array{if(!array_is_list($a))ksort($a);foreach($a as &$v)if(is_array($v))$v=$sort($v);return $a;};
  return json_encode($sort($data),JSON_THROW_ON_ERROR);
 }
 private function revision(array $config): string {
  return hash_hmac('sha256',self::canonical($config).'|'.self::canonical((new IntegrationSettings($this->db))->immich()),env('APP_KEY'));
 }
 public function server(): array {
  $config=$this->storage->configuration();
  return ['flags'=>self::flags($config),'revision'=>$this->revision($config)];
 }
 public function saveServer(array $input,string $revision,string $admin): void {
  $flags=self::parse($input,array_keys(self::SERVER));
  if(!preg_match('/^[a-f0-9]{64}$/D',$revision))throw new \DomainException('Reîncarcă configurația Immich înainte de salvare.');
  $lock=fopen(dirname(__DIR__).'/storage/immich-configuration.lock','c');
  if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new \DomainException('O salvare Immich rulează deja. Reîncearcă.');}
  try{
   $before=$this->storage->configuration();self::flags($before);
   if(!hash_equals($this->revision($before),$revision))throw new \DomainException('Configurația sau conexiunea Immich s-a schimbat. Reîncarcă pagina și verifică opțiunile.');
   $desired=$before;
   foreach(self::SERVER as $name=>$path){$target=&$desired;foreach($path as $part)$target=&$target[$part];$target=$flags[$name];unset($target);}
   // Immich 3.3.3 replaces the full config; keep secrets in memory and preserve unrelated fields.
   $latest=$this->storage->configuration();
   if(self::canonical($latest)!==self::canonical($before) || !hash_equals($this->revision($latest),$revision))throw new \DomainException('Configurația Immich a fost modificată în paralel. Reîncarcă pagina.');
   if(self::canonical($desired)===self::canonical($before))return;
   try{
    $this->storage->updateConfiguration($desired);
    if(self::canonical($this->storage->configuration())!==self::canonical($desired))throw new \RuntimeException('immich_configuration_not_confirmed');
    $this->audit($admin,'immich_server_configuration',$flags);
   }catch(\Throwable $e){
    $this->audit($admin,'immich_server_unconfirmed',['result'=>'Verifică starea curentă și jurnalul API; nu se presupune că setările au rămas neschimbate.']);
    if($e instanceof StorageApiException)throw $e;
    throw new \RuntimeException('immich_configuration_not_confirmed',0,$e);
   }
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function error(\Throwable $e): string {
  if($e instanceof StorageApiException && in_array($e->httpStatus,[401,403],true))return 'Acces refuzat de Immich. Verifică cheia de administrator și permisiunile systemConfig.read / systemConfig.update.';
  return 'Configurația Immich nu a putut fi confirmată. Reîncarcă starea curentă și verifică jurnalul API. Serverele cu IMMICH_CONFIG_FILE pot fi doar citite prin această pagină.';
 }
}
