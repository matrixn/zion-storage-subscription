<?php
namespace Zion;
class StripeService implements BillingService {
 protected \Stripe\StripeClient $client;
 protected OblioInvoiceService $fiscal;
 protected array $settings;
 public function __construct(protected Database $db,protected Plans $plans,protected MailerService $mail,protected StorageProvisioningService $storage, ?OblioInvoiceService $fiscal=null) {
  $this->settings=(new IntegrationSettings($db))->stripe();
  $key=$this->settings['secret_key']?:'sk_test_unconfigured';
  if(!str_starts_with($key,'sk_test_') && !str_starts_with($key,'rk_test_')) throw new \RuntimeException('Only Stripe TEST MODE is enabled in this release');
  $this->client=new \Stripe\StripeClient($key);
  $this->fiscal=$fiscal??new OblioInvoiceService($db,$mail);
 }
 public function checkout(array $u): string {
  if((new AccountControl($this->db))->banned($u['id']) || (new AccountControl($this->db))->manual($u['id'])) throw new \RuntimeException('checkout_ineligible');
  if(!$u['email_verified_at'] || $u['selected_plan_id']==='free') throw new \RuntimeException('checkout_ineligible');
  $profile=(new BillingProfileService($this->db))->get($u['id']);
  if(!$profile) throw new \InvalidArgumentException('billing.required');
  BillingProfileService::validate($profile,$u);
  $interval=$u['billing_interval']??'month';$p=$this->plans->get($u['selected_plan_id'],$interval);
  if(!$p || !$p['stripe_price_id'] || !$this->settings['secret_key']) throw new \RuntimeException('billing_unconfigured');
  // Serialize session creation so two tabs cannot create separate subscriptions.
  $lock=fopen(dirname(__DIR__).'/storage/checkout-'.$u['id'].'.lock','c'); flock($lock,LOCK_EX);
  try {
   $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
   if($s && $s['stripe_subscription_id'] && !in_array($s['status'],['canceled','incomplete_expired'],true)) throw new \RuntimeException('subscription_exists');
   $old=$this->db->one('SELECT * FROM checkout_sessions WHERE user_id=? AND expires_at>?',[$u['id'],time()+60]);
   if($old && $old['price_id']===$p['stripe_price_id'] && $old['billing_interval']===$interval)return $old['url'];
   if($old)throw new \DomainException('checkout.pending_quote');
   BillingCatalog::validatePrice($this->client->prices->retrieve($p['stripe_price_id'])->toArray(),$p);
   $l=$u['locale']; $epoch=intdiv(time(),1800);
   $session=$this->client->checkout->sessions->create([
    'mode'=>'subscription','customer_email'=>$u['email'],'client_reference_id'=>$u['id'],'locale'=>$l==='ro'?'ro':$l,
    'line_items'=>[['price'=>$p['stripe_price_id'],'quantity'=>1]],
    'metadata'=>['user_id'=>$u['id'],'plan'=>$p['slug'],'billing_interval'=>$interval],
    'subscription_data'=>['metadata'=>['user_id'=>$u['id'],'plan'=>$p['slug'],'billing_interval'=>$interval]],
    'success_url'=>base_url().Router::path($l,'status'),'cancel_url'=>base_url().Router::path($l,'cancel'),
   ],['idempotency_key'=>'checkout-'.$u['id'].'-'.$p['stripe_price_id'].'-'.$epoch]);
   $this->db->run('INSERT INTO checkout_sessions(user_id,session_id,url,expires_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET session_id=excluded.session_id,url=excluded.url,expires_at=excluded.expires_at',[$u['id'],$session->id,$session->url,$session->expires_at]);
   $this->db->run('UPDATE checkout_sessions SET price_id=?,billing_interval=? WHERE user_id=?',[$p['stripe_price_id'],$interval,$u['id']]);
   return $session->url;
  } finally { flock($lock,LOCK_UN); fclose($lock); }
 }
 public function portal(array $u): string {
  $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
  if(!$s || !$s['stripe_customer_id']) throw new \RuntimeException('billing_unavailable');
  // Never expose Stripe's generic plan switcher: it bypasses the five-day policy.
  $key='restricted-portal-v1:'.hash('sha256',$this->settings['secret_key']);
  $stored=$this->db->one('SELECT value FROM integration_settings WHERE key=?',[$key]);
  if($stored)$config=$this->client->billingPortal->configurations->retrieve($stored['value']);
  else {
   $config=$this->client->billingPortal->configurations->create(['business_profile'=>['headline'=>'Zion Storage'],'features'=>[
    'invoice_history'=>['enabled'=>true],'payment_method_update'=>['enabled'=>true],
    'customer_update'=>['enabled'=>false],'subscription_update'=>['enabled'=>false],
    'subscription_cancel'=>['enabled'=>true,'mode'=>'at_period_end'],
   ]],['idempotency_key'=>$key]);
   $this->db->run('INSERT OR IGNORE INTO integration_settings(key,value) VALUES(?,?)',[$key,$config->id]);
  }
  if(!$config->active || ($config->features->subscription_update->enabled??true))throw new \RuntimeException('unsafe_portal_configuration');
  return $this->client->billingPortal->sessions->create(['customer'=>$s['stripe_customer_id'],'configuration'=>$config->id,'return_url'=>base_url().Router::path($u['locale'],'status')])->url;
 }
 public function changes(): SubscriptionChanges { return new SubscriptionChanges($this->db,$this->plans,$this->mail,$this->storage,$this->client,$this->fiscal); }
 public function catalog(): BillingCatalog { return new BillingCatalog($this->db,$this->plans,$this->client); }
 public function receive(string $payload,string $signature): void {
  if(!$this->settings['webhook_secret']) throw new \RuntimeException('webhook_unconfigured');
  $event=\Stripe\Webhook::constructEvent($payload,$signature,$this->settings['webhook_secret']);
  if($event->livemode) throw new \RuntimeException('live_event_rejected');
  $this->process($event->toArray());
 }
 protected function fetchSubscription(string $id): array { return $this->client->subscriptions->retrieve($id,['expand'=>['latest_invoice']])->toArray(); }
 protected function fetchInvoice(string $id): array {
  $invoice=$this->client->invoices->retrieve($id)->toArray();
  if($invoice['lines']['has_more']??false) {
   $lines=[]; foreach($this->client->invoices->allLines($id,['limit'=>100])->autoPagingIterator() as $line) $lines[]=$line->toArray();
   $invoice['lines']=['data'=>$lines,'has_more'=>false];
  }
  return $invoice;
 }
 private function invoicePlan(array $invoice,array $fallback): array {
  $lines=$invoice['lines']['data']??[];
  if(count($lines)===1) {
   $price=$lines[0]['price']['id']??$lines[0]['pricing']['price_details']['price']??'';
   if($plan=$this->plans->byPrice($price))return $plan;
  }
  return $fallback;
 }
 private function storeInvoice(array $invoice,string $uid): void {
  $this->db->run('INSERT INTO invoices(id,user_id,hosted_url,amount,currency,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET hosted_url=excluded.hosted_url,amount=excluded.amount,currency=excluded.currency',[
   $invoice['id'],$uid,$invoice['hosted_invoice_url']??null,$invoice['amount_paid']??0,$invoice['currency']??'ron',$invoice['created']??time()
  ]);
 }
 public function process(array $event): void {
  $types=['checkout.session.completed','invoice.paid','invoice.payment_failed','customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'];
  if(!in_array($event['type'],$types,true)) return;
  $lock=fopen(dirname(__DIR__).'/storage/billing.lock','c'); flock($lock,LOCK_EX);
  $userLock=null;
  try {
   if($this->db->one("SELECT id FROM billing_events WHERE id=? AND status='complete'",[$event['id']])) return;
   $this->db->run("INSERT INTO billing_events(id,type,status,created_at) VALUES(?,?,'processing',?) ON CONFLICT(id) DO UPDATE SET status='processing'",[$event['id'],$event['type'],time()]);
   $o=$event['data']['object'];
   // A delayed event must invoice its own paid cycle, never the latest subscription invoice.
   $paidInvoice=$event['type']==='invoice.paid'?$this->fetchInvoice($o['id']):null;
   if($paidInvoice && ($paidInvoice['status']??'')!=='paid') throw new \RuntimeException('invoice_not_paid');
   $source=$paidInvoice??$o;
   $upgradeId=$source['metadata']['upgrade_id']??null;
   if($upgradeId && in_array($event['type'],['checkout.session.completed','invoice.paid'],true)) {
    $change=$this->changes()->record($upgradeId);
    $userLock=fopen(dirname(__DIR__).'/storage/provision-'.$change['user_id'].'.lock','c');flock($userLock,LOCK_EX);
    $this->changes()->fulfill($upgradeId);
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);return;
   }
   $id=str_starts_with($event['type'],'customer.subscription.')?$o['id']:($source['subscription']??$source['parent']['subscription_details']['subscription']??null);
   if(!$id) {
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);
    return;
   }
   // Entitlements always use current authoritative subscription state.
   $s=$this->fetchSubscription($id);
   $uid=$s['metadata']['user_id']??'';
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   if(!$u) throw new \RuntimeException('unknown_billing_user');
   $userLock=fopen(dirname(__DIR__).'/storage/provision-'.$uid.'.lock','c');flock($userLock,LOCK_EX);
   $this->changes()->reconcile($s);
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   $manual=(new AccountControl($this->db))->manual($uid);
   $price=$s['items']['data'][0]['price']['id']??'';
   $p=$this->plans->byPrice($price);
   if(!$p || $p['slug']==='free' || $p['slug']!==($s['metadata']['plan']??'') || (!$manual && $p['slug']!==$u['selected_plan_id']) || (isset($s['metadata']['billing_interval']) && $s['metadata']['billing_interval']!==$p['billing_interval']))throw new \RuntimeException('billing_plan_mismatch');
   if($paidInvoice && ($paidInvoice['id']!==$o['id'] || ($paidInvoice['customer']??null)!==$s['customer'])) throw new \RuntimeException('invoice_identity_mismatch');
   if($paidInvoice && ($paidInvoice['currency']??'')!==$this->invoicePlan($paidInvoice,$p)['currency'])throw new \RuntimeException('invoice_currency_mismatch');
   $invoice=is_array($s['latest_invoice']??null)?$s['latest_invoice']:null;
   $paid=$invoice && ($invoice['status']??'')==='paid' && $s['status']==='active';
   $existing=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$uid]);
   $oldSubscription=false;
   if($existing && $existing['stripe_subscription_id']!==$id) {
    if(in_array($s['status'],['canceled','incomplete_expired'],true)) $oldSubscription=true;
    elseif(!in_array($existing['status'],['canceled','incomplete_expired'],true)) throw new \RuntimeException('duplicate_subscription');
   }
   $fiscalId=$this->db->transaction(function() use($s,$uid,$p,$price,$paid,$invoice,$paidInvoice,$oldSubscription,$u) {
    if(!$oldSubscription) $this->db->run('INSERT INTO subscriptions(user_id,stripe_customer_id,stripe_subscription_id,stripe_price_id,status,plan,renewal_at,last_invoice_id,paid_confirmed) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET stripe_customer_id=excluded.stripe_customer_id,stripe_subscription_id=excluded.stripe_subscription_id,stripe_price_id=excluded.stripe_price_id,status=excluded.status,plan=excluded.plan,renewal_at=excluded.renewal_at,last_invoice_id=excluded.last_invoice_id,paid_confirmed=excluded.paid_confirmed',[
     $uid,$s['customer'],$s['id'],$price,$s['status'],$p['slug'],$s['items']['data'][0]['current_period_end']??$s['current_period_end']??null,$invoice['id']??null,$paid?1:0]);
    if(!$oldSubscription){
     $this->db->run('UPDATE subscriptions SET billing_interval=?,currency=? WHERE user_id=?',[$p['billing_interval'],$p['currency'],$uid]);
     $this->db->run('UPDATE users SET billing_interval=? WHERE id=?',[$p['billing_interval'],$uid]);
    }
    if($invoice) $this->storeInvoice($invoice,$uid);
    if($paidInvoice) {
     $this->changes()->rememberOrigin($paidInvoice,$s,$uid);
     $this->storeInvoice($paidInvoice,$uid);
     return $this->fiscal->capture($paidInvoice,$u,$s,$this->invoicePlan($paidInvoice,$p));
    }
    return null;
   });
   // The durable fiscal job is processed by the CLI worker. Provider latency/outages
   // must never delay Stripe's webhook acknowledgement or change payment status.
   if(!$oldSubscription && !$manual && $u['storage_user_id'] && !(new AccountControl($this->db))->banned($uid)) {
    if($paid) $this->storage->enableUser($u['storage_user_id'],$p['storage_bytes']);
    else $this->storage->disableUser($u['storage_user_id']);
    $this->db->run('UPDATE users SET status=?,updated_at=? WHERE id=?',[$paid?'active':'restricted',time(),$uid]);
   }
   $this->db->transaction(function() use($u,$paidInvoice,$paid,$s,$event,$oldSubscription) {
    if($paidInvoice) $this->mail->queue($u['email'],$u['locale'],'payment',[],'paid:'.$paidInvoice['id']);
    if($paid && !$oldSubscription) $this->mail->queue($u['email'],$u['locale'],'subscription',[],'subscription:'.$s['id']);
    elseif($s['status']==='canceled') $this->mail->queue($u['email'],$u['locale'],'cancelled',[],'cancelled:'.$s['id']);
    elseif($event['type']==='invoice.payment_failed') $this->mail->queue($u['email'],$u['locale'],'failed',[],'failed:'.$event['id']);
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);
   });
  } finally { if($userLock){flock($userLock,LOCK_UN);fclose($userLock);} flock($lock,LOCK_UN); fclose($lock); }
 }
}
