<?php
declare(strict_types=1);
namespace Zion;

/** Integer arithmetic: BNR publishes RON per EUR with four decimal places. */
class BnrRates {
 public const SOURCE='https://curs.bnr.ro/nbrfxrates10days.xml';
 public function __construct(private Database $db) {}
 public static function date(?int $time=null): string {return (new \DateTimeImmutable('@'.($time??time())))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d');}
 public static function convert(int $eurMinor,int $scaled): int {
  if($eurMinor<0 || $eurMinor>100000000 || $scaled<1 || $scaled>1000000)throw new \DomainException('Conversie valutară invalidă.');
  return intdiv($eurMinor*$scaled+5000,10000);
 }
 public static function formatted(array $rate): string {return number_format($rate['eur_ron']/10000,4,'.','');}
 public function current(?string $date=null): ?array {
  $date??=self::date();
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || $date>self::date())return null;
  $row=$this->db->one('SELECT * FROM exchange_rates WHERE rate_date<=? ORDER BY rate_date DESC LIMIT 1',[$date]);
  if(!$row || strtotime($date.' UTC')-strtotime($row['rate_date'].' UTC')>7*86400)return null;
  return $row;
 }
 public static function signature(array $plan,string $uid): string {
  return hash_hmac('sha256',json_encode([$uid,$plan['slug'],$plan['billing_interval'],$plan['stripe_mode'],$plan['eur_amount'],$plan['price_amount'],$plan['tax_bps'],$plan['fx']['rate_date']??null,$plan['fx']['eur_ron']??null],JSON_THROW_ON_ERROR),env('APP_KEY'));
 }
 public static function metadata(array $plan): array {return ['zion_fx_policy'=>'bnr_v1','zion_eur_amount'=>(string)$plan['eur_amount'],'zion_fx_rate'=>(string)$plan['fx']['eur_ron'],'zion_fx_date'=>$plan['fx']['rate_date']];}
 public function quote(int $eurMinor,?string $date=null): array {
  $rate=$this->current($date);
  $error=$this->db->one("SELECT value FROM integration_settings WHERE key='bnr:last_error'");
  if(!$rate || $rate['fetched_at']<time()-3600 || ($error['value']??'0')==='1')throw new \DomainException('Cursul BNR nu este disponibil. Actualizează cursul din administrare.');
  return $rate+['eur_amount'=>$eurMinor,'ron_amount'=>self::convert($eurMinor,$rate['eur_ron'])];
 }
 public function refresh(bool $force=false): array {
  $attempt=$this->db->one("SELECT value FROM integration_settings WHERE key='bnr:last_attempt'");
  if(!$force && (int)($attempt['value']??0)>time()-1800 && $this->current()){
   $error=$this->db->one("SELECT value FROM integration_settings WHERE key='bnr:last_error'");if(($error['value']??'0')==='1')throw new \DomainException('Ultima actualizare BNR a eșuat. Reîncearcă actualizarea din administrare.');
   return $this->current();
  }
  $this->db->run("INSERT INTO integration_settings(key,value) VALUES('bnr:last_attempt',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
  try{$xml=$this->fetch();$rows=self::parse($xml);$hash=hash('sha256',$xml);}
  catch(\Throwable $e){ApiLog::record('bnr','GET',self::SOURCE,0,microtime(true),['failed'=>true,'code'=>'bnr_refresh_failed','message'=>'Cursul BNR nu a putut fi preluat sau validat; plățile noi așteaptă o actualizare reușită.']);$this->db->run("INSERT INTO integration_settings(key,value) VALUES('bnr:last_error','1') ON CONFLICT(key) DO UPDATE SET value='1'");throw $e;}
  $this->db->transaction(function()use($rows,$hash){foreach($rows as $row)$this->db->run('INSERT INTO exchange_rates(rate_date,eur_ron,fetched_at,source_url,content_hash) VALUES(?,?,?,?,?) ON CONFLICT(rate_date) DO UPDATE SET eur_ron=excluded.eur_ron,fetched_at=excluded.fetched_at,source_url=excluded.source_url,content_hash=excluded.content_hash',[$row['rate_date'],$row['eur_ron'],time(),self::SOURCE,$hash]);});
  $this->db->run("INSERT INTO integration_settings(key,value) VALUES('bnr:last_error','0') ON CONFLICT(key) DO UPDATE SET value='0'");
  return $this->current()??throw new \DomainException('BNR nu a returnat un curs recent. Cursul anterior este păstrat.');
 }
 public static function parse(string $xml): array {
  if(strlen($xml)>262144 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new \DomainException('Fișier BNR invalid.');
  $previous=libxml_use_internal_errors(true);
  try{$data=simplexml_load_string($xml,\SimpleXMLElement::class,LIBXML_NONET);}
  finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  if(!$data || $data->getName()!=='DataSet' || (string)$data->Header->Publisher!=='National Bank of Romania' || (string)$data->Body->OrigCurrency!=='RON')throw new \DomainException('Sursa BNR nu a putut fi validată.');
  $rows=[];foreach($data->Body->Cube as $cube){
   $date=(string)$cube['date'];$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Bucharest'));
   if(!$parsed || $parsed->format('Y-m-d')!==$date || $date>self::date())throw new \DomainException('Data cursului BNR este invalidă.');
   $eur=[];foreach($cube->Rate as $rate)if((string)$rate['currency']==='EUR')$eur[]=$rate;
   if(count($eur)!==1 || !preg_match('/^[1-9][0-9]?\.[0-9]{4}$/D',(string)$eur[0]) || (isset($eur[0]['multiplier']) && (string)$eur[0]['multiplier']!=='1'))throw new \DomainException('Curs EUR/RON BNR invalid.');
   $rows[]=['rate_date'=>$date,'eur_ron'=>(int)str_replace('.','',(string)$eur[0])];
  }
  if(!$rows)throw new \DomainException('Nu există curs EUR în răspunsul BNR.');return $rows;
 }
 protected function fetch(): string {
  if(env('APP_ENV')==='test')throw new \RuntimeException('test_network_forbidden');
  $body='';$start=microtime(true);$ch=curl_init(self::SOURCE);
  curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>['Accept: application/xml, text/xml'],CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
  $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  ApiLog::record('bnr','GET',self::SOURCE,$status,$start,['failed'=>$ok===false || $status!==200,'code'=>$ok===false || $status!==200?'rate_fetch_failed':'rate_fetched','message'=>'Preluare cursuri EUR/RON din sursa oficială BNR.']);
  if($ok===false || $status!==200)throw new \DomainException('BNR nu poate fi contactată. Cursul salvat este păstrat.');return $body;
 }
}
