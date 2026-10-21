<?php
declare(strict_types=1);
namespace Zion;

/** RON renewals are held as drafts until their immutable BNR quote is checked. */
class RonRenewals {
 public function __construct(private Database $db,private \Stripe\StripeClient $stripe,private string $mode) {}
 public function arm(array $s,array $u,array $plan): array {
  if(($s['metadata']['zion_fx_policy']??'')!=='bnr_v1' || $plan['currency']!=='ron')return $s;
  if(($s['metadata']['user_id']??'')!==$u['id'] || ($s['livemode']??false)!==($this->mode==='live') || (int)($s['metadata']['zion_eur_amount']??0)!==$plan['eur_amount'])throw new \RuntimeException('ron_contract_mismatch');
  if(($s['pause_collection']['behavior']??'')!=='keep_as_draft')$s=$this->stripe->subscriptions->update($s['id'],['pause_collection'=>['behavior'=>'keep_as_draft'],'expand'=>['latest_invoice']])->toArray();
  if(($s['pause_collection']['behavior']??'')!=='keep_as_draft')throw new \RuntimeException('ron_renewal_hold_not_confirmed');
  $this->db->run('INSERT INTO managed_ron_subscriptions(user_id,subscription_id,stripe_mode,created_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET subscription_id=excluded.subscription_id,stripe_mode=excluded.stripe_mode',[$u['id'],$s['id'],$this->mode,time()]);
  return $s;
 }
 public function queue(array $invoice,array $s,string $uid): void {
  if(($invoice['billing_reason']??'')!=='subscription_cycle' || ($s['metadata']['zion_fx_policy']??'')!=='bnr_v1')return;
  $this->identity($invoice,$s,$uid);
  if(!$this->db->one('SELECT user_id FROM managed_ron_subscriptions WHERE user_id=? AND subscription_id=? AND stripe_mode=?',[$uid,$s['id'],$this->mode]))throw new \RuntimeException('ron_renewal_not_armed');
  $this->db->run("INSERT OR IGNORE INTO invoice_fx_quotes(invoice_id,user_id,subscription_id,stripe_mode,snapshot,status,created_at,updated_at) VALUES(?,?,?,?,?,'pending',?,?)",[$invoice['id'],$uid,$s['id'],$this->mode,'{}',time(),time()]);
 }
 private function identity(array $invoice,array $s,string $uid): void {
  $sub=$invoice['subscription']??$invoice['parent']['subscription_details']['subscription']??null;
  if(($invoice['livemode']??false)!==($this->mode==='live') || ($s['livemode']??false)!==($this->mode==='live') || ($invoice['currency']??'')!=='ron' || ($s['metadata']['user_id']??'')!==$uid || $sub!==$s['id'] || ($invoice['customer']??null)!==($s['customer']??null))throw new \RuntimeException('ron_invoice_identity_mismatch');
 }
 public function work(): array {
  $lock=fopen(dirname(__DIR__).'/storage/ron-worker-'.$this->mode.'.lock','c');
  if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['processed'=>0,'failed'=>0,'busy'=>true];}
  try{return $this->performWork();}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function performWork(): array {
  $done=0;$failed=0;
  try{(new BnrRates($this->db))->refresh();}catch(\Throwable $e){/* Frozen quotes can still be retried; new quotes require a valid rate. */}
  foreach($this->db->run('SELECT m.* FROM managed_ron_subscriptions m JOIN users u ON u.id=m.user_id LEFT JOIN subscriptions s ON s.user_id=m.user_id WHERE m.stripe_mode=? AND (s.renewal_at IS NULL OR s.renewal_at<=? OR s.paid_confirmed=0) ORDER BY m.checked_at,m.created_at LIMIT 100',[$this->mode,time()+60])->fetchAll() as $managed){
   try{
    $s=$this->stripe->subscriptions->retrieve($managed['subscription_id'],['expand'=>['latest_invoice']])->toArray();
    if(($s['metadata']['user_id']??'')!==$managed['user_id'])throw new \RuntimeException('ron_contract_identity_mismatch');
    $invoice=$s['latest_invoice']??null;
    if(is_array($invoice) && in_array($invoice['status']??'',['draft','open'],true))$this->queue($invoice,$s,$managed['user_id']);
   }catch(\Throwable $e){$failed++;}finally{$this->db->run('UPDATE managed_ron_subscriptions SET checked_at=? WHERE user_id=?',[time(),$managed['user_id']]);}
  }
  foreach($this->db->run("SELECT invoice_id FROM invoice_fx_quotes WHERE stripe_mode=? AND status<>'paid' AND next_attempt_at<=? ORDER BY created_at LIMIT 30",[$this->mode,time()])->fetchAll() as $job){
   try{$this->process($job['invoice_id']);if($this->db->one("SELECT invoice_id FROM invoice_fx_quotes WHERE invoice_id=? AND status='paid'",[$job['invoice_id']]))$done++;}catch(\Throwable $e){$failed++;}
  }
  $result=['processed'=>$done,'failed'=>$failed,'checked_at'=>time()];
  $this->db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['bnr:worker:'.$this->mode,json_encode($result,JSON_THROW_ON_ERROR)]);
  return $result;
 }
 public function process(string $invoiceId): void {
  $lock=fopen(dirname(__DIR__).'/storage/billing.lock','c');
  if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}
  $userLock=null;
  try{
   $job=$this->db->one('SELECT * FROM invoice_fx_quotes WHERE invoice_id=? AND stripe_mode=?',[$invoiceId,$this->mode]);
   if(!$job || $job['status']==='paid')return;
   $userLock=fopen(dirname(__DIR__).'/storage/provision-'.$job['user_id'].'.lock','c');if(!$userLock || !flock($userLock,LOCK_EX))throw new \RuntimeException('ron_user_lock_failed');
   $s=$this->stripe->subscriptions->retrieve($job['subscription_id'])->toArray();
   $invoice=$this->stripe->invoices->retrieve($invoiceId)->toArray();$this->identity($invoice,$s,$job['user_id']);
   if(($s['metadata']['zion_fx_policy']??'')!=='bnr_v1' || ($s['pause_collection']['behavior']??'')!=='keep_as_draft')throw new \RuntimeException('ron_renewal_hold_missing');
   if(($invoice['billing_reason']??'')!=='subscription_cycle')throw new \RuntimeException('ron_invoice_not_renewal');
   $quote=json_decode($job['snapshot'],true);
   if(($invoice['status']??'')==='paid'){if(empty($quote['rate_date']))throw new \RuntimeException('ron_unquoted_invoice_paid');$this->complete($invoiceId);return;}
   $lines=self::baseLines($invoice,$quote);
   if(count($lines)!==1 || ($invoice['lines']['has_more']??true) || ($lines[0]['quantity']??0)!==1 || !empty($lines[0]['proration']) || !empty($invoice['discounts']) || !empty($lines[0]['discount_amounts']))throw new \RuntimeException('ron_invoice_requires_review');
   if(empty($quote['rate_date'])){
    if(($invoice['status']??'')!=='draft')throw new \RuntimeException('ron_unquoted_invoice_finalized');
    $price=$lines[0]['price']['id']??$lines[0]['pricing']['price_details']['price']??'';
    $plan=(new Plans($this->db))->byPrice($price);
    $eur=(int)($s['metadata']['zion_eur_amount']??0);
    if(!$plan || !$plan['fx_managed'] || $plan['eur_amount']!==$eur || $eur<=0 || $plan['slug']!==($s['metadata']['plan']??''))throw new \RuntimeException('ron_renewal_plan_mismatch');
    if(($lines[0]['period']['start']??0)>time()+60)return;
    $date=BnrRates::date($lines[0]['period']['start']??$invoice['created']);
    if(($lines[0]['amount']??null)!==$plan['price_amount'])throw new \RuntimeException('ron_base_price_mismatch');
    $quote=(new BnrRates($this->db))->quote($eur,$date);
    $quote+=['price_id'=>$price,'plan'=>$plan['slug'],'interval'=>$plan['billing_interval'],'tax_bps'=>(int)($s['metadata']['zion_vat_bps']??0),'base_ron'=>(int)$lines[0]['amount'],'tax_rate_ids'=>array_map(static fn($r)=>is_array($r)?$r['id']:$r,$lines[0]['tax_rates']??$s['items']['data'][0]['tax_rates']??$s['default_tax_rates']??[])];
    $quote['adjustment_ron']=$quote['ron_amount']-$quote['base_ron'];
    if($quote['tax_bps']>0 && count($quote['tax_rate_ids'])!==1)throw new \RuntimeException('ron_tax_policy_mismatch');
    $this->db->run('UPDATE invoice_fx_quotes SET snapshot=?,updated_at=? WHERE invoice_id=?',[json_encode($quote,JSON_THROW_ON_ERROR),time(),$invoiceId]);
   }
   if(($invoice['status']??'')==='draft'){
    $this->stripe->invoices->update($invoiceId,['auto_advance'=>false]);
    $note=self::note($quote);
    $adjustments=array_filter($invoice['lines']['data'],static fn($line)=>($line['metadata']['zion_fx_invoice']??'')===$invoiceId);
    if($quote['adjustment_ron']!==0 && !$adjustments)$this->stripe->invoiceItems->create(['customer'=>$s['customer'],'invoice'=>$invoiceId,'currency'=>'ron','amount'=>$quote['adjustment_ron'],'description'=>'Ajustare curs valutar BNR · '.$note,'discountable'=>false,'tax_behavior'=>'exclusive','tax_rates'=>$quote['tax_rate_ids'],'period'=>$lines[0]['period'],'metadata'=>['zion_fx_invoice'=>$invoiceId]],['idempotency_key'=>'bnr-adjustment-'.$invoiceId]);
    $this->stripe->invoices->update($invoiceId,['metadata'=>['zion_fx_policy'=>'bnr_v1','zion_fx_rate'=>(string)$quote['eur_ron'],'zion_fx_date'=>$quote['rate_date'],'zion_eur_amount'=>(string)$quote['eur_amount']],'footer'=>$note]);
    $invoice=$this->stripe->invoices->retrieve($invoiceId)->toArray();
    $this->verifyAmount($invoice,$quote);
    $quote['gross_amount']=$invoice['total'];$this->db->run('UPDATE invoice_fx_quotes SET snapshot=?,updated_at=? WHERE invoice_id=?',[json_encode($quote,JSON_THROW_ON_ERROR),time(),$invoiceId]);
    $invoice=$this->stripe->invoices->finalizeInvoice($invoiceId,['auto_advance'=>false],['idempotency_key'=>'bnr-finalize-'.$invoiceId])->toArray();
   }
   $this->verifyAmount($invoice,$quote);
   if(($invoice['status']??'')!=='open')throw new \RuntimeException('ron_invoice_not_payable');
   $paid=$this->stripe->invoices->pay($invoiceId,['off_session'=>true],['idempotency_key'=>'bnr-pay-'.$invoiceId.'-'.intdiv(time(),900)])->toArray();
   if(($paid['status']??'')!=='paid')throw new \RuntimeException('ron_payment_requires_action');
   $this->complete($invoiceId);
  }catch(\Throwable $e){
   $this->db->run("UPDATE invoice_fx_quotes SET status='retry',attempts=attempts+1,next_attempt_at=?,error_code='ron_renewal_requires_retry',updated_at=? WHERE invoice_id=? AND stripe_mode=?",[time()+900,time(),$invoiceId,$this->mode]);
   ApiLog::record('stripe','POST','/bnr-renewals',0,microtime(true),['failed'=>true,'mode'=>$this->mode,'code'=>'ron_renewal_requires_retry','message'=>'Reînnoirea RON nu s-a finalizat. Factura necesită verificare; cursul și oferta deja salvate sunt păstrate.','context'=>['invoice_id'=>$invoiceId,'stage'=>'bnr_renewal']]);
   throw $e;
  }finally{if($userLock){flock($userLock,LOCK_UN);fclose($userLock);}flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function baseLines(array $invoice,?array $quote): array {
  $base=[];$adjustments=0;
  foreach($invoice['lines']['data']??[] as $line){
   if(($line['metadata']['zion_fx_invoice']??'')===($invoice['id']??null)){
    if(!$quote || !isset($quote['adjustment_ron']) || $line['amount']!==$quote['adjustment_ron'] || ($line['currency']??'')!=='ron')throw new \RuntimeException('ron_adjustment_identity_mismatch');
    $adjustments++;
   }else $base[]=$line;
  }
  if(count($base)!==1 || $adjustments>1)throw new \RuntimeException('ron_invoice_requires_review');
  return $base;
 }
 private function verifyAmount(array $invoice,array $quote): void {
  self::baseLines($invoice,$quote);
  $whole=BillingTax::total($quote['ron_amount'],$quote['tax_bps']);
  $delta=$quote['adjustment_ron'];$lineRounded=BillingTax::total($quote['base_ron'],$quote['tax_bps'])+($delta<0?-1:1)*BillingTax::total(abs($delta),$quote['tax_bps']);
  $allowed=isset($quote['gross_amount'])?[$quote['gross_amount']]:[$whole,$lineRounded];
  if(($invoice['currency']??'')!=='ron' || ($invoice['subtotal']??null)!==$quote['ron_amount'] || !in_array($invoice['total']??null,$allowed,true) || ($invoice['amount_due']??null)!==($invoice['total']??null))throw new \RuntimeException('ron_invoice_total_mismatch');
 }
 private function complete(string $id): void {$this->db->run("UPDATE invoice_fx_quotes SET status='paid',error_code=NULL,updated_at=? WHERE invoice_id=?",[time(),$id]);}
 public static function note(array $q): string {return 'Abonament '.number_format($q['eur_amount']/100,2,'.','').' EUR. Curs BNR '.$q['rate_date'].': 1 EUR = '.BnrRates::formatted($q).' RON. Echivalent: '.number_format($q['ron_amount']/100,2,'.','').' RON.';}
}
