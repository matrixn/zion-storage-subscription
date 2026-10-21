<?php
final class ImmichConfigurationFixture extends Zion\StorageProvisioningService {
 public array $config=['machineLearning'=>['enabled'=>true,'facialRecognition'=>['enabled'=>false,'modelName'=>'preserve-model'],'clip'=>['enabled'=>true]],'map'=>['enabled'=>true],'oauth'=>['clientSecret'=>'never-display-this-secret'],'passwordLogin'=>['enabled'=>true],'storageTemplate'=>['enabled'=>false]];
 public int $reads=0,$writes=0;public bool $deny=false,$unconfirmed=false,$race=false;
 public function configuration():array {
  $this->reads++;if($this->deny)throw new Zion\StorageApiException(403);
  if($this->race && $this->reads===3)$this->config['storageTemplate']['enabled']=true;
  return $this->config;
 }
 public function updateConfiguration(array $config):array{$this->writes++;if(!$this->unconfirmed)$this->config=$config;return $config;}
}
$icDb=new Zion\Database(':memory:');$icDb->migrate();testFx($icDb);$icAdmin=bin2hex(random_bytes(16));
$icDb->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',[$icAdmin,'config@example.test','fixture-hash',time()]);
$icTransport=new ImmichConfigurationFixture();$ic=new Zion\ImmichConfiguration($icDb,$icTransport);
check($ic->accountDefaults()===['cast'=>false,'people'=>false,'memories'=>true],'Immich UI starts with the privacy-oriented new-account profile');
$icFingerprint=(new Zion\ImmichStatistics($icDb))->fingerprint();
$ic->saveAccountDefaults(['cast'=>'1','people'=>'1','memories'=>'0'],$icAdmin);
check($ic->preferences()===['cast'=>['gCastEnabled'=>true],'people'=>['enabled'=>true,'sidebarWeb'=>true],'memories'=>['enabled'=>false]],'saved administrator defaults map to actual Immich account fields');
check((new Zion\ImmichStatistics($icDb))->fingerprint()===$icFingerprint,'account preferences never invalidate Immich API/statistics identity');
check($icTransport->reads===0 && $icTransport->writes===0,'saving account defaults does not mutate server configuration');
$icProvision=new ImmichDefaultsFixture($icDb);$icProvision->createUser($imUser,'test-only-password',100);
check($icProvision->userCalls[2][2]===$ic->preferences(),'new provisioning uses the administrator-saved profile');
$icBefore=$ic->accountDefaults();
foreach([['cast'=>'true','people'=>'0','memories'=>'1'],['cast'=>'0','people'=>[],'memories'=>'1'],['cast'=>'0','people'=>'0'],['cast'=>'0','people'=>'0','memories'=>'1','password'=>'secret']] as $bad){
 try{$ic->saveAccountDefaults($bad,$icAdmin);check(false,'invalid defaults');}catch(DomainException $e){check($ic->accountDefaults()===$icBefore,'malformed or unknown preferences are rejected atomically');}
}
$icView=$ic->server();$icOriginal=$icTransport->config;
check(array_keys($icView)===['flags','revision'] && !str_contains(json_encode($icView),'never-display-this-secret'),'general admin model contains only booleans and opaque revision');
$icFlags=['machine_learning'=>'1','facial_recognition'=>'1','smart_search'=>'0','map'=>'1'];
$ic->saveServer($icFlags,$icView['revision'],$icAdmin);
$icExpected=$icOriginal;$icExpected['machineLearning']['facialRecognition']['enabled']=true;$icExpected['machineLearning']['clip']['enabled']=false;
check($icTransport->config===$icExpected && $icTransport->writes===1,'global toggles preserve every unrelated field including secret/model/auth/storage configuration');
try{$ic->saveServer($icFlags,$icView['revision'],$icAdmin);check(false,'stale form');}catch(DomainException $e){check($icTransport->writes===1,'stale configuration revision cannot overwrite later edits');}
$icView=$ic->server();$icFlags['facial_recognition']='0';$ic->saveServer($icFlags,$icView['revision'],$icAdmin);
check($icTransport->config['machineLearning']['facialRecognition']['enabled']===false && $icTransport->config['map']['enabled']===true,'facial recognition can be disabled again independently of maps');
$icView=$ic->server();$icWrites=$icTransport->writes;$ic->saveServer($icFlags,$icView['revision'],$icAdmin);
check($icTransport->writes===$icWrites,'unchanged form never writes full Immich configuration');
$icTransport->deny=true;
try{$ic->server();check(false,'API denied');}catch(Zion\StorageApiException $e){check(str_contains(Zion\ImmichConfiguration::error($e),'systemConfig.read'),'restricted key gives safe permission guidance');}
$icTransport->deny=false;$icTransport->unconfirmed=true;$icView=$ic->server();$icFlags['map']='0';
try{$ic->saveServer($icFlags,$icView['revision'],$icAdmin);check(false,'unconfirmed write');}catch(RuntimeException $e){check($e->getMessage()==='immich_configuration_not_confirmed','HTTP success alone cannot claim a saved global configuration');}
check((bool)$icDb->one("SELECT id FROM admin_audit WHERE action='immich_server_unconfirmed'"),'uncertain remote outcome is recorded without inventing rollback');
$icRaceTransport=new ImmichConfigurationFixture();$icRaceTransport->race=true;$icRace=new Zion\ImmichConfiguration($icDb,$icRaceTransport);$icRaceView=$icRace->server();
try{$icRace->saveServer($icFlags,$icRaceView['revision'],$icAdmin);check(false,'parallel edit');}catch(DomainException $e){check($icRaceTransport->writes===0,'second read detects a parallel Immich operator edit');}
check(!str_contains(json_encode($icDb->run('SELECT detail FROM admin_audit')->fetchAll()),'never-display-this-secret'),'configuration audit stores no full configuration or secret values');
echo "Immich general configuration assertions complete.\n";
