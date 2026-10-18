<?php
namespace Zion;
/** A fixed operator-selected Romanian VAT rate, exclusive of catalog prices. */
final class BillingTax {
 public function __construct(private Database $db) {}
 public function basisPoints(): int {
  $row=$this->db->one("SELECT value FROM integration_settings WHERE key='billing:vat_bps'");
  return $row?(int)$row['value']:2100;
 }
 public static function parse(string $value): int {
  $value=str_replace(',','.',trim($value));
  if(!preg_match('/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D',$value))throw new \DomainException('Cota TVA trebuie să fie între 0 și 100, cu maximum două zecimale.');
  return (int)round((float)$value*100);
 }
 public static function percentage(int $bps): string {return rtrim(rtrim(number_format($bps/100,2,'.',''),'0'),'.');}
 public static function total(int $net,int $bps): int {return $net+intdiv($net*$bps+5000,10000);}
 public function save(string $value,string $admin): void {
  $bps=self::parse($value);
  $this->db->transaction(function()use($bps,$admin){
   $this->db->run("INSERT INTO integration_settings(key,value) VALUES('billing:vat_bps',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)$bps]);
   $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'billing_tax','Exclusive VAT '.self::percentage($bps).'%; new purchases and upgrades only',time()]);
  });
 }
 private static function valid(array $rate,int $bps,string $mode): bool {
  return ($rate['active']??false) && ($rate['livemode']??null)===($mode==='live') && ($rate['inclusive']??null)===false && ($rate['country']??'')==='RO' && ($rate['display_name']??'')==='TVA' && (int)round(($rate['percentage']??-1)*100)===$bps;
 }
 /** Each Stripe account/mode/rate has its own immutable tax object; no manual IDs. */
 public function rates(\Stripe\StripeClient $stripe,string $mode,int $bps): array {
  if($bps===0)return [];
  $fingerprint=hash('sha256',$stripe->getApiKey());
  $key='billing:tax:'.$mode.':'.$fingerprint.':'.$bps;
  $row=$this->db->one('SELECT value FROM integration_settings WHERE key=?',[$key]);
  if($row){
   try{$rate=$stripe->taxRates->retrieve($row['value'])->toArray();if(self::valid($rate,$bps,$mode))return [$rate['id']];}
   catch(\Stripe\Exception\InvalidRequestException $e){if($e->getStripeCode()!=='resource_missing')throw $e;}
  }
  $found=null;
  foreach($stripe->taxRates->all(['limit'=>100])->autoPagingIterator() as $rate){$rate=$rate->toArray();if(($rate['metadata']['zion_vat_bps']??'')===(string)$bps && self::valid($rate,$bps,$mode)){$found=$rate;break;}}
  $found??=$stripe->taxRates->create(['display_name'=>'TVA','percentage'=>self::percentage($bps),'inclusive'=>false,'country'=>'RO','jurisdiction'=>'Romania','tax_type'=>'vat','description'=>'Zion Storage VAT Romania','metadata'=>['zion_vat_bps'=>(string)$bps]],['idempotency_key'=>'zion-vat-'.$mode.'-'.$fingerprint.'-'.$bps.'-'.($row['value']??'initial')])->toArray();
  if(!self::valid($found,$bps,$mode))throw new \RuntimeException('stripe_tax_rate_mismatch');
  $this->db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,$found['id']]);
  return [$found['id']];
 }
}
