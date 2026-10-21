<?php
final class StatisticsFixture extends Zion\StorageProvisioningService {
 public array $data=['usage'=>0,'photos'=>0,'videos'=>0,'usageByUser'=>[]];public bool $fail=false;public int $calls=0;
 public function statistics(): array{$this->calls++;if($this->fail)throw new Zion\StorageApiException(403);return $this->data;}
}
$statsDb=new Zion\Database(':memory:');$statsDb->migrate();testFx($statsDb);$statsDb->migrate();testFx($statsDb);
$_ENV['ADMIN_EMAIL']='immich-admin@example.com';$_ENV['ADMIN_PASSWORD']='immich-admin-password';Zion\AccountControl::seedAdmin($statsDb);$statsAdmin=$statsDb->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
$settings=new Zion\IntegrationSettings($statsDb);
$_ENV['STORAGE_API_URL']='https://old.example/api';$_ENV['STORAGE_API_KEY']='environment-key-123456';
check($settings->immich()['api_key']==='environment-key-123456','Immich uses env only before admin configuration');
$settings->save('immich',['url'=>'https://photos.example','api_key'=>'admin-immich-api-key-123456'],$statsAdmin);
check($settings->immich()===['url'=>'https://photos.example/api','api_key'=>'admin-immich-api-key-123456'],'Immich admin normalizes URL and overrides env credentials');
$settings->save('immich',['url'=>'https://photos.example/api','api_key'=>Zion\IntegrationSettings::maskedSecret('admin-immich-api-key-123456')],$statsAdmin);
check($settings->immich()['api_key']==='admin-immich-api-key-123456','Immich masked key submission preserves actual credential');
foreach(['https://user:pass@photos.example/api','ftp://photos.example','https://photos.example/api?token=secret'] as $bad){try{$settings->save('immich',['url'=>$bad],$statsAdmin);check(false,'invalid Immich URL');}catch(DomainException $e){check(true,'Immich rejects credentials, non-HTTP schemes and query strings');}}
$settings->save('immich',['url'=>'https://photos.example/api','clear_api_key'=>'1'],$statsAdmin);
check($settings->immich()['api_key']==='','clearing saved Immich key never reactivates env fallback');
$settings->save('immich',['url'=>'https://photos.example/api','api_key'=>'admin-immich-api-key-123456'],$statsAdmin);
$fixture=new StatisticsFixture();$statistics=new Zion\ImmichStatistics($statsDb,$fixture);
check($statistics->check()['status']==='ok' && !isset($statistics->overview()['state']['last_success_at']),'connection test does not invent a successful synchronization');
$ids=[];
for($i=0;$i<12;$i++){
 $id=bin2hex(random_bytes(16));$ids[]=$id;
 $statsDb->run('INSERT INTO users(id,name,email,password_hash,locale,selected_plan_id,email_verified_at,storage_user_id,terms_at,privacy_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,'Stats '.$i,'stats'.$i.'@example.com','fixture-hash','ro','120gb',time(),'remote-'.$i,time(),time(),time(),time()]);
 $fixture->data['usageByUser'][]=['userId'=>'remote-'.$i,'usage'=>($i+1)*1_000_000_000,'photos'=>100+$i,'videos'=>$i,'quotaSizeInBytes'=>120_000_000_000];
}
$fixture->data['usage']=999_000_000_000;$fixture->data['photos']=9999;$fixture->data['videos']=999;
$fixture->data['usageByUser'][]=['userId'=>'foreign','userName'=>'Do not persist foreign name','usage'=>900_000_000_000,'photos'=>9000,'videos'=>900];
check($statistics->synchronize()['matched']===12,'sync associates statistics by existing Immich IDs only');
$overview=$statistics->overview();
check($overview['totals']['used_bytes']===78_000_000_000 && $overview['totals']['clients']===12,'managed total excludes unrelated Immich users');
check(count($overview['top'])===10 && $overview['top'][0]['storage_user_id']==='remote-11' && $overview['top'][0]['percent']===10.0,'top ten is ordered by usage with actual plan quota denominator');
check($statsDb->one('SELECT photos,videos FROM immich_statistics WHERE user_id=?',[$ids[0]])===['photos'=>100,'videos'=>0],'per-client photo/video counts persist, including genuine zero');
$held=fopen(dirname(__DIR__).'/storage/immich-statistics.lock','c');flock($held,LOCK_EX);
check($statistics->synchronize()['status']==='busy','manual and scheduled sync cannot overlap');flock($held,LOCK_UN);fclose($held);
$calls=$fixture->calls;check($statistics->synchronize(true)['status']==='skipped' && $fixture->calls===$calls,'five-minute due guard avoids duplicate API polling');
$fixture->fail=true;check($statistics->synchronize()['status']==='error' && $statistics->overview()['totals']['used_bytes']===78_000_000_000 && $statistics->overview()['stale'],'API failure retains last confirmed values and labels them stale');$fixture->fail=false;
$fixture->data['usageByUser'][0]['usage']='broken';
check($statistics->synchronize()['status']==='error' && $statistics->overview()['totals']['used_bytes']===78_000_000_000,'malformed API payload cannot overwrite statistics with zeros');
$fixture->data['usageByUser'][0]['usage']=1_000_000_000;array_shift($fixture->data['usageByUser']);$statistics->synchronize();
check($statistics->overview()['totals']['clients']===11 && (int)$statsDb->one('SELECT available FROM immich_statistics WHERE user_id=?',[$ids[0]])['available']===0,'missing remote accounts are distinguished from empty accounts');
$statsDb->run('DELETE FROM users WHERE id=?',[$ids[1]]);
check(!$statsDb->one('SELECT user_id FROM immich_statistics WHERE user_id=?',[$ids[1]]) && $statistics->overview()['totals']['clients']===10,'deleted clients cannot linger in statistics or totals');
$settings->save('immich',['url'=>'https://different.example/api','api_key'=>'other-immich-key-123456'],$statsAdmin);
check($statistics->overview()['totals']['clients']===0 && !$statistics->state(),'changing Immich instance/credentials invalidates cached statistics and connection state');
check(Zion\ImmichStatistics::gb(3_000_000_000)==='3,00 GB','usage display uses decimal GB consistent with plan capacities');
$_ENV['STORAGE_API_URL']='';$_ENV['STORAGE_API_KEY']='';
echo "$count total assertions passed including Immich configuration/statistics.\n";
