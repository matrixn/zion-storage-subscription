<?php
// Fixture-only transport: no real provider or email delivery.
$listDb=new Zion\Database(':memory:');$listDb->migrate();
$listSettings=new Zion\IntegrationSettings($listDb);
$_ENV['ADMIN_EMAIL']='listmonk-admin@example.com';$_ENV['ADMIN_PASSWORD']='listmonk-test-password';
Zion\AccountControl::seedAdmin($listDb);$listAdmin=$listDb->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
putenv('LISTMONK_URL=https://environment.example');putenv('LISTMONK_API_USER=env-user');putenv('LISTMONK_API_TOKEN=env-token-123456789');putenv('LISTMONK_TEMPLATE_ID=8');putenv('LISTMONK_FROM_EMAIL=env@example.com');
check($listSettings->listmonk()['enabled'] && $listSettings->listmonk()['template_id']==='8','Listmonk environment is available before first save');
$listInput=['enabled'=>'1','url'=>'https://listmonk.example/','api_user'=>'zion-api','api_token'=>'fixture-listmonk-token-abcdef123456','template_id'=>'7','from_email'=>'contact@example.com'];
$listSettings->save('listmonk',$listInput,$listAdmin);
check($listSettings->listmonk()['url']==='https://listmonk.example' && $listSettings->listmonk()['template_id']==='7','saved Listmonk fields override complete environment profile');
$listInput['api_token']=Zion\IntegrationSettings::maskedSecret($listInput['api_token']);$listSettings->save('listmonk',$listInput,$listAdmin);
check($listSettings->listmonk()['api_token']==='fixture-listmonk-token-abcdef123456','resubmitting visible Listmonk mask preserves full saved token');
foreach([['url'=>'https://user:pass@example.com'],['url'=>'https://example.com?token=secret'],['api_user'=>'user:name'],['template_id'=>'0'],['from_email'=>'invalid'],['api_token'=>'bad token'],['clear_api_token'=>'1']] as $bad){
 try{$listSettings->save('listmonk',array_replace($listInput,$bad),$listAdmin);check(false,'invalid Listmonk configuration accepted');}catch(DomainException $e){check($listSettings->listmonk()['api_token']==='fixture-listmonk-token-abcdef123456','invalid Listmonk changes preserve working settings');}
}
final class ListmonkFixture extends Zion\ListmonkClient {
 public array $calls=[];public string $failure='';
 protected function transport(string $method,string $url,?array $payload): array {
  $this->calls[]=[$method,$url,$payload];
  if($this->failure==='http')return ['body'=>json_encode(['message'=>'rejected fixture-listmonk-token-abcdef123456']), 'status'=>401,'errno'=>0,'error'=>''];
  if($this->failure==='semantic')return ['body'=>'{"data":false}','status'=>200,'errno'=>0,'error'=>''];
  if($this->failure==='malformed')return ['body'=>'<html>proxy error</html>','status'=>200,'errno'=>0,'error'=>''];
  if($this->failure==='timeout')return ['body'=>false,'status'=>0,'errno'=>28,'error'=>'timeout'];
  return ['body'=>$method==='GET'?'{"data":{"id":7,"type":"tx"}}':'{"data":true}','status'=>200,'errno'=>0,'error'=>''];
 }
}
$listClient=new ListmonkFixture($listSettings->listmonk());$listClient->check();
check($listClient->calls[0][0]==='GET' && str_ends_with($listClient->calls[0][1],'/api/templates/7'),'Listmonk connection checks configured transactional template');
$listMail=new Zion\MailerService($listDb,$listClient);
$listMail->queue('customer@example.com','ro','verify',['url'=>'https://storage.example/verify?token=fixture-action-secret'],'listmonk-test');
$listClient->failure='http';check($listMail->deliver()===0 && $listDb->one('SELECT sent_at,attempts FROM outbox')['sent_at']===null,'Listmonk HTTP error keeps email pending without SMTP fallback');
$listClient->failure='';check($listMail->deliver()===1,'Listmonk retry delivers pending email');
$listPayload=end($listClient->calls)[2];
check($listPayload['subscriber_mode']==='external' && $listPayload['subscriber_emails']===['customer@example.com'] && $listPayload['template_id']===7,'Listmonk uses external recipients and configured template without subscriber creation');
check($listPayload['data']['locale']==='ro' && str_contains($listPayload['data']['html'],'fixture-action-secret') && str_contains($listPayload['altbody'],'fixture-action-secret'),'localized Listmonk HTML and plaintext preserve activation action');
check(str_contains($listPayload['data']['html'],'bgcolor="#c4f582"') && str_contains($listPayload['data']['html'],'Confirmă adresa de email'),'confirmation email has branded lime button and localized action');
check(substr_count($listPayload['data']['html'],'href="https://storage.example/verify?token=fixture-action-secret"')===2 && str_contains($listPayload['data']['html'],'>https://storage.example/verify?token=fixture-action-secret</a>'),'confirmation email includes same URL as button and full copyable fallback link');
check($listDb->one('SELECT payload FROM outbox')['payload']==='{}' && $listDb->one('SELECT attempts FROM outbox')['attempts']===2,'accepted email clears sensitive pending payload and records retries');
foreach(['semantic','malformed','timeout'] as $failure){$listClient->failure=$failure;try{$listClient->send('test@example.com','Test','<p>Test</p>','Test','en','test');check(false,'bad Listmonk response accepted');}catch(DomainException $e){check(true,'Listmonk rejects '.$failure.' response');}}
$listLogs=Zion\ApiLog::page(['provider'=>'listmonk']);$listRaw=json_encode($listLogs);
check($listLogs['total']>=6 && !str_contains($listRaw,'fixture-listmonk-token-abcdef123456') && !str_contains($listRaw,'customer@example.com') && !str_contains($listRaw,'fixture-action-secret'),'Listmonk API journal records outcomes without tokens, recipients or action links');
$listRaw=json_encode($listDb->run('SELECT * FROM integration_settings')->fetchAll()).json_encode($listDb->run('SELECT * FROM admin_audit')->fetchAll());
check(!str_contains($listRaw,'fixture-listmonk-token-abcdef123456'),'Listmonk credentials are encrypted at rest and absent from audit');
$listClient->failure='';
$listUser=str_repeat('c',32);$listDb->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$listUser,'Confirm Client','confirm@example.com','unused','en','free',time(),time(),time(),time()]);
$listHash=hash('sha256','current-confirmation');
$listDb->run('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)',[$listHash,$listUser,time()+3600]);
$listMail->queue('confirm@example.com','en','verify',['url'=>'https://storage.example/verify?token=current'],'verify:'.$listHash);
$listBefore=count($listClient->calls);$listClient->failure='http';
check($listMail->deliverVerification($listUser)===0 && count($listClient->calls)===$listBefore+1,'immediate signup dispatch retains failed confirmation for retry');
$listClient->failure='';check($listMail->deliverVerification($listUser)===1 && $listMail->deliverVerification($listUser)===0,'targeted confirmation dispatch sends once and never repeats accepted email');
$listDb->run('UPDATE email_verifications SET expires_at=? WHERE token_hash=?',[time()-1,$listHash]);
$listMail->queue('confirm@example.com','en','verify',['url'=>'https://storage.example/verify?token=expired'],'access:'.$listHash);
$listBefore=count($listClient->calls);$listMail->deliver();
check(count($listClient->calls)===$listBefore && !$listDb->one('SELECT id FROM outbox WHERE dedupe_key=?',['access:'.$listHash]),'worker discards expired confirmation without sending dead activation link');
check(!$listMail->diagnostics()['worker_recent'],'web sends and manual flush do not create a worker heartbeat');
$listMail->work();check($listMail->diagnostics()['worker_recent'] && !empty($listMail->diagnostics()['worker']['finished_at']),'actual mail worker records recent completed activity');
$registration=new Zion\AccountService($listDb,new Zion\Plans($listDb),$listMail,new TestStorage());
$signupData=['name'=>'New Customer','email'=>'signup-notice@example.com','password'=>'never-email-this-password','locale'=>'en','plan'=>'free'];
$signupId=$registration->register($signupData);
$signupNotice=$listDb->one("SELECT * FROM outbox WHERE kind='admin_signup' AND dedupe_key LIKE ?",['admin-signup:'.$signupId.':%']);
check($signupNotice['recipient']==='listmonk-admin@example.com' && $signupNotice['locale']==='ro','new signup notifies administrator stored in database');
$signupPayload=Zion\MailerService::decode($signupNotice['payload']);
check($signupPayload['email']==='signup-notice@example.com' && $signupPayload['plan']==='Free' && !str_contains(json_encode($signupPayload),'never-email-this-password'),'administrator notification includes selected plan and contact without password');
$registration->register($signupData);$registration->issueVerification($signupId);
check((int)$listDb->one("SELECT COUNT(*) n FROM outbox WHERE kind='admin_signup'")['n']===1,'duplicate signup and verification resend never duplicate administrator notification');
$listMail->deliverRegistrationNotifications($signupId);
check($listDb->one('SELECT sent_at FROM outbox WHERE id=?',[$signupNotice['id']])['sent_at']!==null,'Listmonk dispatch sends administrator registration notice immediately');
$listInput['enabled']='0';$listInput['clear_api_token']='1';$listSettings->save('listmonk',$listInput,$listAdmin);
check(!$listSettings->listmonk()['enabled'] && $listSettings->listmonk()['api_token']==='','disabled saved Listmonk profile never restores environment token');
foreach(['URL','API_USER','API_TOKEN','TEMPLATE_ID','FROM_EMAIL'] as $suffix){putenv('LISTMONK_'.$suffix.'=');$_ENV['LISTMONK_'.$suffix]='';}
echo "\n$count total assertions passed, including Listmonk configuration, transactional queue and redacted diagnostics.\n";
