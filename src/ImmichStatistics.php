<?php
namespace Zion;
/** Only aggregate counts/bytes for already-linked clients are persisted. */
final class ImmichStatistics {
 public function __construct(private Database $db,private ?StorageProvisioningService $storage=null){$this->storage??=new StorageProvisioningService($db);}
 public function fingerprint(): string {return hash('sha256',json_encode((new IntegrationSettings($this->db))->immich(),JSON_THROW_ON_ERROR));}
 private function config(): array{return (new IntegrationSettings($this->db))->immich();}
 public function state(): array {
  $r=$this->db->one("SELECT value FROM integration_settings WHERE key='immich:statistics:state'");
  $s=$r?json_decode($r['value'],true):[];
  return ($s['config_hash']??'')===$this->fingerprint()?$s:[];
 }
 private function persist(array $state): void {
  $this->db->run("INSERT INTO integration_settings(key,value) VALUES('immich:statistics:state',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[json_encode($state,JSON_THROW_ON_ERROR)]);
 }
 private static function count(mixed $value): int {
  if(!is_int($value) || $value<0 || $value>9007199254740991)throw new \UnexpectedValueException('immich_invalid_statistics');return $value;
 }
 private static function validate(array $data): array {
  foreach(['usage','photos','videos'] as $key)$data[$key]=self::count($data[$key]??null);
  if(!is_array($data['usageByUser']??null) || !array_is_list($data['usageByUser']))throw new \UnexpectedValueException('immich_invalid_statistics');
  $users=[];
  foreach($data['usageByUser'] as $u){
   if(!is_array($u) || !is_string($u['userId']??null) || $u['userId']==='' || strlen($u['userId'])>128 || isset($users[$u['userId']]))throw new \UnexpectedValueException('immich_invalid_statistics');
   foreach(['usage','photos','videos'] as $key)$u[$key]=self::count($u[$key]??null);
   $u['quotaSizeInBytes']=isset($u['quotaSizeInBytes'])?self::count($u['quotaSizeInBytes']):null;
   $users[$u['userId']]=$u;
  }
  return [$data,$users];
 }
 public function check(): array {
  $hash=$this->fingerprint();$state=$this->state();$started=microtime(true);
  try{
   $config=$this->config();if(!$config['url'] || !$config['api_key'])throw new \RuntimeException('storage_unconfigured');
   [$data]=self::validate($this->storage->statistics());
   if($hash!==$this->fingerprint())throw new \RuntimeException('immich_configuration_changed');
   $state['connection']=['status'=>'ok','checked_at'=>time()];$state['config_hash']=$hash;$this->persist($state);
   return ['status'=>'ok','message'=>'Conexiunea Immich funcționează. Cheia poate citi statisticile serverului.'];
  }catch(\Throwable $e){
   $this->failure($e,$hash,$state,$started,false);
   return ['status'=>'error','message'=>'Conexiunea Immich nu a fost confirmată. Verifică URL-ul, permisiunea server.statistics și jurnalul API.'];
  }
 }
 private function failure(\Throwable $e,string $hash,array $state,float $started,bool $sync): void {
  if($hash!==$this->fingerprint())return;
  $code=$e instanceof StorageApiException?'http_'.$e->httpStatus:(in_array($e->getMessage(),['storage_unconfigured','storage_requires_tls','immich_invalid_statistics','immich_configuration_changed'],true)?$e->getMessage():'immich_statistics_failed');
  $state['config_hash']=$hash;
  if($sync){$state['last_attempt_at']=(int)$started;$state['status']='error';}else $state['connection']=['status'=>'error','checked_at'=>time()];
  $this->persist($state);
  ApiLog::record('immich','GET','/server/statistics',503,$started,['code'=>$code,'message'=>'Statisticile Immich nu pot fi actualizate. Verifică răspunsul API asociat; ultimele valori confirmate sunt păstrate.','context'=>['stage'=>'statistics']]);
 }
 public function synchronize(bool $due=false): array {
  $lock=fopen(dirname(__DIR__).'/storage/immich-statistics.lock','c');
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['status'=>'busy','message'=>'O sincronizare Immich rulează deja.'];}
  try{
   $state=$this->state();$hash=$this->fingerprint();$config=$this->config();
   if(!$config['url'] || !$config['api_key'])return ['status'=>'unconfigured','message'=>'Configurează URL-ul și cheia API Immich.'];
   if($due && intdiv($state['last_attempt_at']??0,300)>=intdiv(time(),300))return ['status'=>'skipped','message'=>'Nu au trecut încă 5 minute de la ultima încercare.'];
   $started=microtime(true);$this->persist($state+['config_hash'=>$hash]);
   try{
    [$data,$remote]=self::validate($this->storage->statistics());$now=time();
    if($hash!==$this->fingerprint())throw new \RuntimeException('immich_configuration_changed');
    $matched=$this->db->transaction(function()use($remote,$hash,$now){
     $matched=0;
     // Read associations inside the write transaction: a concurrent reset cannot be resurrected.
     foreach($this->db->run('SELECT id,storage_user_id FROM users WHERE storage_user_id IS NOT NULL')->fetchAll() as $u){
      $row=$remote[$u['storage_user_id']]??null;
      if(!$row){$this->db->run('UPDATE immich_statistics SET available=0 WHERE user_id=?',[$u['id']]);continue;}
      $this->db->run('INSERT INTO immich_statistics(user_id,storage_user_id,used_bytes,photos,videos,remote_quota_bytes,synced_at,config_hash,available) VALUES(?,?,?,?,?,?,?,?,1) ON CONFLICT(user_id) DO UPDATE SET storage_user_id=excluded.storage_user_id,used_bytes=excluded.used_bytes,photos=excluded.photos,videos=excluded.videos,remote_quota_bytes=excluded.remote_quota_bytes,synced_at=excluded.synced_at,config_hash=excluded.config_hash,available=1',[$u['id'],$u['storage_user_id'],$row['usage'],$row['photos'],$row['videos'],$row['quotaSizeInBytes'],$now,$hash]);$matched++;
     }
     return $matched;
    });
    $this->persist(['config_hash'=>$hash,'status'=>'ok','last_attempt_at'=>(int)$started,'last_success_at'=>$now,'matched'=>$matched,'server_used_bytes'=>$data['usage'],'server_photos'=>$data['photos'],'server_videos'=>$data['videos'],'connection'=>['status'=>'ok','checked_at'=>$now]]);
    return ['status'=>'ok','message'=>'Statisticile Immich au fost sincronizate pentru '.$matched.' clienți.','matched'=>$matched];
   }catch(\Throwable $e){$this->failure($e,$hash,$state,$started,true);return ['status'=>'error','message'=>'Sincronizarea Immich nu s-a finalizat. Ultimele valori confirmate sunt păstrate; vezi jurnalul API.'];}
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function overview(): array {
  $hash=$this->fingerprint();$state=$this->state();
  $totals=$this->db->one('SELECT COUNT(*) clients,COALESCE(SUM(s.used_bytes),0) used_bytes,COALESCE(SUM(s.photos),0) photos,COALESCE(SUM(s.videos),0) videos FROM immich_statistics s JOIN users u ON u.id=s.user_id AND u.storage_user_id=s.storage_user_id WHERE s.config_hash=? AND s.available=1',[$hash]);
  $linked=(int)$this->db->one('SELECT COUNT(*) n FROM users WHERE storage_user_id IS NOT NULL')['n'];
  $top=$this->db->run('SELECT u.id,u.name,u.email,u.selected_plan_id,s.* FROM immich_statistics s JOIN users u ON u.id=s.user_id AND u.storage_user_id=s.storage_user_id WHERE s.config_hash=? AND s.available=1 ORDER BY s.used_bytes DESC,u.id LIMIT 10',[$hash])->fetchAll();
  $plans=new Plans($this->db);
  foreach($top as &$u){$plan=$plans->get($u['selected_plan_id']);$u['plan_quota_bytes']=$plan['storage_bytes']??0;$u['percent']=$u['plan_quota_bytes']?round($u['used_bytes']/$u['plan_quota_bytes']*100,1):0;}unset($u);
  return ['state'=>$state,'totals'=>$totals,'linked'=>$linked,'top'=>$top,'stale'=>!isset($state['last_success_at']) || $state['last_success_at']<time()-600 || ($state['status']??'')!=='ok'];
 }
 public static function gb(int $bytes): string {return number_format($bytes/1_000_000_000,2,',',' ').' GB';}
}
