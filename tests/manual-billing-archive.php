<?php
// Explicit manual issuance must override TEST gating only for the selected document.
$manualDb=new Zion\Database(':memory:');$manualDb->migrate();testFx($manualDb);seedCatalog($manualDb);
$manualAdmin='manual-admin';$manualUid=str_repeat('e',32);
$manualDb->run('INSERT INTO administrators VALUES(?,?,?,?)',[$manualAdmin,'manual-admin@example.com','fixture',time()]);
$manualDb->run("INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,'fixture','ro','120gb',?,?,?, ?,?)",[$manualUid,'Manual Client','manual-client@example.com',time(),time(),time(),time(),time()]);
(new Zion\BillingProfileService($manualDb))->save($manualUid,['type'=>'individual','name'=>'Manual Client','billing_email'=>'manual-client@example.com','country'=>'RO','county'=>'Bucuresti','city'=>'Bucuresti','address'=>'Strada Test 1','postal_code'=>'010101']);
$manualPlans=new Zion\Plans($manualDb);$manualMail=new Zion\MailerService($manualDb);$manualStore=new TestStorage();
$manualConfig=array_replace(require dirname(__DIR__).'/config/oblio.php',['enabled'=>true,'email'=>'fixture@example.com','secret'=>'fixture-secret','cif'=>'RO-FIXTURE','series'=>'ZS','vat_rate'=>'0','vat_name'=>'Scutit','language'=>'RO','allow_test_payments'=>false]);
$manualProvider=new FixtureOblio($manualConfig);$manualFiscal=new Zion\OblioInvoiceService($manualDb,$manualMail,$manualProvider,$manualConfig);
$manualUser=$manualDb->one('SELECT * FROM users WHERE id=?',[$manualUid]);$manualPlan=$manualPlans->byPrice('price_120');
$manualInvoice=fiscalInvoiceFixture('in_manual_test',time(),time()+30*86400,199);$manualInvoice['currency']='eur';$manualInvoice['lines']['data'][0]['price']['id']='price_120';
$manualSub=['id'=>'sub_fiscal','customer'=>'cus_fiscal','livemode'=>false,'metadata'=>['user_id'=>$manualUid],'current_period_end'=>time()+30*86400];
$manualId=$manualFiscal->capture($manualInvoice,$manualUser,$manualSub,$manualPlan);
check(!$manualFiscal->process($manualId) && !$manualProvider->documents,'automatic TEST issuance still obeys disabled global TEST permission');
check($manualFiscal->generateManually($manualId,$manualAdmin) && $manualDb->one('SELECT status FROM fiscal_invoices WHERE id=?',[$manualId])['status']==='issued','manual generation explicitly issues real invoice for selected TEST payment');
$manualCount=count($manualProvider->documents);$manualFiscal->generateManually($manualId,$manualAdmin);
check(count($manualProvider->documents)===$manualCount,'repeated manual generation cannot duplicate issued document');
$simFiscal=new Zion\OblioInvoiceService($manualDb,$manualMail,$manualProvider,array_replace($manualConfig,['enabled'=>false]));$manualInvoice['id']='in_manual_simulation';
$simId=$simFiscal->capture($manualInvoice,$manualUser,$manualSub,$manualPlan);$simFiscal->process($simId);
check($manualFiscal->generateManually($simId,$manualAdmin) && $manualDb->one('SELECT status FROM fiscal_invoices WHERE id=?',[$simId])['status']==='issued','explicit button can promote a local simulation into real invoice');
$manualInvoice['id']='in_manual_missing';$manualDb->run('INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)',[$manualInvoice['id'],$manualUid,199,'eur',time()]);
$manualBilling=new TestStripe($manualDb,$manualPlans,$manualMail,$manualStore,$manualFiscal);$manualBilling->invoices=[$manualInvoice['id']=>$manualInvoice];$manualBilling->snapshot=$manualSub;
$recoveredId=$manualBilling->prepareFiscalInvoice($manualInvoice['id']);
check($manualFiscal->generateManually($recoveredId,$manualAdmin),'missing fiscal job recovered from original authoritative paid Stripe invoice');
$manualInvoice['id']='in_manual_mismatch';$manualInvoice['amount_paid']=999;$manualBilling->invoices[$manualInvoice['id']]=$manualInvoice;$manualDb->run('INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES(?,?,?,?,?)',[$manualInvoice['id'],$manualUid,199,'eur',time()]);
try{$manualBilling->prepareFiscalInvoice($manualInvoice['id']);check(false,'mismatched paid amount accepted');}catch(DomainException $e){check(!$manualDb->one('SELECT id FROM fiscal_invoices WHERE stripe_invoice_id=?',[$manualInvoice['id']]),'manual recovery rejects changed Stripe amount before fiscal job creation');}
$manualDb->run("UPDATE users SET stripe_mode='live' WHERE id=?",[$manualUid]);
$manualDb->run("INSERT INTO billing_prices(price_id,plan,interval,currency,amount,status,created_at,updated_at,stripe_mode) VALUES('price_live_manual','120gb','month','eur',199,'active',?,?,'live')",[time(),time()]);
$liveManualInvoice=fiscalInvoiceFixture('in_manual_live',time(),time()+30*86400,199);$liveManualInvoice['currency']='eur';$liveManualInvoice['livemode']=true;$liveManualInvoice['lines']['data'][0]['price']['id']='price_live_manual';
$manualDb->run("INSERT INTO invoices(id,user_id,amount,currency,created_at,stripe_mode) VALUES(?,?,199,'eur',?,'live')",[$liveManualInvoice['id'],$manualUid,time()]);
$liveManualBilling=new TestStripe($manualDb,$manualPlans,$manualMail,$manualStore,$manualFiscal,'live');$liveManualBilling->snapshot=array_replace($manualSub,['livemode'=>true]);$liveManualBilling->invoices=[$liveManualInvoice['id']=>$liveManualInvoice];
$liveManualId=$liveManualBilling->prepareFiscalInvoice($liveManualInvoice['id']);
check($manualFiscal->generateManually($liveManualId,$manualAdmin) && $manualDb->one('SELECT stripe_mode FROM fiscal_invoices WHERE id=?',[$liveManualId])['stripe_mode']==='live','manual recovery and invoice issuance also work in original LIVE mode');
$manualChanges=new Zion\SubscriptionChanges($manualDb,$manualPlans,$manualMail,$manualStore,new Stripe\StripeClient('sk_live_fixture'),$manualFiscal,'live');
$manualService=new Zion\AdminService($manualDb,$manualPlans,$manualMail,$manualStore,$manualChanges);
$invoiceCount=(int)$manualDb->one('SELECT COUNT(*) n FROM fiscal_invoices')['n'];
$manualService->archive($manualUid,true,'Archive fixture',$manualAdmin);
check($manualDb->one('SELECT is_deleted FROM users WHERE id=?',[$manualUid])['is_deleted']===1 && (new Zion\AccountControl($manualDb))->banned($manualUid),'LIVE deletion is archive flag plus suspended access');
check((int)$manualDb->one('SELECT COUNT(*) n FROM fiscal_invoices')['n']===$invoiceCount && $manualDb->one('SELECT email FROM users WHERE id=?',[$manualUid])['email']==='manual-client@example.com','LIVE archive preserves customer identity and all fiscal invoices');
$manualService->archive($manualUid,false,'Restore fixture',$manualAdmin);
check($manualDb->one('SELECT is_deleted FROM users WHERE id=?',[$manualUid])['is_deleted']===0 && (new Zion\AccountControl($manualDb))->banned($manualUid),'restore returns LIVE customer to list without silently granting access');

final class ResetOblioFixture extends Zion\OblioClient {
 public bool $fail=true,$missing=false,$missingAfterDelete=false,$lookupFail=false;public int $deletes=0;public array $calls=[];
 protected function transport(string $method,string $path,array $data,?string $token,bool $json=true):array {
  $this->calls[]=[$method,$path,$data];
  if($path==='/authorize/token')return ['access_token'=>'fixture','expires_in'=>3600];
  if($method==='GET' && $this->lookupFail)throw new Zion\OblioException('provider_transport_failed');
  if($method==='GET' && $this->missing)throw new Zion\OblioException('provider_document_missing');
  if($method==='DELETE'){
   check(!$json && $data['deleteCollect']===1 && str_starts_with($data['idempotencyKey'],'zion-test-reset:'),'TEST Oblio deletion removes collection and uses stable idempotency key');
   if($this->missingAfterDelete){$this->missing=true;throw new Zion\OblioException('provider_transport_failed');}
   if($this->fail)throw new Zion\OblioException('not_last_document');$this->deletes++;
  }
  return ['status'=>200,'data'=>['id'=>'oblio-reset-doc','seriesName'=>'ZS','number'=>'0001']];
 }
}
$oldStripe=Stripe\ApiRequestor::httpClient();
try{
 [$resetDb,$resetUid,$other,$resetHttp,$resetStorage,$unusedReset,$resetAdmin,$resetAccounts,$resetPlans,$resetMail]=resetFixture();
 $resetDb->run("INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,amount,currency,plan,status,idempotency_key,source_snapshot,request_snapshot,oblio_invoice_id,oblio_series,oblio_number,issued_at,created_at,updated_at) VALUES(?,?,'in_reset_oblio','sub_reset','cus_reset',199,'eur','120gb','issued','reset-oblio-key',?,?,'oblio-reset-doc','ZS','0001',?,?,?)",[str_repeat('f',32),$resetUid,Zion\EncryptedPayload::encode(['simulated'=>false,'invoice'=>['id'=>'in_reset_oblio','livemode'=>false]]),Zion\EncryptedPayload::encode(['cif'=>'RO-FIXTURE']),time(),time(),time()]);
 $resetProvider=new ResetOblioFixture($manualConfig);$resetStripe=new Stripe\StripeClient('sk_test_resetFixture');
 $resetChanges=new Zion\SubscriptionChanges($resetDb,$resetPlans,$resetMail,$resetStorage,$resetStripe,new Zion\OblioInvoiceService($resetDb,$resetMail));
 $resetWithOblio=new Zion\TestAccountReset($resetDb,$resetChanges,$resetStorage,$resetStripe,$resetProvider);
 try{$resetWithOblio->reset($resetUid,'reset-fixture@example.com',true,false,$resetAdmin);check(false,'Oblio deletion refusal ignored');}catch(DomainException $e){check($resetDb->one('SELECT id FROM users WHERE id=?',[$resetUid]) && $resetHttp->deletes===0,'Oblio refuses deletion: local client and Stripe customer are preserved');}
 $resetProvider->fail=false;$resetWithOblio->reset($resetUid,'reset-fixture@example.com',true,false,$resetAdmin);
 check(!$resetDb->one('SELECT id FROM users WHERE id=?',[$resetUid]) && $resetProvider->deletes===1,'TEST reset resumes and deletes associated Oblio invoice before local cleanup');
}finally{Stripe\ApiRequestor::setHttpClient($oldStripe);}
echo "\n$count total assertions passed including manual billing, LIVE archive and TEST Oblio deletion.\n";

foreach(['missing_before','missing_after_timeout','lookup_outage'] as $missingCase){
 $savedStripe=Stripe\ApiRequestor::httpClient();
 try{
  [$resetDb,$resetUid,$other,$resetHttp,$resetStorage,$unusedReset,$resetAdmin,$resetAccounts,$resetPlans,$resetMail]=resetFixture();
  $resetDb->run("INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,amount,currency,plan,status,idempotency_key,source_snapshot,request_snapshot,oblio_invoice_id,oblio_series,oblio_number,issued_at,created_at,updated_at) VALUES(?,?,'in_reset_oblio','sub_reset','cus_reset',199,'eur','120gb','issued','reset-oblio-key',?,?,'oblio-reset-doc','ZS','0001',?,?,?)",[str_repeat('f',32),$resetUid,Zion\EncryptedPayload::encode(['simulated'=>false,'invoice'=>['id'=>'in_reset_oblio','livemode'=>false]]),Zion\EncryptedPayload::encode(['cif'=>'RO-FIXTURE']),time(),time(),time()]);
  $provider=new ResetOblioFixture($manualConfig);$provider->fail=false;
  $provider->missing=$missingCase==='missing_before';$provider->missingAfterDelete=$missingCase==='missing_after_timeout';$provider->lookupFail=$missingCase==='lookup_outage';
  $client=new Stripe\StripeClient('sk_test_resetFixture');$changes=new Zion\SubscriptionChanges($resetDb,$resetPlans,$resetMail,$resetStorage,$client,new Zion\OblioInvoiceService($resetDb,$resetMail));
  $reset=new Zion\TestAccountReset($resetDb,$changes,$resetStorage,$client,$provider);
  try{$reset->reset($resetUid,'reset-fixture@example.com',true,false,$resetAdmin);check($missingCase!=='lookup_outage','lookup outage cannot permit account deletion');}
  catch(DomainException $e){check($missingCase==='lookup_outage' && $resetDb->one('SELECT id FROM users WHERE id=?',[$resetUid]),'unconfirmed Oblio lookup failure preserves customer');continue;}
  check(!$resetDb->one('SELECT id FROM users WHERE id=?',[$resetUid]) && $reset->missingInvoiceCount()===1,'confirmed missing invoice permits TEST cleanup and reports warning: '.$missingCase);
  check(str_contains($resetDb->one("SELECT detail FROM admin_audit WHERE action='test_account_reset'")['detail'],'Oblio: 1'),'missing document warning is retained in reset audit');
 }finally{Stripe\ApiRequestor::setHttpClient($savedStripe);}
}
$missingBody=json_encode(['status'=>400,'statusMessage'=>'Nu exista document cu seria ZS si numarul 0001']);
check(Zion\OblioClient::missingDocumentResponse('GET','/docs/invoice',400,$missingBody),'exact Oblio missing-document HTTP 400 classified distinctly');
foreach([['GET','/docs/invoice',401,$missingBody],['DELETE','/docs/invoice',400,$missingBody],['GET','/authorize/token',400,$missingBody],['GET','/docs/invoice',400,'<html>Nu exista document cu seria ZS si numarul 1</html>'],['GET','/docs/invoice',400,json_encode(['message'=>'Invalid company or permissions'])]] as $bad)check(!Zion\OblioClient::missingDocumentResponse(...$bad),'unrelated provider failure never classified as missing invoice');
