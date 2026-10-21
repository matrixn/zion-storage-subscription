<?php
namespace Zion;
final class Plans {
 public function __construct(private ?Database $db=null) { $this->db??=new Database(); }
 public function all(string $interval='month',?string $mode=null): array {
  if(!in_array($interval,['month','year'],true))throw new \InvalidArgumentException('invalid_billing_interval');
  $plans=(new PlanSettings($this->db))->all();
  $mode=(new IntegrationSettings($this->db))->stripe($mode)['mode'];
  $taxBps=(new BillingTax($this->db))->basisPoints();
  $vat=(new IntegrationSettings($this->db))->oblio()['vat_rate'];
  $fx=(new BnrRates($this->db))->current();
  foreach($plans as &$p){
   $p['stripe_mode']=$mode;
   $p['invoice_vat_rate']=$vat;
   $p['tax_bps']=$taxBps;
   $p['billing_interval']=$interval;$p['eur_amount']=$interval==='year'?$p['yearly_price']:$p['monthly_price'];
   $p['fx']=$fx;$p['ron_monthly']=$fx?BnrRates::convert($p['monthly_price'],$fx['eur_ron']):null;$p['ron_yearly']=$fx?BnrRates::convert($p['yearly_price'],$fx['eur_ron']):null;
   $p['price_amount']=$fx?BnrRates::convert($p['eur_amount'],$fx['eur_ron']):0;
   $p['fx_managed']=$p['slug']!=='free' && $fx!==null;
   $p['gross_amount']=BillingTax::total($p['price_amount'],$taxBps);
   if($interval==='year'){$p['invoice_name_ro']='Abonament anual Zion Storage '.$p['name'].' '.$p['capacity'];$p['invoice_product_code'].='_YEAR';}
   $row=$this->db->one("SELECT * FROM billing_prices WHERE plan=? AND interval=? AND stripe_mode=? AND status='active'",[$p['slug'],$interval,$mode]);
   $p['stripe_price_id']=$row['price_id']??'';
   $p['accepted_stripe_price_ids']=array_column($this->db->run('SELECT price_id FROM billing_prices WHERE plan=? AND stripe_mode=?',[$p['slug'],$mode])->fetchAll(),'price_id');
  }unset($p);return $plans;
 }
 public function get(string $slug,string $interval='month',?string $mode=null): ?array { foreach($this->all($interval,$mode) as $p)if($p['slug']===$slug)return $p;return null; }
 public function byPrice(string $id): ?array {
  $r=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$id]);if(!$r)return null;
  $p=$this->get($r['plan'],$r['interval'],$r['stripe_mode']);if(!$p)return null;
  $fx=$r['fx_snapshot']?json_decode($r['fx_snapshot'],true):null;
  $p=self::withCapacity($p,$r['storage_bytes']??$p['storage_bytes']);
  return array_replace($p,['fx'=>$fx,'eur_amount'=>$r['eur_amount']??($r['currency']==='eur'?$r['amount']:$p['eur_amount']),'fx_managed'=>$r['currency']==='ron' && $fx!==null,'stripe_price_id'=>$id,'gross_amount'=>BillingTax::total($r['amount']??$p['price_amount'],$p['tax_bps']),'price_amount'=>$r['amount']??$p['price_amount'],'currency'=>$r['currency'],'billing_interval'=>$r['interval'],'legacy'=>$r['status']==='legacy']);
 }
 public static function withCapacity(array $p,int $bytes): array {
  $p['storage_bytes']=$bytes;$p['capacity']=PlanSettings::capacity($bytes);
  $p['invoice_name_ro']=$p['slug']==='free'?'':($p['billing_interval']==='year'?'Abonament anual Zion Storage ':'Abonament Zion Storage ').$p['name'].' '.$p['capacity'];
  return $p;
 }
 public function forUser(array $u): ?array {
  $p=$this->get($u['selected_plan_id'],$u['billing_interval']??'month',$u['stripe_mode']??null);if(!$p)return null;
  if($u['selected_plan_id']!=='free' && !(new AccountControl($this->db))->manual($u['id'])){
   $s=$this->db->one('SELECT stripe_price_id FROM subscriptions WHERE user_id=?',[$u['id']]);
   if($s && ($historical=$this->byPrice($s['stripe_price_id']??'')))return $historical;
  }
  return self::withCapacity($p,$u['storage_quota_bytes']>0?$u['storage_quota_bytes']:$p['storage_bytes']);
 }

}
