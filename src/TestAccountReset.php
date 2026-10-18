<?php
declare(strict_types=1);
namespace Zion;

/** Destructive TEST-only reset. Remote work is resumable; local cleanup is atomic. */
final class TestAccountReset {
 private \Stripe\StripeClient $stripe;private bool $networkAllowed;
 public function __construct(private Database $db,private SubscriptionChanges $changes,private StorageProvisioningService $storage,?\Stripe\StripeClient $stripe=null){
  $this->networkAllowed=$stripe!==null || env('APP_ENV')!=='test';
  $this->stripe=$stripe??new \Stripe\StripeClient((new IntegrationSettings($db))->stripe('test')['secret_key']?:'sk_test_unconfigured');
 }
 private function network(): void {
  if(!$this->networkAllowed)throw new \DomainException('Resetarea Stripe externă este dezactivată în testele izolate.');
  if(!preg_match('/^(sk|rk)_test_/',$this->stripe->getApiKey()))throw new \DomainException('Resetarea acceptă doar cheia Stripe TEST.');
 }
 private static function id(mixed $value): string {return is_array($value)?(string)($value['id']??''):(is_string($value)?$value:'');}
 private function allowed(array $u): void {
  if($u['stripe_mode']!=='test')throw new \DomainException('Resetarea este disponibilă doar pentru conturile TEST.');
  foreach(['subscriptions','checkout_sessions','plan_changes','invoices','fiscal_invoices','billing_events'] as $table)if($this->db->one('SELECT 1 FROM '.$table." WHERE user_id=? AND stripe_mode<>'test' LIMIT 1",[$u['id']]))throw new \DomainException('Contul are înregistrări LIVE și nu poate fi resetat ca test.');
  foreach($this->db->run('SELECT * FROM fiscal_invoices WHERE user_id=?',[$u['id']])->fetchAll() as $f){
   if(in_array($f['status'],['issued','cancelled'],true) || $f['oblio_invoice_id'] || $f['oblio_series'] || $f['oblio_number'] || $f['issued_at'])throw new \DomainException('Contul are documente fiscale reale; resetarea de test nu le șterge.');
   $source=EncryptedPayload::decode($f['source_snapshot']);
   if(!($source['simulated']??false) && $f['attempts']>0 && $f['amount']>0)throw new \DomainException('Există o posibilă emitere fiscală reală în așteptare. Clarifică documentul înainte de resetare.');
   if($this->db->one('SELECT 1 FROM fiscal_corrections WHERE original_fiscal_invoice_id=?',[$f['id']]))throw new \DomainException('Contul are corecții fiscale; resetarea de test a fost oprită.');
  }
 }
 private function plan(array $u): array {
  $uid=$u['id'];$sessions=[];$customers=[];$resources=[$uid];
  $rows=$this->db->run('SELECT session_id FROM checkout_sessions WHERE user_id=? UNION SELECT checkout_session_id FROM plan_changes WHERE user_id=? AND checkout_session_id IS NOT NULL',[$uid,$uid])->fetchAll();
  foreach($rows as $row){
   $this->network();$id=$row['session_id'];$s=$this->stripe->checkout->sessions->retrieve($id)->toArray();
   if(($s['livemode']??null)!==false || ($s['id']??'')!==$id || ($s['metadata']['user_id']??$s['client_reference_id']??'')!==$uid)throw new \DomainException('Sesiunea Checkout nu corespunde contului TEST.');
   $sessions[$id]=($s['status']??'')==='open';$resources[]=$id;
   if($c=self::id($s['customer']??null))$customers[$c]=true;
   if($sub=self::id($s['subscription']??null))$resources[]=$sub;
  }
  foreach(['subscriptions'=>'stripe_customer_id','fiscal_invoices'=>'stripe_customer_id','plan_changes'=>'customer_id'] as $table=>$column)foreach($this->db->run('SELECT '.$column.' id FROM '.$table.' WHERE user_id=?',[$uid])->fetchAll() as $row)if($row['id'])$customers[$row['id']]=true;
  foreach($this->db->run('SELECT stripe_subscription_id id FROM subscriptions WHERE user_id=? UNION SELECT stripe_subscription_id FROM plan_changes WHERE user_id=? UNION SELECT stripe_subscription_id FROM fiscal_invoices WHERE user_id=?',[$uid,$uid,$uid])->fetchAll() as $row)if($row['id']){
   $this->network();$sub=$this->stripe->subscriptions->retrieve($row['id'])->toArray();
   if(($sub['livemode']??null)!==false || ($sub['metadata']['user_id']??'')!==$uid)throw new \DomainException('Abonamentul Stripe nu corespunde contului TEST.');
   $resources[]=$sub['id'];if($customer=self::id($sub['customer']??null))$customers[$customer]=true;
  }
  foreach($customers as $id=>$_){
   $this->network();$customer=$this->stripe->customers->retrieve($id)->toArray();
   if(!($customer['deleted']??false) && ($customer['livemode']??null)!==false)throw new \DomainException('Clientul Stripe nu aparține mediului TEST.');
   foreach(['subscriptions'=>'stripe_customer_id','fiscal_invoices'=>'stripe_customer_id','plan_changes'=>'customer_id'] as $table=>$column)if($this->db->one('SELECT 1 FROM '.$table.' WHERE '.$column.'=? AND user_id<>? LIMIT 1',[$id,$uid]))throw new \DomainException('Clientul Stripe este partajat cu alt cont; resetarea a fost oprită.');
   if(!($customer['deleted']??false))foreach($this->stripe->subscriptions->all(['customer'=>$id,'status'=>'all','limit'=>100])->autoPagingIterator() as $subscription){
    $sub=$subscription->toArray();if(($sub['livemode']??null)!==false || ($sub['metadata']['user_id']??'')!==$uid)throw new \DomainException('Clientul Stripe conține un abonament care nu aparține acestui cont TEST.');
    $resources[]=$sub['id'];
   }
   $resources[]=$id;
  }
  foreach(['invoices'=>'id','fiscal_invoices'=>'stripe_invoice_id','subscriptions'=>'stripe_subscription_id','plan_changes'=>'stripe_subscription_id'] as $table=>$column)foreach($this->db->run('SELECT '.$column.' id FROM '.$table.' WHERE user_id=?',[$uid])->fetchAll() as $row)if($row['id'])$resources[]=$row['id'];
  if($u['storage_user_id']){$this->storage->assertTestResetIdentity($u['storage_user_id'],$uid,$u['email']);$resources[]=$u['storage_user_id'];}
  $events=ApiLog::eventsForResources($resources);
  return ['sessions'=>$sessions,'customers'=>array_keys($customers),'resources'=>array_values(array_unique(array_merge($resources,$events))),'event_ids'=>$events,'storage_id'=>$u['storage_user_id'],'email'=>$u['email'],'done'=>[]];
 }
 private function save(string $uid,array $plan,string $step): void {
  $this->db->transaction(function()use($uid,$plan,$step){
   $this->db->run('UPDATE test_account_resets SET snapshot=?,step=?,error_code=NULL,updated_at=? WHERE user_id=?',[EncryptedPayload::encode($plan),$step,time(),$uid]);
   foreach($plan['resources'] as $resource)$this->db->run('INSERT OR IGNORE INTO reset_resource_hashes VALUES(?,?)',[hash('sha256',$resource),$uid]);
  });
 }
 public function reset(string $uid,string $email,bool $confirm,bool $deleteStorage,string $admin): void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$uid))throw new \DomainException('Client invalid.');
  $this->changes->serialized($uid,function()use($uid,$email,$confirm,$deleteStorage,$admin){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   if(!$u){if($confirm && $this->db->one("SELECT 1 FROM test_account_resets WHERE user_id=? AND status='complete'",[$uid]))return;throw new \DomainException('Client inexistent.');}
   if(!$confirm || strtolower(trim($email))!==strtolower($u['email']))throw new \DomainException('Confirmă ștergerea și introdu exact emailul clientului.');
   $this->allowed($u);
   if($u['storage_user_id'] && !$deleteStorage)throw new \DomainException('Confirmă separat ștergerea contului Immich și a fișierelor sale.');
   $locks=[];
   try{
    foreach(['checkout-'.$uid,'mail'] as $name){$lock=fopen(dirname(__DIR__).'/storage/'.$name.'.lock','c');flock($lock,LOCK_EX);$locks[]=$lock;}
    foreach($this->db->run('SELECT id FROM fiscal_invoices WHERE user_id=?',[$uid])->fetchAll() as $row){$lock=fopen(dirname(__DIR__).'/storage/fiscal-'.hash('sha256',$row['id']).'.lock','c');flock($lock,LOCK_EX);$locks[]=$lock;}
    $this->allowed($u);
    $job=$this->db->one('SELECT * FROM test_account_resets WHERE user_id=?',[$uid]);
    if($job){
     $plan=EncryptedPayload::decode($job['snapshot']);$current=$this->plan($u);
     $plan['sessions']=array_replace($plan['sessions'],$current['sessions']);
     $plan['customers']=array_values(array_unique(array_merge($plan['customers'],$current['customers'])));
     $plan['event_ids']=array_values(array_unique(array_merge($plan['event_ids']??[],$current['event_ids'])));
     $plan['resources']=array_values(array_unique(array_merge($plan['resources'],$current['resources'])));$this->save($uid,$plan,'reconciled');
    }
    else{
     $plan=$this->plan($u);
     $this->db->transaction(function()use($uid,$admin,$plan){
      $this->db->run("INSERT INTO test_account_resets VALUES(?,?,'pending','planned',?,NULL,?,?)",[$uid,$admin,EncryptedPayload::encode($plan),time(),time()]);
      foreach($plan['resources'] as $resource)$this->db->run('INSERT INTO reset_resource_hashes VALUES(?,?)',[hash('sha256',$resource),$uid]);
      $this->db->run("INSERT INTO account_controls(user_id,banned_at,reason,updated_at) VALUES(?,?,'Resetare TEST în curs',?) ON CONFLICT(user_id) DO UPDATE SET banned_at=excluded.banned_at,reason=excluded.reason",[$uid,time(),time()]);
      $this->db->run("UPDATE users SET status='banned',updated_at=? WHERE id=?",[time(),$uid]);
     });
    }
    foreach($plan['sessions'] as $id=>$open)if($open && !in_array('session:'.$id,$plan['done'],true)){
     $this->network();$s=$this->stripe->checkout->sessions->retrieve($id)->toArray();
     if(($s['status']??'')==='complete')throw new \DomainException('Checkout s-a finalizat în timpul resetării. Reîncearcă resetarea pentru a include clientul Stripe nou creat.');
     if(($s['status']??'')==='open')$this->stripe->checkout->sessions->expire($id);
     $plan['done'][]='session:'.$id;$this->save($uid,$plan,'stripe_sessions');
    }
    foreach($plan['customers'] as $id)if(!in_array('customer:'.$id,$plan['done'],true)){
     $this->network();$c=$this->stripe->customers->retrieve($id)->toArray();
     if(!($c['deleted']??false))$this->stripe->customers->delete($id);
     $plan['done'][]='customer:'.$id;$this->save($uid,$plan,'stripe_customers');
    }
    if($plan['storage_id'] && !in_array('immich',$plan['done'],true)){$this->storage->purgeTestUser($plan['storage_id'],$uid,$plan['email']);$plan['done'][]='immich';$this->save($uid,$plan,'immich');}
    $this->db->transaction(function()use($uid,$admin,$u,$plan){
     foreach($plan['event_ids']??[] as $event)$this->db->run("DELETE FROM billing_events WHERE id=? AND stripe_mode='test'",[$event]);
     $this->db->run("DELETE FROM outbox WHERE kind='fiscal_invoice' AND dedupe_key IN (SELECT 'fiscal:'||stripe_invoice_id FROM fiscal_invoices WHERE user_id=?)",[$uid]);
     $this->db->run('DELETE FROM fiscal_corrections WHERE original_fiscal_invoice_id IN (SELECT id FROM fiscal_invoices WHERE user_id=?)',[$uid]);
     foreach(['email_verifications','billing_profiles','subscriptions','invoices','fiscal_invoices','account_controls','subscription_origins','plan_changes','provisioning_events','checkout_sessions','notices','billing_events','admin_audit'] as $table)$this->db->run('DELETE FROM '.$table.' WHERE user_id=?',[$uid]);
     $this->db->run('DELETE FROM outbox WHERE recipient=?',[$u['email']]);
     $this->db->run('DELETE FROM users WHERE id=?',[$uid]);
     $this->db->run("UPDATE test_account_resets SET status='complete',step='complete',snapshot='{}',error_code=NULL,updated_at=? WHERE user_id=?",[time(),$uid]);
     $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'test_account_reset','Cont TEST '.$uid.' și evidențe locale șterse; resursele externe de test au fost închise.',time()]);
    });
   }catch(\Throwable $e){
    $this->db->run('UPDATE test_account_resets SET error_code=?,updated_at=? WHERE user_id=?',['reset_requires_retry',time(),$uid]);
    if($e instanceof \DomainException)throw $e;
    throw new \DomainException('Resetarea nu s-a finalizat. Datele locale sunt păstrate; verifică jurnalul API și reîncearcă resetarea.');
   }finally{foreach(array_reverse($locks) as $lock){flock($lock,LOCK_UN);fclose($lock);}}
  });
 }
 public static function eventState(Database $db,array $object): ?string {
  $values=[];
  foreach(['id','customer','subscription','invoice'] as $key)if($id=self::id($object[$key]??null))$values[]=$id;
  foreach([$object['metadata']['user_id']??null,$object['client_reference_id']??null,$object['parent']['subscription_details']['subscription']??null,$object['parent']['subscription_details']['metadata']['user_id']??null] as $value)if(is_string($value)&&$value!=='')$values[]=$value;
  foreach($values as $value){$row=$db->one('SELECT r.status FROM test_account_resets r JOIN reset_resource_hashes h ON h.user_id=r.user_id WHERE h.resource_hash=?',[hash('sha256',$value)]);if($row)return $row['status'];}
  return null;
 }
}
