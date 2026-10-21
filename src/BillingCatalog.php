<?php
declare(strict_types=1);
namespace Zion;
/** Operator-controlled mappings. Historical Prices remain immutable and recognizable. */
final class BillingCatalog {
 private string $mode;
 public function __construct(private Database $db,private Plans $plans,private \Stripe\StripeClient $stripe,?string $mode=null) {$this->mode=(new IntegrationSettings($db))->stripe($mode)['mode'];}
 public static function validatePrice(array $price,array $plan): void {
  if(!isset($price['livemode']) || $price['livemode']!==($plan['stripe_mode']==='live') || !($price['active']??false) || ($price['currency']??'')!==$plan['currency'] || ($price['unit_amount']??null)!==$plan['price_amount'] || ($price['recurring']['interval']??'')!==$plan['billing_interval'] || ($price['recurring']['interval_count']??1)!==1 || ($price['billing_scheme']??'per_unit')!=='per_unit' || ($price['tax_behavior']??'unspecified')==='inclusive')throw new \DomainException('Price ID-ul nu corespunde sumei RON, intervalului și modului Stripe din catalog.');
 }
 private function audit(string $admin,string $detail): void { $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'billing_catalog',$detail,time()]); }
 public function save(string $slug,string $interval,string $priceId,string $admin): void {
  if(!(new IntegrationSettings($this->db))->stripe($this->mode)['secret_key'])throw new \DomainException('Configurează cheia Stripe pentru modul selectat în administrare înainte de a valida un ID.');
  $p=$this->plans->get($slug,$interval,$this->mode);
  if(!$p || $slug==='free' || !preg_match('/^price_[a-zA-Z0-9_]+$/D',$priceId))throw new \DomainException('Plan, interval sau Price ID invalid.');
  $price=$this->stripe->prices->retrieve($priceId)->toArray();self::validatePrice($price,$p);
  $this->assertCurrentKey();
  $this->persist($price,$p,$admin);
 }
 private function persist(array $price,array $p,string $admin): void {
  $this->db->transaction(function()use($price,$p,$admin){
   $existing=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$price['id']]);
   if($existing && ($existing['stripe_mode']!==$this->mode || $existing['plan']!==$p['slug'] || $existing['currency']!==$p['currency'] || $existing['interval']!==$p['billing_interval'] || (($existing['storage_bytes']!==null && $existing['storage_bytes']!==$p['storage_bytes']) || ($existing['amount']!==null && $existing['amount']!==$p['price_amount']))))throw new \DomainException('Acest Price ID are deja o asociere istorică diferită.');
   $this->db->run("UPDATE billing_prices SET status='legacy',updated_at=? WHERE plan=? AND interval=? AND stripe_mode=? AND status='active'",[time(),$p['slug'],$p['billing_interval'],$this->mode]);
   $product=is_array($price['product'])?$price['product']['id']:$price['product'];
   $this->db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,product_id,status,created_at,updated_at,stripe_mode) VALUES(?,?,?,?,?,?,'active',?,?,?) ON CONFLICT(price_id) DO UPDATE SET status='active',amount=excluded.amount,product_id=excluded.product_id,updated_at=excluded.updated_at",[$price['id'],$p['slug'],$p['billing_interval'],$p['currency'],$p['price_amount'],$product,time(),time(),$this->mode]);
   $this->db->run('UPDATE billing_prices SET eur_amount=?,fx_snapshot=?,storage_bytes=COALESCE(storage_bytes,?) WHERE price_id=?',[$p['eur_amount'],json_encode($p['fx'],JSON_THROW_ON_ERROR),$p['storage_bytes'],$price['id']]);
   $this->audit($admin,$this->mode.' / '.$p['slug'].' / '.$p['billing_interval'].' / '.$price['id'].' / '.$p['price_amount'].' RON minor units');
   $this->db->run('DELETE FROM integration_settings WHERE key=?',['catalog_disabled:'.$this->mode.':'.$p['slug'].':'.$p['billing_interval']]);
   $this->db->run('DELETE FROM integration_settings WHERE key=?',['stripe:sync:'.$this->mode]);
  });
 }
 public function disable(string $slug,string $interval,string $admin): void {
  if(!$this->plans->get($slug,$interval,$this->mode) || $slug==='free')throw new \DomainException('Plan invalid.');
  $this->db->transaction(function()use($slug,$interval,$admin){
   $this->db->run("UPDATE billing_prices SET status='legacy',updated_at=? WHERE plan=? AND interval=? AND stripe_mode=? AND status='active'",[time(),$slug,$interval,$this->mode]);
   $this->audit($admin,'disabled new purchases: '.$slug.' / '.$interval);
   $this->db->run("INSERT INTO integration_settings(key,value) VALUES(?,'1') ON CONFLICT(key) DO UPDATE SET value='1'",['catalog_disabled:'.$this->mode.':'.$slug.':'.$interval]);
   $this->db->run('DELETE FROM integration_settings WHERE key=?',['stripe:sync:'.$this->mode]);
  });
 }
 public function legacy(string $slug,string $id,string $admin): void {
  if(!(new IntegrationSettings($this->db))->stripe($this->mode)['secret_key'])throw new \DomainException('Configurează cheia Stripe pentru modul selectat în administrare înainte de a valida un ID.');
  if(!$this->plans->get($slug,'month',$this->mode) || $slug==='free' || !preg_match('/^price_[a-zA-Z0-9_]+$/D',$id))throw new \DomainException('Plan sau Price ID invalid.');
  $p=$this->stripe->prices->retrieve($id)->toArray();$interval=$p['recurring']['interval']??'';
  if(!isset($p['livemode']) || $p['livemode']!==($this->mode==='live') || !in_array($interval,['month','year'],true) || ($p['recurring']['interval_count']??1)!==1 || !in_array($p['currency']??'',['ron','eur'],true) || !is_int($p['unit_amount']??null) || $p['unit_amount']<=0)throw new \DomainException('Price istoric incompatibil.');
  $this->db->transaction(function()use($slug,$id,$admin,$p,$interval){
   $old=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$id]);
   if($old && ($old['stripe_mode']!==$this->mode || $old['plan']!==$slug || $old['interval']!==$interval || $old['currency']!==$p['currency']))throw new \DomainException('Asociere istorică diferită.');
   $this->db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,product_id,status,created_at,updated_at,stripe_mode) VALUES(?,?,?,?,?,?,'legacy',?,?,?) ON CONFLICT(price_id) DO UPDATE SET amount=excluded.amount,updated_at=excluded.updated_at",[$id,$slug,$interval,$p['currency'],$p['unit_amount'],is_array($p['product'])?$p['product']['id']:$p['product'],time(),time(),$this->mode]);
   $this->audit($admin,'legacy '.$slug.' / '.$id);
  });
 }
 public function create(string $slug,string $interval,string $admin,bool $replace=false): void {
  if(!(new IntegrationSettings($this->db))->stripe($this->mode)['secret_key'])throw new \DomainException('Configurează cheia Stripe pentru modul selectat în administrare înainte de a crea produse.');
  $p=$this->plans->get($slug,$interval,$this->mode);if(!$p || $slug==='free')throw new \DomainException('Plan invalid.');
  if(!$p['fx'] || $p['price_amount']<=0)throw new \DomainException('Actualizează cursul BNR înaintea sincronizării catalogului RON.');
  if($p['stripe_price_id'] && !$replace)throw new \DomainException('Există deja un Price activ pentru această opțiune.');
  $lock=fopen(dirname(__DIR__).'/storage/catalog.lock','c');flock($lock,LOCK_EX);
  try{
   $this->assertCurrentKey();
   if($replace && $this->db->one('SELECT key FROM integration_settings WHERE key=?',['catalog_disabled:'.$this->mode.':'.$slug.':'.$interval]))throw new \DomainException('Opțiunea a fost oprită manual în timpul sincronizării.');
   if($this->plans->get($slug,$interval,$this->mode)['stripe_price_id'] && !$replace)throw new \DomainException('Există deja un Price activ pentru această opțiune.');
    // Stable Stripe Product IDs also survive a lost response or local restore.
    $id='zs_'.$p['currency'].'_'.$slug;
    foreach(require dirname(__DIR__).'/config/plans.php' as $default)if($default['slug']===$slug && $default['storage_bytes']!==$p['storage_bytes'])$id.='_b'.$p['storage_bytes'];
    try{$product=$this->stripe->products->retrieve($id);}
    catch(\Stripe\Exception\InvalidRequestException $e){if($e->getStripeCode()!=='resource_missing')throw $e;$product=$this->stripe->products->create(['id'=>$id,'name'=>'Zion Storage '.$p['name'].' '.$p['capacity'],'metadata'=>['zion_plan'=>$slug,'zion_storage_bytes'=>(string)$p['storage_bytes']]],['idempotency_key'=>'zion-product-'.$this->mode.'-'.$id]);}
    if($product->livemode!==($this->mode==='live') || ($product->metadata->zion_plan??'')!==$slug || !$product->active)throw new \DomainException('Produs Stripe incompatibil.');
    if($product->name!=='Zion Storage '.$p['name'].' '.$p['capacity'])$this->stripe->products->update($id,['name'=>'Zion Storage '.$p['name'].' '.$p['capacity']]);
    $this->db->run('INSERT INTO stripe_catalog_products(plan,product_id,updated_at,stripe_mode) VALUES(?,?,?,?) ON CONFLICT(plan,stripe_mode) DO UPDATE SET product_id=excluded.product_id,updated_at=excluded.updated_at',[$slug,$product->id,time(),$this->mode]);$row=['product_id'=>$product->id];
   $lookup='zs_'.$p['currency'].'_'.$slug.'_'.$interval.'_'.$p['price_amount'].'_eur'.$p['eur_amount'].'_b'.$p['storage_bytes'];
   $found=$this->stripe->prices->all(['lookup_keys'=>[$lookup],'limit'=>2])->data;
   $price=$found[0]??$this->stripe->prices->create(['product'=>$row['product_id'],'currency'=>$p['currency'],'unit_amount'=>$p['price_amount'],'tax_behavior'=>'exclusive','recurring'=>['interval'=>$interval],'lookup_key'=>$lookup,'metadata'=>['zion_plan'=>$slug]],['idempotency_key'=>'zion-price-'.$this->mode.'-'.$lookup]);
   $array=$price->toArray();
   if(!$array['active'] && ($array['metadata']['zion_plan']??'')===$slug){
    self::validatePrice(array_replace($array,['active'=>true]),$p);
    $array=$this->stripe->prices->update($array['id'],['active'=>true])->toArray();
   }
   self::validatePrice($array,$p);
   if($array['product']!==$row['product_id'])throw new \DomainException('Prețul Stripe aparține altui produs.');
   $this->assertCurrentKey();$this->persist($array,$p,$admin);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function assertCurrentKey(): void {
  if($this->stripe->getApiKey()!==(new IntegrationSettings($this->db))->stripe($this->mode)['secret_key'])throw new \DomainException('Configurația Stripe s-a schimbat. Reia sincronizarea.');
 }
 public function synchronize(string $slug,string $interval,string $admin): string {
  if($this->db->one('SELECT key FROM integration_settings WHERE key=?',['catalog_disabled:'.$this->mode.':'.$slug.':'.$interval]))return 'paused';
  $this->assertCurrentKey();$p=$this->plans->get($slug,$interval,$this->mode);
  if(!$p || $slug==='free')throw new \DomainException('Plan invalid.');
  if($p['stripe_price_id']){
   try{
    $mapping=$this->db->one('SELECT storage_bytes FROM billing_prices WHERE price_id=?',[$p['stripe_price_id']]);
    if($mapping['storage_bytes']!==null && $mapping['storage_bytes']!==$p['storage_bytes'])throw new \DomainException('Capacitatea necesită o versiune nouă de preț.');
    $price=$this->stripe->prices->retrieve($p['stripe_price_id'])->toArray();self::validatePrice($price,$p);
    $product=$this->stripe->products->retrieve(is_array($price['product'])?$price['product']['id']:$price['product']);
    if($product->livemode!==($this->mode==='live') || !$product->active)throw new \DomainException('Produs Stripe inactiv sau incompatibil.');
    $this->assertCurrentKey();
    $name='Zion Storage '.$p['name'].' '.$p['capacity'];
    if($product->name!==$name)$this->stripe->products->update($product->id,['name'=>$name]);
    return 'verified';
   }catch(\Stripe\Exception\InvalidRequestException $e){if($e->getStripeCode()!=='resource_missing')throw $e;}
   catch(\DomainException $e){/* Replace stale mappings, retaining their historical identity. */}
  }
  $this->create($slug,$interval,$admin,true);return 'synchronized';
 }
 public function rows(): array { return $this->db->run('SELECT * FROM billing_prices WHERE stripe_mode=? ORDER BY plan,interval,created_at DESC',[$this->mode])->fetchAll(); }
}
