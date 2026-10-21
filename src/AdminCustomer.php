<?php
declare(strict_types=1);
namespace Zion;

class AdminCustomer {
 public function __construct(private Database $db,private MailerService $mail,private ?StorageProvisioningService $storage=null) {}
 public function customer(string $uid): array {
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
  if(!$u)throw new \DomainException('Clientul nu există.');
  unset($u['password_hash']);return $u;
 }
 public function audit(string $uid,string $admin,string $action,string $detail=''): void {
  $this->db->run('INSERT INTO admin_audit(admin_id,user_id,action,detail,created_at) VALUES(?,?,?,?,?)',[$admin,$uid,$action,$detail,time()]);
 }
 public function invoiceEmail(string $uid,string $id,string $admin): string {
  $u=$this->customer($uid);
  $row=$this->db->one("SELECT oblio_series,oblio_number FROM fiscal_invoices WHERE id=? AND user_id=? AND status IN ('issued','cancelled') AND oblio_number IS NOT NULL",[$id,$uid]);
  if(!$row)throw new \DomainException('Factura nu este disponibilă pentru acest client.');
  $profile=(new BillingProfileService($this->db))->get($uid);
  $key='invoice-copy:'.$uid.':'.bin2hex(random_bytes(16));
  $this->mail->queue($profile['billing_email']??$u['email'],$u['locale'],'invoice_copy',['name'=>$u['name'],'invoice'=>$row['oblio_series'].' / '.$row['oblio_number'],'attachment_invoice_id'=>$id,'user_id'=>$uid],$key);
  $this->audit($uid,$admin,'invoice_email',$id);
  return $this->mail->dispatch($key);
 }
 public function saveBilling(string $uid,array $input,string $admin): void {
  $u=$this->customer($uid);
  try{(new BillingProfileService($this->db))->save($uid,$input);}
  catch(\InvalidArgumentException $e){throw new \DomainException('Verifică datele de facturare și câmpurile obligatorii.');}
  $this->audit($uid,$admin,'customer_billing_edit','Datele viitoare de facturare au fost actualizate; facturile emise sunt păstrate.');
 }
 /** Durable intent permits retry after a partial remote update, without changing its target. */
 public function saveContact(string $uid,array $input,string $admin,bool $retry=false): void {
  $global=fopen(dirname(__DIR__).'/storage/billing.lock','c');
  if(!$global || !flock($global,LOCK_EX))throw new \RuntimeException('billing_lock_unavailable');
  $lock=null;
  try{
   $u=$this->customer($uid);
   if(!preg_match('/^[a-f0-9]{32}$/D',$uid))throw new \DomainException('Client invalid.');
   $lock=fopen(dirname(__DIR__).'/storage/provision-'.$uid.'.lock','c');
   if(!$lock || !flock($lock,LOCK_EX))throw new \RuntimeException('customer_lock_unavailable');
   $pending=$this->db->one('SELECT * FROM customer_contact_changes WHERE user_id=?',[$uid]);
   if($pending && !$retry)throw new \DomainException('Există o modificare în așteptare. Reîncearcă sincronizarea înaintea unei alte editări.');
   if($retry && !$pending)throw new \DomainException('Nu există o modificare de reluat.');
   $data=$pending?EncryptedPayload::decode($pending['payload']):array_intersect_key($input,array_flip(['name','email','locale']));
   $data=['name'=>trim((string)($data['name']??'')),'email'=>strtolower(trim((string)($data['email']??''))),'locale'=>(string)($data['locale']??'')];
   if(mb_strlen($data['name'])<2 || mb_strlen($data['name'])>150 || preg_match('/[\x00-\x1f\x7f]/',$data['name']) || !filter_var($data['email'],FILTER_VALIDATE_EMAIL) || strlen($data['email'])>254 || !in_array($data['locale'],TranslationService::LOCALES,true))throw new \DomainException('Verifică numele, emailul și limba clientului.');
   if($this->db->one('SELECT id FROM users WHERE email=? AND id<>?',[$data['email'],$uid]))throw new \DomainException('Emailul aparține altui client.');
   if(!$pending)$this->db->run('INSERT INTO customer_contact_changes(user_id,payload,admin_id,created_at) VALUES(?,?,?,?)',[$uid,EncryptedPayload::encode($data),$admin,time()]);
   if($u['storage_user_id'])($this->storage??new StorageProvisioningService($this->db))->updateContact($u,$data);
   $this->syncStripe($u,$data);
   $this->db->transaction(function()use($u,$uid,$data,$admin){
    $changed=$u['email']!==$data['email'];
    $this->db->run('UPDATE users SET name=?,email=?,locale=?,session_version=session_version+?,updated_at=? WHERE id=?',[$data['name'],$data['email'],$data['locale'],(int)$changed,time(),$uid]);
    if($changed){
     $this->db->run('DELETE FROM email_verifications WHERE user_id=?',[$uid]);
     $this->db->run("DELETE FROM outbox WHERE sent_at IS NULL AND recipient=? AND kind IN ('verify','onboarding_access')",[$u['email']]);
     $this->db->run('UPDATE onboarding_links SET expires_at=? WHERE user_id=?',[time()-1,$uid]);
    }
    $this->db->run('DELETE FROM customer_contact_changes WHERE user_id=?',[$uid]);
    $this->audit($uid,$admin,'customer_contact_edit','Date contact sincronizate. Email schimbat: '.($changed?'da':'nu'));
   });
  }finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}flock($global,LOCK_UN);fclose($global);}
 }
 protected function syncStripe(array $u,array $data): void {
  $s=$this->db->one('SELECT stripe_customer_id,stripe_subscription_id,stripe_mode FROM subscriptions WHERE user_id=?',[$u['id']]);
  if(!$s || !$s['stripe_customer_id'])return;
  $config=(new IntegrationSettings($this->db))->stripe($s['stripe_mode']);
  $client=new \Stripe\StripeClient($config['secret_key']);
  $subscription=$client->subscriptions->retrieve($s['stripe_subscription_id']);
  if(($subscription->metadata['user_id']??'')!==$u['id'] || $subscription->customer!==$s['stripe_customer_id'])throw new \DomainException('Identitatea abonamentului Stripe nu corespunde.');
  $customer=$client->customers->retrieve($s['stripe_customer_id']);
  if(($customer->deleted??false))throw new \DomainException('Identitatea clientului Stripe nu corespunde.');
  $client->customers->update($s['stripe_customer_id'],['name'=>$data['name'],'email'=>$data['email']]);
 }
}
