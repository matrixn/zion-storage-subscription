<?php
namespace Zion;
final class Plans {
 public function __construct(private ?Database $db=null) { $this->db??=new Database(); }
 public function all(string $interval='month',?string $mode=null): array {
  if(!in_array($interval,['month','year'],true))throw new \InvalidArgumentException('invalid_billing_interval');
  $plans=require dirname(__DIR__).'/config/plans.php';
  $mode=(new IntegrationSettings($this->db))->stripe($mode)['mode'];
  $taxBps=(new BillingTax($this->db))->basisPoints();
  $vat=(new IntegrationSettings($this->db))->oblio()['vat_rate'];
  foreach($plans as &$p){
   $p['stripe_mode']=$mode;
   $p['invoice_vat_rate']=$vat;
   $p['tax_bps']=$taxBps;
   $p['billing_interval']=$interval;$p['price_amount']=$interval==='year'?$p['yearly_price']:$p['monthly_price'];
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
  return array_replace($p,['stripe_price_id'=>$id,'price_amount'=>$r['amount']??$p['price_amount'],'currency'=>$r['currency'],'billing_interval'=>$r['interval'],'legacy'=>$r['status']==='legacy']);
 }
}
