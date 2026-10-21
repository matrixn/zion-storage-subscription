<?php
final class TaxHttp extends ChangesHttp {
 public array $rates=[];public int $taxCreates=0;
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  $path=parse_url($url,PHP_URL_PATH);$post=strtolower($method)==='post';$live=str_contains(json_encode($headers),'sk_live_');
  if(str_contains($path,'/tax_rates')){
   $this->calls[]=[$method,$path,$params];
   if(str_ends_with($path,'/tax_rates')){
    if($post){$id='txr_tax_'.(++$this->taxCreates);$r=$params+['id'=>$id,'object'=>'tax_rate','active'=>true,'livemode'=>$live];$r['percentage']=(float)$r['percentage'];$r['inclusive']=in_array($r['inclusive'],[true,'true'],true);$this->rates[$id]=$r;}
    else $r=['object'=>'list','url'=>'/v1/tax_rates','has_more'=>false,'data'=>array_values(array_filter($this->rates,fn($v)=>$v['livemode']===$live))];
   }else {$r=$this->rates[basename($path)];if($r['livemode']!==$live)throw new RuntimeException('Cross-mode tax access');}
   return [json_encode($r),200,[]];
  }
  if(str_ends_with($path,'/checkout/sessions') && $post && $params['mode']==='subscription'){
   $this->calls[]=[$method,$path,$params];$id='cs_tax_'.count($this->sessions);
   $r=['id'=>$id,'object'=>'checkout.session','url'=>'https://checkout.stripe.com/c/pay/'.$id,'expires_at'=>time()+3600];$this->sessions[$id]=$r;
   return [json_encode($r),200,[]];
  }
  $result=parent::request($method,$url,$headers,$params,$hasFile,$apiMode,$maxNetworkRetries);
  if(str_ends_with($path,'/checkout/sessions') && $post && $params['mode']==='payment'){
   $r=json_decode($result[0],true);$tax=$params['line_items'][0]['tax_rates'][0]??null;
   if($tax){$r['amount_total']=Zion\BillingTax::total($r['amount_total'],(int)round($this->rates[$tax]['percentage']*100));$this->sessions[$r['id']]=$r;$result[0]=json_encode($r);}
  }
  return $result;
 }
}
$taxDb=new Zion\Database(':memory:');$taxDb->migrate();testFx($taxDb);$taxDb->migrate();testFx($taxDb);
$taxSettings=new Zion\BillingTax($taxDb);
check($taxSettings->basisPoints()===0,'VAT defaults to no collection for the declared non-VAT-registered operator');
check(Zion\BillingTax::total(399,2100)===483 && Zion\BillingTax::total(4389,2100)===5311,'monthly and annual VAT round to EUR cents without changing base price');
check(Zion\BillingTax::parse('21,25')===2125 && Zion\BillingTax::percentage(2100)==='21','VAT input supports decimal comma and stable percent format');
foreach(['-1','101','21.001','1e1','NaN',''] as $bad){try{Zion\BillingTax::parse($bad);check(false,'reject invalid VAT');}catch(DomainException $e){check(true,'reject invalid VAT '.$bad);}}
seedCatalog($taxDb);$taxDb->run("UPDATE integration_settings SET value='2100' WHERE key='billing:vat_bps'"); // Explicit taxable-rate fixture, independent of operator defaults.
$_ENV['ADMIN_EMAIL']='tax-admin@example.com';$_ENV['ADMIN_PASSWORD']='tax-admin-password';Zion\AccountControl::seedAdmin($taxDb);$taxAdmin=$taxDb->one('SELECT id FROM administrators')['id'];$_ENV['ADMIN_EMAIL']='';$_ENV['ADMIN_PASSWORD']='';
$taxPlans=new Zion\Plans($taxDb);$taxMail=new Zion\MailerService($taxDb);$taxStore=new TestStorage();$taxAccounts=new Zion\AccountService($taxDb,$taxPlans,$taxMail,$taxStore);
$taxSignup=function(string $email,string $interval='month')use($taxDb,$taxAccounts){
 $id=$taxAccounts->register(['name'=>'Tax User','email'=>$email,'password'=>'tax-fixture-password','locale'=>'ro','plan'=>'120gb','billing_interval'=>$interval]);
 $taxDb->run('UPDATE users SET email_verified_at=? WHERE id=?',[time(),$id]);
 (new Zion\BillingProfileService($taxDb))->save($id,['type'=>'individual','name'=>'Tax User','billing_email'=>$email,'country'=>'RO','county'=>'Bucuresti','city'=>'Bucuresti','address'=>'Strada Test 1','postal_code'=>'010101']);
 return $taxDb->one('SELECT * FROM users WHERE id=?',[$id]);
};
$taxHttp=new TaxHttp();Stripe\ApiRequestor::setHttpClient($taxHttp);
$taxConfig=array_replace(require dirname(__DIR__).'/config/oblio.php',['enabled'=>true,'email'=>'fixture@example.com','secret'=>'fixture-secret','cif'=>'RO-FIXTURE','series'=>'ZS','vat_rate'=>'19','vat_name'=>'Old VAT','language'=>'RO','allow_test_payments'=>true]);
$taxFiscal=new Zion\OblioInvoiceService($taxDb,$taxMail,new FixtureOblio($taxConfig),$taxConfig);
$taxBilling=new Zion\StripeService($taxDb,$taxPlans,$taxMail,$taxStore,$taxFiscal);
$taxUser=$taxSignup('tax-month@example.com');$taxBilling->checkout($taxUser);
$quote=array_values(array_filter($taxHttp->calls,fn($c)=>$c[1]==='/v1/checkout/sessions'))[0][2];$rateId=$quote['line_items'][0]['tax_rates'][0];
check($quote['line_items'][0]['price']==='price_120' && $taxHttp->rates[$rateId]['inclusive']===false && $taxHttp->rates[$rateId]['percentage']===21.0,'Stripe Checkout receives base Price plus separate exclusive 21 percent VAT');
check($quote['subscription_data']['metadata']['zion_vat_bps']==='2100' && $taxHttp->rates[$rateId]['display_name']==='TVA','subscription stores tax policy for renewal and fiscal snapshots');
$taxBilling->checkout($taxUser);check($taxHttp->taxCreates===1,'existing Checkout quote is reused without duplicate tax creation');
$taxBilling->checkout($taxSignup('tax-year@example.com','year'));
check($taxHttp->taxCreates===1,'monthly and annual products share automatically created tax rate');
$liveRates=$taxSettings->rates(new Stripe\StripeClient('sk_live_taxfixture'),'live',2100);
check($liveRates[0]!==$rateId && $taxHttp->rates[$liveRates[0]]['livemode']===true,'LIVE and TEST tax objects remain separate');
$taxSettings->save('19',$taxAdmin);
try{$taxBilling->checkout($taxUser);check(false,'stale quote rejected');}catch(DomainException $e){check($e->getMessage()==='checkout.pending_quote','tax changes cannot silently reuse a stale open quote');}
$taxBilling->checkout($taxSignup('tax-new-rate@example.com'));
check($taxHttp->taxCreates===3 && $taxHttp->rates[$rateId]['percentage']===21.0,'changing VAT creates a new object without mutating historical rate');
$taxSettings->save('21',$taxAdmin);
$u=$taxSignup('tax-upgrade@example.com');$end=time()+29*86400;$bought=time()-3600;
$invoice=['id'=>'in_tax_original','object'=>'invoice','livemode'=>false,'customer'=>'cus_tax','subscription'=>'sub_tax','status'=>'paid','amount_paid'=>483,'currency'=>'ron','billing_reason'=>'subscription_create','created'=>$bought,'status_transitions'=>['paid_at'=>$bought],'subscription_details'=>['metadata'=>['zion_vat_bps'=>'2100']],'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>'price_120'],'quantity'=>1,'period'=>['start'=>$bought,'end'=>$end]]]]];
$taxHttp->subscription=['id'=>'sub_tax','object'=>'subscription','customer'=>'cus_tax','status'=>'active','metadata'=>['user_id'=>$u['id'],'plan'=>'120gb','zion_vat_bps'=>'2100'],'items'=>['data'=>[['id'=>'si_tax','quantity'=>1,'price'=>$taxHttp->price('price_120'),'current_period_end'=>$end]]],'latest_invoice'=>$invoice];$taxHttp->invoices[$invoice['id']]=$invoice;
$taxBilling->process(['id'=>'evt_tax_original','type'=>'invoice.paid','data'=>['object'=>$invoice]]);
$changes=$taxBilling->changes();$changes->checkout($u,'250gb');$change=$taxDb->one('SELECT * FROM plan_changes');
check($change['base_amount']===320 && $change['amount']===387 && $change['tax_bps']===2100,'upgrade stores net difference and gross amount including VAT');
$paidSession=$taxHttp->pay($change['checkout_session_id'],time());
$taxSettings->save('19',$taxAdmin);$changes->fulfill($change['id']);
check($taxDb->one('SELECT status FROM plan_changes')['status']==='completed','taxed upgrade confirms gross Stripe payment instead of rejecting it as a net mismatch');
$update=array_values(array_filter($taxHttp->calls,fn($c)=>$c[1]==='/v1/subscriptions/sub_tax' && strtolower($c[0])==='post'))[0][2];
check($update['items'][0]['tax_rates']===[$rateId] && $update['metadata']['zion_vat_bps']==='2100','upgrade preserves quoted VAT for recurring renewal despite later settings change');
$fiscalRow=$taxDb->one('SELECT * FROM fiscal_invoices WHERE stripe_invoice_id=?',[$paidSession['invoice']['id']]);$source=Zion\EncryptedPayload::decode($fiscalRow['source_snapshot']);
check($source['plan']['invoice_vat_rate']==='21' && $fiscalRow['amount']===387,'upgrade fiscal snapshot freezes original VAT and collected gross');
$config=array_replace(require dirname(__DIR__).'/config/oblio.php',['enabled'=>true,'email'=>'fixture@example.com','secret'=>'fixture-secret','cif'=>'RO-FIXTURE','series'=>'ZS','vat_rate'=>'19','vat_name'=>'Old VAT','language'=>'RO','allow_test_payments'=>true]);
$provider=new FixtureOblio($config);$fiscal=new Zion\OblioInvoiceService($taxDb,$taxMail,$provider,$config);$fiscal->process($fiscalRow['id']);$payload=$provider->payloads['zion-storage:'.$paidSession['invoice']['id']];
check($payload['products'][0]['price']===3.87 && $payload['products'][0]['vatIncluded']===1 && $payload['products'][0]['vatPercentage']===21.0 && $payload['collect']['value']===3.87,'Oblio shows original VAT within collected gross and never adds it twice');
// Replay an old invoice while current subscription/settings have a different VAT.
$taxHttp->subscription['metadata']['zion_vat_bps']='1900';$invoice['id']='in_tax_delayed';$taxHttp->invoices[$invoice['id']]=$invoice;
$taxBilling->process(['id'=>'evt_tax_delayed','type'=>'invoice.paid','data'=>['object'=>$invoice]]);
$row=$taxDb->one('SELECT source_snapshot FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoice['id']]);
check(Zion\EncryptedPayload::decode($row['source_snapshot'])['plan']['invoice_vat_rate']==='21','delayed fiscal invoice uses its own historical subscription tax snapshot');
$taxSettings->save('0',$taxAdmin);$before=$taxHttp->taxCreates;
check($taxSettings->rates(new Stripe\StripeClient('sk_test_fake'),'test',0)===[] && $taxHttp->taxCreates===$before,'zero VAT needs no Stripe tax object');
$price=$taxHttp->price('price_120');$price['tax_behavior']='inclusive';
try{Zion\BillingCatalog::validatePrice($price,$taxPlans->get('120gb'));check(false,'reject inclusive price');}catch(DomainException $e){check(true,'inclusive catalog price cannot absorb requested exclusive VAT');}
echo "$count total assertions passed, including exclusive VAT integration.\n";
