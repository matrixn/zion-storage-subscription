<?php
// Full SDK + webhook fixtures; all unexpected HTTP is rejected.
class ChangesHttp implements Stripe\HttpClient\ClientInterface {
 public array $calls=[];public array $subscription=[];public array $invoices=[];public array $sessions=[];public int $updates=0;public bool $loseUpdate=false;
 public function price(string $id): array {$year=str_ends_with($id,'_year');$base=$year?substr($id,0,-5):$id;return ['id'=>$id,'object'=>'price','active'=>true,'livemode'=>false,'product'=>'prod_fixture','currency'=>'ron','unit_amount'=>['price_120'=>399,'price_250'=>719,'price_500'=>1199][$base]*($year?11:1),'recurring'=>['interval'=>$year?'year':'month','interval_count'=>1]];}
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $path=parse_url($url,PHP_URL_PATH);$this->calls[]=[$method,$path,$params];$r=null;
  if(preg_match('~/prices/(price_\d+(?:_year)?)$~',$path,$m))$r=$this->price($m[1]);
  elseif(preg_match('~/subscriptions/[^/]+$~',$path)){
   if(strtolower($method)==='post' && isset($params['pause_collection']) && !isset($params['items'])){$this->subscription['pause_collection']=$params['pause_collection'];return [json_encode($this->subscription),200,[]];}
   if(strtolower($method)==='post'){
    check($params['proration_behavior']==='none' && $params['billing_cycle_anchor']==='unchanged','upgrade never prorates or resets renewal');
    $this->updates++;if(array_key_exists('pause_collection',$params))$this->subscription['pause_collection']=$params['pause_collection']?:null;if(array_key_exists('tax_rates',$params['items'][0]))$this->subscription['items']['data'][0]['tax_rates']=$params['items'][0]['tax_rates'];if(array_key_exists('default_tax_rates',$params))$this->subscription['default_tax_rates']=$params['default_tax_rates'];$this->subscription['items']['data'][0]['price']=$this->price($params['items'][0]['price']);
    $this->subscription['metadata']=array_replace($this->subscription['metadata'],$params['metadata']);
    if($this->loseUpdate){$this->loseUpdate=false;throw new RuntimeException('fixture response lost');}
   }
   $r=$this->subscription;
  }
  elseif(preg_match('~/invoices/([^/]+)$~',$path,$m))$r=$this->invoices[$m[1]]??null;
  elseif(str_ends_with($path,'/checkout/sessions') && strtolower($method)==='post'){
   $id='cs_upgrade_'.(count($this->sessions)+1);$r=['id'=>$id,'object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/'.$id,'status'=>'open','payment_status'=>'unpaid','livemode'=>false,'currency'=>'ron','customer'=>$params['customer'],'amount_total'=>$params['line_items'][0]['price_data']['unit_amount'],'expires_at'=>$params['expires_at'],'metadata'=>$params['metadata']];$this->sessions[$id]=$r;
  }
  elseif(preg_match('~/checkout/sessions/([^/]+)(/expire)?$~',$path,$m)){
   if(isset($m[2]))$this->sessions[$m[1]]['status']='expired';$r=$this->sessions[$m[1]];
  }
  if(!$r)throw new RuntimeException('Unexpected fixture request: '.$method.' '.$path);
  return [json_encode($r),200,[]];
 }
 public function pay(string $sessionId,int $paidAt): array {
  $s=&$this->sessions[$sessionId];$s['status']='complete';$s['payment_status']='paid';$id='in_'.$sessionId;
  $s['payment_intent']=['id'=>'pi_'.$sessionId,'status'=>'succeeded','amount_received'=>$s['amount_total'],'metadata'=>$s['metadata'],'latest_charge'=>['id'=>'ch_'.$sessionId,'paid'=>true,'created'=>$paidAt]];
  $s['invoice']=['id'=>$id,'object'=>'invoice','livemode'=>false,'customer'=>$s['customer'],'status'=>'paid','amount_paid'=>$s['amount_total'],'currency'=>'ron','metadata'=>$s['metadata'],'status_transitions'=>['paid_at'=>$paidAt],'created'=>$paidAt,'payment_intent'=>$s['payment_intent']['id'],'lines'=>['data'=>[['quantity'=>1,'period'=>['start'=>$paidAt,'end'=>$paidAt]]],'has_more'=>false]];
  $this->invoices[$id]=$s['invoice'];return $s;
 }
}
$_ENV['STRIPE_PRICE_250GB']='price_250';$_ENV['STRIPE_PRICE_500GB']='price_500';$_ENV['OBLIO_ENABLED']='false';
$db=new Zion\Database(':memory:');$db->migrate();testFx($db);$db->migrate();testFx($db);seedCatalog($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();$accounts=new Zion\AccountService($db,$plans,$mail,$store);
$_ENV['ADMIN_EMAIL']='admin-fixture@example.com';$_ENV['ADMIN_PASSWORD']='fixture-admin-password';
check(Zion\AccountControl::seedAdmin($db),'installation seeds administrator');
$admin=$db->one('SELECT * FROM administrators');check(password_verify('fixture-admin-password',$admin['password_hash']),'admin password hashed');
$_ENV['ADMIN_PASSWORD']='changed-fixture-password';Zion\AccountControl::seedAdmin($db);
check($db->one('SELECT password_hash FROM administrators')['password_hash']===$admin['password_hash'],'repeated install never overwrites existing admin password');
$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
$http=new ChangesHttp();Stripe\ApiRequestor::setHttpClient($http);
[$id,$token]=signup('upgrades@example.com','120gb','ro');$accounts->verify($token);$end=time()+29*86400;$bought=time()-86400;
$invoice=['id'=>'in_original_upgrade','object'=>'invoice','livemode'=>false,'customer'=>'cus_upgrade','subscription'=>'sub_upgrade','status'=>'paid','amount_paid'=>399,'currency'=>'ron','billing_reason'=>'subscription_create','status_transitions'=>['paid_at'=>$bought],'created'=>$bought,'lines'=>['data'=>[['price'=>['id'=>'price_120'],'quantity'=>1,'period'=>['start'=>$bought,'end'=>$end]]],'has_more'=>false]];
$http->invoices[$invoice['id']]=$invoice;
$http->subscription=['id'=>'sub_upgrade','object'=>'subscription','customer'=>'cus_upgrade','status'=>'active','metadata'=>['user_id'=>$id,'plan'=>'120gb'],'items'=>['data'=>[['id'=>'si_upgrade','quantity'=>1,'price'=>$http->price('price_120'),'current_period_end'=>$end]]],'latest_invoice'=>$invoice];
$billing=new Zion\StripeService($db,$plans,$mail,$store);$changes=$billing->changes();
$billing->process(['id'=>'evt_initial_upgrade','type'=>'invoice.paid','data'=>['object'=>$invoice]]);
$origin=$db->one('SELECT * FROM subscription_origins');check($origin['purchased_at']===$bought,'window starts at original successful payment, not webhook delivery');
$u=$db->one('SELECT * FROM users WHERE id=?',[$id]);$offers=$changes->offers($u);
check(array_column($offers,'amount')===[320,800],'120GB to 250GB costs 3.20 EUR; only larger plans offered');
check($changes->offers($u,$bought+5*86400+1)===[],'five-day window expires without renewal reset');
$db->run('UPDATE subscription_origins SET purchased_at=?',[time()-5*86400+60]);
try{$changes->checkout($u,'250gb');check(false,'closing window');}catch(DomainException $e){check($e->getMessage()==='upgrade.closing','last 30 minutes cannot create a Checkout extending beyond deadline');}
$db->run('UPDATE subscription_origins SET purchased_at=?',[$bought]);
$url=$changes->checkout($u,'250gb');$r=$db->one('SELECT * FROM plan_changes');
$create=array_values(array_filter($http->calls,fn($c)=>$c[1]==='/v1/checkout/sessions'))[0][2];
check($create['mode']==='payment' && $create['line_items'][0]['price_data']['unit_amount']===320,'upgrade is one-time difference payment');
check($create['invoice_creation']['enabled'] && $create['customer']==='cus_upgrade','upgrade creates a separate invoice for existing customer');
check($create['expires_at']<=$bought+5*86400,'checkout cannot outlive upgrade window');
check($changes->checkout($u,'250gb')===$url && count($http->sessions)===1,'duplicate tabs reuse same upgrade checkout');
check($db->one('SELECT selected_plan_id FROM users WHERE id=?',[$id])['selected_plan_id']==='120gb','browser checkout does not grant upgrade');
try{$changes->checkout($u,'500gb');check(false,'concurrent target');}catch(DomainException $e){check(true,'second pending upgrade blocked');}
$session=$http->pay($r['checkout_session_id'],time());
$event=['id'=>'evt_upgrade_paid','type'=>'checkout.session.completed','data'=>['object'=>$session]];
foreach(['amount','customer','currency','live','unconfirmed'] as $case){
 $bad=$session;
 if($case==='amount')$bad['payment_intent']['amount_received']++;
 if($case==='customer')$bad['customer']='cus_foreign';
 if($case==='currency')$bad['invoice']['currency']='usd';
 if($case==='live')$bad['livemode']=true;
 if($case==='unconfirmed')$bad['payment_intent']['status']='processing';
 $http->sessions[$r['checkout_session_id']]=$bad;
 try{$badEvent=$event;$badEvent['id']='evt_invalid_'.$case;$billing->process($badEvent);check(false,'invalid payment '.$case);}catch(RuntimeException $e){check(true,'reject invalid upgrade payment: '.$case);}
 check($db->one('SELECT selected_plan_id FROM users WHERE id=?',[$id])['selected_plan_id']==='120gb','invalid payment grants no larger quota: '.$case);
}
$http->sessions[$r['checkout_session_id']]=$session;
$billing->process($event);
check($db->one('SELECT status FROM plan_changes WHERE id=?',[$r['id']])['status']==='completed','authoritative payment applies upgrade');
check($db->one('SELECT selected_plan_id FROM users WHERE id=?',[$id])['selected_plan_id']==='250gb','paid upgrade changes account plan');
check($http->subscription['items']['data'][0]['price']['unit_amount']===719 && $http->subscription['items']['data'][0]['current_period_end']===$end,'next renewal is 7.19 at original date');
$event['id']='evt_upgrade_replay';$billing->process($event);
check($http->updates===1,'duplicate webhook cannot repeat Stripe price mutation');
try{$changes->cancel($r['id']);check(false,'paid cancellation');}catch(DomainException $e){check(true,'confirmed upgrade cannot be cancelled as unpaid');}
$fi=$db->one('SELECT * FROM fiscal_invoices WHERE stripe_invoice_id=?',[$session['invoice']['id']]);
check($fi['amount']===320 && $fi['billing_period_end']===$end,'upgrade fiscal amount is actual 3.20 EUR and covers remaining original cycle');
$fiscal=new Zion\OblioInvoiceService($db,$mail);check($fiscal->process($fi['id']),'upgrade fiscal simulation processes');
$fi=$db->one('SELECT * FROM fiscal_invoices WHERE id=?',[$fi['id']]);$payload=Zion\EncryptedPayload::decode($fi['request_snapshot']);
check($payload['products'][0]['price']===3.2 && str_starts_with($payload['products'][0]['name'],'Upgrade Zion Storage'),'upgrade fiscal product is distinct from monthly subscription');
check($db->one('SELECT purchased_at FROM subscription_origins')['purchased_at']===$bought,'upgrade never opens a new five-day window');
// Crash recovery: price changed remotely, response lost before local synchronization.
$u=$db->one('SELECT * FROM users WHERE id=?',[$id]);$changes->checkout($u,'500gb');$r2=$db->one("SELECT * FROM plan_changes WHERE to_plan='500gb'");$session2=$http->pay($r2['checkout_session_id'],time());
$http->loseUpdate=true;
try{$billing->process(['id'=>'evt_response_lost','type'=>'checkout.session.completed','data'=>['object'=>$session2]]);check(false,'lost response');}catch(RuntimeException $e){check(true,'lost Stripe response remains retryable');}
check($db->one('SELECT status FROM plan_changes WHERE id=?',[$r2['id']])['status']==='retry','confirmed payment retained for retry');
$billing->process(['id'=>'evt_reconcile','type'=>'customer.subscription.updated','data'=>['object'=>$http->subscription]]);
check($db->one('SELECT status FROM plan_changes WHERE id=?',[$r2['id']])['status']==='completed' && $http->updates===2,'subscription webhook reconciles accepted change without charging again');
// Ban and manual management are separate from Stripe payment state.
$accounts->provision($id,'a-long-test-password');$adminService=new Zion\AdminService($db,$plans,$mail,$store,$changes);
$adminService->ban($id,true,'Fixture ban',$admin['id']);
check(!$accounts->eligible($db->one('SELECT * FROM users WHERE id=?',[$id])),'ban blocks account activation');
$billing->process(['id'=>'evt_paid_while_banned','type'=>'customer.subscription.updated','data'=>['object'=>$http->subscription]]);
check($db->one('SELECT status FROM users WHERE id=?',[$id])['status']==='banned','billing webhook cannot unban a user');
check($db->one('SELECT paid_confirmed FROM subscriptions WHERE user_id=?',[$id])['paid_confirmed']===1,'ban preserves paid subscription');
$adminService->ban($id,false,'Fixture unban',$admin['id']);check($accounts->eligible($db->one('SELECT * FROM users WHERE id=?',[$id])),'unban restores eligible account');
$changes->adminPlan($id,'250gb',$admin['id'],'Fixture downgrade');
check($http->subscription['items']['data'][0]['price']['unit_amount']===719,'admin downgrade synchronizes future Stripe renewal');
$store->users['store-'.$id]['used']=130_000_000_000;
try{$changes->adminPlan($id,'120gb',$admin['id'],'Too much data');check(false,'unsafe downgrade');}catch(DomainException $e){check(true,'downgrade cannot put existing files above target quota');}
$manual=$adminService->create(['name'=>'Manual client','email'=>'manual@example.com','password'=>'manual-client-password','plan'=>'250gb','locale'=>'ro'],$admin['id']);
$mu=$db->one('SELECT * FROM users WHERE id=?',[$manual]);check(!$accounts->eligible($mu) && !$mu['terms_at'],'admin-created customer must verify and accept terms');
check(!$db->one('SELECT * FROM subscriptions WHERE user_id=?',[$manual]),'manual plan does not fabricate Stripe payments');
$db->run('UPDATE users SET email_verified_at=?,terms_at=?,privacy_at=? WHERE id=?',[time(),time(),time(),$manual]);
$mu=$db->one('SELECT * FROM users WHERE id=?',[$manual]);check($accounts->eligible($mu),'verified manual grant enables selected plan');
$changes->adminPlan($manual,'free',$admin['id'],'Manual downgrade');check((new Zion\AccountControl($db))->manual($manual)==='free','manual downgrade works independently of Stripe');
$accounts->provision($manual,'manual-client-password');$store->failQuota=true;
try{$changes->adminPlan($manual,'120gb',$admin['id'],'Quota outage');check(false,'quota failure');}catch(RuntimeException $e){check(true,'manual quota failure remains recoverable');}
check((new Zion\AccountControl($db))->get($manual)['quota_sync']==='pending','failed quota synchronization is durably pending');
$store->failQuota=false;$adminService->syncQuota($manual,$admin['id']);
check((new Zion\AccountControl($db))->get($manual)['quota_sync']==='ready' && $store->users['store-'.$manual]['quota']===120_000_000_000,'admin retries manual quota without changing plan again');
check(!str_contains(json_encode($db->run('SELECT * FROM admin_audit')->fetchAll()),'manual-client-password'),'admin audit never stores plaintext password');
final class GatewayFixture extends Zion\StorageGateway {public ?string $identityId=null;protected function identity(array $headers):?string{return $this->identityId;}}
$gate=new GatewayFixture($db);$gate->identityId='store-'.$id;$adminService->ban($id,true,'Gateway test',$admin['id']);
check(!$gate->allowed('/api/assets/123',[]),'gateway denies banned user with an existing session');
check(!$gate->allowed('/api/assets/123',[]) && !$gate->allowed('/api/shared-links/123',[]),'ban denies files and shared links');
$gate->identityId=null;check(!$gate->allowed('/api/assets/123',[]) && $gate->allowed('/api/auth/login',[]),'gateway fails closed without identity while allowing login bootstrap');
// Even an exceptional late successful payment stays recorded, without granting space.
[$lateId,$lateToken]=signup('late-upgrade@example.com','120gb');$accounts->verify($lateToken);
$lateInitial=$invoice;$lateInitial['id']='in_late_initial';$lateInitial['customer']='cus_late';$lateInitial['subscription']='sub_late';$lateInitial['billing_reason']='subscription_create';
$http->subscription=['id'=>'sub_late','object'=>'subscription','customer'=>'cus_late','status'=>'active','metadata'=>['user_id'=>$lateId,'plan'=>'120gb'],'items'=>['data'=>[['id'=>'si_late','quantity'=>1,'price'=>$http->price('price_120'),'current_period_end'=>$end]]],'latest_invoice'=>$lateInitial];$http->invoices[$lateInitial['id']]=$lateInitial;
$billing->process(['id'=>'evt_late_initial','type'=>'invoice.paid','data'=>['object'=>$lateInitial]]);
$changes->checkout($db->one('SELECT * FROM users WHERE id=?',[$lateId]),'250gb');$lateChange=$db->one('SELECT * FROM plan_changes WHERE user_id=?',[$lateId]);
$lateSession=$http->pay($lateChange['checkout_session_id'],$lateChange['deadline']+1);
try{$billing->process(['id'=>'evt_late_upgrade','type'=>'checkout.session.completed','data'=>['object'=>$lateSession]]);check(false,'late paid upgrade');}catch(RuntimeException $e){check(true,'paid upgrade outside deadline needs operator review');}
$lateSaved=$changes->record($lateChange['id']);check($lateSaved['status']==='review' && $lateSaved['paid_at']===$lateChange['deadline']+1,'late payment remains confirmed in audit without pretending it is unpaid');
check($db->one('SELECT selected_plan_id FROM users WHERE id=?',[$lateId])['selected_plan_id']==='120gb','late payment cannot grant larger plan');
check($db->one('SELECT amount FROM fiscal_invoices WHERE stripe_invoice_id=?',[$lateSession['invoice']['id']])['amount']===320,'late confirmed funds retain durable fiscal record for manual correction');
// A new catalog price must not break or reprice an existing Plus subscription.
$db->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,status,created_at,updated_at) VALUES('price_250_1999','250gb','month','ron',1999,'legacy',1,1)");
[$legacyId,$legacyToken]=signup('legacy-plus@example.com','250gb');$accounts->verify($legacyToken);
$legacyInvoice=$invoice;$legacyInvoice['id']='in_legacy_plus';$legacyInvoice['customer']='cus_legacy';$legacyInvoice['subscription']='sub_legacy';$legacyInvoice['amount_paid']=1999;$legacyInvoice['currency']='ron';$legacyInvoice['lines']['data'][0]['price']['id']='price_250_1999';
$legacyBilling=new TestStripe($db,$plans,$mail,$store);
$legacyBilling->snapshot=['id'=>'sub_legacy','customer'=>'cus_legacy','status'=>'active','metadata'=>['user_id'=>$legacyId,'plan'=>'250gb'],'items'=>['data'=>[['price'=>['id'=>'price_250_1999'],'current_period_end'=>$end]]],'latest_invoice'=>$legacyInvoice];
$legacyBilling->invoices[$legacyInvoice['id']]=$legacyInvoice;
$legacyBilling->process(['id'=>'evt_legacy_plus','type'=>'invoice.paid','data'=>['object'=>$legacyInvoice]]);
check($db->one('SELECT paid_confirmed FROM subscriptions WHERE user_id=?',[$legacyId])['paid_confirmed']===1,'legacy Plus payment remains eligible after catalog price change');
$legacyFiscal=$db->one('SELECT plan,amount FROM fiscal_invoices WHERE stripe_invoice_id=?',[$legacyInvoice['id']]);
check($legacyFiscal['plan']==='250gb' && $legacyFiscal['amount']===1999,'legacy fiscal record retains actual amount and original plan');
check($db->one('SELECT stripe_price_id FROM subscriptions WHERE user_id=?',[$legacyId])['stripe_price_id']==='price_250_1999' && $plans->get('250gb')['stripe_price_id']==='price_250','legacy ID stays separate from new Checkout Price');
echo "\n$count total assertions passed, including account management and upgrade recovery.\n";
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
