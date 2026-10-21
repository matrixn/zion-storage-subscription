<?php
// Conflicting environment values must never bleed into a saved provider.
$db=new Zion\Database(':memory:');$db->migrate();testFx($db);seedCatalog($db);$settings=new Zion\IntegrationSettings($db);
$_ENV['ADMIN_EMAIL']='settings-fixture@example.com';$_ENV['ADMIN_PASSWORD']='settings-fixture-password';
Zion\AccountControl::seedAdmin($db);$admin=$db->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
check($settings->stripe()['secret_key']===env('STRIPE_SECRET_KEY'),'unsaved Stripe uses environment for backward compatibility');
$_ENV['OBLIO_ENABLED']='true';$_ENV['OBLIO_VAT_RATE']='99';$_ENV['OBLIO_WORKSTATION']='environment-workstation';
check($settings->oblio()['enabled'],'unsaved Oblio uses environment');
$settings->save('stripe',['secret_key'=>'sk_test_adminSaved','webhook_secret'=>'whsec_adminSaved'],$admin);
$_ENV['STRIPE_SECRET_KEY']='invalid-environment-key';$_ENV['STRIPE_WEBHOOK_SECRET']='whsec_environment';
$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();$accounts=new Zion\AccountService($db,$plans,$mail,$store);
[$id,$token]=signup('settings-checkout@example.com','120gb');$accounts->verify($token);
$http=new FixtureHttp();Stripe\ApiRequestor::setHttpClient($http);
$billing=new Zion\StripeService($db,$plans,$mail,$store);$billing->checkout($db->one('SELECT * FROM users WHERE id=?',[$id]));
check(count($http->calls)===2,'Checkout works with saved TEST key despite invalid environment key');
check(str_contains(json_encode($http->calls[0][3]),'Bearer sk_test_adminSaved'),'SDK authenticates API requests with database key');
$event=json_encode(['id'=>'evt_settings_ignored','object'=>'event','type'=>'unhandled','livemode'=>false,'data'=>['object'=>[]]]);
$timestamp=time();$signature='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$event,'whsec_adminSaved');
$billing->receive($event,$signature);check(true,'webhook verifies signature with saved signing secret');
try{$billing->receive($event,'t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$event,'whsec_environment'));check(false,'environment signing key accepted');}catch(Stripe\Exception\SignatureVerificationException $e){check(true,'environment webhook signing secret is ignored after save');}
$settings->save('stripe',[],$admin);
check($settings->stripe()['secret_key']==='sk_test_adminSaved','empty edit retains stored secret');
try{$settings->save('stripe',['secret_key'=>'sk_live_rejected'],$admin);check(false,'LIVE key saved');}catch(DomainException $e){check($settings->stripe()['secret_key']==='sk_test_adminSaved','invalid key cannot overwrite working settings');}
$settings->save('stripe',['clear_secret_key'=>'1','clear_webhook_secret'=>'1'],$admin);
check($settings->stripe()===['secret_key'=>'','webhook_secret'=>'','mode'=>'test'],'clearing keys never reactivates environment credentials');
$disabled=new Zion\StripeService($db,$plans,$mail,$store);
$db->run('DELETE FROM checkout_sessions');
try{$disabled->checkout($db->one('SELECT * FROM users WHERE id=?',[$id]));check(false,'empty admin API key accepted');}catch(RuntimeException $e){check($e->getMessage()==='billing_unconfigured','empty saved key blocks new Checkout before HTTP');}
$input=['email'=>'operator@example.com','secret'=>'oblioSavedSecret','cif'=>'RO12345678','series'=>'ZS','workstation'=>'','language'=>'RO','vat_rate'=>'0','vat_name'=>'Scutit'];
$settings->save('oblio',$input,$admin);
$oblio=$settings->oblio();
check(!$oblio['enabled'] && !$oblio['allow_test_payments'] && $oblio['workstation']==='','saved disabled and empty Oblio values override environment');
check($plans->get('120gb')['invoice_vat_rate']==='0','new fiscal plan snapshots use admin VAT instead of environment VAT');
$invoice=['id'=>'in_admin_settings','customer'=>'cus_admin_settings','status'=>'paid','livemode'=>false,'amount_paid'=>199,'currency'=>'ron','created'=>time(),'status_transitions'=>['paid_at'=>time()],'lines'=>['data'=>[['price'=>['id'=>'price_120'],'quantity'=>1,'period'=>['start'=>time(),'end'=>time()+30*86400]]],'has_more'=>false]];
$fiscal=new Zion\OblioInvoiceService($db,$mail);$fid=$fiscal->capture($invoice,$db->one('SELECT * FROM users WHERE id=?',[$id]),['id'=>'sub_settings'],$plans->get('120gb'));
check($fiscal->process($fid) && $db->one('SELECT status FROM fiscal_invoices WHERE id=?',[$fid])['status']==='simulated','Oblio worker uses saved disabled mode despite enabled environment');
$payload=Zion\EncryptedPayload::decode($db->one('SELECT request_snapshot FROM fiscal_invoices WHERE id=?',[$fid])['request_snapshot']);
check($payload['products'][0]['vatPercentage']==0 && $payload['cif']==='RO12345678' && !isset($payload['workStation']),'fiscal payload uses saved issuer, VAT and empty workstation');
$input['enabled']='1';$input['secret']='';$settings->save('oblio',$input,$admin);
check($settings->oblio()['enabled'] && $settings->oblio()['secret']==='oblioSavedSecret','complete Oblio configuration enables issuance while retaining secret');
$input['clear_secret']='1';
try{$settings->save('oblio',$input,$admin);check(false,'enabled Oblio saved without secret');}catch(DomainException $e){check($settings->oblio()['enabled'],'invalid Oblio update is atomic');}
$raw=json_encode($db->run('SELECT value FROM integration_settings')->fetchAll()).json_encode($db->run('SELECT detail FROM admin_audit')->fetchAll());
check(!str_contains($raw,'oblioSavedSecret') && !str_contains($raw,'sk_test_adminSaved'),'database settings and audit contain no plaintext credentials');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
$_ENV['STRIPE_SECRET_KEY']='sk_test_fake';$_ENV['OBLIO_ENABLED']='false';
echo "\n$count total assertions passed, including encrypted provider configuration and environment precedence.\n";
