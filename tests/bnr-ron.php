<?php
final class BnrFixture extends Zion\BnrRates {
 public string $xml='';public bool $fail=false;public int $fetches=0;
 protected function fetch():string{$this->fetches++;if($this->fail)throw new RuntimeException('isolated rate outage');return $this->xml;}
}
$bnrXml=static fn(string $date,string $rate):string=>'<?xml version="1.0"?><DataSet xmlns="https://www.bnr.ro/xsd"><Header><Publisher>National Bank of Romania</Publisher></Header><Body><OrigCurrency>RON</OrigCurrency><Cube date="'.$date.'"><Rate currency="EUR">'.$rate.'</Rate></Cube></Body></DataSet>';
$bnrDb=new Zion\Database(':memory:');$bnrDb->migrate();$bnr=new BnrFixture($bnrDb);$bnr->xml=$bnrXml(Zion\BnrRates::date(),'5.3285');
check($bnr->refresh(true)['eur_ron']===53285,'BNR XML parsed into exact scaled integer');
check($bnr->quote(399)['ron_amount']===2126 && $bnr->quote(4389)['ron_amount']===23387,'EUR monthly and eleven-month annual base convert to RON cents with integer rounding');
$bnr->refresh();check($bnr->fetches===1,'BNR cache avoids redundant source calls');
$bnrSaved=$bnr->current();$bnr->fail=true;
try{$bnr->refresh(true);check(false,'failed BNR fetch accepted');}catch(RuntimeException $e){check($bnr->current()===$bnrSaved,'rate fetch failure preserves last known source and date');}
try{$bnr->quote(399);check(false,'failed source allowed a fresh charge');}catch(DomainException $e){check(true,'known rate-source failure blocks new payment quotes');}
$bnr->fail=false;$bnr->refresh(true);check($bnr->quote(399)['ron_amount']===2126,'successful refresh restores quote availability');
foreach(['<!DOCTYPE x [<!ENTITY remote SYSTEM "file:///etc/passwd">]>'.$bnr->xml,str_replace('5.3285','-5.3285',$bnr->xml),str_replace('National Bank of Romania','Unknown Publisher',$bnr->xml),str_replace('currency="EUR"','currency="USD"',$bnr->xml),$bnrXml(Zion\BnrRates::date(time()+86400),'5.3285')] as $invalid){try{Zion\BnrRates::parse($invalid);check(false,'invalid BNR source accepted');}catch(DomainException $e){check(true,'malformed, foreign, negative, missing or future BNR data rejected');}}
check(Zion\OblioAddress::normalize(['country'=>'RO','county'=>'RO-VL','city'=>'RÂMNICU VÂLCEA'])===['country'=>'Romania','state'=>'Valcea','city'=>'Ramnicu Valcea'],'Oblio address uses canonical country, county name and locality');
check(Zion\OblioAddress::normalize(['country'=>'RO','county'=>'jud. Vâlcea','city'=>'Mun. Râmnicu Vâlcea'])['state']==='Valcea','Romanian county prefixes and diacritics normalized');
check(Zion\OblioAddress::normalize(['country'=>'RO','county'=>'B','city'=>'Sector 3'])['city']==='Sector 3','explicit Bucharest sector preserved without inventing data');
check(Zion\OblioAddress::normalize(['country'=>'DE','county'=>'Bayern','city'=>'München'])['city']==='München','foreign addresses retain original locality');

final class RonHttpFixture implements Stripe\HttpClient\ClientInterface {
 public array $subscription=[],$invoice=[],$calls=[];public int $adjustments=0,$charges=0,$payCalls=0,$taxBps=0;public bool $loseAdjustment=false,$loseFinalize=false,$losePayment=false;
 public function total():void{
  $net=0;$gross=0;foreach($this->invoice['lines']['data'] as $line){$net+=$line['amount'];$gross+=($line['amount']<0?-1:1)*Zion\BillingTax::total(abs($line['amount']),$this->taxBps);}
  $this->invoice['subtotal']=$net;$this->invoice['total']=$gross;$this->invoice['amount_due']=$gross;
 }
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $path=parse_url($url,PHP_URL_PATH);$post=strtolower($method)==='post';$this->calls[]=[$method,$path,$params];
  if(str_contains($path,'/subscriptions/')){
   if($post && isset($params['pause_collection']))$this->subscription['pause_collection']=$params['pause_collection'];
   $result=$this->subscription;if(in_array('latest_invoice',$params['expand']??[],true))$result['latest_invoice']=$this->invoice;
  }elseif($path==='/v1/invoiceitems' && $post){
   check($params['invoice']===$this->invoice['id'] && $params['customer']===$this->subscription['customer'] && $params['currency']==='ron','FX adjustment bound to the exact invoice, customer and RON currency');
   $this->adjustments++;$line=['id'=>'il_fx_adjustment','currency'=>'ron','amount'=>(int)$params['amount'],'metadata'=>$params['metadata'],'quantity'=>1,'period'=>$params['period']];$this->invoice['lines']['data'][]=$line;$this->total();$result=['id'=>'ii_fx_adjustment','object'=>'invoiceitem']+$line;
   if($this->loseAdjustment){$this->loseAdjustment=false;throw new RuntimeException('isolated lost adjustment response');}
  }elseif(str_ends_with($path,'/finalize')){
   $this->invoice['status']='open';$result=$this->invoice;
   if($this->loseFinalize){$this->loseFinalize=false;throw new RuntimeException('isolated lost finalize response');}
  }elseif(str_ends_with($path,'/pay')){
   $this->payCalls++;if($this->invoice['status']!=='paid')$this->charges++;$this->invoice['status']='paid';$this->invoice['amount_paid']=$this->invoice['total'];$this->invoice['status_transitions']=['paid_at'=>time()];$result=$this->invoice;
   if($this->losePayment){$this->losePayment=false;throw new RuntimeException('isolated lost payment response');}
  }elseif(str_contains($path,'/invoices/')){
   if($post){if(isset($params['auto_advance']))$this->invoice['auto_advance']=$params['auto_advance'];if(isset($params['metadata']))$this->invoice['metadata']=array_replace($this->invoice['metadata']??[],$params['metadata']);}
   $result=$this->invoice;
  }else throw new RuntimeException('Unexpected RON fixture request');
  return [json_encode($result,JSON_THROW_ON_ERROR),200,[]];
 }
}
function ronFixture(int $rate=53285,int $oldRate=50125,int $tax=0):array {
 $db=new Zion\Database(':memory:');$db->migrate();testFx($db);seedCatalog($db);$db->run("UPDATE billing_prices SET price_policy='bnr_v1'");
 $db->run('UPDATE exchange_rates SET eur_ron=?',[$rate]);
 $old=['rate_date'=>Zion\BnrRates::date(),'eur_ron'=>$oldRate,'source_url'=>Zion\BnrRates::SOURCE,'fetched_at'=>time()];
 $base=Zion\BnrRates::convert(399,$oldRate);$db->run("UPDATE billing_prices SET amount=?,eur_amount=399,fx_snapshot=? WHERE price_id='price_120'",[$base,json_encode($old)]);
 $uid=bin2hex(random_bytes(16));$now=time();$db->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$uid,'RON Fixture','ron-fixture@example.test','unused','ro','120gb',$now,'verified',$now,$now,$now,$now]);
 (new Zion\BillingProfileService($db))->save($uid,['type'=>'individual','name'=>'RON Fixture','billing_email'=>'ron-fixture@example.test','country'=>'RO','county'=>'VL','city'=>'RÂMNICU VÂLCEA','address'=>'Fixture street 1','postal_code'=>'240001']);
 $http=new RonHttpFixture();$http->taxBps=$tax;
 $http->subscription=['id'=>'sub_ron_fixture','object'=>'subscription','customer'=>'cus_ron_fixture','livemode'=>false,'status'=>'active','metadata'=>['user_id'=>$uid,'plan'=>'120gb','billing_interval'=>'month','zion_fx_policy'=>'bnr_v1','zion_eur_amount'=>'399','zion_vat_bps'=>(string)$tax],'items'=>['data'=>[['id'=>'si_ron_fixture','quantity'=>1,'price'=>['id'=>'price_120','currency'=>'ron','unit_amount'=>$base,'recurring'=>['interval'=>'month']],'current_period_end'=>$now+30*86400]]],'latest_invoice'=>'in_ron_fixture'];
 $http->invoice=['id'=>'in_ron_fixture','object'=>'invoice','customer'=>'cus_ron_fixture','subscription'=>'sub_ron_fixture','livemode'=>false,'currency'=>'ron','billing_reason'=>'subscription_cycle','status'=>'draft','created'=>$now,'auto_advance'=>false,'metadata'=>[],'lines'=>['has_more'=>false,'data'=>[['id'=>'il_ron_base','currency'=>'ron','type'=>'subscription','amount'=>$base,'quantity'=>1,'price'=>['id'=>'price_120'],'proration'=>false,'tax_rates'=>$tax?[['id'=>'txr_ron_fixture','percentage'=>$tax/100]]:[],'period'=>['start'=>$now-60,'end'=>$now+30*86400]]]]];$http->total();
 Stripe\ApiRequestor::setHttpClient($http);$worker=new Zion\RonRenewals($db,new Stripe\StripeClient('sk_test_fake'),'test');
 $http->subscription=$worker->arm($http->subscription,$db->one('SELECT * FROM users WHERE id=?',[$uid]),(new Zion\Plans($db))->byPrice('price_120'));
 $worker->queue($http->invoice,$http->subscription,$uid);
 return [$db,$uid,$http,$worker];
}
$ronOldHttp=Stripe\ApiRequestor::httpClient();
try{
 foreach(['normal','adjustment_lost','finalize_lost','payment_lost'] as $case){
  [$rdb,$ruid,$rhttp,$worker]=ronFixture();$rhttp->loseAdjustment=$case==='adjustment_lost';$rhttp->loseFinalize=$case==='finalize_lost';$rhttp->losePayment=$case==='payment_lost';
  try{$worker->process('in_ron_fixture');}catch(RuntimeException $e){check($case!=='normal','transport error retained for retry');}
  $frozen=json_decode($rdb->one('SELECT snapshot FROM invoice_fx_quotes')['snapshot'],true);$rdb->run('UPDATE exchange_rates SET eur_ron=60000');
  $worker->process('in_ron_fixture');$worker->process('in_ron_fixture');
  check($rhttp->charges===1 && $rhttp->payCalls===1 && $rhttp->adjustments===1,'retries never duplicate adjustment or payment: '.$case);
  check($rhttp->invoice['amount_paid']===2126 && $frozen['eur_ron']===53285 && $rdb->one('SELECT status FROM invoice_fx_quotes')['status']==='paid','renewal preserves its BNR snapshot across later rate updates: '.$case);
 }
 [$rdb,$ruid,$rhttp,$worker]=ronFixture();$rdb->run('DELETE FROM invoice_fx_quotes');$result=$worker->work();
 check($result['processed']===1 && $rhttp->charges===1,'periodic worker discovers renewal without invoice.created delivery');
 [$rdb,$ruid,$rhttp,$worker]=ronFixture();$billing=new Zion\StripeService($rdb,new Zion\Plans($rdb),new Zion\MailerService($rdb),new TestStorage());$beforeCalls=count($rhttp->calls);
 try{$billing->checkout($rdb->one('SELECT * FROM users WHERE id=?',[$ruid]),'outdated-quote');check(false,'outdated quote charged');}catch(DomainException $e){check($e->getMessage()==='checkout.quote_changed' && count($rhttp->calls)===$beforeCalls,'stale checkout signature cannot trigger any Stripe request');}
 [$rdb,$ruid,$rhttp,$worker]=ronFixture(50000);$worker->process('in_ron_fixture');
 check($rhttp->invoice['amount_paid']===1995 && $rhttp->invoice['lines']['data'][1]['amount']===-5,'falling exchange rate reduces the renewal charge');
 [$rdb,$ruid,$rhttp,$worker]=ronFixture(53285,50199,2100);$worker->process('in_ron_fixture');
 check($rhttp->invoice['amount_paid']===2573,'exclusive VAT preserves legitimate per-line Stripe rounding');
 $quote=json_decode($rdb->one('SELECT snapshot FROM invoice_fx_quotes')['snapshot'],true);
 $plan=(new Zion\Plans($rdb))->byPrice('price_120');$plan['fx']=$quote;$plan['eur_amount']=$quote['eur_amount'];$plan['price_amount']=$quote['ron_amount'];$plan['invoice_vat_rate']='21';
 $fiscal=new Zion\OblioInvoiceService($rdb,new Zion\MailerService($rdb));$fid=$fiscal->capture($rhttp->invoice,$rdb->one('SELECT * FROM users WHERE id=?',[$ruid]),$rhttp->subscription,$plan);check($fiscal->process($fid),'Oblio accepts local snapshot with a recognized FX adjustment and original service period');
 $doc=Zion\EncryptedPayload::decode($rdb->one('SELECT request_snapshot FROM fiscal_invoices WHERE id=?',[$fid])['request_snapshot']);
 check($doc['currency']==='RON' && $doc['products'][0]['price']===25.73 && str_contains($doc['mentions'],'5.3285') && str_contains($doc['mentions'],'3.99 EUR'),'Oblio RON invoice records actual collected amount and visible BNR conversion note');
 check($doc['client']['state']==='Valcea' && $doc['client']['city']==='Ramnicu Valcea' && $doc['client']['country']==='Romania' && $doc['client']['save']===1 && !isset($doc['clientLocation']),'Oblio buyer address normalized and saved without fictitious work location');
 [$rdb,$ruid,$rhttp,$worker]=ronFixture();$rhttp->invoice['customer']='cus_foreign';try{$worker->process('in_ron_fixture');check(false,'foreign invoice paid');}catch(RuntimeException $e){check($rhttp->charges===0 && $rhttp->adjustments===0,'foreign customer invoice cannot be altered or charged');}
 [$rdb,$ruid,$rhttp,$worker]=ronFixture();$rhttp->invoice['lines']['data'][]=['id'=>'il_foreign','amount'=>100,'currency'=>'ron'];try{$worker->process('in_ron_fixture');check(false,'unexpected invoice item paid');}catch(RuntimeException $e){check($rhttp->charges===0,'unexpected invoice lines remain held for review');}
}finally{Stripe\ApiRequestor::setHttpClient($ronOldHttp);}
echo "\n$count total assertions including BNR, RON renewal safety and Oblio address normalization.\n";
