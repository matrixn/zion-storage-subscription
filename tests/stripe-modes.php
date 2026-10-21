<?php
/** Two disjoint SDK accounts. Every fixture rejects objects from the other mode. */
final class ModeStripeHttp implements Stripe\HttpClient\ClientInterface {
 public array $catalogs;public array $calls=[];public array $subscriptions=['test'=>[],'live'=>[]];public array $invoices=['test'=>[],'live'=>[]];public array $sessions=['test'=>[],'live'=>[]];public bool $authFail=false;public bool $loseUpdate=false;
 public function __construct(){$this->catalogs=['test'=>new CatalogHttp(),'live'=>new CatalogHttp()];$this->catalogs['live']->mode='live';}
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $mode=str_contains(json_encode($headers),'_live_')?'live':'test';$path=parse_url($url,PHP_URL_PATH);$post=strtolower($method)==='post';$this->calls[]=[$mode,$method,$path,$params];
  if($this->authFail)return [json_encode(['error'=>['type'=>'invalid_request_error','message'=>'fixture-provider-secret']]),401,[]];
  if(str_contains($path,'/products')||str_contains($path,'/prices'))return $this->catalogs[$mode]->request($method,$url,$headers,$params,$hasFile,$apiMode,$maxNetworkRetries);
  if(preg_match('~/subscriptions/([^/]+)$~',$path,$m)){
   if(!isset($this->subscriptions[$mode][$m[1]]))throw new RuntimeException('Fixture mode mismatch');
   $s=&$this->subscriptions[$mode][$m[1]];
   if($post && isset($params['pause_collection']) && !isset($params['items'])){$s['pause_collection']=$params['pause_collection'];return [json_encode($s),200,[]];}
   if($post){if(array_key_exists('pause_collection',$params))$s['pause_collection']=$params['pause_collection']?:null;if(array_key_exists('tax_rates',$params['items'][0]))$s['items']['data'][0]['tax_rates']=$params['items'][0]['tax_rates'];if(array_key_exists('default_tax_rates',$params))$s['default_tax_rates']=$params['default_tax_rates'];$s['items']['data'][0]['price']=$this->catalogs[$mode]->prices[$params['items'][0]['price']];$s['metadata']=array_replace($s['metadata'],$params['metadata']);check($params['proration_behavior']==='none'&&$params['billing_cycle_anchor']==='unchanged','mode-aware subscription update preserves renewal');if($this->loseUpdate){$this->loseUpdate=false;throw new RuntimeException('fixture-response-lost');}}
   $r=$s;
  }elseif(str_ends_with($path,'/checkout/sessions')&&$post){
   $id='cs_'.$mode.'_'.(count($this->sessions[$mode])+1);
   $amount=$params['line_items'][0]['price_data']['unit_amount']??$this->catalogs[$mode]->prices[$params['line_items'][0]['price']]['unit_amount'];
   $r=['id'=>$id,'object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/'.$id,'status'=>'open','payment_status'=>'unpaid','livemode'=>$mode==='live','currency'=>'ron','customer'=>$params['customer']??'cus_'.$mode,'amount_total'=>$amount,'expires_at'=>$params['expires_at']??time()+3600,'metadata'=>$params['metadata']];$this->sessions[$mode][$id]=$r;
  }elseif(preg_match('~/checkout/sessions/([^/]+)$~',$path,$m))$r=$this->sessions[$mode][$m[1]]??throw new RuntimeException('Fixture mode mismatch');
  elseif(preg_match('~/invoices/([^/]+)$~',$path,$m))$r=$this->invoices[$mode][$m[1]]??throw new RuntimeException('Fixture mode mismatch');
  elseif(str_contains($path,'billing_portal/configurations'))$r=['id'=>'bpc_'.$mode,'object'=>'billing_portal.configuration','active'=>true,'features'=>['subscription_update'=>['enabled'=>false]]];
  elseif(str_contains($path,'billing_portal/sessions'))$r=['id'=>'bps_'.$mode,'object'=>'billing_portal.session','url'=>'https://billing.stripe.com/p/session/'.$mode];
  else throw new RuntimeException('Unexpected mode fixture request: '.$path);
  return [json_encode($r),200,[]];
 }
 public function pay(string $mode,string $id): array {
  $s=&$this->sessions[$mode][$id];$now=time();$s['status']='complete';$s['payment_status']='paid';
  $s['payment_intent']=['id'=>'pi_'.$id,'status'=>'succeeded','amount_received'=>$s['amount_total'],'metadata'=>$s['metadata'],'latest_charge'=>['id'=>'ch_'.$id,'paid'=>true,'created'=>$now]];
  $s['invoice']=['id'=>'in_'.$id,'object'=>'invoice','status'=>'paid','livemode'=>$mode==='live','customer'=>$s['customer'],'amount_paid'=>$s['amount_total'],'currency'=>'ron','created'=>$now,'status_transitions'=>['paid_at'=>$now],'metadata'=>$s['metadata'],'lines'=>['has_more'=>false,'data'=>[['quantity'=>1,'period'=>['start'=>$now,'end'=>$now]]]]];$this->invoices[$mode][$s['invoice']['id']]=$s['invoice'];return $s;
 }
}
$db=new Zion\Database(':memory:');$db->migrate();testFx($db);$db->run("INSERT INTO integration_settings(key,value) VALUES('billing:vat_bps','0')");$db->run('DELETE FROM billing_prices');$settings=new Zion\IntegrationSettings($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();$accounts=new Zion\AccountService($db,$plans,$mail,$store);
$_ENV['ADMIN_EMAIL']='mode-fixture@example.com';$_ENV['ADMIN_PASSWORD']='mode-fixture-password';Zion\AccountControl::seedAdmin($db);$admin=$db->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
$settings->save('stripe',['credential_mode'=>'test','secret_key'=>'sk_test_fake','webhook_secret'=>'whsec_testFixture'],$admin);
$settings->save('stripe',['credential_mode'=>'live','secret_key'=>'sk_live_fake','webhook_secret'=>'whsec_liveFixture'],$admin);
$settings->save('stripe',['credential_mode'=>'test','secret_key'=>Zion\IntegrationSettings::maskedSecret('sk_test_fake'),'webhook_secret'=>Zion\IntegrationSettings::maskedSecret('whsec_testFixture')],$admin);
check($settings->stripe('test')['secret_key']==='sk_test_fake' && $settings->stripe('test')['webhook_secret']==='whsec_testFixture','resubmitting displayed Stripe masks retains complete encrypted credentials');
$settings->save('oblio',['secret'=>'fixture-oblio-secret','language'=>'RO'],$admin);
$settings->save('oblio',['secret'=>Zion\IntegrationSettings::maskedSecret('fixture-oblio-secret'),'language'=>'RO'],$admin);
check($settings->oblio()['secret']==='fixture-oblio-secret','resubmitting displayed Oblio mask retains complete encrypted credential');
try{$settings->save('oblio',['secret'=>'modified••••••••secret','language'=>'RO'],$admin);check(false,'edited mask accepted');}catch(DomainException $e){check($settings->oblio()['secret']==='fixture-oblio-secret','edited masks are rejected without overwriting stored secret');}
$settings->save('oblio',['secret'=>Zion\IntegrationSettings::maskedSecret('fixture-oblio-secret'),'clear_secret'=>'1','language'=>'RO'],$admin);
check($settings->oblio()['secret']==='','explicit clear overrides a submitted masked value');
check($settings->stripe()['mode']==='test' && $settings->stripe('live')['secret_key']==='sk_live_fake','saving LIVE credentials does not activate LIVE or overwrite TEST');
try{$settings->activateStripe('live',$admin);check(false,'unverified live activated');}catch(DomainException $e){check($settings->stripe()['mode']==='test','activation requires verified API and catalog');}
$http=new ModeStripeHttp();Stripe\ApiRequestor::setHttpClient($http);
$testSync=new Zion\StripeIntegration($db,$plans,new Stripe\StripeClient('sk_test_fake'),'test');
$http->catalogs['test']->failAfterPrices=2;$partial=$testSync->synchronize($admin);
check($partial['status']==='partial' && $http->catalogs['test']->created===2,'automatic synchronization records partial provider failure');
check(!str_contains(json_encode($partial),'fixture-provider-secret'),'provider exception details never leak into diagnostics');
$http->catalogs['test']->failAfterPrices=null;$complete=$testSync->synchronize($admin);
check($complete['status']==='ok' && $http->catalogs['test']->created===6 && $http->catalogs['test']->productCreates===3,'retry completes exactly three products and six recurring prices');
$testSync->synchronize($admin);
check($http->catalogs['test']->created===6 && $http->catalogs['test']->productCreates===3,'repeated synchronization never duplicates products or prices');
$liveSync=new Zion\StripeIntegration($db,$plans,new Stripe\StripeClient('sk_live_fake'),'live');
$http->catalogs['live']->losePriceResponse=true;$lost=$liveSync->synchronize($admin);
check($lost['status']==='partial','lost response remains explicitly incomplete');
check($liveSync->synchronize($admin)['status']==='ok' && $http->catalogs['live']->created===6,'lookup keys recover a remotely committed price after response loss');
check($plans->get('120gb','month','test')['stripe_price_id']!==$plans->get('120gb','month','live')['stripe_price_id'],'TEST and LIVE catalog mappings are isolated');
$db->migrate();testFx($db);$db->migrate();testFx($db);
check((int)$db->one("SELECT COUNT(*) n FROM billing_prices WHERE status='active'")['n']===12,'repeated migrations preserve twelve mode-specific active prices');
$catalog=new Zion\BillingCatalog($db,$plans,new Stripe\StripeClient('sk_test_fake'),'test');$catalog->disable('120gb','month',$admin);
check($testSync->synchronize($admin)['status']==='paused' && !$plans->get('120gb','month','test')['stripe_price_id'] && $plans->get('120gb','month','live')['stripe_price_id'],'automatic synchronization respects manual pause in one mode only');
$catalog->create('120gb','month',$admin);$testSync->synchronize($admin);
$http->authFail=true;$bad=$testSync->check();
check($bad['status']==='failed' && !str_contains($bad['message'],'fixture-provider-secret'),'API authentication failure is shown without provider secrets');
$http->authFail=false;$testSync->check();
[$testId,$testToken]=signup('sandbox-user@example.com','120gb');$accounts->verify($testToken);
$settings->activateStripe('live',$admin);
[$liveId,$liveToken]=signup('live-user@example.com','120gb');$accounts->verify($liveToken);
$billing=new Zion\StripeService($db,$plans,$mail,$store);
check(str_contains($billing->checkout($db->one('SELECT * FROM users WHERE id=?',[$liveId])),'cs_live_'),'new LIVE registration uses LIVE Checkout');
[$liveYear,$liveYearToken]=signup('live-yearly@example.com','120gb','fr','year');$accounts->verify($liveYearToken);
$billing->checkout($db->one('SELECT * FROM users WHERE id=?',[$liveYear]));
$yearSession=end($http->sessions['live']);
check($yearSession['amount_total']===4389 && $yearSession['metadata']['billing_interval']==='year','LIVE annual Checkout charges eleven months using the annual LIVE Price');
check(str_contains($billing->checkout($db->one('SELECT * FROM users WHERE id=?',[$testId])),'cs_test_'),'old TEST registration cannot become a LIVE charge after switching mode');
$livePrice=$plans->get('120gb','month','live')['stripe_price_id'];$start=time()-60;$end=time()+29*86400;
$invoice=['id'=>'in_mode_live','object'=>'invoice','livemode'=>true,'customer'=>'cus_live','subscription'=>'sub_live','status'=>'paid','amount_paid'=>399,'currency'=>'ron','created'=>$start,'billing_reason'=>'subscription_create','status_transitions'=>['paid_at'=>$start],'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>$livePrice],'quantity'=>1,'period'=>['start'=>$start,'end'=>$end]]]]];
$http->invoices['live'][$invoice['id']]=$invoice;
$http->subscriptions['live']['sub_live']=['id'=>'sub_live','object'=>'subscription','livemode'=>true,'customer'=>'cus_live','status'=>'active','metadata'=>['user_id'=>$liveId,'plan'=>'120gb','billing_interval'=>'month','stripe_mode'=>'live'],'items'=>['data'=>[['id'=>'si_live','quantity'=>1,'price'=>$http->catalogs['live']->prices[$livePrice],'current_period_end'=>$end]]],'latest_invoice'=>$invoice];
$settings->activateStripe('test',$admin);$billing=new Zion\StripeService($db,$plans,$mail,$store);
$send=function(array $event,string $secret)use($billing){$payload=json_encode($event);$timestamp=time();$billing->receive($payload,'t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$payload,$secret));};
$event=['id'=>'evt_live_paid','object'=>'event','livemode'=>true,'type'=>'invoice.paid','data'=>['object'=>$invoice]];
try{$send($event,'whsec_testFixture');check(false,'TEST signed a LIVE event');}catch(Stripe\Exception\SignatureVerificationException $e){check(true,'TEST signing secret cannot authenticate LIVE events');}
$send($event,'whsec_liveFixture');
check($db->one('SELECT stripe_mode FROM subscriptions WHERE user_id=?',[$liveId])['stripe_mode']==='live' && $db->one('SELECT stripe_mode FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoice['id']])['stripe_mode']==='live','LIVE webhook stays in LIVE while public mode is TEST');
check((new Zion\StripeIntegration($db,$plans,null,'live'))->state()['webhook']['event_type']==='invoice.paid' && (new Zion\StripeIntegration($db,$plans,null,'test'))->state()['webhook']===null,'webhook verification status is separated per mode');
$changeService=$billing->changes();$changeService->checkout($db->one('SELECT * FROM users WHERE id=?',[$liveId]),'250gb');$change=$db->one('SELECT * FROM plan_changes WHERE user_id=?',[$liveId]);
check($change['stripe_mode']==='live' && $change['amount']===320 && str_contains($change['checkout_session_id'],'cs_live_'),'LIVE upgrade uses LIVE key even when public mode is TEST');
$session=$http->pay('live',$change['checkout_session_id']);$http->loseUpdate=true;
try{$send(['id'=>'evt_live_upgrade','object'=>'event','livemode'=>true,'type'=>'checkout.session.completed','data'=>['object'=>$session]],'whsec_liveFixture');check(false,'lost update response');}catch(RuntimeException $e){check(true,'LIVE response loss is retained for retry');}
$changeService->retry($change['id']);
check($db->one('SELECT status FROM plan_changes WHERE id=?',[$change['id']])['status']==='completed' && $http->subscriptions['live']['sub_live']['items']['data'][0]['price']['unit_amount']===719,'retry reconciles LIVE payment without a duplicate TEST or LIVE charge');
check(str_ends_with($billing->portal($db->one('SELECT * FROM users WHERE id=?',[$liveId])),'live'),'billing portal follows subscription mode rather than public mode');
$changeService->adminPlan($liveId,'500gb',$admin,'Mode fixture upgrade');
check($http->subscriptions['live']['sub_live']['items']['data'][0]['price']['unit_amount']===1199 && $db->one('SELECT renewal_at FROM subscriptions WHERE user_id=?',[$liveId])['renewal_at']===$end,'admin plan change keeps LIVE mode and original renewal');
$testMetrics=(new Zion\AdminDashboard($db))->snapshot();
check($testMetrics['stripe_mode']==='test' && $testMetrics['revenue']===[],'TEST dashboard excludes LIVE receipts');
$settings->activateStripe('live',$admin);$liveMetrics=(new Zion\AdminDashboard($db))->snapshot();
check($liveMetrics['revenue'][0]['amount']===719,'LIVE dashboard includes original payment and paid upgrade once');
$settings->save('stripe',['credential_mode'=>'test','secret_key'=>'sk_test_rotated'],$admin);
check((new Zion\StripeIntegration($db,$plans,null,'test'))->state()['connection']===null && (new Zion\StripeIntegration($db,$plans,null,'live'))->state()['connection']['status']==='ok','credential rotation invalidates only that mode diagnostics');
$spoof=$accounts->register(['name'=>'Mode fixture','email'=>'mode-spoof@example.com','password'=>'mode-fixture-password','locale'=>'en','plan'=>'free','stripe_mode'=>'test']);
check($db->one('SELECT stripe_mode FROM users WHERE id=?',[$spoof])['stripe_mode']==='live','registration mode is selected by server configuration rather than client input');
$fresh=new Zion\Database(':memory:');$fresh->migrate();testFx($fresh);$fresh->run("INSERT INTO administrators(id,email,password_hash,created_at) VALUES('fixture','legacy-mode@example.com','fixture',1)");
$fresh->run('INSERT INTO integration_settings(key,value) VALUES(?,?)',['provider:stripe',Zion\EncryptedPayload::encode(['secret_key'=>'sk_test_fake','webhook_secret'=>'whsec_legacy'])]);
$freshSettings=new Zion\IntegrationSettings($fresh);
check($freshSettings->stripe('test')['secret_key']==='sk_test_fake' && $freshSettings->stripe()['mode']==='test','legacy single-profile credentials remain in TEST without activation of LIVE');
$fresh->run("DELETE FROM integration_settings WHERE key='provider:stripe'");
$freshSettings->save('stripe',['credential_mode'=>'live','secret_key'=>'sk_live_fake','webhook_secret'=>'whsec_liveFixture'],'fixture');
check($freshSettings->stripe('test')['secret_key']==='' && $freshSettings->stripe('live')['secret_key']==='sk_live_fake','first saved LIVE profile never imports environment TEST credentials');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
echo "\n$count total assertions passed, including automatic Stripe synchronization and TEST/LIVE isolation.\n";
