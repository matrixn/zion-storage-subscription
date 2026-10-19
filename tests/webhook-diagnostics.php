<?php
final class DebugStripeFixture extends Zion\StripeService {
 public array $events=[];public array $snapshot=[];public array $invoice=[];
 protected function fetchEvent(string $id):array{return $this->events[$id]??throw new RuntimeException('missing_fixture_event');}
 protected function fetchSubscription(string $id):array{return $this->snapshot;}
 protected function fetchInvoice(string $id):array{return $this->invoice;}
}
$oldApiLog=$_ENV['API_LOG_PATH']??null;$oldGet=$_GET;
$debugPath=sys_get_temp_dir().'/zion-webhook-'.bin2hex(random_bytes(8)).'.sqlite';$_ENV['API_LOG_PATH']=$debugPath;$_GET=['mode'=>'test'];
try{
 $db=new Zion\Database(':memory:');$db->migrate();seedCatalog($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();
 $admin='webhook-admin';$db->run('INSERT INTO administrators VALUES(?,?,?,?)',[$admin,'webhook-admin@example.com','fixture-hash',time()]);
 $settings=new Zion\IntegrationSettings($db);$settings->save('stripe',['credential_mode'=>'test','secret_key'=>'sk_test_fixture','webhook_secret'=>'whsec_debugFixture'],$admin);
 $accounts=new Zion\AccountService($db,$plans,$mail,$store);
 $uid=$accounts->register(['name'=>'Webhook Client','email'=>'webhook-fixture@example.com','password'=>'fixture-webhook-password','locale'=>'ro','plan'=>'120gb']);
 $payload=Zion\MailerService::decode($db->one("SELECT payload FROM outbox WHERE kind='verify'")['payload']);parse_str(parse_url($payload['url'],PHP_URL_QUERY),$q);$accounts->verify($q['token']);
 (new Zion\BillingProfileService($db))->save($uid,['type'=>'individual','name'=>'Webhook Client','billing_email'=>'webhook-fixture@example.com','country'=>'RO','county'=>'Bucuresti','city'=>'Bucuresti','address'=>'Strada Test 1','postal_code'=>'010101']);
 $billing=new DebugStripeFixture($db,$plans,$mail,$store);
 $send=function(array $event,string $secret='whsec_debugFixture',?int $time=null)use($billing){$raw=json_encode($event);$time??=time();$billing->receive($raw,'t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$raw,$secret));};
 $event=['id'=>'evt_debugIgnored','object'=>'event','livemode'=>false,'type'=>'integration.fixture','api_version'=>'2024-06-20','data'=>['object'=>['email'=>'never-log@example.com','secret'=>'whsec_neverLog']]];
 $send($event);$row=Zion\ApiLog::page(['event'=>$event['id']])['rows'][0];$context=json_decode($row['context'],true);
 check($row['http_status']===204 && $context['signature']==='valid' && $context['outcome']==='ignored','webhook diagnostics distinguish verified receipt from ignored event');
 $delivery=Zion\ApiLog::page(['event'=>$context['delivery_id']]);check($delivery['total']===2,'delivery correlation includes receipt and final processing records');
 check(!str_contains(json_encode($delivery),'never-log@example.com') && !str_contains(json_encode($delivery),'whsec_neverLog'),'webhook logs never capture payload or signature data');
 try{$send(array_replace($event,['id'=>'evt_debugBadSignature']),'whsec_wrongFixture');check(false,'bad signature');}catch(Stripe\Exception\SignatureVerificationException $e){}
 $row=Zion\ApiLog::page(['event'=>'evt_debugBadSignature'])['rows'][0];$context=json_decode($row['context'],true);
 check($row['error_code']==='signature_invalid' && $context['stage']==='signature' && $context['signature']==='invalid','webhook logs expose wrong signing secret and failed stage');
 try{$send(array_replace($event,['id'=>'evt_debugExpired']),'whsec_debugFixture',time()-1000);check(false,'expired signature');}catch(Stripe\Exception\SignatureVerificationException $e){}
 check(Zion\ApiLog::page(['event'=>'evt_debugExpired'])['rows'][0]['error_code']==='signature_expired','webhook diagnostics distinguish expired signatures');
 $_GET=['mode'=>'live'];try{$send(array_replace($event,['id'=>'evt_debugWrongMode']));check(false,'wrong mode');}catch(UnexpectedValueException $e){}
 check(Zion\ApiLog::page(['event'=>'evt_debugWrongMode'])['rows'][0]['error_code']==='webhook_mode_mismatch','webhook diagnostics distinguish endpoint and payload modes');$_GET=['mode'=>'test'];
 try{$billing->receive('{broken','');check(false,'malformed payload');}catch(JsonException $e){}
 check(Zion\ApiLog::page(['status'=>'error'])['rows'][0]['error_code']==='invalid_json','malformed webhook is logged without recording raw payload');
 $start=time()-300;$invoice=['id'=>'in_debug','object'=>'invoice','livemode'=>false,'subscription'=>'sub_debug','customer'=>'cus_debug','status'=>'paid','amount_paid'=>199,'currency'=>'eur','created'=>$start,'billing_reason'=>'subscription_create','status_transitions'=>['paid_at'=>$start],'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>'price_120'],'quantity'=>1,'period'=>['start'=>$start,'end'=>$start+30*86400]]]]];
 $billing->invoice=$invoice;$billing->snapshot=['id'=>'sub_debug','object'=>'subscription','livemode'=>false,'customer'=>'cus_debug','status'=>'active','metadata'=>['user_id'=>str_repeat('f',32),'plan'=>'120gb','billing_interval'=>'month'],'items'=>['data'=>[['id'=>'si_debug','price'=>['id'=>'price_120'],'current_period_end'=>$start+30*86400]]],'latest_invoice'=>$invoice];
 $paidEvent=['id'=>'evt_debugPaid','object'=>'event','livemode'=>false,'type'=>'invoice.paid','data'=>['object'=>$invoice]];$billing->events[$paidEvent['id']]=$paidEvent;
 try{$send($paidEvent);check(false,'unknown billing user');}catch(RuntimeException $e){}
 $row=Zion\ApiLog::page(['event'=>$paidEvent['id']])['rows'][0];$context=json_decode($row['context'],true);
 check($row['http_status']===503 && $row['error_code']==='unknown_billing_user' && $context['signature']==='valid' && $context['stage']==='identify_user','verified webhook processing failures identify exact business stage');
 $billing->snapshot['metadata']['user_id']=$uid;$result=$billing->replayEvent($paidEvent['id'],$admin);
 check($result['outcome']==='processed' && $result['paid_confirmed'] && $accounts->eligible($db->one('SELECT * FROM users WHERE id=?',[$uid])),'API replay recovers missing payment confirmation from authoritative subscription and invoice');
 check((int)$db->one('SELECT COUNT(*) n FROM fiscal_invoices')['n']===1 && $store->created===0,'payment recovery queues fiscal work and leaves password provisioning to customer');
 $result=$billing->replayEvent($paidEvent['id'],$admin);$send($paidEvent);
 check($result['outcome']==='duplicate' && (int)$db->one('SELECT COUNT(*) n FROM fiscal_invoices')['n']===1,'replay and delayed webhook remain idempotent without duplicate fiscal jobs');
 $liveEvent=array_replace($paidEvent,['id'=>'evt_debugLive','livemode'=>true]);$billing->events[$liveEvent['id']]=$liveEvent;
 try{$billing->replayEvent($liveEvent['id'],$admin);check(false,'cross-mode replay');}catch(DomainException $e){check(true,'event replay refuses another payment environment');}
 $billing->events['evt_debugMismatch']=array_replace($paidEvent,['id'=>'evt_someOther']);
 try{$billing->replayEvent('evt_debugMismatch',$admin);check(false,'wrong event returned');}catch(DomainException $e){check(true,'event replay validates returned event identity');}
 check(Zion\ApiLog::safeContext(['event_id'=>'whsec_private','user_id'=>'private.name','payload'=>'raw','signature'=>'valid'])===['signature'=>'valid'],'webhook metadata accepts only defined safe identifiers');
}finally{$_GET=$oldGet;if($oldApiLog===null)unset($_ENV['API_LOG_PATH']);else $_ENV['API_LOG_PATH']=$oldApiLog;@unlink($debugPath);}
echo "\n$count total assertions passed, including webhook diagnostics and recovery.\n";
