<?php
declare(strict_types=1);
namespace Zion;
/** Operator-controlled mappings. Historical Prices remain immutable and recognizable. */
final class BillingCatalog {
 public function __construct(private Database $db,private Plans $plans,private \Stripe\StripeClient $stripe) {}
 public static function validatePrice(array $price,array $plan): void {
  if(($price['livemode']??true)!==false || !($price['active']??false) || ($price['currency']??'')!==$plan['currency'] || ($price['unit_amount']??null)!==$plan['price_amount'] || ($price['recurring']['interval']??'')!==$plan['billing_interval'] || ($price['recurring']['interval_count']??1)!==1 || ($price['billing_scheme']??'per_unit')!=='per_unit')throw new \DomainException('Price ID-ul nu corespunde sumei EUR, intervalului și modului TEST din catalog.');
 }
 private function audit(string $admin,string $detail): void { $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'billing_catalog',$detail,time()]); }
 public function save(string $slug,string $interval,string $priceId,string $admin): void {
  if(!env('STRIPE_SECRET_KEY'))throw new \DomainException('Configurează cheia Stripe TEST pe server înainte de a valida un ID.');
  $p=$this->plans->get($slug,$interval);
  if(!$p || $slug==='free' || !preg_match('/^price_[a-zA-Z0-9_]+$/D',$priceId))throw new \DomainException('Plan, interval sau Price ID invalid.');
  $price=$this->stripe->prices->retrieve($priceId)->toArray();self::validatePrice($price,$p);
  $this->persist($price,$p,$admin);
 }
 private function persist(array $price,array $p,string $admin): void {
  $this->db->transaction(function()use($price,$p,$admin){
   $existing=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$price['id']]);
   if($existing && ($existing['plan']!==$p['slug'] || $existing['currency']!==$p['currency'] || $existing['interval']!==$p['billing_interval'] || ($existing['amount']!==null && $existing['amount']!==$p['price_amount'])))throw new \DomainException('Acest Price ID are deja o asociere istorică diferită.');
   $this->db->run("UPDATE billing_prices SET status='legacy',updated_at=? WHERE plan=? AND interval=? AND status='active'",[time(),$p['slug'],$p['billing_interval']]);
   $product=is_array($price['product'])?$price['product']['id']:$price['product'];
   $this->db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,product_id,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'active',?,?) ON CONFLICT(price_id) DO UPDATE SET status='active',amount=excluded.amount,product_id=excluded.product_id,updated_at=excluded.updated_at",[$price['id'],$p['slug'],$p['billing_interval'],$p['currency'],$p['price_amount'],$product,time(),time()]);
   $this->audit($admin,$p['slug'].' / '.$p['billing_interval'].' / '.$price['id'].' / '.$p['price_amount'].' EUR minor units');
  });
 }
 public function disable(string $slug,string $interval,string $admin): void {
  if(!$this->plans->get($slug,$interval) || $slug==='free')throw new \DomainException('Plan invalid.');
  $this->db->transaction(function()use($slug,$interval,$admin){
   $this->db->run("UPDATE billing_prices SET status='legacy',updated_at=? WHERE plan=? AND interval=? AND status='active'",[time(),$slug,$interval]);
   $this->audit($admin,'disabled new purchases: '.$slug.' / '.$interval);
  });
 }
 public function legacy(string $slug,string $id,string $admin): void {
  if(!env('STRIPE_SECRET_KEY'))throw new \DomainException('Configurează cheia Stripe TEST pe server înainte de a valida un ID.');
  if(!$this->plans->get($slug) || $slug==='free' || !preg_match('/^price_[a-zA-Z0-9_]+$/D',$id))throw new \DomainException('Plan sau Price ID invalid.');
  $p=$this->stripe->prices->retrieve($id)->toArray();$interval=$p['recurring']['interval']??'';
  if(($p['livemode']??true) || !in_array($interval,['month','year'],true) || ($p['recurring']['interval_count']??1)!==1 || !in_array($p['currency']??'',['ron','eur'],true) || !is_int($p['unit_amount']??null) || $p['unit_amount']<=0)throw new \DomainException('Price istoric incompatibil.');
  $this->db->transaction(function()use($slug,$id,$admin,$p,$interval){
   $old=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$id]);
   if($old && ($old['plan']!==$slug || $old['interval']!==$interval || $old['currency']!==$p['currency']))throw new \DomainException('Asociere istorică diferită.');
   $this->db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,product_id,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'legacy',?,?) ON CONFLICT(price_id) DO UPDATE SET amount=excluded.amount,updated_at=excluded.updated_at",[$id,$slug,$interval,$p['currency'],$p['unit_amount'],is_array($p['product'])?$p['product']['id']:$p['product'],time(),time()]);
   $this->audit($admin,'legacy '.$slug.' / '.$id);
  });
 }
 public function create(string $slug,string $interval,string $admin): void {
  if(!env('STRIPE_SECRET_KEY'))throw new \DomainException('Configurează cheia Stripe TEST pe server înainte de a crea produse.');
  $p=$this->plans->get($slug,$interval);if(!$p || $slug==='free')throw new \DomainException('Plan invalid.');
  if($p['stripe_price_id'])throw new \DomainException('Există deja un Price activ pentru această opțiune.');
  $lock=fopen(dirname(__DIR__).'/storage/catalog.lock','c');flock($lock,LOCK_EX);
  try{
   if($this->plans->get($slug,$interval)['stripe_price_id'])throw new \DomainException('Există deja un Price activ pentru această opțiune.');
   $row=$this->db->one('SELECT * FROM billing_products WHERE plan=?',[$slug]);
   if(!$row){
    // Stable Stripe Product IDs also survive a lost response or local restore.
    $id='zs_eur_'.$slug;
    try{$product=$this->stripe->products->retrieve($id);}
    catch(\Stripe\Exception\InvalidRequestException $e){if($e->getStripeCode()!=='resource_missing')throw $e;$product=$this->stripe->products->create(['id'=>$id,'name'=>'Zion Storage '.$p['name'].' '.$p['capacity'],'metadata'=>['zion_plan'=>$slug]],['idempotency_key'=>'zion-product-eur-'.$slug]);}
    if($product->livemode || ($product->metadata->zion_plan??'')!==$slug || !$product->active)throw new \DomainException('Produs Stripe incompatibil.');
    $this->db->run('INSERT INTO billing_products(plan,product_id,updated_at) VALUES(?,?,?) ON CONFLICT(plan) DO NOTHING',[$slug,$product->id,time()]);$row=['product_id'=>$product->id];
   }
   $lookup='zs_eur_'.$slug.'_'.$interval.'_'.$p['price_amount'];
   $found=$this->stripe->prices->all(['lookup_keys'=>[$lookup],'limit'=>2])->data;
   $price=$found[0]??$this->stripe->prices->create(['product'=>$row['product_id'],'currency'=>'eur','unit_amount'=>$p['price_amount'],'recurring'=>['interval'=>$interval],'lookup_key'=>$lookup,'metadata'=>['zion_plan'=>$slug]],['idempotency_key'=>'zion-price-'.$lookup]);
   $array=$price->toArray();self::validatePrice($array,$p);$this->persist($array,$p,$admin);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function rows(): array { return $this->db->run('SELECT * FROM billing_prices ORDER BY plan,interval,created_at DESC')->fetchAll(); }
}
