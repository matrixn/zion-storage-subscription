<?php
declare(strict_types=1);
namespace Zion;

/** Provider checks are explicit POST/worker actions, never side effects of rendering. */
final class StripeIntegration {
 private \Stripe\StripeClient $client;
 private array $settings;
 private bool $transportAllowed;
 public function __construct(private Database $db,private Plans $plans,?\Stripe\StripeClient $client=null,?string $mode=null){
  $this->settings=(new IntegrationSettings($db))->stripe($mode);
  $this->transportAllowed=$client!==null || env('APP_ENV')!=='test';
  $this->client=$client??new \Stripe\StripeClient(['api_key'=>$this->settings['secret_key']?:'sk_test_unconfigured','max_network_retries'=>1]);
 }
 private static function fingerprint(array $settings,bool $webhook=false): string {return hash('sha256',$settings['mode'].'|'.$settings['secret_key'].($webhook?'|'.$settings['webhook_secret']:''));}
 private function manifest(): string {
  return hash('sha256',json_encode(array_map(static fn($p)=>[$p['slug'],$p['name'],$p['monthly_price'],$p['yearly_price'],$p['currency']],$this->plans->all('month',$this->settings['mode'])),JSON_THROW_ON_ERROR));
 }
 private function store(string $key,array $value): void {
  $this->db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['stripe:'.$key.':'.$this->settings['mode'],json_encode($value,JSON_THROW_ON_ERROR)]);
 }
 private function read(string $key): ?array {
  $row=$this->db->one('SELECT value FROM integration_settings WHERE key=?',['stripe:'.$key.':'.$this->settings['mode']]);
  return $row?json_decode($row['value'],true,512,JSON_THROW_ON_ERROR):null;
 }
 private function guard(): void {
  if(!$this->settings['secret_key'])throw new \DomainException('Configurează cheia API Stripe.');
  if(!preg_match('/^(sk|rk)_'.$this->settings['mode'].'_/',$this->settings['secret_key']))throw new \DomainException('Cheia API nu corespunde modului Stripe selectat.');
  if(!$this->transportAllowed)throw new \DomainException('Conexiunile externe sunt blocate în mediul de teste izolate.');
  if($this->client->getApiKey()!==(new IntegrationSettings($this->db))->stripe($this->settings['mode'])['secret_key'])throw new \DomainException('Configurația Stripe s-a schimbat. Reia verificarea.');
 }
 public static function safeError(\Throwable $e): string {
  return match(true){
   $e instanceof \Stripe\Exception\AuthenticationException=>'Stripe a respins cheia API. Verifică cheia salvată.',
   $e instanceof \Stripe\Exception\PermissionException=>'Cheia Stripe nu are permisiunile necesare pentru catalog.',
   $e instanceof \Stripe\Exception\RateLimitException=>'Limita Stripe a fost atinsă. Reîncearcă mai târziu.',
   $e instanceof \Stripe\Exception\ApiConnectionException=>'Stripe nu poate fi contactat momentan. Reîncearcă.',
   $e instanceof \DomainException=>$e->getMessage(),
   default=>'Operațiunea Stripe nu s-a finalizat. Reia verificarea sau sincronizarea.',
  };
 }
 public function check(): array {
  $state=['status'=>'failed','checked_at'=>time(),'fingerprint'=>self::fingerprint($this->settings)];
  try{
   $this->guard();
   foreach([$this->client->products->all(['limit'=>1]),$this->client->prices->all(['limit'=>1])] as $list)foreach($list->data as $item)if($item->livemode!==($this->settings['mode']==='live'))throw new \DomainException('Răspuns Stripe incompatibil cu modul selectat.');
   $state['status']='ok';$state['message']='Autentificare reușită. Citirea produselor și prețurilor este permisă.';
  }catch(\Throwable $e){$state['message']=self::safeError($e);}
  $this->store('connection',$state);return $state;
 }
 public function synchronize(string $admin): array {
  $lock=fopen(dirname(__DIR__).'/storage/stripe-sync.lock','c');
  if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['status'=>'busy','message'=>'O sincronizare este deja în curs. Reîncarcă pagina.','rows'=>[]];}
  try{
   $connection=$this->check();
   $state=['status'=>'failed','checked_at'=>time(),'fingerprint'=>self::fingerprint($this->settings),'manifest'=>$this->manifest(),'rows'=>[]];
   if($connection['status']!=='ok')$state['message']=$connection['message'];
   else{
    $catalog=new BillingCatalog($this->db,$this->plans,$this->client,$this->settings['mode']);$failed=0;$paused=0;
    foreach($this->plans->all() as $p){
     if($p['slug']==='free')continue;
     foreach(['month','year'] as $interval){
      $row=['plan'=>$p['slug'],'interval'=>$interval];
      try{$this->guard();$row['status']=$catalog->synchronize($p['slug'],$interval,$admin);if($row['status']==='paused')$paused++;}
      catch(\Throwable $e){$row['status']='failed';$row['message']=self::safeError($e);$failed++;}
      $state['rows'][]=$row;
     }
    }
    $state['status']=$failed?'partial':($paused?'paused':'ok');
    $state['message']=$failed?'Unele prețuri nu au fost sincronizate. Reia sincronizarea; pașii confirmați sunt păstrați.':($paused?'Catalog verificat; opțiunile oprite manual rămân suspendate.':'Catalog sincronizat: cele 6 opțiuni lunare/anuale au fost validate.');
   }
   $state['checked_at']=time();$this->store('sync',$state);
   $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'stripe_sync',$this->settings['mode'].' catalog synchronization: '.$state['status'],time()]);
   return $state;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function state(): array {
  $connection=$this->read('connection');$sync=$this->read('sync');$webhook=$this->read('webhook');
  if(($connection['fingerprint']??'')!==self::fingerprint($this->settings))$connection=null;
  if(($sync['fingerprint']??'')!==self::fingerprint($this->settings) || ($sync['manifest']??'')!==$this->manifest())$sync=null;
  if(($webhook['fingerprint']??'')!==self::fingerprint($this->settings,true))$webhook=null;
  return ['connection'=>$connection,'sync'=>$sync,'webhook'=>$webhook];
 }
 public static function received(Database $db,array $settings,string $id,string $type): void {
  $value=['fingerprint'=>self::fingerprint($settings,true),'received_at'=>time(),'event_id'=>$id,'event_type'=>$type];
  $db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['stripe:webhook:'.$settings['mode'],json_encode($value,JSON_THROW_ON_ERROR)]);
 }
}
