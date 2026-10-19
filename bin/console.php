#!/usr/bin/env php
<?php
require dirname(__DIR__).'/src/bootstrap.php';
$db=new Zion\Database();
$cmd=$argv[1]??'help';
if($cmd==='immich:sync'){
 $result=(new Zion\ImmichStatistics($db))->synchronize(in_array('--due',$argv,true));echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;exit($result['status']==='error'?1:0);
}
if($cmd==='key'){echo bin2hex(random_bytes(32)).PHP_EOL;exit;}
if(in_array($cmd,['migrate','install'],true)){$db->migrate();$seeded=Zion\AccountControl::seedAdmin($db);echo "Database ready\n".($seeded?"Admin configured (existing passwords preserved)\n":"Set ADMIN_EMAIL and ADMIN_PASSWORD, then run install to create the administrator\n");exit;}
$mail=new Zion\MailerService($db);
if($cmd==='changes:retry'){
 $plans=new Zion\Plans($db);$storage=new Zion\StorageProvisioningService();$changes=(new Zion\StripeService($db,$plans,$mail,$storage))->changes();
 $activation=(new Zion\AccountService($db,$plans,$mail,$storage))->retryAutomatic();
 $failed=0;$processed=$activation['ready'];
 foreach($db->run("SELECT id FROM plan_changes WHERE status IN ('creating','pending','paid','applying','retry') ORDER BY created_at LIMIT 100")->fetchAll() as $r){
  try{$record=$changes->record($r['id']);if($record['status']==='creating' && !$record['checkout_session_id'] && $record['expires_at']<=time())$changes->cancel($r['id']);else $changes->retry($r['id']);$processed++;}
  catch(Throwable $e){$failed++;error_log('Plan change requires retry; id='.$r['id']);}
 }
 $admin=new Zion\AdminService($db,$plans,$mail,$storage,$changes);
 foreach($db->run("SELECT user_id FROM account_controls WHERE quota_sync='pending' LIMIT 100")->fetchAll() as $r){try{$admin->syncQuota($r['user_id']);$processed++;}catch(Throwable $e){$failed++;}}
 echo json_encode(['processed'=>$processed,'failed'=>$failed],JSON_THROW_ON_ERROR).PHP_EOL;exit($failed?1:0);
}
if($cmd==='oblio:retry'){
 $result=(new Zion\OblioInvoiceService($db,$mail))->retryPending(in_array('--due',$argv,true));
 echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;exit($result['pending']>0?1:0);
}
if($cmd==='mail:work'){echo 'Delivered: '.$mail->work().PHP_EOL;exit;}
if($cmd==='activation:retry'){
 $result=(new Zion\AccountService($db,new Zion\Plans($db),$mail,new Zion\StorageProvisioningService()))->retryAutomatic();
 echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;exit;
}
if($cmd==='maintenance'){
 $db->run("DELETE FROM withdrawal_requests WHERE status='resolved' AND resolved_at<?",[strtotime('-3 years')]);
 $db->run('DELETE FROM activation_credentials WHERE expires_at<=?',[time()]);
 $db->run('DELETE FROM email_verifications WHERE expires_at<?',[time()-86400]);
 $db->run('DELETE FROM rate_limits WHERE expires_at<?',[time()]);
 $db->run('DELETE FROM outbox WHERE sent_at IS NOT NULL AND created_at<?',[time()-30*86400]);
 $db->run('DELETE FROM contact_messages WHERE created_at<?',[time()-90*86400]);
 echo "Maintenance complete\n";exit;
}
if($cmd==='quota:check'){
 $storage=new Zion\StorageProvisioningService();
 foreach($db->run("SELECT * FROM users WHERE status='active' AND storage_user_id IS NOT NULL")->fetchAll() as $u){
  try{
   $usage=$storage->getUsage($u['storage_user_id']);
   if($usage['quota']>0 && $usage['used']/$usage['quota']>=.9){
    $period=gmdate('Y-m');
    $db->transaction(function()use($db,$mail,$u,$period){
     if(!$db->one('SELECT * FROM notices WHERE user_id=? AND kind=? AND period=?',[$u['id'],'quota',$period])){
      $mail->queue($u['email'],$u['locale'],'quota',[],'quota:'.$u['id'].':'.$period);
      $db->run('INSERT INTO notices(user_id,kind,period) VALUES(?,?,?)',[$u['id'],'quota',$period]);
     }
    });
   }
  }catch(Throwable $e){fwrite(STDERR,'Usage check failed for user '.$u['id'].PHP_EOL);}
 }exit;
}
$queries=[
 'users'=>'SELECT id,name,email,locale,email_verified_at,selected_plan_id,status FROM users',
 'subscriptions'=>'SELECT * FROM subscriptions',
 'provisioning'=>'SELECT id,storage_user_id,storage_quota_bytes,status FROM users',
 'events'=>'SELECT * FROM billing_events ORDER BY created_at DESC LIMIT 100',
 'deliveries'=>'SELECT id,kind,attempts,sent_at FROM outbox ORDER BY id DESC LIMIT 100'
 ,'fiscal-invoices'=>'SELECT id,user_id,stripe_invoice_id,stripe_subscription_id,oblio_invoice_id,oblio_series,oblio_number,amount,currency,status,error_message,attempts,issued_at,notification_sent_at,completed_at FROM fiscal_invoices ORDER BY created_at DESC LIMIT 100'
];
if(isset($queries[$cmd])){echo json_encode($db->run($queries[$cmd])->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit;}
echo "Commands: immich:sync [--due], key, install, migrate, activation:retry, changes:retry, mail:work, maintenance, quota:check, users, subscriptions, provisioning, events, deliveries, fiscal-invoices, oblio:retry [--due]\n";
