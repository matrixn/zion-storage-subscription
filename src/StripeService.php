<?php
namespace Zion;
class StripeService implements BillingService {
 public const WEBHOOK_TYPES=['invoice.created','invoice.payment_action_required','checkout.session.completed','invoice.paid','invoice.payment_failed','customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'];
 protected array $webhookDebug=[];
 private function trace(string $stage,array $context=[]): void {$this->webhookDebug=array_replace($this->webhookDebug,$context,['stage'=>$stage]);ApiLog::context($this->webhookDebug);}
 protected \Stripe\StripeClient $client;
 protected OblioInvoiceService $fiscal;
 protected array $settings;
 public function __construct(protected Database $db,protected Plans $plans,protected MailerService $mail,protected StorageProvisioningService $storage, ?OblioInvoiceService $fiscal=null,?string $mode=null) {
  $this->settings=(new IntegrationSettings($db))->stripe($mode);
  $key=$this->settings['secret_key']?:'sk_'.$this->settings['mode'].'_unconfigured';
  if(!preg_match('/^(sk|rk)_'.$this->settings['mode'].'_/',$key))throw new \RuntimeException('Stripe key does not match selected mode');
  $this->client=new \Stripe\StripeClient($key);
  $this->fiscal=$fiscal??new OblioInvoiceService($db,$mail);
 }
 public function checkout(array $u,?string $quote=null): string {
  if(($u['stripe_mode']??'test')!==$this->settings['mode'])return (new self($this->db,$this->plans,$this->mail,$this->storage,null,$u['stripe_mode']))->checkout($u,$quote);
  if((new AccountControl($this->db))->banned($u['id']) || (new AccountControl($this->db))->manual($u['id'])) throw new \RuntimeException('checkout_ineligible');
  if(!$u['email_verified_at'] || $u['selected_plan_id']==='free') throw new \RuntimeException('checkout_ineligible');
  $profile=(new BillingProfileService($this->db))->get($u['id']);
  if(!$profile) throw new \InvalidArgumentException('billing.required');
  BillingProfileService::validate($profile,$u);
  (new BnrRates($this->db))->refresh();
  $interval=$u['billing_interval']??'month';$p=$this->plans->get($u['selected_plan_id'],$interval,$this->settings['mode']);
  if(!$p || !$p['fx'] || !$this->settings['secret_key']) throw new \RuntimeException('billing_unconfigured');
  if($quote!==null && !hash_equals(BnrRates::signature($p,$u['id']),$quote))throw new \DomainException('checkout.quote_changed');
  // Serialize session creation so two tabs cannot create separate subscriptions.
  $lock=fopen(dirname(__DIR__).'/storage/checkout-'.$u['id'].'.lock','c'); flock($lock,LOCK_EX);
  try {
   $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
   if($s && $s['stripe_subscription_id'] && !in_array($s['status'],['canceled','incomplete_expired'],true)) throw new \RuntimeException('subscription_exists');
   $old=$this->db->one('SELECT * FROM checkout_sessions WHERE user_id=? AND expires_at>?',[$u['id'],time()+60]);
   if($old && ($old['fx_snapshot']?json_decode($old['fx_snapshot'],true)['eur_ron']??null:null)===$p['fx']['eur_ron'] && ($old['fx_snapshot']?json_decode($old['fx_snapshot'],true)['rate_date']??null:null)===$p['fx']['rate_date'] && $old['price_id']===$p['stripe_price_id'] && $old['billing_interval']===$interval && $old['stripe_mode']===$this->settings['mode'] && $old['tax_bps']===$p['tax_bps'])return $old['url'];
   if($old)throw new \DomainException('checkout.pending_quote');
   $mapping=$this->db->one('SELECT currency,amount,storage_bytes FROM billing_prices WHERE price_id=?',[$p['stripe_price_id']]);
   if(!$mapping || $mapping['currency']!=='ron' || $mapping['amount']!==$p['price_amount'] || ($mapping['storage_bytes']!==null && $mapping['storage_bytes']!==$p['storage_bytes'])){
    $admin=$this->db->one('SELECT id FROM administrators ORDER BY created_at LIMIT 1')['id']??null;
    if(!$admin)throw new \DomainException('upgrade.unconfigured');
    $this->catalog()->synchronize($p['slug'],$interval,$admin);
    $p['stripe_price_id']=$this->plans->get($p['slug'],$interval,$this->settings['mode'])['stripe_price_id'];
   }
   if(!$p['stripe_price_id'])throw new \DomainException('upgrade.unconfigured');
   BillingCatalog::validatePrice($this->client->prices->retrieve($p['stripe_price_id'])->toArray(),$p);
   $rates=(new BillingTax($this->db))->rates($this->client,$this->settings['mode'],$p['tax_bps']);
   $l=$u['locale']; $epoch=intdiv(time(),1800);
   $session=$this->client->checkout->sessions->create([
    'expires_at'=>time()+1860,'mode'=>'subscription','customer_email'=>$u['email'],'client_reference_id'=>$u['id'],'locale'=>$l==='ro'?'ro':$l,
    'line_items'=>[['price'=>$p['stripe_price_id'],'quantity'=>1,'tax_rates'=>$rates]],
    'metadata'=>['user_id'=>$u['id'],'plan'=>$p['slug'],'billing_interval'=>$interval,'stripe_mode'=>$this->settings['mode']],
    'custom_text'=>['submit'=>['message'=>(new TranslationService($l))->get('fx.policy')]],
    'subscription_data'=>['metadata'=>BnrRates::metadata($p)+['zion_vat_bps'=>(string)$p['tax_bps'],'user_id'=>$u['id'],'plan'=>$p['slug'],'billing_interval'=>$interval,'stripe_mode'=>$this->settings['mode']]],
    'success_url'=>base_url().Router::path($l,'status'),'cancel_url'=>base_url().Router::path($l,'cancel'),
   ],['idempotency_key'=>'checkout-'.$u['id'].'-'.$p['stripe_price_id'].'-vat'.$p['tax_bps'].'-'.$epoch]);
   $this->db->run('INSERT INTO checkout_sessions(user_id,session_id,url,expires_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET session_id=excluded.session_id,url=excluded.url,expires_at=excluded.expires_at',[$u['id'],$session->id,$session->url,$session->expires_at]);
   $this->db->run('UPDATE checkout_sessions SET price_id=?,billing_interval=?,stripe_mode=?,tax_bps=? WHERE user_id=?',[$p['stripe_price_id'],$interval,$this->settings['mode'],$p['tax_bps'],$u['id']]);
   $this->db->run('UPDATE checkout_sessions SET fx_snapshot=? WHERE user_id=?',[json_encode($p['fx']+['eur_amount'=>$p['eur_amount'],'ron_amount'=>$p['price_amount']],JSON_THROW_ON_ERROR),$u['id']]);
   return $session->url;
  } finally { flock($lock,LOCK_UN); fclose($lock); }
 }
 public function portal(array $u): string {
  $s=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
  if(!$s || !$s['stripe_customer_id']) throw new \RuntimeException('billing_unavailable');
  if($s['stripe_mode']!==$this->settings['mode'])return (new self($this->db,$this->plans,$this->mail,$this->storage,null,$s['stripe_mode']))->portal($u);
  if(!$this->settings['secret_key'])throw new \RuntimeException('billing_unconfigured');
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
 public function changes(): SubscriptionChanges { return new SubscriptionChanges($this->db,$this->plans,$this->mail,$this->storage,$this->client,$this->fiscal,$this->settings['mode']); }
 public function catalog(): BillingCatalog { return new BillingCatalog($this->db,$this->plans,$this->client,$this->settings['mode']); }
 public function receive(string $payload,string $signature): void {
  $started=microtime(true);$http=204;$error=[];
  $this->webhookDebug=['source'=>'webhook','delivery_id'=>bin2hex(random_bytes(8)),'signature'=>$signature===''?'missing':'not_checked','expected_mode'=>is_string($_GET['mode']??null)?$_GET['mode']:'','stage'=>'received','outcome'=>'received'];
  $previous=ApiLog::context($this->webhookDebug);
  ApiLog::record('stripe','POST','/webhooks/stripe',202,$started,['direction'=>'in','mode'=>$_GET['mode']??'','message'=>WebhookDiagnostics::result($this->webhookDebug),'context'=>$this->webhookDebug]);
  try{
  $this->trace('decode');
  $raw=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
  if(!is_array($raw))throw new \UnexpectedValueException('webhook_mode_missing');
  $this->trace('mode',['event_id'=>$raw['id']??'','event_type'=>$raw['type']??'','api_version'=>$raw['api_version']??'']);
  if(!is_bool($raw['livemode']??null))throw new \UnexpectedValueException('webhook_mode_missing');
  $mode=$raw['livemode']?'live':'test';
  $this->trace('mode',['payload_mode'=>$mode]);
  if(isset($_GET['mode']) && $_GET['mode']!==$mode)throw new \UnexpectedValueException('webhook_mode_mismatch');
  $this->trace('secret');
  $settings=(new IntegrationSettings($this->db))->stripe($mode);
  if(!$settings['webhook_secret'])throw new \RuntimeException('webhook_unconfigured');
  $this->trace('signature');
  $event=\Stripe\Webhook::constructEvent($payload,$signature,$settings['webhook_secret']);
  $this->trace('validated',['signature'=>'valid']);
  if($event->livemode!==($mode==='live'))throw new \RuntimeException('webhook_mode_mismatch');
  StripeIntegration::received($this->db,$settings,(string)$event->id,(string)$event->type);
  if($mode!==$this->settings['mode']){
   $other=new self($this->db,$this->plans,$this->mail,$this->storage,null,$mode);$other->webhookDebug=$this->webhookDebug;
   try{$other->process($event->toArray());}finally{$this->webhookDebug=$other->webhookDebug;}return;
  }
  $this->process($event->toArray());
  }catch(\Throwable $e){
   $http=$e instanceof \JsonException || $e instanceof \UnexpectedValueException || $e instanceof \Stripe\Exception\SignatureVerificationException?400:503;
   $error=WebhookDiagnostics::error($e);$this->webhookDebug['outcome']='failed';
   if($e instanceof \Stripe\Exception\SignatureVerificationException)$this->webhookDebug['signature']=$signature===''?'missing':($error['code']==='signature_expired'?'expired':'invalid');
   throw $e;
  }finally{
   ApiLog::record('stripe','POST','/webhooks/stripe',$http,$started,['direction'=>'in','mode'=>$this->webhookDebug['payload_mode']??$_GET['mode']??'','context'=>$this->webhookDebug]+$error+['message'=>WebhookDiagnostics::result($this->webhookDebug)]);
   ApiLog::context($previous);
  }
 }
 protected function fetchEvent(string $id): array {if(env('APP_ENV')==='test')throw new \RuntimeException('test_network_forbidden');return $this->client->events->retrieve($id)->toArray();}
 public function replayEvent(string $id,string $admin): array {
  if(!preg_match('/^evt_[a-zA-Z0-9_]{1,140}$/D',$id))throw new \DomainException('Introdu un Event ID Stripe valid (evt_…).');
  $started=microtime(true);$error=[];$http=200;
  $this->webhookDebug=['source'=>'stripe_api_replay','delivery_id'=>bin2hex(random_bytes(8)),'signature'=>'not_applicable','event_id'=>$id,'payload_mode'=>$this->settings['mode'],'stage'=>'replay_fetch'];
  $previous=ApiLog::context($this->webhookDebug);
  try{
   $event=$this->fetchEvent($id);
   if(($event['id']??'')!==$id || ($event['object']??'')!=='event')throw new \RuntimeException('event_identity_mismatch');
   if(($event['livemode']??null)!==($this->settings['mode']==='live'))throw new \RuntimeException('event_mode_mismatch');
   $this->trace('validated',['event_type'=>$event['type']??'','api_version'=>$event['api_version']??'']);
   if(!in_array($event['type']??'',self::WEBHOOK_TYPES,true))throw new \RuntimeException('unsupported_replay_event');
   $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'stripe_event_replay',$this->settings['mode'].' / '.$id.' / '.$event['type'],time()]);
   $this->process($event);
   return $this->webhookDebug;
  }catch(\Throwable $e){$http=503;$error=WebhookDiagnostics::error($e);$this->webhookDebug['outcome']='failed';throw new \DomainException($error['message']);}
  finally{ApiLog::record('stripe','POST','/admin/stripe-event-replay',$http,$started,['direction'=>'in','mode'=>$this->settings['mode'],'context'=>$this->webhookDebug]+$error+['message'=>WebhookDiagnostics::result($this->webhookDebug)]);ApiLog::context($previous);}
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
 /** Recover a missing fiscal job from the original paid Stripe invoice, without replaying entitlements. */
 public function prepareFiscalInvoice(string $id): string {
  $lock=fopen(dirname(__DIR__).'/storage/billing.lock','c');flock($lock,LOCK_EX);
  try{return $this->prepareFiscalInvoiceLocked($id);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function prepareFiscalInvoiceLocked(string $id): string {
  $existing=$this->db->one('SELECT id,stripe_mode FROM fiscal_invoices WHERE stripe_invoice_id=?',[$id]);
  if($existing){if($existing['stripe_mode']!==$this->settings['mode'])throw new \DomainException('Mediul plății nu corespunde.');return $existing['id'];}
  $payment=$this->db->one('SELECT * FROM invoices WHERE id=?',[$id]);
  if(!$payment)$payment=$this->db->one('SELECT invoice_id id,user_id,amount,currency,stripe_mode FROM plan_changes WHERE invoice_id=? AND paid_at IS NOT NULL',[$id]);
  if(!$payment || $payment['stripe_mode']!==$this->settings['mode'] || $payment['amount']<=0)throw new \DomainException('Plata nu există în mediul selectat.');
  if($this->db->one("SELECT user_id FROM test_account_resets WHERE user_id=? AND status='pending'",[$payment['user_id']]))throw new \DomainException('Clientul are o resetare TEST în curs.');
  $invoice=$this->fetchInvoice($id);$user=$this->db->one('SELECT * FROM users WHERE id=?',[$payment['user_id']]);
  if(!$user || ($invoice['id']??'')!==$id || ($invoice['status']??'')!=='paid' || ($invoice['livemode']??null)!==($this->settings['mode']==='live') || ($invoice['amount_paid']??null)!==$payment['amount'] || strtoupper($invoice['currency']??'')!==strtoupper($payment['currency']))throw new \DomainException('Stripe nu confirmă identitatea, suma sau plata facturii selectate.');
  $upgrade=$this->db->one('SELECT * FROM plan_changes WHERE invoice_id=? AND user_id=? AND paid_at IS NOT NULL',[$id,$user['id']]);
  if($upgrade){
   if($upgrade['stripe_mode']!==$this->settings['mode'] || $upgrade['customer_id']!==($invoice['customer']??''))throw new \DomainException('Identitatea upgrade-ului nu corespunde plății.');
   $plan=$this->plans->byPrice($upgrade['to_price']);if(!$plan)throw new \DomainException('Planul plății nu este disponibil.');
   return $this->fiscal->captureUpgrade($invoice,$user,$upgrade,$plan);
  }
  $subscriptionId=$invoice['subscription']??$invoice['parent']['subscription_details']['subscription']??'';
  if(!is_string($subscriptionId) || $subscriptionId==='')throw new \DomainException('Factura Stripe nu are un abonament identificabil.');
  $subscription=$this->fetchSubscription($subscriptionId);
  if(($subscription['metadata']['user_id']??'')!==$user['id'] || ($subscription['customer']??null)!==($invoice['customer']??null) || ($subscription['livemode']??false)!==($this->settings['mode']==='live'))throw new \DomainException('Abonamentul Stripe nu aparține clientului selectat.');
  $lines=$invoice['lines']['data']??[];$price=$lines[0]['price']['id']??$lines[0]['pricing']['price_details']['price']??'';$plan=$this->plans->byPrice($price);
  if(!$plan || $plan['stripe_mode']!==$this->settings['mode'])throw new \DomainException('Planul istoric al plății nu poate fi identificat.');
  $fiscalId=$this->db->transaction(fn()=>$this->fiscal->capture($invoice,$user,$subscription,$this->invoicePlan($invoice,$plan)));
  return $fiscalId??throw new \DomainException('Plata nu necesită factură fiscală.');
 }
 private function invoicePlan(array $invoice,array $fallback): array {
  $lines=$invoice['lines']['data']??[];
  if(count($lines)===1) {
   $price=$lines[0]['price']['id']??$lines[0]['pricing']['price_details']['price']??'';
   if($plan=$this->plans->byPrice($price)){if($plan['stripe_mode']!==$this->settings['mode'])throw new \RuntimeException('invoice_mode_mismatch');$fallback=array_replace($plan,array_intersect_key($fallback,array_flip(['invoice_vat_rate','invoice_vat_name'])));}
  }
  $snapshot=$invoice['parent']['subscription_details']['metadata']['zion_vat_bps']??$invoice['subscription_details']['metadata']['zion_vat_bps']??null;
  $rates=$lines[0]['tax_rates']??[];
  if(count($rates)===1 && isset($rates[0]['percentage']))$snapshot=(int)round($rates[0]['percentage']*100);
  if($snapshot!==null){$fallback['invoice_vat_rate']=BillingTax::percentage((int)$snapshot);$fallback['invoice_vat_name']='TVA';}
  if(($invoice['currency']??'')==='ron'){
   $quote=$this->db->one('SELECT snapshot FROM invoice_fx_quotes WHERE invoice_id=? AND stripe_mode=?',[$invoice['id'],$this->settings['mode']]);
   $fx=$quote?json_decode($quote['snapshot'],true):null;
   $meta=$invoice['metadata']??[];
   if(empty($meta['zion_fx_rate']))$meta=$invoice['parent']['subscription_details']['metadata']??$invoice['subscription_details']['metadata']??[];
   if(!$fx && ($meta['zion_fx_policy']??'')==='bnr_v1' && preg_match('/^[1-9][0-9]{0,6}$/D',(string)($meta['zion_fx_rate']??'')) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($meta['zion_fx_date']??''))){
    $fx=['eur_ron'=>(int)$meta['zion_fx_rate'],'rate_date'=>$meta['zion_fx_date'],'eur_amount'=>(int)$meta['zion_eur_amount'],'source_url'=>BnrRates::SOURCE];
    $fx['ron_amount']=BnrRates::convert($fx['eur_amount'],$fx['eur_ron']);
   }
   if(!empty($fx['rate_date'])){$fallback['fx']=$fx;$fallback['eur_amount']=$fx['eur_amount'];$fallback['price_amount']=$fx['ron_amount'];}
  }
  return $fallback;
 }
 private function storeInvoice(array $invoice,string $uid): void {
  $this->db->run('INSERT INTO invoices(id,user_id,hosted_url,amount,currency,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET hosted_url=excluded.hosted_url,amount=excluded.amount,currency=excluded.currency',[
   $invoice['id'],$uid,$invoice['hosted_invoice_url']??null,$invoice['amount_paid']??0,$invoice['currency']??'ron',$invoice['created']??time()
  ]);
  $this->db->run('UPDATE invoices SET stripe_mode=? WHERE id=?',[$this->settings['mode'],$invoice['id']]);
 }
 public function process(array $event): void {
  $previous=ApiLog::context($this->webhookDebug);
  try{
  $this->trace('processing',['event_id'=>$event['id']??'','event_type'=>$event['type']??'']);
  if(($event['livemode']??$event['data']['object']['livemode']??false)!==($this->settings['mode']==='live'))throw new \RuntimeException('event_mode_mismatch');
  if(!in_array($event['type'],self::WEBHOOK_TYPES,true)){$this->trace('complete',['outcome'=>'ignored']);return;}
  $lock=fopen(dirname(__DIR__).'/storage/billing.lock','c'); flock($lock,LOCK_EX);
  $userLock=null;
  try {
   if($this->settings['mode']==='test' && ($reset=TestAccountReset::eventState($this->db,$event['data']['object']??[])??TestAccountReset::eventState($this->db,['id'=>$event['id']??'']))){
    if($reset==='pending')throw new \RuntimeException('test_reset_in_progress');
    $this->trace('complete',['outcome'=>'deleted_account']);return;
   }
   if($this->db->one("SELECT id FROM billing_events WHERE id=? AND status='complete'",[$event['id']])){$this->trace('complete',['outcome'=>'duplicate']);return;}
   $this->db->run("INSERT INTO billing_events(id,type,status,created_at) VALUES(?,?,'processing',?) ON CONFLICT(id) DO UPDATE SET status='processing'",[$event['id'],$event['type'],time()]);
   $this->db->run('UPDATE billing_events SET stripe_mode=? WHERE id=?',[$this->settings['mode'],$event['id']]);
   $o=$event['data']['object'];
   $purpose=$o['metadata']['purpose']??$o['parent']['subscription_details']['metadata']['purpose']??$o['subscription_details']['metadata']['purpose']??'';
   if($purpose==='zion_bnr_api_validation'){$this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);$this->trace('complete',['outcome'=>'isolated_validation']);return;}
   $this->trace('fetch_invoice',['invoice_id'=>$event['type']==='invoice.paid'?($o['id']??''):'','checkout_session_id'=>$event['type']==='checkout.session.completed'?($o['id']??''):'']);
   // A delayed event must invoice its own paid cycle, never the latest subscription invoice.
   $paidInvoice=$event['type']==='invoice.paid'?$this->fetchInvoice($o['id']):null;
   if($paidInvoice && ($paidInvoice['status']??'')!=='paid') throw new \RuntimeException('invoice_not_paid');
   $source=$paidInvoice??$o;
   $upgradeId=$source['metadata']['upgrade_id']??null;
   if($upgradeId && in_array($event['type'],['checkout.session.completed','invoice.paid'],true)) {
    $this->trace('upgrade');
    $change=$this->changes()->record($upgradeId);
    $this->db->run('UPDATE billing_events SET user_id=? WHERE id=?',[$change['user_id'],$event['id']]);
    if($change['stripe_mode']!==$this->settings['mode'])throw new \RuntimeException('upgrade_mode_mismatch');
    $userLock=fopen(dirname(__DIR__).'/storage/provision-'.$change['user_id'].'.lock','c');flock($userLock,LOCK_EX);
    $this->changes()->fulfill($upgradeId);
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);$this->trace('complete',['outcome'=>'processed','user_id'=>$change['user_id']]);return;
   }
   $id=str_starts_with($event['type'],'customer.subscription.')?$o['id']:($source['subscription']??$source['parent']['subscription_details']['subscription']??null);
   if(!$id) {
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);
    $this->trace('complete',['outcome'=>'no_subscription']);
    return;
   }
   // Entitlements always use current authoritative subscription state.
   $this->trace('fetch_subscription',['subscription_id'=>is_string($id)?$id:'']);
   $s=$this->fetchSubscription($id);
   $uid=$s['metadata']['user_id']??'';
   if($uid==='' && ($s['metadata']['purpose']??'')==='zion_bnr_api_validation'){$this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);$this->trace('complete',['outcome'=>'isolated_validation']);return;}
   $this->trace('identify_user',['user_id'=>$uid]);
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   if(!$u) throw new \RuntimeException('unknown_billing_user');
   $this->db->run('UPDATE billing_events SET user_id=? WHERE id=?',[$uid,$event['id']]);
   if($u['stripe_mode']!==$this->settings['mode'] || ($s['livemode']??false)!==($this->settings['mode']==='live'))throw new \RuntimeException('subscription_mode_mismatch');
   $userLock=fopen(dirname(__DIR__).'/storage/provision-'.$uid.'.lock','c');flock($userLock,LOCK_EX);
   if($event['type']==='invoice.created'){
    $draft=$this->fetchInvoice($o['id']);(new RonRenewals($this->db,$this->client,$this->settings['mode']))->queue($draft,$s,$uid);
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);$this->trace('complete',['outcome'=>'ron_renewal_queued']);return;
   }
   $this->changes()->reconcile($s);
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);
   $manual=(new AccountControl($this->db))->manual($uid);
   $price=$s['items']['data'][0]['price']['id']??'';
   $this->trace('validate_plan');
   $p=$this->plans->byPrice($price);
   if($p && isset($s['metadata']['zion_vat_bps'])){$p['invoice_vat_rate']=BillingTax::percentage((int)$s['metadata']['zion_vat_bps']);$p['invoice_vat_name']='TVA';}
   if(!$p || $p['stripe_mode']!==$this->settings['mode'] || $p['slug']==='free' || $p['slug']!==($s['metadata']['plan']??'') || (!$manual && $p['slug']!==$u['selected_plan_id']) || (isset($s['metadata']['billing_interval']) && $s['metadata']['billing_interval']!==$p['billing_interval']))throw new \RuntimeException('billing_plan_mismatch');
   if($paidInvoice && ($paidInvoice['id']!==$o['id'] || ($paidInvoice['customer']??null)!==$s['customer'])) throw new \RuntimeException('invoice_identity_mismatch');
   if($paidInvoice && ($paidInvoice['currency']??'')!==$this->invoicePlan($paidInvoice,$p)['currency'])throw new \RuntimeException('invoice_currency_mismatch');
   $invoice=is_array($s['latest_invoice']??null)?$s['latest_invoice']:null;
   if($invoice && ($invoice['livemode']??false)!==($this->settings['mode']==='live'))throw new \RuntimeException('invoice_mode_mismatch');
   if($paidInvoice && ($paidInvoice['livemode']??false)!==($this->settings['mode']==='live'))throw new \RuntimeException('invoice_mode_mismatch');
   $paid=$invoice && ($invoice['status']??'')==='paid' && $s['status']==='active';
   if($paid && $p['fx_managed'] && ($s['metadata']['zion_fx_policy']??'')==='bnr_v1')$s=(new RonRenewals($this->db,$this->client,$this->settings['mode']))->arm($s,$u,$p);
   $this->trace('persist',['paid_confirmed'=>(bool)$paid,'invoice_id'=>$paidInvoice['id']??$invoice['id']??'']);
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
     $this->db->run('UPDATE subscriptions SET billing_interval=?,currency=?,stripe_mode=? WHERE user_id=?',[$p['billing_interval'],$p['currency'],$this->settings['mode'],$uid]);
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
   $this->trace('storage');
   if(!$oldSubscription && !$manual && $u['storage_user_id'] && !(new AccountControl($this->db))->banned($uid)) {
    if($paid){$this->storage->enableUser($u['storage_user_id'],$p['storage_bytes']);$this->db->run('UPDATE users SET storage_quota_bytes=? WHERE id=?',[$p['storage_bytes'],$uid]);}
    else $this->storage->disableUser($u['storage_user_id']);
    $this->db->run('UPDATE users SET status=?,updated_at=? WHERE id=?',[$paid?'active':'restricted',time(),$uid]);
   }
   if($paid && !$oldSubscription && ($paidInvoice || $invoice) && (($paidInvoice??$invoice)['billing_reason']??'')==='subscription_create')(new OnboardingLinks($this->db,$this->mail))->firstPayment($uid,($paidInvoice??$invoice)['id']);
   $this->trace('notifications');
   $this->db->transaction(function() use($u,$paidInvoice,$paid,$s,$event,$oldSubscription) {
    if($paidInvoice) $this->mail->queue($u['email'],$u['locale'],'payment',[],'paid:'.$paidInvoice['id']);
    if($paid && !$oldSubscription) $this->mail->queue($u['email'],$u['locale'],'subscription',[],'subscription:'.$s['id']);
    elseif($s['status']==='canceled') $this->mail->queue($u['email'],$u['locale'],'cancelled',[],'cancelled:'.$s['id']);
    elseif(in_array($event['type'],['invoice.payment_failed','invoice.payment_action_required'],true)) $this->mail->queue($u['email'],$u['locale'],'failed',['url'=>base_url().Router::path($u['locale'],'status')],'failed:'.$event['id']);
    $this->db->run("UPDATE billing_events SET status='complete' WHERE id=?",[$event['id']]);
   });
   $this->trace('complete',['outcome'=>'processed']);
  } finally { if($userLock){flock($userLock,LOCK_UN);fclose($userLock);} flock($lock,LOCK_UN); fclose($lock); }
  }finally{ApiLog::context($previous);}
 }
}
