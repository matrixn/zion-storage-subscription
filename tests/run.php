<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$_ENV['APP_ENV']='test';$_ENV['MAIL_TRANSPORT']='log';$_ENV['STRIPE_SECRET_KEY']='sk_test_fake';$_ENV['STRIPE_WEBHOOK_SECRET']='whsec_fixture';
$_ENV['STRIPE_PRICE_120GB']='price_120'; $_ENV['APP_URL']='http://localhost:8088';
$_ENV['APP_KEY']=str_repeat('test-key',8);
$_ENV['OBLIO_ENABLED']='false';
$_ENV['MAIL_LOG_DIRECTORY']=dirname(__DIR__).'/storage/test-mail';
final class NoNetworkStripe implements Stripe\HttpClient\ClientInterface {
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null) { throw new RuntimeException('Unexpected network call in tests'); }
}
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
final class TestStorage extends Zion\StorageProvisioningService {
 public int $created=0;public array $users=[];public bool $fail=false;public int $disabled=0;public bool $failQuota=false;
 public function createUser(array $u,string $p,int $quota):string{if($this->fail)throw new RuntimeException('offline');$this->created++;$this->users['store-'.$u['id']]=['quota'=>$quota,'used'=>0];return 'store-'.$u['id'];}
 public function enableUser(string $id,int $quota):void{if($this->failQuota)throw new RuntimeException('fixture quota outage');$this->users[$id]['quota']=$quota;}
 public function disableUser(string $id):void{$this->disabled++;}
 public function getUsage(string $id):array{return $this->users[$id];}
}
final class TestStripe extends Zion\StripeService {
 public array $snapshot=[];
 public array $invoices=[];
 protected function fetchSubscription(string $id):array{return $this->snapshot;}
 protected function fetchInvoice(string $id):array{return $this->invoices[$id]??throw new RuntimeException('Missing invoice fixture');}
}
$count=0;
function check(bool $condition,string $name):void {global $count;if(!$condition)throw new RuntimeException('FAIL: '.$name);$count++;echo "PASS ".$name."\n";}
check(Zion\IntegrationSettings::maskedSecret('sk_test_abcdefghijk123456')==='sk_test••••••••123456','saved secret preview shows only seven leading and six trailing characters');
check(Zion\IntegrationSettings::maskedSecret('')==='' && Zion\IntegrationSettings::maskedSecret('short')==='••••••••' && Zion\IntegrationSettings::maskedSecret('1234567890123')==='••••••••','empty and short secrets never reveal a complete credential');
function seedCatalog(Zion\Database $db):void {
 if(env('APP_ENV')!=='test' || $db->one('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Fixture requires empty test database');
 $db->run('DELETE FROM billing_prices');
 foreach(['120gb'=>['price_120',199],'250gb'=>['price_250',359],'500gb'=>['price_500',599]] as $slug=>[$id,$amount])foreach(['month'=>1,'year'=>11] as $interval=>$multiple)$db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,product_id,status,created_at,updated_at) VALUES(?,?,?,'eur',?,'prod_fixture','active',?,?)",[$id.($interval==='year'?'_year':''),$slug,$interval,$amount*$multiple,time(),time()]);
}
$db=new Zion\Database(':memory:');$db->migrate();seedCatalog($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();$accounts=new Zion\AccountService($db,$plans,$mail,$store);
function signup(string $email,string $plan='free',string $locale='en',string $interval='month'):array{
 global $accounts,$db;
 $id=$accounts->register(['name'=>'Test User','email'=>$email,'password'=>'a-long-test-password','locale'=>$locale,'plan'=>$plan,'billing_interval'=>$interval]);
 if($plan!=='free') (new Zion\BillingProfileService($db))->save($id,['type'=>'individual','name'=>'Test User','billing_email'=>$email,'country'=>'RO','county'=>'Bucuresti','city'=>'Bucuresti','address'=>'Strada Test 1','postal_code'=>'010101']);
 $payload=Zion\MailerService::decode($db->one("SELECT payload FROM outbox WHERE recipient=? ORDER BY id DESC",[$email])['payload']);
 parse_str(parse_url($payload['url'],PHP_URL_QUERY),$q);return [$id,$q['token'],$payload['url']];
}
foreach(Zion\TranslationService::LOCALES as $l){
 $t=new Zion\TranslationService($l);$en=new Zion\TranslationService('en');
 check(array_keys($t->messages)===array_keys($en->messages),'translation parity '.$l);
 foreach(['verify','activated','payment','subscription','failed','cancelled','quota','contact'] as $kind)check(strlen($t->get('email.'.$kind.'.body'))>20,$l.' email '.$kind);
 foreach(['home','how','register','contact'] as $p)check(Zion\Router::match(Zion\Router::path($l,$p))===[$l,$p],$l.' route '.$p);
}
check(Zion\TranslationService::preferred('de','ro')==='de','cookie preference');
check(Zion\TranslationService::preferred('','fr;q=0.3,ro-RO;q=0.9,en;q=0.5')==='ro','Accept-Language priorities');
check(Zion\TranslationService::preferred('','es')==='en','English fallback');
check(count($plans->all())===4 && $plans->get('free')['storage_bytes']===10_000_000_000 && $plans->get('1tb')===null && array_column($plans->all(),'monthly_price')===[0,199,359,599],'configured plans and quotas');
check(!$plans->get('tampered'),'reject unknown plan');
check(!Zion\AccountService::passwordValid('short'),'password length');
[$free,$token,$link]=signup('free@example.com','free','ro');
$u=$db->one('SELECT * FROM users WHERE id=?',[$free]);
check(!str_contains(json_encode($u),'a-long-test-password'),'no plaintext password');
check(!$accounts->eligible($u),'unverified free account cannot activate');
check(str_contains($link,'/ro/verificare-email'),'verification preserves selected locale');
check($db->one('SELECT * FROM email_verifications')['token_hash']!==$token,'verification token hashed');
check(!$accounts->verify('bad'),'bad verification token');
check($accounts->verify($token)===$free,'verification works');
check(!$accounts->verify($token),'verification replay rejected');
check(!$accounts->provision($free,'wrong'),'wrong activation password');
check($accounts->provision($free,'a-long-test-password'),'verified free account provisioning');
check($accounts->provision($free,'a-long-test-password') && $store->created===1,'provisioning idempotent');
check($accounts->register(['name'=>'Other','email'=>'FREE@example.com','password'=>'a-long-test-password','locale'=>'en','plan'=>'free'])===null,'case insensitive duplicate protection');
[$expired,$expiredToken]=signup('expired@example.com');$db->run('UPDATE email_verifications SET expires_at=? WHERE user_id=?',[time()-1,$expired]);check(!$accounts->verify($expiredToken),'expired token rejected');
[$retry,$rt]=signup('retry@example.com');$accounts->verify($rt);$store->fail=true;try{$accounts->provision($retry,'a-long-test-password');}catch(Throwable $e){}$store->fail=false;check($accounts->provision($retry,'a-long-test-password'),'failed provisioning retry');
[$paid,$pt]=signup('paid@example.com','120gb');$accounts->verify($pt);
check(!$accounts->provision($paid,'a-long-test-password'),'paid user cannot activate before webhook');
$billing=new TestStripe($db,$plans,$mail,$store);
$billing->snapshot=['id'=>'sub_test','customer'=>'cus_test','status'=>'active','metadata'=>['user_id'=>$paid,'plan'=>'120gb'],'items'=>['data'=>[['price'=>['id'=>'price_120'],'current_period_end'=>time()+86400]]],'latest_invoice'=>['id'=>'in_test','status'=>'paid','hosted_invoice_url'=>'https://invoice.stripe.com/i/test','amount_paid'=>799,'currency'=>'eur','created'=>time()]];
$event=['id'=>'evt_paid','object'=>'event','livemode'=>false,'type'=>'invoice.paid','data'=>['object'=>['id'=>'in_test','subscription'=>'sub_test']]];
$billing->invoices['in_test']=$billing->snapshot['latest_invoice']+['livemode'=>false,'customer'=>'cus_test','subscription'=>'sub_test','lines'=>['data'=>[['price'=>['id'=>'price_120'],'quantity'=>1,'period'=>['start'=>1792108800,'end'=>1794787200]]],'has_more'=>false]];
$raw=json_encode($event);$sig='t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'whsec_fixture');
$billing->receive($raw,$sig);
check($accounts->provision($paid,'a-long-test-password'),'signed webhook permits paid provisioning');
$billing->receive($raw,$sig);check((int)$db->one("SELECT COUNT(*) AS n FROM billing_events WHERE id='evt_paid'")['n']===1,'webhook replay idempotent');
check((int)$db->one("SELECT COUNT(*) AS n FROM outbox WHERE dedupe_key='paid:in_test'")['n']===1,'payment email deduplicated');
check($db->one('SELECT * FROM invoices WHERE user_id=?',[$paid])['id']==='in_test','invoice recorded');
try{$billing->receive($raw,'t='.time().',v1=invalid');check(false,'invalid signature');}catch(Stripe\Exception\SignatureVerificationException $e){check(true,'invalid signature rejected');}
try{$old=time()-1000;$billing->receive($raw,'t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$raw,'whsec_fixture'));check(false,'expired signature');}catch(Stripe\Exception\SignatureVerificationException $e){check(true,'expired webhook signature rejected');}
$billing->snapshot['status']='past_due';$billing->snapshot['latest_invoice']['status']='open';$event['id']='evt_failed';$event['type']='invoice.payment_failed';$billing->process($event);
check($store->disabled===1 && $db->one('SELECT status FROM users WHERE id=?',[$paid])['status']==='restricted','failed payment pauses uploads');
$billing->snapshot['status']='canceled';$event['id']='evt_cancel';$event['type']='customer.subscription.deleted';$event['data']['object']['id']='sub_test';$billing->process($event);
check($db->one('SELECT status FROM subscriptions WHERE user_id=?',[$paid])['status']==='canceled','subscription cancellation stored');
check($db->one('SELECT storage_user_id FROM users WHERE id=?',[$paid])['storage_user_id']!==null,'cancellation retains storage account');
$event['id']='evt_late_paid';$event['type']='invoice.paid';$event['data']['object']=['id'=>'in_test','subscription'=>'sub_test'];$billing->process($event);
check(!$accounts->eligible($db->one('SELECT * FROM users WHERE id=?',[$paid])),'late paid event cannot reactivate canceled subscription');
$billing->snapshot['items']['data'][0]['price']['id']='price_tampered';
try{$event['id']='evt_tamper';$billing->process($event);check(false,'tampered price');}catch(RuntimeException $e){check(true,'server side price match');}
check(Zion\Security::limit($db,'test:ip',2,60),'rate first');check(Zion\Security::limit($db,'test:ip',2,60),'rate second');check(!Zion\Security::limit($db,'test:ip',2,60),'rate limit enforced');
$_SESSION=[];$csrf=Zion\Security::csrf();$_POST=['_csrf'=>$csrf];check(Zion\Security::checkCsrf(),'CSRF valid');$_POST=['_csrf'=>'invalid'];check(!Zion\Security::checkCsrf(),'CSRF invalid');
$_ENV['TRUSTED_PROXIES']='127.0.0.1';$_SERVER['REMOTE_ADDR']='203.0.113.5';$_SERVER['HTTP_X_FORWARDED_FOR']='1.2.3.4';check(Zion\Security::clientIp()==='203.0.113.5','ignore untrusted forwarded IP');
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_X_FORWARDED_FOR']='1.2.3.4, 203.0.113.5';check(Zion\Security::clientIp()==='203.0.113.5','trusted proxy chain excludes spoofed prefix');
echo "\n$count assertions passed. No external service was contacted.\n";
require __DIR__.'/services.php';
require __DIR__.'/fiscal.php';
require __DIR__.'/changes.php';
require __DIR__.'/catalog.php';
require __DIR__.'/integration-settings.php';
require __DIR__.'/admin-dashboard.php';
require __DIR__.'/stripe-modes.php';
