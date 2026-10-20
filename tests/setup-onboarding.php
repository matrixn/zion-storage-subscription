<?php
// CLI-only, isolated fixtures. Never included by public/index.php.
require dirname(__DIR__).'/src/bootstrap.php';
$directory=getenv('ONBOARDING_TEST_DIRECTORY')?:'';
if(PHP_SAPI!=='cli' || env('APP_ENV')!=='test' || !str_starts_with($directory,sys_get_temp_dir().'/zion-onboarding-') || env('DB_PATH')!==$directory.'/app.sqlite')throw new RuntimeException('Isolated onboarding fixture only');
$db=new Zion\Database();$db->migrate();
if(!is_dir($directory.'/sessions'))mkdir($directory.'/sessions',0700);
ini_set('session.save_path',$directory.'/sessions');session_name('zion_session');
$sessions=[];$now=time();
foreach(['ready','pending','provisioning','failed','password','inactive','banned','unverified','expired','paid-ready'] as $state){
 $id=bin2hex(random_bytes(16));$paid=in_array($state,['pending','inactive','paid-ready']);$linked=in_array($state,['ready','inactive','banned','paid-ready']);
 $db->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,storage_user_id,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,'Onboarding preview',$state.'@example.test',password_hash('fixture-password-not-real',PASSWORD_ARGON2ID),'ro',$paid?'120gb':'free',$state==='unverified'?null:$now,$linked?'fixture-storage-'.$id:null,$linked?'active':'verified',$now,$now,$now,$now]);
 if($paid)$db->run('INSERT INTO subscriptions(user_id,status,paid_confirmed,plan) VALUES(?,?,?,?)',[$id,$state==='paid-ready'?'active':($state==='inactive'?'canceled':'pending'),$state==='paid-ready'?1:0,'120gb']);
 if(in_array($state,['provisioning','failed']))$db->run('INSERT INTO activation_credentials(user_id,payload,expires_at,next_attempt_at) VALUES(?,?,?,?)',[$id,Zion\EncryptedPayload::encode(['password'=>'fixture-password-not-real']),$now+86400,$now+3600]);
 if($state==='failed')$db->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','retry_required',?)",[$id,$now]);
 if($state==='banned')$db->run('INSERT INTO account_controls(user_id,banned_at,updated_at) VALUES(?,?,?)',[$id,$now,$now]);
 session_id(bin2hex(random_bytes(24)));session_start();$_SESSION=['uid'=>$id,'auth_until'=>$state==='expired'?$now-1:$now+3600];$sessions[$state]=session_id();session_write_close();
}
$adminId=bin2hex(random_bytes(16));
$db->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',[$adminId,'preview-admin@example.test','fixture-cli-only',$now]);
session_name('zion_admin_session');session_id(bin2hex(random_bytes(24)));session_start();$_SESSION=['admin_id'=>$adminId,'admin_until'=>$now+3600];$sessions['admin']=session_id();session_write_close();
echo json_encode($sessions,JSON_THROW_ON_ERROR);
