<?php
// Run from tests/run.php: providers are fixtures and cannot reach the network.
$cdDb=new Zion\Database(':memory:');$cdDb->migrate();
$cdUid=bin2hex(random_bytes(16));$cdAdmin=bin2hex(random_bytes(16));$cdNow=time();
$cdDb->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',[$cdAdmin,'documents-admin@example.test','unused',$cdNow]);
$cdDb->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$cdUid,'Document Customer','documents@example.test','unused','fr','free',$cdNow,'verified',$cdNow,$cdNow,$cdNow,$cdNow]);
final class DocumentOblioFixture extends Zion\OblioClient {
 public string $url='https://www.oblio.eu/utils/show_file/?ic=private-issuer&id=private-document&it=1';public string $number='001';public array $requested=[];
 public function getInvoice(string $cif,string $series,string $number):array{$this->requested=[$cif,$series,$number];return ['series'=>'ZS','number'=>$this->number,'url'=>$this->url];}
}
final class DocumentPdfFixture extends Zion\FiscalDocuments {
 public int $downloads=0;public string $bytes="%PDF-1.4\nfixture-private-invoice\n%%EOF";
 protected function fetchPdf(string $url):string{$this->downloads++;return $this->bytes;}
}
final class DocumentMailFixture extends Zion\ListmonkClient {
 public array $calls=[];public bool $fail=false;
 protected function transport(string $method,string $url,?array $payload):array{
  $this->calls[]=$payload;return ['body'=>$this->fail?'{"message":"fixture rejected"}':'{"data":true}','status'=>$this->fail?503:200,'errno'=>0,'error'=>''];
 }
}
$cdSettings=new Zion\IntegrationSettings($cdDb);
$cdSettings->save('listmonk',['enabled'=>'1','url'=>'https://mail.example.test','api_user'=>'fixture','api_token'=>'fixture-document-token-123456789','template_id'=>'7','from_email'=>'contact@example.test'],$cdAdmin);
$cdOblio=new DocumentOblioFixture([]);$cdDocs=new DocumentPdfFixture($cdDb,$cdOblio);$cdClient=new DocumentMailFixture($cdSettings->listmonk());$cdMail=new Zion\MailerService($cdDb,$cdClient,$cdDocs);
$cdInvoice=bin2hex(random_bytes(16));
$cdDb->run("INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,source_snapshot,oblio_series,oblio_number,amount,currency,plan,status,idempotency_key,request_snapshot,created_at,updated_at,issued_at) VALUES(?,?,?,'sub_fixture','cus_fixture','{}','ZS','1',199,'eur','120gb','issued',?,?,?,?,?)",[$cdInvoice,$cdUid,'in_document_fixture','document-fixture',Zion\EncryptedPayload::encode(['cif'=>'49490924']),$cdNow,$cdNow,$cdNow]);
$cdPdf=$cdDocs->pdf($cdInvoice,$cdUid);
check($cdPdf['filename']==='Zion-Storage-ZS-1.pdf' && str_starts_with($cdPdf['bytes'],'%PDF-') && $cdOblio->requested===['49490924','ZS','1'],'PDF download verifies invoice ownership, immutable issuer and matching Oblio document');
try{$cdDocs->pdf($cdInvoice,'foreign');check(false,'foreign PDF accepted');}catch(DomainException $e){check($cdDocs->downloads===1,'foreign customer cannot download invoice');}
foreach(['https://attacker.test/utils/show_file/','http://www.oblio.eu/utils/show_file/','https://www.oblio.eu/redirect','https://user:pass@www.oblio.eu/utils/show_file/'] as $bad){$cdOblio->url=$bad;try{$cdDocs->pdf($cdInvoice,$cdUid);check(false,'unsafe PDF URL accepted');}catch(DomainException $e){check(true,'unsafe PDF URL rejected before fetch');}}
$cdOblio->url='https://www.oblio.eu/utils/show_file/?id=private';$cdOblio->number='2';
try{$cdDocs->pdf($cdInvoice,$cdUid);check(false,'mismatched invoice accepted');}catch(DomainException $e){check(true,'mismatched Oblio identity rejected');}
$cdOblio->number='001';$cdDocs->bytes='<html>Login required</html>';
try{$cdDocs->pdf($cdInvoice,$cdUid);check(false,'HTML attached as PDF');}catch(DomainException $e){check(true,'HTML cannot be downloaded or attached as PDF');}
$cdDocs->bytes="%PDF-1.4\nfixture-private-invoice\n%%EOF";
$cdManager=new Zion\AdminCustomer($cdDb,$cdMail);
check($cdManager->invoiceEmail($cdUid,$cdInvoice,$cdAdmin)==='accepted','manual invoice email reports provider acceptance');
$cdPayload=end($cdClient->calls);$cdMessage=json_decode($cdPayload['data'],true);
check($cdPayload['file'] instanceof CURLStringFile && $cdPayload['file']->data===$cdPdf['bytes'] && $cdPayload['file']->postname===$cdPdf['filename'],'Listmonk invoice email uses multipart PDF file, not a link or JSON base64');
check($cdMessage['data']['locale']==='fr' && str_contains($cdMessage['subject'],'Votre facture') && str_contains($cdMessage['subject'],'ZS / 1') && str_contains($cdMessage['data']['html'],'jointe à ce message'),'invoice introduction and subject use customer locale');
check($cdDb->one("SELECT payload FROM outbox WHERE kind='invoice_copy'")['payload']==='{}','accepted invoice email scrubs encrypted payload');
$cdClient->fail=true;check($cdManager->invoiceEmail($cdUid,$cdInvoice,$cdAdmin)==='pending','failed invoice send stays retryable and never reports success');$cdClient->fail=false;
$cdFirst=bin2hex(random_bytes(16));
$cdDb->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$cdFirst,'First Payment','first-paid@example.test','unused','en','120gb',$cdNow,'verified',$cdNow,$cdNow,$cdNow,$cdNow]);
$cdDb->run("INSERT INTO subscriptions(user_id,status,plan,paid_confirmed) VALUES(?,'active','120gb',1)",[$cdFirst]);
$cdDb->run("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES('in_first_guide',?,199,'eur',?)",[$cdFirst,$cdNow]);
$cdFirstLinks=new Zion\OnboardingLinks($cdDb,$cdMail);$cdFirstLinks->firstPayment($cdFirst,'in_first_guide');$firstGuide=$cdFirstLinks->current($cdFirst);
check($firstGuide && $firstGuide['valid'],'first confirmed positive payment creates an onboarding email and link');
$cdFirstLinks->firstPayment($cdFirst,'in_first_guide');
check($cdFirstLinks->current($cdFirst)['url']===$firstGuide['url'],'replayed first payment does not rotate link or duplicate mail');
$cdDb->run("INSERT INTO invoices(id,user_id,amount,currency,created_at) VALUES('in_renewal_guide',?,199,'eur',?)",[$cdFirst,$cdNow+30*86400]);
$cdFirstLinks->firstPayment($cdFirst,'in_renewal_guide');
check($cdFirstLinks->current($cdFirst)['url']===$firstGuide['url'],'renewal payment never extends or replaces onboarding link');
$cdDb->run('UPDATE users SET storage_user_id=? WHERE id=?',['fixture-linked-free',$cdUid]);
$cdDb->run("DELETE FROM integration_settings WHERE key='free-quota-5gb-v1'");$cdDb->migrate();
check($cdDb->one('SELECT quota_sync FROM account_controls WHERE user_id=?',[$cdUid])['quota_sync']==='pending','migration queues existing linked free account for 5 GB synchronization');
$cdDb->run("UPDATE account_controls SET quota_sync='ready' WHERE user_id=?",[$cdUid]);$cdDb->migrate();
check($cdDb->one('SELECT quota_sync FROM account_controls WHERE user_id=?',[$cdUid])['quota_sync']==='ready','repeated database migration never requeues completed quota changes');
$cdDb->run('UPDATE users SET storage_user_id=NULL WHERE id=?',[$cdUid]);$cdDb->run('DELETE FROM account_controls WHERE user_id=?',[$cdUid]);
$cdLinks=new Zion\OnboardingLinks($cdDb,$cdMail);$cdLink=$cdLinks->issue($cdUid);$cdToken=explode('#token=',$cdLink['url'])[1];
check($cdLink['expires_at']>=time()+259198 && $cdLink['expires_at']<=time()+259200 && str_contains($cdLink['url'],'/fr/guide-activation#token='),'personal onboarding URL uses customer locale and expires after 72 hours');
check($cdLinks->resolve($cdToken)['id']===$cdUid && $cdLinks->resolve($cdToken)['id']===$cdUid,'onboarding guide token is reusable during validity');
check(!str_contains(json_encode($cdDb->one('SELECT * FROM onboarding_links')),$cdToken),'guide token encrypted at rest');
check($cdLinks->current($cdUid)['url']===$cdLink['url'] && $cdLinks->issue($cdUid)['url']===$cdLink['url'],'admin can recover active URL without rotation or duplicate email');
$cdRenewed=$cdLinks->issue($cdUid,true);
check(!$cdLinks->resolve($cdToken) && $cdRenewed['url']!==$cdLink['url'],'regeneration immediately revokes old guide token');
check(!$cdDb->one('SELECT id FROM outbox WHERE dedupe_key=?',['onboarding:'.$cdLink['token_hash']]),'regeneration removes stale queued onboarding email');
check($cdMail->dispatch('onboarding:'.$cdRenewed['token_hash'])==='accepted','regenerated guide email reports confirmed provider acceptance');
$cdDb->run('UPDATE onboarding_links SET expires_at=?',[time()-1]);
check(!$cdLinks->resolveHash($cdRenewed['token_hash']) && !$cdLinks->current($cdUid)['valid'],'expired links deny access and admin shows expiry');
$cdRenewed=$cdLinks->issue($cdUid,true);$cdDb->run('UPDATE users SET email_verified_at=NULL WHERE id=?',[$cdUid]);
check(!$cdLinks->resolveHash($cdRenewed['token_hash']),'onboarding link never bypasses email confirmation');
$cdDb->run('UPDATE users SET email_verified_at=? WHERE id=?',[$cdNow,$cdUid]);
$cdDb->run('INSERT INTO account_controls(user_id,banned_at,updated_at) VALUES(?,?,?)',[$cdUid,$cdNow,$cdNow]);
check(!$cdLinks->resolveHash($cdRenewed['token_hash']),'suspension revokes onboarding access');
$cdDb->run('UPDATE account_controls SET banned_at=NULL WHERE user_id=?',[$cdUid]);
final class ContactManagerFixture extends Zion\AdminCustomer {public bool $fail=false;protected function syncStripe(array $u,array $data):void{if($this->fail)throw new RuntimeException('fixture unavailable');}}
$cdContact=new ContactManagerFixture($cdDb,$cdMail);$cdContact->fail=true;
try{$cdContact->saveContact($cdUid,['name'=>'Updated Customer','email'=>'updated@example.test','locale'=>'de'],$cdAdmin);}catch(RuntimeException $e){}
check($cdDb->one('SELECT user_id FROM customer_contact_changes') && $cdDb->one('SELECT email FROM users')['email']==='documents@example.test','partial contact synchronization retains durable intent and old local identity');
$cdContact->fail=false;$cdContact->saveContact($cdUid,[],$cdAdmin,true);
check($cdDb->one('SELECT email,locale,session_version FROM users')===['email'=>'updated@example.test','locale'=>'de','session_version'=>1] && !$cdDb->one('SELECT user_id FROM customer_contact_changes'),'retry commits intended contact and invalidates customer sessions');
check(!$cdLinks->resolveHash($cdRenewed['token_hash']),'email correction revokes old guide');
check((new Zion\Plans($cdDb))->get('free')['storage_bytes']===5_000_000_000,'free plan quota is consistently 5 GB');
foreach(Zion\TranslationService::LOCALES as $lang){$tr=new Zion\TranslationService($lang);check(str_contains($tr->get('hero.note'),'5 ') && !str_contains($tr->get('email.onboarding_access.body',['name'=>'Fixture']),'{name}'),'free capacity and guide mail localized for '.$lang);}
$cdDb->run('DELETE FROM admin_audit WHERE user_id=?',[$cdUid]);$cdDb->run('DELETE FROM fiscal_invoices WHERE user_id=?',[$cdUid]);$cdDb->run('DELETE FROM account_controls WHERE user_id=?',[$cdUid]);$cdDb->run('DELETE FROM users WHERE id=?',[$cdUid]);
check(!$cdDb->one('SELECT user_id FROM onboarding_links WHERE user_id=?',[$cdUid]),'test account reset cascades encrypted guide tokens');
echo "\n$count assertions including customer PDF, attached email, contact sync and expiring guide links.\n";
