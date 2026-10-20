<?php
// Official SDK transport fixture: exercise Checkout request construction without network calls.
final class FixtureHttp implements Stripe\HttpClient\ClientInterface {
 public array $calls=[];
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null) {
  $this->calls[]=[$method,$url,$params,$headers];
  if(str_contains($url,'/prices/')){$year=str_ends_with($url,'_year');$r=['id'=>$year?'price_120_year':'price_120','object'=>'price','active'=>true,'livemode'=>false,'currency'=>'eur','unit_amount'=>$year?2189:199,'recurring'=>['interval'=>$year?'year':'month','interval_count'=>1],'product'=>'prod_fixture'];}
  elseif(str_contains($url,'checkout/sessions'))$r=['id'=>'cs_fixture','object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/cs_fixture','expires_at'=>time()+3600];
  elseif(str_contains($url,'billing_portal/configurations'))$r=['id'=>'bpc_fixture','object'=>'billing_portal.configuration','active'=>true,'features'=>['subscription_update'=>['enabled'=>false]]];
  else $r=['id'=>'bps_fixture','object'=>'billing_portal.session','url'=>'https://billing.stripe.com/p/session/test'];
  return [json_encode($r),200,[]];
 }
}
$http=new FixtureHttp();Stripe\ApiRequestor::setHttpClient($http);
[$checkoutId,$checkoutToken]=signup('checkout@example.com','120gb','fr');$accounts->verify($checkoutToken);
$cu=$db->one('SELECT * FROM users WHERE id=?',[$checkoutId]);
$realBilling=new Zion\StripeService($db,$plans,$mail,$store);
$checkoutUrl=$realBilling->checkout($cu);
check(str_starts_with($checkoutUrl,'https://checkout.stripe.com/'),'SDK checkout URL');
check($http->calls[1][2]['mode']==='subscription','SDK checkout subscription mode');
check($http->calls[1][2]['line_items'][0]['price']==='price_120','SDK uses configured price');
check($http->calls[1][2]['locale']==='fr','SDK checkout locale');
check($http->calls[1][2]['cancel_url']==='http://localhost:8088/fr/paiement-annule','SDK localized cancel route');
check($http->calls[1][2]['subscription_data']['metadata']['user_id']===$checkoutId,'SDK authoritative user metadata');
$realBilling->checkout($cu);check(count($http->calls)===2,'existing Checkout Session reused');
$db->run('INSERT INTO subscriptions(user_id,stripe_customer_id,status) VALUES(?,?,?)',[$checkoutId,'cus_portal','pending']);
check(str_starts_with($realBilling->portal($cu),'https://billing.stripe.com/'),'SDK customer portal');
check($http->calls[3][2]['customer']==='cus_portal','portal uses stored customer');
check(in_array($http->calls[2][2]['features']['subscription_update']['enabled'],[false,'false'],true) && $http->calls[3][2]['configuration']==='bpc_fixture','portal cannot bypass upgrade policy');
[$yearId,$yearToken]=signup('annual-checkout@example.com','120gb','de','year');$accounts->verify($yearToken);
$realBilling->checkout($db->one('SELECT * FROM users WHERE id=?',[$yearId]));
$request=$http->calls[5][2];
check($request['line_items'][0]['price']==='price_120_year' && $request['subscription_data']['metadata']['billing_interval']==='year','annual Checkout uses annual Price and authoritative interval');
$saved=$db->one('SELECT * FROM checkout_sessions WHERE user_id=?',[$yearId]);
check($saved['price_id']==='price_120_year' && $saved['billing_interval']==='year','annual Checkout quote persists chosen Price and interval');

final class AdapterFixture extends Zion\StorageProvisioningService {
 public array $calls=[];public array $records=[];
 public function __construct(){ $db=new Zion\Database(':memory:');$db->migrate();parent::__construct($db); }
 private bool $onboarded=false;
 protected function userRequest(string $method,string $path,?array $body,?string $token):array {
  if($path==='/auth/login')return ['accessToken'=>'fixture-user-session-token','userId'=>'adapter-id','userEmail'=>'adapter@example.com','isAdmin'=>false];
  if($path==='/auth/logout')return ['successful'=>true];
  if($path==='/users/me/preferences')return $body;
  if($path==='/users/me/onboarding'){if($method==='PUT')$this->onboarded=$body['isOnboarded'];return ['isOnboarded'=>$this->onboarded];}
  throw new RuntimeException('Unexpected user API request');
 }
 protected function request(string $method,string $path,?array $body=null):array {
  $this->calls[]=[$method,$path,$body];
  if($method==='GET' && str_starts_with($path,'/admin/users?'))return $this->records;
  if($method==='POST'){$this->records[]=$body+['id'=>'adapter-id'];return ['id'=>'adapter-id'];}
  if($method==='GET')return ['quotaUsageInBytes'=>456,'quotaSizeInBytes'=>1000];
  return [];
 }
}
$adapter=new AdapterFixture();$adapterUser=['id'=>'opaque-safe-id','name'=>'Test','email'=>'adapter@example.com'];
check($adapter->createUser($adapterUser,'never-persist-this-password',1000)==='adapter-id','adapter creates user');
check($adapter->calls[1][2]['quotaSizeInBytes']===1000 && $adapter->calls[1][2]['isAdmin']===false,'adapter quota and non-admin');
check($adapter->calls[1][2]['notify']===false,'upstream branded notifications disabled');
$adapter->createUser($adapterUser,'never-persist-this-password',1000);
check(count(array_filter($adapter->calls,fn($x)=>$x[0]==='POST'))===1,'adapter reconciles create timeout');
$adapter->disableUser('adapter-id');$last=end($adapter->calls);
check($last[0]==='PUT' && $last[2]['quotaSizeInBytes']===456,'restriction retains files and blocks uploads');
$adapter->enableUser('adapter-id',1000);$last=end($adapter->calls);check($last[2]['quotaSizeInBytes']===1000,'restores paid quota');
$adapter->records=[['email'=>'adapter@example.com','id'=>'unrelated','storageLabel'=>'another-label']];
try{$adapter->createUser($adapterUser,'password',1000);check(false,'identity collision');}catch(RuntimeException $e){check(true,'adapter refuses unrelated existing identity');}
check(!str_contains($db->one("SELECT payload FROM outbox WHERE recipient='checkout@example.com'")['payload'],$checkoutToken),'queued verification token encrypted');
foreach(Zion\TranslationService::LOCALES as $l)foreach(['activated','payment','subscription','failed','cancelled','quota','contact'] as $kind){
 $mail->queue('preview@example.com',$l,$kind,[],'render-'.$l.'-'.$kind);
}
$mail->deliver();
check((int)$db->one('SELECT COUNT(*) AS n FROM outbox WHERE sent_at IS NULL')['n']===0,'all localized queued email templates render');
check(!$db->one("SELECT payload FROM outbox WHERE recipient='checkout@example.com'"),'consumed confirmation payload removed without resending link');
check($db->one("SELECT payload FROM outbox WHERE dedupe_key='render-en-activated'")['payload']==='{}','delivered payload scrubbed');
echo "\n$count total assertions passed, including SDK/adapter/email fixtures.\n";
