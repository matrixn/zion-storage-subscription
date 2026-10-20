<?php
$onDb=new Zion\Database(':memory:');$onDb->migrate();$onView=new Zion\MobileOnboarding($onDb);
$onId=bin2hex(random_bytes(16));$onNow=time();
$onDb->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,storage_user_id,status,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$onId,'Onboarding','onboarding@example.test','fixture-hash','en','120gb',$onNow,null,'verified',$onNow,$onNow,$onNow,$onNow]);
$onUser=$onDb->one('SELECT * FROM users WHERE id=?',[$onId]);
check($onView->view(null,false,false,false)['state']==='session','onboarding requires authorized account context');
check($onView->view($onUser,false,false,true)['state']==='payment','checkout redirect alone cannot confirm payment');
check($onView->view(array_replace($onUser,['email_verified_at'=>null]),true,false,true)['state']==='verification','unverified account never shows ready');
check($onView->view($onUser,true,true,true)['state']==='suspended','ban takes precedence over provisioning and entitlements');
check($onView->view($onUser,true,false,true)['state']==='provisioning','eligible automatic account still waits for provisioning');
check($onView->view($onUser,true,false,false)['state']==='password','existing initial-password fallback remains available');
check($onView->view(array_replace($onUser,['terms_at'=>null]),true,false,true)['state']==='password','missing consent preserves existing activation form');
$onDb->run("INSERT INTO provisioning_events(user_id,action,status,created_at) VALUES(?,'create','retry_required',?)",[$onId,$onNow]);
check($onView->view($onUser,true,false,true)['state']==='failed','failed provisioning does not claim success');
$onUser['storage_user_id']='on-storage';$onUser['status']='active';
check($onView->view($onUser,false,false,false)['state']==='inactive','linked account without entitlement is not ready');
check($onView->view($onUser,true,false,false)===['state'=>'ready','firstFile'=>null],'confirmed provisioned eligible account ready without invented backup evidence');
$onHash=(new Zion\ImmichStatistics($onDb))->fingerprint();
$onDb->run('INSERT INTO immich_statistics(user_id,storage_user_id,used_bytes,photos,videos,synced_at,config_hash,available) VALUES(?,?,0,0,0,?,?,1)',[$onId,'on-storage',$onNow,$onHash]);
check($onView->view($onUser,true,false,false)['firstFile']===false,'fresh empty statistics indicate waiting only');
$onDb->run('UPDATE immich_statistics SET videos=1 WHERE user_id=?',[$onId]);
check($onView->view($onUser,true,false,false)['firstFile']===true,'existing aggregate video count confirms saved file');
foreach(['synced_at'=>time()-601,'available'=>0,'storage_user_id'=>'someone-else','config_hash'=>'obsolete-config'] as $column=>$value){
 $onDb->run('UPDATE immich_statistics SET '.$column.'=? WHERE user_id=?',[$value,$onId]);
 check($onView->view($onUser,true,false,false)['firstFile']===null,'onboarding ignores unavailable/stale/foreign statistics: '.$column);
 $onDb->run('UPDATE immich_statistics SET synced_at=?,available=1,storage_user_id=?,config_hash=? WHERE user_id=?',[$onNow,'on-storage',$onHash,$onId]);
}
$onDb->run("INSERT INTO subscriptions(user_id,status,paid_confirmed,plan) VALUES(?,'canceled',0,'120gb')",[$onId]);
check($onView->view(array_replace($onUser,['storage_user_id'=>null]),false,false,true)['state']==='inactive','canceled subscription without storage is inactive');
$onTranslations=require dirname(__DIR__).'/translations/onboarding.php';$onKeys=array_keys($onTranslations['ro']);sort($onKeys);
foreach(Zion\TranslationService::LOCALES as $loc){$keys=array_keys($onTranslations[$loc]);sort($keys);check($keys===$onKeys && !in_array('',array_values($onTranslations[$loc]),true),'complete nonempty onboarding translations '.$loc);}
echo "Mobile onboarding state and statistics assertions complete.\n";
