<?php
final class ResetStripeHttp implements Stripe\HttpClient\ClientInterface {
 public array $sessions=[],$customers=[],$subscriptions=[],$calls=[];public bool $loseDelete=false,$raceCheckout=false;
 public int $deletes=0,$sessionReads=0;
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $method=strtolower($method);$path=parse_url($url,PHP_URL_PATH);$this->calls[]=[$method,$path];$response=null;
  foreach($headers as $header)if(str_starts_with($header,'Authorization:'))check(str_contains($header,'sk_test_'),'reset uses TEST credentials only');
  if(preg_match('~/checkout/sessions/([^/]+)(/expire)?$~',$path,$m)){
   if($this->raceCheckout && ++$this->sessionReads===2){$this->sessions[$m[1]]['status']='complete';$this->sessions[$m[1]]['customer']='cus_reset';}
   if(isset($m[2]))$this->sessions[$m[1]]['status']='expired';$response=$this->sessions[$m[1]];
  }elseif(preg_match('~/customers/(.+)$~',$path,$m)){
   if($method==='delete'){$this->deletes++;$this->customers[$m[1]]=['id'=>$m[1],'object'=>'customer','deleted'=>true];foreach($this->subscriptions as &$sub)if($sub['customer']===$m[1])$sub['status']='canceled';unset($sub);if($this->loseDelete){$this->loseDelete=false;throw new Stripe\Exception\ApiConnectionException('fixture lost delete response');}}
   $response=$this->customers[$m[1]];
  }elseif($path==='/v1/subscriptions')$response=['object'=>'list','url'=>'/v1/subscriptions','has_more'=>false,'data'=>array_values(array_filter($this->subscriptions,fn($s)=>$s['customer']===$params['customer']))];
  elseif(preg_match('~/subscriptions/(.+)$~',$path,$m))$response=$this->subscriptions[$m[1]];
  if(!$response)throw new RuntimeException('Unexpected reset fixture transport');
  return [json_encode($response),200,[]];
 }
}
final class ResetImmichFixture extends Zion\StorageProvisioningService {
 public array $remote=[],$calls=[];public bool $loseDelete=false;
 protected function request(string $method,string $path,?array $body=null):array {
  $this->calls[]=[$method,$path,$body];
  if(!$this->remote)throw new Zion\StorageApiException(404);
  if($method==='PUT')$this->remote=array_replace($this->remote,$body);
  if($method==='DELETE'){$this->remote['status']='removing';if($this->loseDelete){$this->loseDelete=false;throw new Zion\StorageApiException(503);}}
  return $this->remote;
 }
}
function resetFixture(): array {
 $db=new Zion\Database(':memory:');$db->migrate();seedCatalog($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new ResetImmichFixture();
 $admin='reset-admin';$db->run('INSERT INTO administrators VALUES(?,?,?,?)',[$admin,'reset-admin@example.com','fixture-hash',time()]);
 $accounts=new Zion\AccountService($db,$plans,$mail,$store);
 $uid=$accounts->register(['name'=>'Reset Client','email'=>'reset-fixture@example.com','password'=>'fixture-reset-password','locale'=>'ro','plan'=>'120gb']);
 $other=$accounts->register(['name'=>'Other Client','email'=>'other-fixture@example.com','password'=>'fixture-reset-password','locale'=>'ro','plan'=>'free']);
 $http=new ResetStripeHttp();Stripe\ApiRequestor::setHttpClient($http);$stripe=new Stripe\StripeClient('sk_test_resetFixture');
 $http->customers['cus_reset']=['id'=>'cus_reset','object'=>'customer','livemode'=>false];
 $http->sessions['cs_test_reset']=['id'=>'cs_test_reset','object'=>'checkout.session','livemode'=>false,'metadata'=>['user_id'=>$uid],'status'=>'complete','customer'=>'cus_reset','subscription'=>'sub_reset'];
 $http->subscriptions['sub_reset']=['id'=>'sub_reset','object'=>'subscription','livemode'=>false,'customer'=>'cus_reset','status'=>'active','metadata'=>['user_id'=>$uid]];
 $db->run("INSERT INTO checkout_sessions(user_id,session_id,url,expires_at) VALUES(?,'cs_test_reset','https://checkout.stripe.com/fixture',?)",[$uid,time()+3600]);
 $changes=new Zion\SubscriptionChanges($db,$plans,$mail,$store,$stripe,new Zion\OblioInvoiceService($db,$mail));
 $reset=new Zion\TestAccountReset($db,$changes,$store,$stripe);
 return [$db,$uid,$other,$http,$store,$reset,$admin,$accounts,$plans,$mail];
}
$previousStripe=Stripe\ApiRequestor::httpClient();
$previousLogPath=$_ENV['API_LOG_PATH']??null;$resetLog=sys_get_temp_dir().'/zion-reset-log-'.bin2hex(random_bytes(8)).'.sqlite';$_ENV['API_LOG_PATH']=$resetLog;
try{
 [$db,$uid,$other,$http,$store,$reset,$admin,$accounts,$plans,$mail]=resetFixture();
 try{$reset->reset($uid,'wrong@example.com',true,false,$admin);check(false,'wrong confirmation');}catch(DomainException $e){check(!$http->calls,'reset requires exact email before external calls');}
 try{$reset->reset($uid,'reset-fixture@example.com',false,false,$admin);check(false,'missing checkbox');}catch(DomainException $e){check(!$http->calls,'reset requires explicit irreversible-deletion confirmation');}
 $db->run("UPDATE users SET stripe_mode='live' WHERE id=?",[$uid]);
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'LIVE reset');}catch(DomainException $e){check(!$http->calls,'LIVE accounts cannot be reset by TEST deletion');}
 $db->run("UPDATE users SET stripe_mode='test' WHERE id=?",[$uid]);
 $db->run("INSERT INTO invoices(id,user_id,amount,currency,created_at,stripe_mode) VALUES('in_live_guard',?,199,'eur',?,'live')",[$uid,time()]);
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'mixed mode reset');}catch(DomainException $e){check(!$http->calls,'mixed TEST LIVE histories cannot be deleted as test data');}
 $db->run('DELETE FROM invoices WHERE user_id=?',[$uid]);
 $store->remote=['id'=>'immich-reset','email'=>'reset-fixture@example.com','storageLabel'=>'zs-'.$uid,'isAdmin'=>false,'status'=>'active'];
 $db->run("UPDATE users SET storage_user_id='immich-reset' WHERE id=?",[$uid]);
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'storage checkbox');}catch(DomainException $e){check(!$http->calls,'Immich file deletion needs separate explicit confirmation');}
 $db->run("INSERT INTO subscriptions(user_id,stripe_customer_id,stripe_subscription_id,status,plan,paid_confirmed) VALUES(?,'cus_reset','sub_reset','active','120gb',1)",[$uid]);
 $db->run("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES('in_reset',?,199,'eur',?)",[$uid,time()]);
 $db->run("INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,amount,currency,plan,status,idempotency_key,source_snapshot,created_at,updated_at) VALUES('fi_reset',?,'in_reset','sub_reset','cus_reset',199,'eur','120gb','issued','fiscal-reset',?,?,?)",[$uid,Zion\EncryptedPayload::encode(['simulated'=>true]),time(),time()]);
 try{$reset->reset($uid,'reset-fixture@example.com',true,true,$admin);check(false,'issued invoice reset');}catch(DomainException $e){check(!$http->calls,'issued fiscal documents block test reset');}
 $db->run("UPDATE fiscal_invoices SET status='simulated' WHERE id='fi_reset'");
 $mail->queue('billing-other@example.com','ro','fiscal_invoice',[],'fiscal:in_reset');
 $store->remote['isAdmin']=true;
 try{$reset->reset($uid,'reset-fixture@example.com',true,true,$admin);check(false,'Immich admin reset');}catch(DomainException $e){check($http->deletes===0,'Immich administrator identity blocks destructive reset');}
 $store->remote['isAdmin']=false;$store->loseDelete=true;
 $db->run("INSERT INTO billing_events(id,type,status,created_at,user_id) VALUES('evt_reset','invoice.paid','complete',?,?)",[time(),$uid]);
 $db->run("INSERT INTO billing_events(id,type,status,created_at) VALUES('evt_reset_legacy','invoice.paid','processing',?)",[time()]);
 Zion\ApiLog::record('stripe','POST','/webhooks/stripe',503,microtime(true),['mode'=>'test','direction'=>'in','context'=>['event_id'=>'evt_reset_legacy','subscription_id'=>'sub_reset']]);
 $db->run("INSERT INTO subscription_origins VALUES('sub_reset',?,?,?,'in_reset')",[$uid,time(),time()+30*86400]);
 $db->run("INSERT INTO notices VALUES(?,'renewal','fixture')",[$uid]);
 try{$reset->reset($uid,'reset-fixture@example.com',true,true,$admin);check(false,'lost Immich response');}catch(DomainException $e){}
 check($db->one('SELECT id FROM users WHERE id=?',[$uid]) && $db->one('SELECT status FROM test_account_resets WHERE user_id=?',[$uid])['status']==='pending' && (new Zion\AccountControl($db))->banned($uid),'partial reset retains local records and blocks the account for resumable cleanup');
 check($http->deletes===1 && $store->remote['status']==='removing' && $store->remote['email']==='reset-'.$uid.'@deleted.invalid','TEST customer is removed and Immich email released before asynchronous file removal');
 $billing=new Zion\StripeService($db,$plans,$mail,$store);
 try{$billing->process(['id'=>'evt_during_reset','type'=>'invoice.paid','livemode'=>false,'data'=>['object'=>['id'=>'in_reset','customer'=>'cus_reset']]]);check(false,'pending reset callback');}catch(RuntimeException $e){check($e->getMessage()==='test_reset_in_progress','webhooks retry while an account reset is incomplete');}
 $reset->reset($uid,'reset-fixture@example.com',true,true,$admin);
 foreach(['users'=>'id','subscriptions'=>'user_id','invoices'=>'user_id','fiscal_invoices'=>'user_id','email_verifications'=>'user_id','checkout_sessions'=>'user_id','billing_events'=>'user_id','notices'=>'user_id','subscription_origins'=>'user_id'] as $table=>$column)check(!$db->one('SELECT 1 FROM '.$table.' WHERE '.$column.'=?',[$uid]),'reset removes local '.$table.' records');
 check(!$db->one("SELECT 1 FROM billing_events WHERE id='evt_reset_legacy'"),'reset removes older unattributed events when journal correlation establishes ownership');
 check(!$db->one("SELECT 1 FROM outbox WHERE dedupe_key='fiscal:in_reset'"),'reset removes associated fiscal notification even when billing email differs');
 check($http->deletes===1 && count(array_filter($store->calls,fn($call)=>$call[0]==='DELETE'))===1,'retry never repeats a confirmed or lost-response remote deletion');
 $before=count($http->calls);$reset->reset($uid,'reset-fixture@example.com',true,true,$admin);
 check(count($http->calls)===$before,'resubmitting an already completed reset is idempotent');
 check($db->one('SELECT id FROM users WHERE id=?',[$other]) && (int)$db->one('SELECT COUNT(*) n FROM billing_prices')['n']===6,'reset preserves other clients and the billing catalog');
 $job=$db->one('SELECT * FROM test_account_resets WHERE user_id=?',[$uid]);check($job['status']==='complete' && $job['snapshot']==='{}','completed reset scrubs its encrypted recovery snapshot');
 $before=count($http->calls);$billing->process(['id'=>'evt_after_reset','type'=>'invoice.paid','livemode'=>false,'data'=>['object'=>['id'=>'in_reset','customer'=>'cus_reset']]]);
 check(count($http->calls)===$before && !$db->one("SELECT id FROM billing_events WHERE id='evt_after_reset'"),'delayed callbacks are acknowledged without API calls or recreating deleted transactions');
 $new=$accounts->register(['name'=>'Fresh Client','email'=>'reset-fixture@example.com','password'=>'fixture-reset-password','locale'=>'ro','plan'=>'free']);
 check($new && $new!==$uid,'same email can register again with a fresh account identity');
 [$db,$uid,$other,$http,$store,$reset,$admin]=resetFixture();$http->loseDelete=true;
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'lost Stripe deletion');}catch(DomainException $e){}
 check($http->deletes===1 && $db->one('SELECT id FROM users WHERE id=?',[$uid]),'lost Stripe response preserves a resumable local account');
 $reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check($http->deletes===1 && !$db->one('SELECT id FROM users WHERE id=?',[$uid]),'retry reconciles an already deleted Stripe customer');
 [$db,$uid,$other,$http,$store,$reset,$admin]=resetFixture();
 $http->sessions['cs_test_reset']['status']='open';$http->sessions['cs_test_reset']['customer']=null;$http->raceCheckout=true;
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'racing Checkout');}catch(DomainException $e){}
 check($db->one('SELECT id FROM users WHERE id=?',[$uid]) && $http->deletes===0,'Checkout completing during reset cannot orphan an uncancelled subscription');
 $reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check($http->deletes===1 && !$db->one('SELECT id FROM users WHERE id=?',[$uid]),'retry discovers and removes newly completed Checkout customer');
 [$db,$uid,$other,$http,$store,$reset,$admin,$accounts,$plans,$mail]=resetFixture();
 $http->subscriptions['sub_foreign']=['id'=>'sub_foreign','object'=>'subscription','livemode'=>false,'customer'=>'cus_reset','status'=>'active','metadata'=>['user_id'=>$other]];
 try{$reset->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'foreign subscription reset');}catch(DomainException $e){check($http->deletes===0 && $db->one('SELECT id FROM users WHERE id=?',[$uid]),'foreign subscriptions on a shared Stripe customer block deletion');}
 unset($http->subscriptions['sub_foreign']);$http->sessions['cs_test_reset']['status']='open';
 $reset->reset($uid,'reset-fixture@example.com',true,false,$admin);
 check($http->sessions['cs_test_reset']['status']==='expired' && $http->deletes===1,'open Checkout sessions are expired before removing their test customer');
 [$db,$uid,$other,$http,$store,$reset,$admin,$accounts,$plans,$mail]=resetFixture();
 $live=new Stripe\StripeClient('sk_live_fixture');$changes=new Zion\SubscriptionChanges($db,$plans,$mail,$store,$live,new Zion\OblioInvoiceService($db,$mail));
 try{(new Zion\TestAccountReset($db,$changes,$store,$live))->reset($uid,'reset-fixture@example.com',true,false,$admin);check(false,'LIVE key accepted');}catch(DomainException $e){check(!$http->calls,'TEST reset cannot send requests through a LIVE key');}
}finally{Stripe\ApiRequestor::setHttpClient($previousStripe);if($previousLogPath===null)unset($_ENV['API_LOG_PATH']);else $_ENV['API_LOG_PATH']=$previousLogPath;@unlink($resetLog);}
echo "\n$count total assertions passed, including isolated TEST account resets.\n";
