<?php
/** Only protocol fixtures: never creates an account in the real Immich server. */
final class ImmichDefaultsFixture extends Zion\StorageProvisioningService {
 public function __construct(?Zion\Database $db=null){$db??=new Zion\Database(':memory:');$db->migrate();testFx($db);parent::__construct($db);}
 public array $adminCalls=[],$userCalls=[],$records=[];
 public bool $onboarded=false,$adminSession=false,$wrongIdentity=false,$wrongPreferences=false,$failLogout=false;
 public string $failPath='';
 protected function request(string $method,string $path,?array $body=null):array {
  $this->adminCalls[]=[$method,$path,$body];
  if($method==='GET')return $this->records;
  if($method==='POST'){$this->records[]=$body+['id'=>'defaults-storage-id'];return ['id'=>'defaults-storage-id'];}
  throw new RuntimeException('Unexpected administrative API');
 }
 protected function userRequest(string $method,string $path,?array $body,?string $token):array {
  $this->userCalls[]=[$method,$path,$body];
  if($path===$this->failPath)throw new Zion\StorageApiException(503);
  if($path==='/auth/login'){
   if($token!==null)throw new RuntimeException('Login must be anonymous');
   return ['accessToken'=>'fixture-transient-session-token==','userId'=>$this->wrongIdentity?'other-id':'defaults-storage-id','userEmail'=>'defaults@example.test','isAdmin'=>$this->adminSession];
  }
  if($token!=='fixture-transient-session-token==')throw new RuntimeException('User endpoint must use its own session');
  if($path==='/auth/logout'){if($this->failLogout)throw new Zion\StorageApiException(503);return ['successful'=>true];}
  if($path==='/users/me/onboarding'){if($method==='PUT')$this->onboarded=$body['isOnboarded'];return ['isOnboarded'=>$this->onboarded];}
  if($path==='/users/me/preferences')return $this->wrongPreferences?[]:$body;
  throw new RuntimeException('Unexpected user API');
 }
}
$imUser=['id'=>'default-portal-id','email'=>'defaults@example.test','name'=>'Defaults fixture'];
$imAdapter=new ImmichDefaultsFixture();
check($imAdapter->createUser($imUser,'test-only-password',100)==='defaults-storage-id','Immich defaults complete before linking new storage account');
check(array_column($imAdapter->userCalls,1)===['/auth/login','/users/me/onboarding','/users/me/preferences','/users/me/onboarding','/auth/logout'],'preferences confirmed before onboarding completion and temporary-session logout');
check($imAdapter->userCalls[2][2]===['cast'=>['gCastEnabled'=>false],'people'=>['enabled'=>false,'sidebarWeb'=>false],'memories'=>['enabled'=>true]],'explicit account defaults with no invented language/map/theme/backup fields');
check($imAdapter->adminCalls[1][2]['quotaSizeInBytes']===100 && $imAdapter->adminCalls[1][2]['shouldChangePassword']===false,'account defaults preserve quota and chosen-password behavior');
$imAdapter->userCalls=[];$imAdapter->createUser($imUser,'test-only-password',100);
check(array_column($imAdapter->userCalls,1)===['/auth/login','/users/me/onboarding','/auth/logout'],'completed onboarding preserves any subsequent user preferences');
check(count(array_filter($imAdapter->adminCalls,fn($c)=>$c[0]==='POST'))===1,'defaults retry never recreates linked remote identity');
foreach(['adminSession','wrongIdentity'] as $property){
 $probe=new ImmichDefaultsFixture();$probe->$property=true;
 try{$probe->createUser($imUser,'test-only-password',100);check(false,'reject unsafe session');}catch(RuntimeException $e){check($e->getMessage()==='storage_session_identity_mismatch','reject mismatched/admin session: '.$property);}
 check(array_column($probe->userCalls,1)===['/auth/login','/auth/logout'],'identity mismatch never writes preferences or administrator onboarding');
}
$probe=new ImmichDefaultsFixture();$probe->failPath='/users/me/preferences';
try{$probe->createUser($imUser,'test-only-password',100);check(false,'preference outage');}catch(Zion\StorageApiException $e){check(!$probe->onboarded,'preferences outage leaves onboarding pending');}
check(end($probe->userCalls)[1]==='/auth/logout','failed setup still revokes temporary user session');
$probe->failPath='';$probe->createUser($imUser,'test-only-password',100);
check(count(array_filter($probe->adminCalls,fn($c)=>$c[0]==='POST'))===1 && $probe->onboarded,'partial setup resumes against the exact existing storage label/email');
$probe=new ImmichDefaultsFixture();$probe->wrongPreferences=true;
try{$probe->createUser($imUser,'test-only-password',100);check(false,'invalid preferences response');}catch(RuntimeException $e){check($e->getMessage()==='storage_preferences_not_confirmed' && !$probe->onboarded,'HTTP success without saved settings cannot complete onboarding');}
$probe=new ImmichDefaultsFixture();$probe->failLogout=true;
try{$probe->createUser($imUser,'test-only-password',100);check(false,'logout outage');}catch(Zion\StorageApiException $e){check($probe->onboarded,'logout outage is surfaced after confirmed setup');}
$probe->failLogout=false;$probe->userCalls=[];$probe->createUser($imUser,'test-only-password',100);
check(!in_array('/users/me/preferences',array_column($probe->userCalls,1),true),'retry after logout failure does not reset completed preferences');
$probe=new ImmichDefaultsFixture();$probe->records=[['storageLabel'=>'someone-else','id'=>'foreign-id','email'=>$imUser['email']]];
try{$probe->createUser($imUser,'test-only-password',100);check(false,'unrelated identity');}catch(RuntimeException $e){check($e->getMessage()==='storage_identity_conflict' && !$probe->userCalls,'unrelated existing identity never receives a login/setup attempt');}
echo "Immich account-default assertions complete.\n";
