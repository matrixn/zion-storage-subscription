<?php
$ndb=new Zion\Database(':memory:');$ndb->migrate();
$rate=[Zion\BnrRates::date(),53285,time(),Zion\BnrRates::SOURCE,'fixture'];
$ndb->run('INSERT INTO exchange_rates VALUES(?,?,?,?,?)',$rate);
$np=new Zion\Plans($ndb);$n=$np->get('120gb');
check($n['monthly_price']===2126 && $n['eur_amount']===399 && !$n['fx_managed'],'fresh catalog uses native RON with EUR derived by division');
check($np->get('120gb','year')['price_amount']===23386,'native RON annual price is exactly eleven rounded monthly RON amounts');
check(Zion\PlanDisplay::label($n,'ro','month')==='21,26 RON' && Zion\PlanDisplay::label($n,'ro','month',true)==='≈ 3,99 EUR','Romanian pricing places RON first and converted EUR second');
foreach(['en','fr','de'] as $lang)check(str_contains(Zion\PlanDisplay::label($n,$lang,'month'),'EUR') && str_contains(Zion\PlanDisplay::label($n,$lang,'month',true),'RON'),'foreign pricing places converted EUR first and source RON second: '.$lang);
$sig=Zion\BnrRates::signature($n,'native-user');$offer=['plan'=>$np->get('250gb'),'eur_amount'=>320,'base_amount'=>1705,'amount'=>1705];$upgradeSig=Zion\SubscriptionChanges::quoteSignature($offer,'native-user');
$ndb->run('UPDATE exchange_rates SET eur_ron=60000');
$offer['plan']=$np->get('250gb');$offer['eur_amount']=284;check(hash_equals($upgradeSig,Zion\SubscriptionChanges::quoteSignature($offer,'native-user')),'fixed RON upgrade quote excludes informational EUR fluctuations');
check($np->get('120gb')['eur_amount']===354 && $np->get('120gb')['price_amount']===2126 && hash_equals($sig,Zion\BnrRates::signature($np->get('120gb'),'native-user')),'FX refresh changes the EUR display but neither charge nor confirmed RON quote');
$ndb->run("INSERT INTO administrators(id,email,password_hash,created_at) VALUES('native-admin','native-admin@example.test','fixture',?)",[time()]);
$http=new class implements Stripe\HttpClient\ClientInterface {
 public CatalogHttp $catalog;public array $checkouts=[];
 public function __construct(){$this->catalog=new CatalogHttp();}
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  if(str_ends_with(parse_url($url,PHP_URL_PATH),'/checkout/sessions')){$this->checkouts[]=$params;return [json_encode(['id'=>'cs_native','object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/cs_native','expires_at'=>time()+1860]),200,[]];}
  return $this->catalog->request($method,$url,$headers,$params,$hasFile,$apiMode,$maxNetworkRetries);
 }
};
Stripe\ApiRequestor::setHttpClient($http);$catalog=new Zion\BillingCatalog($ndb,$np,new Stripe\StripeClient((new Zion\IntegrationSettings($ndb))->stripe()['secret_key']));
$catalog->synchronize('120gb','month','native-admin');$price=$np->get('120gb')['stripe_price_id'];
$ndb->run('UPDATE exchange_rates SET eur_ron=53285');$catalog->synchronize('120gb','month','native-admin');
check($np->get('120gb')['stripe_price_id']===$price && $http->catalog->created===1,'FX updates never replace fixed RON Stripe Prices');
$ndb->run('DELETE FROM exchange_rates');
check($np->get('120gb')['eur_amount']===null && $np->get('120gb')['price_amount']===2126 && Zion\PlanDisplay::label($np->get('120gb'),'ro','month')==='21,26 RON','unavailable BNR hides only indicative EUR, preserving RON prices');
$mail=new Zion\MailerService($ndb);$storage=new TestStorage();$account=new Zion\AccountService($ndb,$np,$mail,$storage);
$uid=$account->register(['name'=>'Native RON','email'=>'native-ron@example.test','password'=>'native-ron-password','locale'=>'ro','plan'=>'120gb']);$ndb->run('UPDATE users SET email_verified_at=? WHERE id=?',[time(),$uid]);
(new Zion\BillingProfileService($ndb))->save($uid,['type'=>'individual','name'=>'Native RON','billing_email'=>'native-ron@example.test','country'=>'RO','county'=>'VL','city'=>'Ramnicu Valcea','address'=>'Fixture street 1','postal_code'=>'240001']);
$stripe=new Zion\StripeService($ndb,$np,$mail,$storage);$user=$ndb->one('SELECT * FROM users WHERE id=?',[$uid]);
$stripe->checkout($user,Zion\BnrRates::signature($np->get('120gb'),$uid));
check(count($http->checkouts)===1 && $http->checkouts[0]['adaptive_pricing']['enabled']==='false' && $http->checkouts[0]['subscription_data']['metadata']['zion_fx_policy']==='ron_fixed_v1' && $http->checkouts[0]['line_items'][0]['price']===$price,'Checkout remains available in RON during a BNR outage and does not enable FX draft renewal management');
$paidInvoice=['id'=>'in_native','livemode'=>false,'customer'=>'cus_native','subscription'=>'sub_native','status'=>'paid','amount_paid'=>2126,'currency'=>'ron','billing_reason'=>'subscription_create','created'=>time(),'status_transitions'=>['paid_at'=>time()],'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>$price],'quantity'=>1,'period'=>['start'=>time(),'end'=>time()+30*86400]]]]];
$paidService=new TestStripe($ndb,$np,$mail,$storage);$paidService->invoices['in_native']=$paidInvoice;
$paidService->snapshot=['id'=>'sub_native','livemode'=>false,'customer'=>'cus_native','status'=>'active','metadata'=>['user_id'=>$uid,'plan'=>'120gb','zion_fx_policy'=>'ron_fixed_v1','zion_ron_amount'=>'2126'],'items'=>['data'=>[['price'=>['id'=>$price],'current_period_end'=>time()+30*86400]]],'latest_invoice'=>$paidInvoice];
$paidService->process(['id'=>'evt_native_paid','type'=>'invoice.paid','data'=>['object'=>$paidInvoice]]);
check(!$ndb->one('SELECT * FROM managed_ron_subscriptions'),'paid fixed RON subscription is not paused or enrolled in the historical FX worker');
$fiscal=new Zion\OblioInvoiceService($ndb,$mail);$fiscalId=$ndb->one("SELECT id FROM fiscal_invoices WHERE stripe_invoice_id='in_native'")['id'];$fiscal->process($fiscalId);
$document=Zion\EncryptedPayload::decode($ndb->one('SELECT request_snapshot FROM fiscal_invoices WHERE id=?',[$fiscalId])['request_snapshot']);
check($document['currency']==='RON' && $document['products'][0]['price']===21.26,'Oblio invoice products use the actual fixed RON amount without round-trip EUR conversion');
$history=$ndb->run('SELECT * FROM billing_prices')->fetchAll();
$ndb->run('INSERT INTO exchange_rates VALUES(?,?,?,?,?)',$rate);
$ndb->run("DELETE FROM integration_settings WHERE key='catalog:ron-primary-v1'");
$ndb->run("INSERT INTO plan_settings(slug,name,monthly_price,storage_bytes,updated_at,price_currency) VALUES('120gb','Personal Historic',549,150000000000,?,'eur')",[time()]);
Zion\CatalogCurrencyMigration::run($ndb);
$converted=$np->get('120gb');
check($converted['monthly_price']===2925 && $converted['storage_bytes']===150_000_000_000 && $converted['name']==='Personal Historic','migration converts saved EUR prices once while preserving names and capacities');
check($ndb->run('SELECT * FROM billing_prices')->fetchAll()===$history,'catalog migration never purges or rewrites historical Stripe contracts');
$ndb->migrate();check($np->get('120gb')['monthly_price']===2925,'repeated migration never reconverts an already native RON amount');
$meta=json_decode($ndb->one("SELECT value FROM integration_settings WHERE key='catalog:ron-primary-v1'")['value'],true);
check($meta['eur_ron']===53285 && $meta['previous_settings'][0]['monthly_price']===549,'migration records the conversion rate and pre-migration values');
$retryDb=new Zion\Database(':memory:');$retryDb->migrate();
$retryDb->run("DELETE FROM integration_settings WHERE key='catalog:ron-primary-v1'");
$retryDb->run("INSERT INTO plan_settings(slug,name,monthly_price,updated_at,price_currency) VALUES('120gb','Pending migration',499,?,'eur')",[time()]);
try{Zion\CatalogCurrencyMigration::run($retryDb);check(false,'unrated migration accepted');}catch(DomainException $e){check($retryDb->one('SELECT monthly_price FROM plan_settings')['monthly_price']===499 && !$retryDb->one("SELECT key FROM integration_settings WHERE key='catalog:ron-primary-v1'"),'missing rate blocks migration without altering saved prices or marking completion');}
$retryDb->run('INSERT INTO exchange_rates VALUES(?,?,?,?,?)',$rate);Zion\CatalogCurrencyMigration::run($retryDb);
check((new Zion\Plans($retryDb))->get('120gb')['monthly_price']===2659,'failed migration resumes with a valid rate and converts only once');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
echo "\n$count total assertions including RON-native catalog migration and display.\n";
