<?php
namespace Zion;
final class Plans {
 public function __construct(private ?Database $db=null) { $this->db??=new Database(); }
 public function all(string $interval='month'): array {
  if(!in_array($interval,['month','year'],true))throw new \InvalidArgumentException('invalid_billing_interval');
  $plans=require dirname(__DIR__).'/config/plans.php';
  $vat=(new IntegrationSettings($this->db))->oblio()['vat_rate'];
  foreach($plans as &$p){
   $p['invoice_vat_rate']=$vat;
   $p['billing_interval']=$interval;$p['price_amount']=$interval==='year'?$p['yearly_price']:$p['monthly_price'];
   if($interval==='year'){$p['invoice_name_ro']='Abonament anual Zion Storage '.$p['name'].' '.$p['capacity'];$p['invoice_product_code'].='_YEAR';}
   $row=$this->db->one("SELECT * FROM billing_prices WHERE plan=? AND interval=? AND status='active'",[$p['slug'],$interval]);
   $p['stripe_price_id']=$row['price_id']??'';
   $p['accepted_stripe_price_ids']=array_column($this->db->run('SELECT price_id FROM billing_prices WHERE plan=?',[$p['slug']])->fetchAll(),'price_id');
  }unset($p);return $plans;
 }
 public function get(string $slug,string $interval='month'): ?array { foreach($this->all($interval) as $p)if($p['slug']===$slug)return $p;return null; }
 public function byPrice(string $id): ?array {
  $r=$this->db->one('SELECT * FROM billing_prices WHERE price_id=?',[$id]);if(!$r)return null;
  $p=$this->get($r['plan'],$r['interval']);if(!$p)return null;
  return array_replace($p,['stripe_price_id'=>$id,'price_amount'=>$r['amount']??$p['price_amount'],'currency'=>$r['currency'],'billing_interval'=>$r['interval'],'legacy'=>$r['status']==='legacy']);
 }
}
