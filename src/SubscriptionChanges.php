<?php
declare(strict_types=1);
namespace Zion;

/** Durable, serialized operations. Browser redirects never grant an entitlement. */
class SubscriptionChanges {
 public static function displayDate(int $timestamp): string { return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i').' (Europe/Bucharest)'; }
 public function __construct(private Database $db,private Plans $plans,private MailerService $mail,private StorageProvisioningService $storage,private \Stripe\StripeClient $stripe,private OblioInvoiceService $fiscal) {}
 public function serialized(string $uid,callable $fn): mixed {
  $global=fopen(dirname(__DIR__).'/storage/billing.lock','c');flock($global,LOCK_EX);
  $user=fopen(dirname(__DIR__).'/storage/provision-'.$uid.'.lock','c');flock($user,LOCK_EX);
  try{return $fn();}finally{flock($user,LOCK_UN);fclose($user);flock($global,LOCK_UN);fclose($global);}
 }
 public function offers(array $u,?int $now=null): array {
  $now??=time();$control=new AccountControl($this->db);
  if(!$u['email_verified_at'] || $control->banned($u['id']) || $control->manual($u['id'])) return [];
  $s=$this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status='active' AND paid_confirmed=1",[$u['id']]);
  if(!$s) return [];
  $origin=$this->db->one('SELECT * FROM subscription_origins WHERE stripe_subscription_id=?',[$s['stripe_subscription_id']]);
  $interval=$s['billing_interval']??'month';$from=$this->plans->get($s['plan'],$interval);
  if(!$from || $s['currency']!==$from['currency'] || $s['stripe_price_id']!==$from['stripe_price_id'])return [];
  if(!$origin || !$from || $from['slug']==='free' || $now>$origin['purchased_at']+5*86400 || $now>=$origin['original_period_end'])return [];
  $offers=[];
  foreach($this->plans->all($interval) as $p) if($p['storage_bytes']>$from['storage_bytes'] && $p['price_amount']>$from['price_amount']) {
   $offers[]=['plan'=>$p,'amount'=>$p['price_amount']-$from['price_amount'],'deadline'=>$origin['purchased_at']+5*86400,'renewal_at'=>$s['renewal_at']];
  }
  return $offers;
 }
 public function rememberOrigin(array $invoice,array $subscription,string $uid): void {
  // Only the original successful subscription purchase opens the window. Renewals never reset it.
  if(($invoice['billing_reason']??'')!=='subscription_create' || ($invoice['status']??'')!=='paid' || ($invoice['amount_paid']??0)<=0)return;
  $paidAt=$invoice['status_transitions']['paid_at']??null;
  $end=$subscription['items']['data'][0]['current_period_end']??$subscription['current_period_end']??null;
  if(!is_int($paidAt) || !is_int($end) || $end<=$paidAt)return;
  $this->db->run('INSERT OR IGNORE INTO subscription_origins(stripe_subscription_id,user_id,purchased_at,original_period_end,invoice_id) VALUES(?,?,?,?,?)',[$subscription['id'],$uid,$paidAt,$end,$invoice['id']]);
 }
 public function checkout(array $u,string $target): string {
  return $this->serialized($u['id'],function()use($u,$target){
   $expired=$this->db->one("SELECT * FROM plan_changes WHERE user_id=? AND status='pending' AND expires_at<=?",[$u['id'],time()]);
   if($expired)$this->fulfill($expired['id']);
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$u['id']]);
   $offer=null;foreach($this->offers($u) as $o)if($o['plan']['slug']===$target)$offer=$o;
   if(!$offer)throw new \DomainException('upgrade.unavailable');
   $pending=$this->db->one("SELECT * FROM plan_changes WHERE user_id=? AND status IN ('creating','pending','paid','applying','retry')",[$u['id']]);
   if($pending) {
    if(in_array($pending['status'],['creating','pending'],true) && $pending['to_plan']===$target && ($pending['expires_at']??PHP_INT_MAX)>time())return $this->createSession($pending,$u);
    throw new \DomainException('upgrade.pending');
   }
   // Checkout requires at least 30 minutes. Existing sessions remain valid up to the exact five-day cutoff.
   if($offer['deadline']-time()<1830)throw new \DomainException('upgrade.closing');
   $local=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$u['id']]);
   $s=$this->stripe->subscriptions->retrieve($local['stripe_subscription_id'])->toArray();
   $from=$this->plans->get($u['selected_plan_id'],$local['billing_interval']);$p=$offer['plan'];$item=$s['items']['data'][0]??[];
   $this->validateSubscription($s,$u,$from,$local['renewal_at']);
   if(!$p['stripe_price_id'] || !env('STRIPE_SECRET_KEY'))throw new \DomainException('upgrade.unconfigured');
   $price=$this->stripe->prices->retrieve($p['stripe_price_id'])->toArray();
   if(($price['currency']??'')!==$p['currency'] || ($price['unit_amount']??null)!==$p['price_amount'] || ($price['recurring']['interval']??'')!==$p['billing_interval'] || ($price['recurring']['interval_count']??1)!==1 || !($price['active']??false))throw new \DomainException('upgrade.unconfigured');
   if(!(new BillingProfileService($this->db))->get($u['id']))throw new \DomainException('billing.required');
   $id=bin2hex(random_bytes(16));$expires=min(time()+86400,$offer['deadline']);
   $this->db->transaction(function()use($id,$u,$s,$item,$from,$p,$offer,$local,$expires){
   $this->db->run("INSERT INTO plan_changes(id,user_id,kind,stripe_subscription_id,customer_id,item_id,from_plan,to_plan,from_price,to_price,amount,period_end,deadline,expires_at,status,created_at,updated_at) VALUES(?,?,'customer',?,?,?,?,?,?,?,?,?,?,?,'creating',?,?)",[$id,$u['id'],$s['id'],$s['customer'],$item['id'],$from['slug'],$p['slug'],$from['stripe_price_id'],$p['stripe_price_id'],$offer['amount'],$local['renewal_at'],$offer['deadline'],$expires,time(),time()]);
   $this->db->run('UPDATE plan_changes SET currency=?,billing_interval=? WHERE id=?',[$p['currency'],$p['billing_interval'],$id]);
   });
   return $this->createSession($this->record($id),$u);
  });
 }
 private function createSession(array $r,array $u): string {
  if($r['checkout_url'])return $r['checkout_url'];
  if($r['expires_at']-time()<1800)throw new \DomainException('upgrade.closing');
  $p=$this->plans->get($r['to_plan'],$r['billing_interval']);$meta=['upgrade_id'=>$r['id'],'user_id'=>$u['id']];
  $session=$this->stripe->checkout->sessions->create([
   'mode'=>'payment','customer'=>$r['customer_id'],'client_reference_id'=>$u['id'],'payment_method_types'=>['card'],
   'locale'=>$u['locale'],'expires_at'=>$r['expires_at'],'metadata'=>$meta,'payment_intent_data'=>['metadata'=>$meta],
   'invoice_creation'=>['enabled'=>true,'invoice_data'=>['metadata'=>$meta,'description'=>'Upgrade Zion Storage '.$r['from_plan'].' → '.$r['to_plan']]],
   'line_items'=>[['price_data'=>['currency'=>$r['currency'],'unit_amount'=>$r['amount'],'product_data'=>['name'=>'Upgrade Zion Storage '.$p['capacity']]],'quantity'=>1]],
   'success_url'=>base_url().Router::path($u['locale'],'status'),'cancel_url'=>base_url().Router::path($u['locale'],'upgrade'),
  ],['idempotency_key'=>'upgrade-checkout-'.$r['id']]);
  $this->db->run("UPDATE plan_changes SET status='pending',checkout_session_id=?,checkout_url=?,updated_at=? WHERE id=?",[$session->id,$session->url,time(),$r['id']]);
  return $session->url;
 }
 public function record(string $id): array { return $this->db->one('SELECT * FROM plan_changes WHERE id=?',[$id])??throw new \DomainException('upgrade.unavailable'); }
 private function validateSubscription(array $s,array $u,array $from,?int $end): void {
  $items=$s['items']['data']??[];$item=$items[0]??[];$price=$item['price']??[];
  if(count($items)!==1 || ($item['quantity']??1)!==1 || ($s['metadata']['user_id']??'')!==$u['id'] || ($s['status']??'')!=='active' || ($s['cancel_at_period_end']??false) || !empty($s['schedule']) || !empty($s['pending_update']) || ($price['id']??'')!==$from['stripe_price_id'] || ($price['currency']??'')!==$from['currency'] || ($price['unit_amount']??null)!==$from['price_amount'] || ($price['recurring']['interval']??'')!==$from['billing_interval'] || ($price['recurring']['interval_count']??1)!==1 || ($item['current_period_end']??$s['current_period_end']??null)!==$end)throw new \DomainException('upgrade.unavailable');
 }
 /** Called under the billing + user locks by webhooks or retry worker. */
 public function fulfill(string $id): void {
  try{$this->performFulfillment($id);}
  catch(\Throwable $e){
   $code=in_array($e->getMessage(),['upgrade_session_pending','upgrade_payment_mismatch','upgrade_invoice_pending','upgrade_payment_outside_window','subscription_changed_requires_review','subscription_sync_requires_review'],true)?$e->getMessage():'change_requires_retry';
   $this->db->run('UPDATE plan_changes SET error_code=?,updated_at=? WHERE id=?',[$code,time(),$id]);throw $e;
  }
 }
 private function performFulfillment(string $id): void {
  $r=$this->record($id);
  if($r['status']==='completed')return;
  if($r['kind']==='admin'){ $this->apply($r);return; }
  if(!$r['checkout_session_id']){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$r['user_id']]);
   if((new AccountControl($this->db))->banned($u['id']))throw new \RuntimeException('upgrade_session_pending');
   $this->createSession($r,$u);$r=$this->record($id);
  }
  $session=$this->stripe->checkout->sessions->retrieve($r['checkout_session_id'],['expand'=>['payment_intent.latest_charge','invoice']])->toArray();
  if(($session['status']??'')==='expired'){
   $this->db->run("UPDATE plan_changes SET status='expired',updated_at=? WHERE id=? AND paid_at IS NULL",[time(),$id]);return;
  }
  if(($session['payment_status']??'')!=='paid')return;
  $pi=$session['payment_intent']??[];$charge=$pi['latest_charge']??[];$paidAt=$charge['created']??null;
  if(($session['livemode']??true) || ($pi['status']??'')!=='succeeded' || ($pi['amount_received']??null)!==$r['amount'] || ($session['amount_total']??null)!==$r['amount'] || ($session['currency']??'')!==$r['currency'] || ($session['customer']??'')!==$r['customer_id'] || ($session['metadata']['upgrade_id']??'')!==$id || ($pi['metadata']['upgrade_id']??'')!==$id || !is_int($paidAt) || $paidAt<$r['created_at'] || !($charge['paid']??false))throw new \RuntimeException('upgrade_payment_mismatch');
  $invoice=$session['invoice']??null;
  if(!is_array($invoice) || ($invoice['status']??'')!=='paid' || ($invoice['amount_paid']??null)!==$r['amount'] || ($invoice['currency']??'')!==$r['currency'] || ($invoice['customer']??'')!==$r['customer_id'] || ($invoice['metadata']['upgrade_id']??'')!==$id)throw new \RuntimeException('upgrade_invoice_pending');
  $paidAt=$invoice['status_transitions']['paid_at']??null;
  if(!is_int($paidAt) || $paidAt<$r['created_at'])throw new \RuntimeException('upgrade_payment_mismatch');
  $this->db->run("UPDATE plan_changes SET status='paid',paid_at=?,payment_intent_id=?,invoice_id=?,updated_at=? WHERE id=?",[$paidAt,$pi['id'],$invoice['id'],time(),$id]);
  $r=$this->record($id);$u=$this->db->one('SELECT * FROM users WHERE id=?',[$r['user_id']]);
  $this->fiscal->captureUpgrade($invoice,$u,$r,$this->plans->get($r['to_plan'],$r['billing_interval']));
  if($paidAt>$r['deadline']){
   $this->db->run("UPDATE plan_changes SET status='review',error_code='upgrade_payment_outside_window',updated_at=? WHERE id=?",[time(),$id]);
   throw new \RuntimeException('upgrade_payment_outside_window');
  }
  $this->apply($r);
 }
 private function apply(array $r): void {
  $p=$this->plans->get($r['to_plan'],$r['billing_interval']);$uid=$r['user_id'];
  $this->db->run("UPDATE plan_changes SET status='applying',updated_at=? WHERE id=?",[time(),$r['id']]);
  try {
   $s=$this->stripe->subscriptions->retrieve($r['stripe_subscription_id'])->toArray();
   $item=$s['items']['data'][0]??[];
   if(($s['customer']??'')!==$r['customer_id'] || ($s['metadata']['user_id']??'')!==$uid || count($s['items']['data']??[])!==1 || ($item['id']??'')!==$r['item_id'] || !in_array($item['price']['id']??'!',[$r['from_price'],$r['to_price']],true) || ($item['current_period_end']??$s['current_period_end']??0)!==$r['period_end'] || ($s['status']??'')!=='active')throw new \RuntimeException('subscription_changed_requires_review');
   if(($item['price']['id']??'')!==$r['to_price']) $s=$this->stripe->subscriptions->update($s['id'],[
    'items'=>[['id'=>$r['item_id'],'price'=>$r['to_price'],'quantity'=>1]],'metadata'=>['plan'=>$r['to_plan']],
    'proration_behavior'=>'none','billing_cycle_anchor'=>'unchanged','payment_behavior'=>'error_if_incomplete',
   ],['idempotency_key'=>'plan-change-'.$r['id']])->toArray();
   if(($s['items']['data'][0]['price']['id']??'')!==$r['to_price'] || ($s['items']['data'][0]['current_period_end']??$s['current_period_end']??0)!==$r['period_end'])throw new \RuntimeException('subscription_sync_requires_review');
   $this->reconcile($s);
  }catch(\Throwable $e){$this->db->run("UPDATE plan_changes SET status='retry',error_code='change_requires_retry',updated_at=? WHERE id=?",[time(),$r['id']]);throw $e;}
 }
 /** Recover a crash after Stripe accepted a change but before its response was saved. */
 public function reconcile(array $s): void {
  if(($s['status']??'')!=='active')return;
  $r=$this->db->one("SELECT * FROM plan_changes WHERE stripe_subscription_id=? AND status IN ('paid','applying','retry') AND to_price=?",[$s['id'],$s['items']['data'][0]['price']['id']??'']);
  if(!$r || ($r['kind']==='customer' && !$r['paid_at']))return;
  if(($s['metadata']['user_id']??'')!==$r['user_id'] || ($s['metadata']['plan']??'')!==$r['to_plan'] || ($s['items']['data'][0]['current_period_end']??$s['current_period_end']??0)!==$r['period_end'])throw new \RuntimeException('change_reconciliation_mismatch');
  $p=$this->plans->get($r['to_plan'],$r['billing_interval']);$u=$this->db->one('SELECT * FROM users WHERE id=?',[$r['user_id']]);
  // Persist billing identity before provisioning. A failed quota update remains retryable.
  $this->db->transaction(function()use($r,$p){
   $this->db->run('UPDATE users SET selected_plan_id=?,updated_at=? WHERE id=?',[$p['slug'],time(),$r['user_id']]);
   $this->db->run('UPDATE subscriptions SET plan=?,stripe_price_id=? WHERE user_id=?',[$p['slug'],$r['to_price'],$r['user_id']]);
  });
  if($u['storage_user_id'] && !(new AccountControl($this->db))->banned($u['id']))$this->storage->enableUser($u['storage_user_id'],$p['storage_bytes']);
  $this->db->run('UPDATE users SET storage_quota_bytes=?,updated_at=? WHERE id=?',[$p['storage_bytes'],time(),$u['id']]);
  $this->db->run("UPDATE plan_changes SET status='completed',error_code=NULL,updated_at=? WHERE id=?",[time(),$r['id']]);
 }
 public function retry(string $id): void {
  $r=$this->record($id);$this->serialized($r['user_id'],fn()=>$this->fulfill($id));
 }
 public function cancel(string $id): void {
  $r=$this->record($id);$this->serialized($r['user_id'],function()use($id){
   $r=$this->record($id);if(!in_array($r['status'],['creating','pending'],true))throw new \DomainException('Plata confirmată nu poate fi anulată aici.');
   if($r['checkout_session_id']){
    $s=$this->stripe->checkout->sessions->retrieve($r['checkout_session_id']);
    if($s->status==='complete')throw new \DomainException('Plata este în procesare. Folosește Reîncearcă.');
    if($s->status==='open')$this->stripe->checkout->sessions->expire($s->id);
   }
   $this->db->run("UPDATE plan_changes SET status='cancelled',updated_at=? WHERE id=?",[time(),$id]);
  });
 }
 public function adminPlan(string $uid,string $target,string $admin,string $reason): void {
  $this->serialized($uid,function()use($uid,$target,$admin,$reason){
   $u=$this->db->one('SELECT * FROM users WHERE id=?',[$uid]);$p=$this->plans->get($target);
   if(!$u || !$p || $p['slug']===$u['selected_plan_id'])throw new \DomainException('Selectează un plan diferit.');
   if($this->db->one("SELECT id FROM plan_changes WHERE user_id=? AND status IN ('creating','pending','paid','applying','retry')",[$uid]))throw new \DomainException('Rezolvă mai întâi schimbarea în curs.');
   if($u['storage_user_id'] && $this->storage->getUsage($u['storage_user_id'])['used']>$p['storage_bytes'])throw new \DomainException('Fișierele existente depășesc cota planului ales.');
   $local=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$uid]);
   if($local && !in_array($local['status'],['canceled','incomplete_expired'],true)){
    if($target==='free')throw new \DomainException('Anulează mai întâi abonamentul Stripe; trecerea la gratuit nu anulează plățile implicit.');
    $s=$this->stripe->subscriptions->retrieve($local['stripe_subscription_id'])->toArray();$from=$this->plans->get($u['selected_plan_id'],$local['billing_interval']);$p=$this->plans->get($target,$local['billing_interval']);
    $this->validateSubscription($s,$u,$from,$local['renewal_at']);
    $price=$this->stripe->prices->retrieve($p['stripe_price_id'])->toArray();
    if(($price['unit_amount']??null)!==$p['price_amount'] || ($price['currency']??'')!==$p['currency'] || ($price['recurring']['interval']??'')!==$p['billing_interval'] || ($price['recurring']['interval_count']??1)!==1 || !($price['active']??false))throw new \DomainException('Preț Stripe neconfigurat.');
    $id=bin2hex(random_bytes(16));
    $this->db->transaction(function()use($id,$uid,$s,$from,$p,$local,$admin,$reason){
     $this->db->run("INSERT INTO plan_changes(id,user_id,kind,stripe_subscription_id,customer_id,item_id,from_plan,to_plan,from_price,to_price,amount,period_end,status,created_at,updated_at) VALUES(?,?,'admin',?,?,?,?,?,?,?,0,?,'applying',?,?)",[$id,$uid,$s['id'],$s['customer'],$s['items']['data'][0]['id'],$from['slug'],$p['slug'],$from['stripe_price_id'],$p['stripe_price_id'],$local['renewal_at'],time(),time()]);
     $this->audit($admin,$uid,'plan_change',$from['slug'].' → '.$p['slug'].'; fără încasare/refund în perioada curentă; '.$reason);
     $this->db->run('UPDATE plan_changes SET currency=?,billing_interval=? WHERE id=?',[$p['currency'],$p['billing_interval'],$id]);
    });
    $this->apply($this->record($id));
   }else{
    if($this->db->one('SELECT user_id FROM checkout_sessions WHERE user_id=? AND expires_at>?',[$uid,time()]))throw new \DomainException('Există o plată inițială deschisă. Așteaptă expirarea sau confirmarea ei înainte de acordarea manuală a planului.');
    // Manual entitlement is deliberately separate from a paid Stripe subscription.
    $this->db->transaction(function()use($uid,$target,$admin,$reason){
     $this->db->run("INSERT INTO account_controls(user_id,manual_plan,quota_sync,updated_at) VALUES(?,?,'pending',?) ON CONFLICT(user_id) DO UPDATE SET manual_plan=excluded.manual_plan,quota_sync='pending',updated_at=excluded.updated_at",[$uid,$target,time()]);
     $this->db->run('UPDATE users SET selected_plan_id=?,updated_at=? WHERE id=?',[$target,time(),$uid]);
     $this->audit($admin,$uid,'manual_plan',$target.'; '.$reason);
    });
    if($u['storage_user_id'] && !(new AccountControl($this->db))->banned($uid))$this->storage->enableUser($u['storage_user_id'],$p['storage_bytes']);
    $this->db->run('UPDATE users SET storage_quota_bytes=? WHERE id=?',[$p['storage_bytes'],$uid]);
    $this->db->run("UPDATE account_controls SET quota_sync='ready' WHERE user_id=?",[$uid]);
    if($u['storage_user_id'] && $u['email_verified_at'] && !(new AccountControl($this->db))->banned($uid))$this->db->run("UPDATE users SET status='active',updated_at=? WHERE id=?",[time(),$uid]);
   }
  });
 }
 public function audit(string $admin,?string $uid,string $action,string $detail): void { $this->db->run('INSERT INTO admin_audit(admin_id,user_id,action,detail,created_at) VALUES(?,?,?,?,?)',[$admin,$uid,$action,$detail,time()]); }
}
