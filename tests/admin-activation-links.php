<?php
$db=new Zion\Database(':memory:');$db->migrate();testFx($db);$plans=new Zion\Plans($db);$mail=new Zion\MailerService($db);$store=new TestStorage();
$admin='activation-admin';$db->run('INSERT INTO administrators VALUES(?,?,?,?)',[$admin,'activation-admin@example.com','unused-fixture-hash',time()]);
$changes=new Zion\SubscriptionChanges($db,$plans,$mail,$store,new Stripe\StripeClient('sk_test_fixture'),new Zion\OblioInvoiceService($db,$mail));
$service=new Zion\AdminService($db,$plans,$mail,$store,$changes);
$id=$service->create(['name'=>'Activation Client','email'=>'activation-fixture@example.com','password'=>'fixture-activation-password','plan'=>'free','locale'=>'fr'],$admin);
$payload=Zion\MailerService::decode($db->one('SELECT payload FROM outbox')['payload']);
$first=$service->activationLink($id,$admin);$second=$service->activationLink($id,$admin);
check($first['url']===$payload['url'] && $second===$first && str_contains($first['url'],Zion\Router::path('fr','verify')),'admin reuses original live verification link in customer locale without extending expiry');
check((int)$db->one('SELECT COUNT(*) n FROM outbox')['n']===1 && !$db->one('SELECT email_verified_at FROM users WHERE id=?',[$id])['email_verified_at'],'viewing activation link neither duplicates notifications nor confirms the account');
parse_str(parse_url($first['url'],PHP_URL_QUERY),$q);$oldToken=$q['token'];
$db->run('UPDATE email_verifications SET expires_at=? WHERE user_id=?',[time()-1,$id]);
$fresh=$service->activationLink($id,$admin);parse_str(parse_url($fresh['url'],PHP_URL_QUERY),$q);$newToken=$q['token'];
$accounts=new Zion\AccountService($db,$plans,$mail,$store);
check($fresh['url']!==$first['url'] && $fresh['expires_at']>time()+3500 && $accounts->verify($oldToken)===null,'expired activation links are replaced with one-hour links and old tokens stop working');
$audit=json_encode($db->run('SELECT * FROM admin_audit')->fetchAll());
check(!str_contains($audit,$oldToken) && !str_contains($audit,$newToken) && str_contains($audit,'activation_link_viewed'),'activation disclosure is audited without bearer tokens');
check($accounts->verify($newToken)===$id && $accounts->verify($newToken)===null,'admin-disclosed activation links retain normal single-use verification');
try{$service->activationLink($id,$admin);check(false,'confirmed customer link');}catch(DomainException $e){check(true,'confirmed customer is refused another new-client activation link');}
$id=$service->create(['name'=>'Suspended Client','email'=>'suspended-fixture@example.com','password'=>'fixture-activation-password','plan'=>'free','locale'=>'ro'],$admin);
$db->run('UPDATE account_controls SET banned_at=? WHERE user_id=?',[time(),$id]);
try{$service->activationLink($id,$admin);check(false,'suspended customer link');}catch(DomainException $e){check(true,'suspended clients cannot receive activation links from admin');}
$db->run('UPDATE account_controls SET banned_at=NULL WHERE user_id=?',[$id]);
$db->run("UPDATE outbox SET sent_at=?,payload='{}' WHERE recipient=?",[time(),'suspended-fixture@example.com']);
$renewed=$service->activationLink($id,$admin);
check($renewed['expires_at']>time()+3500 && (int)$db->one('SELECT COUNT(*) n FROM email_verifications WHERE user_id=? AND token_hash=?',[$id,$renewed['token_hash']])['n']===1,'unrecoverable delivered verification payload is replaced with a fresh valid activation link');
try{$service->activationLink('missing-customer',$admin);check(false,'missing customer link');}catch(DomainException $e){check(true,'activation link requires an existing customer');}
echo "\n$count total assertions passed, including admin activation links.\n";
