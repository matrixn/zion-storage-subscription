<?php
// SDK fixtures exercise catalog writes without contacting Stripe.
final class CatalogHttp implements Stripe\HttpClient\ClientInterface {
 public array $prices=[];public array $products=[];public int $created=0;public int $productCreates=0;
 public string $mode='test';public ?int $failAfterPrices=null;public bool $losePriceResponse=false;
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $path=parse_url($url,PHP_URL_PATH);$post=strtolower($method)==='post';
  if(preg_match('~/prices/([^/]+)$~',$path,$m)){
   if(!isset($this->prices[$m[1]]))return [json_encode(['error'=>['type'=>'invalid_request_error','code'=>'resource_missing','message'=>'Fixture missing Price']]),404,[]];
   if($post)$this->prices[$m[1]]=array_replace($this->prices[$m[1]],$params);
   $r=$this->prices[$m[1]];
  }
  elseif(preg_match('~/products/([^/]+)$~',$path,$m)){
   if(!isset($this->products[$m[1]]))return [json_encode(['error'=>['type'=>'invalid_request_error','code'=>'resource_missing','message'=>'Fixture missing product']]),404,[]];
   if($post)$this->products[$m[1]]=array_replace($this->products[$m[1]],$params);
   $r=$this->products[$m[1]];
  }elseif(str_ends_with($path,'/products')&&$post){
   $this->productCreates++;$r=$params+['object'=>'product','active'=>true,'livemode'=>$this->mode==='live'];$this->products[$r['id']]=$r;
  }elseif(str_ends_with($path,'/products')){
   $r=['object'=>'list','data'=>array_values($this->products)];
  }elseif(str_ends_with($path,'/prices')&&$post){
   if($this->failAfterPrices!==null && $this->created>=$this->failAfterPrices)throw new RuntimeException('fixture-provider-secret');
   $r=$params+['id'=>'price_'.$this->mode.'_generated_'.++$this->created,'object'=>'price','active'=>true,'livemode'=>$this->mode==='live'];
   $r['unit_amount']=(int)$r['unit_amount'];$r['recurring']['interval_count']=1;$this->prices[$r['id']]=$r;
   if($this->losePriceResponse){$this->losePriceResponse=false;throw new RuntimeException('fixture-provider-secret');}
  }elseif(str_ends_with($path,'/prices')){
   $r=['object'=>'list','data'=>isset($params['lookup_keys'])?array_values(array_filter($this->prices,fn($p)=>in_array($p['lookup_key']??'', $params['lookup_keys'],true))):array_values($this->prices)];
  }else throw new RuntimeException('Unexpected catalog request: '.$path);
  return [json_encode($r),200,[]];
 }
}
$db=new Zion\Database(':memory:');$db->migrate();seedCatalog($db);$plans=new Zion\Plans($db);
$mail=new Zion\MailerService($db);$store=new TestStorage();$accounts=new Zion\AccountService($db,$plans,$mail,$store);
$_ENV['ADMIN_EMAIL']='catalog-fixture@example.com';$_ENV['ADMIN_PASSWORD']='catalog-fixture-password';
Zion\AccountControl::seedAdmin($db);$admin=$db->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
$fixture=new ChangesHttp();$http=new CatalogHttp();
foreach(['price_120','price_250','price_500'] as $id)foreach(['','_year'] as $suffix)$http->prices[$id.$suffix]=$fixture->price($id.$suffix);
Stripe\ApiRequestor::setHttpClient($http);$billing=new Zion\StripeService($db,$plans,$mail,$store);$catalog=$billing->catalog();
check(array_column($plans->all('year'),'price_amount')===[0,4389,7909,13189],'annual catalog charges eleven monthly payments');
check($plans->get('500gb')['name']==='Titan','Titan replaces Family without changing plan slug');
foreach(['120gb'=>'price_120','250gb'=>'price_250','500gb'=>'price_500'] as $slug=>$id)foreach(['month'=>'','year'=>'_year'] as $interval=>$suffix)$catalog->save($slug,$interval,$id.$suffix,$admin);
check(count($catalog->rows())===6,'six validated prices managed in database');
foreach(['currency'=>'ron','unit_amount'=>999,'livemode'=>true,'recurring'=>['interval'=>'year','interval_count'=>1]] as $field=>$value){
 $http->prices['price_invalid']=array_replace($fixture->price('price_120'),['id'=>'price_invalid',$field=>$value]);
 try{$catalog->save('120gb','month','price_invalid',$admin);check(false,'invalid price accepted');}catch(DomainException $e){check($plans->get('120gb')['stripe_price_id']==='price_120','invalid price cannot replace catalog mapping');}
}
$http->prices['price_replacement']=array_replace($fixture->price('price_120'),['id'=>'price_replacement']);
$catalog->save('120gb','month','price_replacement',$admin);
check($plans->byPrice('price_120')['legacy'] && $plans->get('120gb')['stripe_price_id']==='price_replacement','replacing a price retains historical mapping');
$catalog->disable('500gb','month',$admin);$catalog->create('500gb','month',$admin);
$catalog->disable('500gb','month',$admin);$catalog->create('500gb','month',$admin);
check($http->created===1 && $http->productCreates===1,'catalog retry reuses stable product and price');
$catalog->disable('500gb','year',$admin);$catalog->create('500gb','year',$admin);
check($http->created===2 && $http->productCreates===1,'annual and monthly Prices share one Stripe Product');
// Annual paid event and paid upgrade preserve the original annual renewal.
Stripe\ApiRequestor::setHttpClient($fixture);$billing=new Zion\StripeService($db,$plans,$mail,$store);
[$id,$token]=signup('annual@example.com','120gb','de','year');$accounts->verify($token);
$start=time()-60;$end=strtotime('+1 year',$start);
$invoice=['id'=>'in_annual','object'=>'invoice','livemode'=>false,'customer'=>'cus_annual','subscription'=>'sub_annual','status'=>'paid','amount_paid'=>4389,'currency'=>'eur','billing_reason'=>'subscription_create','status_transitions'=>['paid_at'=>$start],'created'=>$start,'lines'=>['data'=>[['price'=>['id'=>'price_120_year'],'quantity'=>1,'period'=>['start'=>$start,'end'=>$end]]],'has_more'=>false]];
$fixture->invoices[$invoice['id']]=$invoice;
$fixture->subscription=['id'=>'sub_annual','object'=>'subscription','customer'=>'cus_annual','status'=>'active','metadata'=>['user_id'=>$id,'plan'=>'120gb','billing_interval'=>'year'],'items'=>['data'=>[['id'=>'si_annual','quantity'=>1,'price'=>$fixture->price('price_120_year'),'current_period_end'=>$end]]],'latest_invoice'=>$invoice];
$billing->process(['id'=>'evt_annual','type'=>'invoice.paid','data'=>['object'=>$invoice]]);
$sub=$db->one('SELECT * FROM subscriptions WHERE user_id=?',[$id]);
check($sub['billing_interval']==='year' && $sub['currency']==='eur' && $sub['renewal_at']===$end,'annual webhook stores actual currency and full renewal period');
$fiscal=$db->one('SELECT * FROM fiscal_invoices WHERE stripe_invoice_id=?',['in_annual']);
check($fiscal['amount']===4389 && $fiscal['currency']==='EUR','annual fiscal amount is actual EUR payment');
$source=Zion\EncryptedPayload::decode($fiscal['source_snapshot']);
check(str_contains($source['plan']['invoice_name_ro'],'anual') && $fiscal['billing_period_start']===$start && $fiscal['billing_period_end']===$end,'annual fiscal snapshot names full annual service period');
$user=$db->one('SELECT * FROM users WHERE id=?',[$id]);$changes=$billing->changes();
check($changes->offers($user)[0]['amount']===3520,'annual upgrade charges annual catalog difference');
$changes->checkout($user,'250gb');$change=$db->one('SELECT * FROM plan_changes WHERE user_id=?',[$id]);$session=$fixture->pay($change['checkout_session_id'],time());
$billing->process(['id'=>'evt_annual_upgrade','type'=>'checkout.session.completed','data'=>['object'=>$session]]);
check($fixture->subscription['items']['data'][0]['price']['unit_amount']===7909 && $fixture->subscription['items']['data'][0]['price']['recurring']['interval']==='year','annual upgrade changes future renewal to new annual Price');
$upgradeFiscal=$db->one('SELECT source_snapshot FROM fiscal_invoices WHERE stripe_invoice_id=?',[$session['invoice']['id']]);
check(str_contains(Zion\EncryptedPayload::decode($upgradeFiscal['source_snapshot'])['plan']['invoice_name_ro'],'anual'),'annual upgrade fiscal snapshot retains annual service label');
check($db->one('SELECT renewal_at FROM subscriptions WHERE user_id=?',[$id])['renewal_at']===$end && $change['currency']==='eur' && $change['billing_interval']==='year','annual upgrade preserves anniversary and payment currency');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
echo "\n$count total assertions passed, including EUR annual catalog and upgrade.\n";
