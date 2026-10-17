<?php
declare(strict_types=1);

// All provider responses are local fixtures, including remote-commit/response-loss retries.
final class FixtureOblio extends Zion\OblioClient
{
    public array $documents = [];
    public array $payloads = [];
    public int $authCalls = 0;
    public int $createCalls = 0;
    public int $cancelCalls = 0;
    public bool $failBefore = false;
    public bool $loseResponse = false;
    public bool $unsafeLink = false;

    protected function transport(string $method, string $path, array $data, ?string $token, bool $json = true): array
    {
        if ($path === '/authorize/token') {
            $this->authCalls++;
            check(!$json && $data['client_id']==='fixture@example.com' && $data['client_secret']==='fixture-secret','Oblio authentication contract');
            return ['access_token'=>'fixture-access-token','expires_in'=>3600];
        }
        check($token === 'fixture-access-token','Oblio bearer authorization');
        if ($path === '/docs/invoice' && $method === 'POST') {
            $this->createCalls++;
            if ($this->failBefore) throw new Zion\OblioException('provider_transport_failed');
            $key=$data['idempotencyKey'];
            if (!isset($this->documents[$key])) {
                $n=count($this->documents)+1;
                $this->documents[$key]=['id'=>'fixture-'.$n,'seriesName'=>'ZS','number'=>str_pad((string)$n,4,'0',STR_PAD_LEFT),'link'=>$this->unsafeLink?'https://attacker.invalid/invoice':'https://www.oblio.eu/utils/show_file/?id='.$n];
                $this->payloads[$key]=$data;
            } else check(json_encode($this->payloads[$key])===json_encode($data),'ambiguous retry reuses byte-equivalent provider payload');
            if ($this->loseResponse) { $this->loseResponse=false; throw new Zion\OblioException('provider_transport_failed'); }
            return ['status'=>200,'data'=>$this->documents[$key]];
        }
        if ($path === '/docs/invoice/cancel') $this->cancelCalls++;
        return ['status'=>200,'data'=>['seriesName'=>'ZS','number'=>$data['number'],'link'=>'https://www.oblio.eu/utils/show_file/?id=1']];
    }
}

function fiscalInvoiceFixture(string $id, int $start, int $end, int $amount=799): array
{
    return [
        'id'=>$id,'object'=>'invoice','status'=>'paid','livemode'=>false,
        'subscription'=>'sub_fiscal','customer'=>'cus_fiscal','amount_paid'=>$amount,'currency'=>'ron',
        'total'=>$amount,'total_discount_amounts'=>[['amount'=>102]],'status_transitions'=>['paid_at'=>$start+60],
        'created'=>$start,'hosted_invoice_url'=>'https://invoice.stripe.com/i/'.$id,
        'lines'=>['has_more'=>false,'data'=>[[
            'id'=>'il_'.$id,'quantity'=>1,'price'=>['id'=>'price_120'],
            'period'=>['start'=>$start,'end'=>$end], 'proration'=>false,
        ]]],
    ];
}

$db = new Zion\Database(':memory:'); $db->migrate(); $db->migrate();
$plans = new Zion\Plans(); $mail = new Zion\MailerService($db); $store = new TestStorage();
$accounts = new Zion\AccountService($db,$plans,$mail,$store);
$config = require dirname(__DIR__).'/config/oblio.php';
$config = array_replace($config,[
    'enabled'=>true,'email'=>'fixture@example.com','secret'=>'fixture-secret','cif'=>'RO-FIXTURE',
    'series'=>'ZS','vat_rate'=>'21','vat_name'=>'Fixture VAT','language'=>'RO','allow_test_payments'=>true,
]);
$provider = new FixtureOblio($config);
$fiscal = new Zion\OblioInvoiceService($db,$mail,$provider,$config);
$billing = new TestStripe($db,$plans,$mail,$store,$fiscal);
[$uid,$token] = signup('fiscal@example.com','120gb','fr'); $accounts->verify($token);
$u=$db->one('SELECT * FROM users WHERE id=?',[$uid]);
$profileService=new Zion\BillingProfileService($db);
$p=$plans->get('120gb');
$oct=strtotime('2026-10-16T00:00:00Z');$nov=strtotime('2026-11-16T00:00:00Z');$dec=strtotime('2026-12-16T00:00:00Z');$jan=strtotime('2027-01-16T00:00:00Z');
$invoice1=fiscalInvoiceFixture('in_fiscal_1',$oct,$nov,697);
$invoice1['status_transitions']['paid_at']=strtotime('2026-10-15T22:30:00Z');
$invoice2=fiscalInvoiceFixture('in_fiscal_2',$nov,$dec,799);
$invoice3=fiscalInvoiceFixture('in_fiscal_3',$dec,$jan,799);
$subscription=['id'=>'sub_fiscal','customer'=>'cus_fiscal','status'=>'active','metadata'=>['user_id'=>$uid,'plan'=>'120gb'],'items'=>['data'=>[['price'=>['id'=>'price_120'],'current_period_end'=>$nov]]],'latest_invoice'=>$invoice1];
$billing->snapshot=$subscription;
$billing->invoices=['in_fiscal_1'=>$invoice1,'in_fiscal_2'=>$invoice2,'in_fiscal_3'=>$invoice3];
$event=['id'=>'evt_fiscal_1','type'=>'invoice.paid','data'=>['object'=>['id'=>'in_fiscal_1','subscription'=>'sub_fiscal']]];
check($fiscal->capture($invoice1,$u,$subscription,$plans->get('free'))===null,'free plan never creates a fiscal record');
$billing->process($event);
check($db->one("SELECT status FROM fiscal_invoices WHERE stripe_invoice_id='in_fiscal_1'")['status']==='pending' && $provider->createCalls===0,'webhook durably queues fiscal work without provider network latency');
$fiscal->retryPending();
$row1=$db->one("SELECT * FROM fiscal_invoices WHERE stripe_invoice_id='in_fiscal_1'");
check($row1['status']==='issued' && count($provider->documents)===1,'initial paid subscription issues exactly one fiscal invoice');
$payload1=$provider->payloads['zion-storage:in_fiscal_1'];
check($payload1['products'][0]['name']==='Abonament Zion Storage Personal 120 GB - perioada 16.10.2026 - 15.11.2026','Romanian fiscal line uses actual exclusive-end covered period');
check($payload1['products'][0]['price']===6.97 && $payload1['collect']['value']===6.97 && $row1['amount']===697,'fiscal gross equals actual discounted Stripe amount, not catalog price');
check($payload1['products'][0]['vatIncluded']===1 && $payload1['products'][0]['vatPercentage']===21.0,'VAT is isolated/configured and included in collected gross');
check($payload1['language']==='RO' && $payload1['sendEmail']===0,'Romanian fiscal content and locally localized customer email');
check($payload1['clientLocation']['postalCode']==='010101' && $payload1['client']['autocomplete']===0,'complete local billing address passed without remote autocomplete');
check($payload1['collectDate']==='2026-10-16','fiscal collection date uses Romanian issuer timezone across UTC midnight');
check($row1['billing_period_start']===$oct && $row1['billing_period_end']===$nov,'exact Stripe period timestamps persisted');
check($row1['completed_at']===null && $row1['notification_queued_at']!==null,'fiscal completion waits for email delivery');
$notification=$db->one("SELECT * FROM outbox WHERE dedupe_key='fiscal:in_fiscal_1'");
$data=Zion\EncryptedPayload::decode($notification['payload']);
check($notification['locale']==='fr' && $data['number']==='ZS 0001' && $data['period']==='16.10.2026 - 15.11.2026','localized invoice notification includes number and period');
check($data['plan']==='Personal 120 GB' && $data['amount']==='6,97 RON' && $data['renewal']==='16.11.2026','invoice email includes plan, collected amount and next renewal');
$mail->deliver();
check($db->one('SELECT completed_at FROM fiscal_invoices WHERE id=?',[$row1['id']])['completed_at']!==null,'fiscal step completes after local email delivery');
$billing->process($event);
$event['id']='evt_same_invoice_new_id';$billing->process($event);
check(count($provider->documents)===1 && $provider->createCalls===1,'same or distinct webhook IDs cannot duplicate the same Stripe invoice');
check((int)$db->one("SELECT COUNT(*) n FROM outbox WHERE dedupe_key='fiscal:in_fiscal_1'")['n']===1,'fiscal notification deduplicated by Stripe invoice');
check(!str_contains($row1['source_snapshot'],'fiscal@example.com'),'billing and invoice snapshots encrypted');
$billing->snapshot['latest_invoice']=$invoice2;$billing->snapshot['items']['data'][0]['current_period_end']=$dec;
$event=['id'=>'evt_fiscal_2','type'=>'invoice.paid','data'=>['object'=>['id'=>'in_fiscal_2','subscription'=>'sub_fiscal']]];
$provider->failBefore=true;$billing->process($event);$fiscal->retryPending();
$row2=$db->one("SELECT * FROM fiscal_invoices WHERE stripe_invoice_id='in_fiscal_2'");
check($row2['status']==='failed' && count($provider->documents)===1,'provider outage records failed fiscal step');
check($db->one('SELECT paid_confirmed FROM subscriptions WHERE user_id=?',[$uid])['paid_confirmed']===1,'provider outage preserves successful Stripe payment');
check($db->one("SELECT status FROM billing_events WHERE id='evt_fiscal_2'")['status']==='complete','provider outage does not reject Stripe webhook');
check($row2['error_message']==='provider_transport_failed' && !str_contains(json_encode($row2),'fixture-secret'),'persisted API error contains no secret or provider raw body');
$oldSnapshot=$row1['source_snapshot'];
$company=['type'=>'company','name'=>'Company Contact','company_name'=>'Future Company SRL','tax_id'=>'RO12345678','registration_number'=>'J00/1/2020','country'=>'RO','county'=>'Cluj','city'=>'Cluj-Napoca','address'=>'Strada Viitoare 2','postal_code'=>'400001','phone'=>'','billing_email'=>'billing@example.com'];
$profileService->save($uid,$company);
$provider->failBefore=false;$result=$fiscal->retryPending();
check($result['processed']===1 && $result['succeeded']===1 && count($provider->documents)===2,'CLI-equivalent retry issues failed invoice once');
check($provider->payloads['zion-storage:in_fiscal_2']['client']['name']==='Test User','failed invoice retry preserves original billing snapshot');
check($db->one('SELECT source_snapshot FROM fiscal_invoices WHERE id=?',[$row1['id']])['source_snapshot']===$oldSnapshot,'editing profile leaves issued invoice snapshot unchanged');
$fiscal->retryPending();check(count($provider->documents)===2,'retry skips issued invoices');
$billing->snapshot['latest_invoice']=$invoice3;$billing->snapshot['items']['data'][0]['current_period_end']=$jan;
$event=['id'=>'evt_fiscal_3','type'=>'invoice.paid','data'=>['object'=>['id'=>'in_fiscal_3','subscription'=>'sub_fiscal']]];
$billing->process($event);
 $fiscal->retryPending();
check(count($provider->documents)===3 && $provider->payloads['zion-storage:in_fiscal_3']['client']['name']==='Future Company SRL','monthly renewal uses updated company billing profile');
$notification=$db->one("SELECT * FROM outbox WHERE dedupe_key='fiscal:in_fiscal_3'");
check($notification['recipient']==='billing@example.com' && $notification['locale']==='fr','fiscal email uses local billing email and account locale');
check($provider->authCalls===1,'Oblio OAuth token reused until expiry');
$billing->snapshot['status']='past_due';$billing->snapshot['latest_invoice']['status']='open';
$event=['id'=>'evt_fiscal_failed','type'=>'invoice.payment_failed','data'=>['object'=>['id'=>'in_fiscal_4','subscription'=>'sub_fiscal']]];
$billing->process($event);
check(count($provider->documents)===3 && (int)$db->one('SELECT COUNT(*) n FROM fiscal_invoices')['n']===3,'failed payment creates no fiscal invoice');
$billing->snapshot['status']='canceled';
$event=['id'=>'evt_fiscal_cancel','type'=>'customer.subscription.deleted','data'=>['object'=>['id'=>'sub_fiscal']]];
$billing->process($event);
check($provider->cancelCalls===0 && count($provider->documents)===3,'subscription cancellation never cancels or creates fiscal documents');

// A late event must preserve subscription cancellation yet invoice its historical payment.
$late=fiscalInvoiceFixture('in_historical',$oct,$nov,555);$billing->invoices[$late['id']]=$late;
$event=['id'=>'evt_historical','type'=>'invoice.paid','data'=>['object'=>['id'=>$late['id'],'subscription'=>'sub_fiscal']]];
$billing->process($event);
$fiscal->retryPending();
$lateRow=$db->one('SELECT * FROM fiscal_invoices WHERE stripe_invoice_id=?',[$late['id']]);
check($lateRow['amount']===555 && $lateRow['billing_period_end']===$nov,'late event captures its specific invoice, not latest invoice');
check($db->one('SELECT status FROM subscriptions WHERE user_id=?',[$uid])['status']==='canceled','historical paid event cannot reactivate canceled subscription');

// Ambiguous timeout: remote invoice exists, response was lost, local retry reuses key AND payload.
$timeoutInvoice=fiscalInvoiceFixture('in_timeout',$nov,$dec);
$id=$fiscal->capture($timeoutInvoice,$u,$subscription,$p);$provider->loseResponse=true;
check(!$fiscal->process($id),'lost provider response remains retryable');
$before=count($provider->documents);$fiscal->process($id);
check(count($provider->documents)===$before && $db->one('SELECT status FROM fiscal_invoices WHERE id=?',[$id])['status']==='issued','timeout after remote creation cannot duplicate invoice');

// Crash after remote call: the provider key survives stale processing recovery.
$interrupted=fiscalInvoiceFixture('in_interrupted',$nov,$dec);
$id=$fiscal->capture($interrupted,$u,$subscription,$p);
$provider->loseResponse=true;$fiscal->process($id);
$db->run("UPDATE fiscal_invoices SET status='processing' WHERE id=?",[$id]);$before=count($provider->documents);
$fiscal->retryPending();
check(count($provider->documents)===$before && $db->one('SELECT status FROM fiscal_invoices WHERE id=?',[$id])['status']==='issued','interrupted worker reconciles safely');

// Unsupported currencies and proration are blocked for review, never silently transformed.
$currency=fiscalInvoiceFixture('in_eur',$nov,$dec);$currency['currency']='eur';
$id=$fiscal->capture($currency,$u,$subscription,$p);$before=count($provider->documents);
check(!$fiscal->process($id) && count($provider->documents)===$before,'unconfigured currency blocks fiscal issuance');
$db->run("UPDATE fiscal_invoices SET status='cancelled' WHERE id=?",[$id]); // retire this fixture, not provider document
$proration=fiscalInvoiceFixture('in_proration',$nov,$dec);$proration['lines']['data'][0]['proration']=true;
$id=$fiscal->capture($proration,$u,$subscription,$p);
check(!$fiscal->process($id),'proration needs explicit fiscal mapping');
$db->run("UPDATE fiscal_invoices SET status='cancelled' WHERE id=?",[$id]);
$zero=fiscalInvoiceFixture('in_zero',$nov,$dec,0);
$id=$fiscal->capture($zero,$u,$subscription,$p);$before=count($provider->documents);$fiscal->process($id);
check($db->one('SELECT status FROM fiscal_invoices WHERE id=?',[$id])['status']==='no_charge' && count($provider->documents)===$before,'zero collected amount generates no fiscal document');

// Default-off simulation never issues real documents, even when later retried with enabled config.
$off=array_replace($config,['enabled'=>false]);
$simulation=new Zion\OblioInvoiceService($db,$mail,$provider,$off);
$sim=fiscalInvoiceFixture('in_simulation',$nov,$dec);
$id=$simulation->capture($sim,$u,$subscription,$p);$before=count($provider->documents);$simulation->process($id);
$simRow=$db->one('SELECT * FROM fiscal_invoices WHERE id=?',[$id]);
check($simRow['status']==='simulated' && $simRow['oblio_invoice_id']===null && $simRow['completed_at']===null,'disabled mode clearly separates simulation from issued fiscal documents');
check(!$db->one("SELECT id FROM outbox WHERE dedupe_key='fiscal:in_simulation'"),'simulation sends no misleading fiscal-issued email');
$fiscal->process($id);check(count($provider->documents)===$before,'simulation never silently promoted to live issuance');
$blocked=new Zion\OblioInvoiceService($db,$mail,$provider,array_replace($config,['allow_test_payments'=>false]));
$id=$blocked->capture(fiscalInvoiceFixture('in_test_block',$nov,$dec),$u,$subscription,$p);
check(!$blocked->process($id) && count($provider->documents)===$before,'real fiscal issuance for Stripe TEST payment requires explicit configuration');
$db->run("UPDATE fiscal_invoices SET status='cancelled' WHERE id=?",[$id]);
try {(new Zion\OblioClient($config))->authenticate();check(false,'network test guard');}
catch(Zion\OblioException $e) {check($e->getMessage()==='test_network_forbidden','automated tests cannot reach production Oblio');}

check(Zion\OblioClient::safeDocumentUrl('javascript:alert(1)')===null,'unsafe provider document URL rejected');
check(Zion\OblioClient::safeDocumentUrl('https://www.oblio.eu.attacker.invalid/x')===null,'document hostname suffix trick rejected');
foreach(['ro','en','fr','de'] as $locale) {
    $tr=new Zion\TranslationService($locale);
    check(!str_contains($tr->get('email.fiscal_invoice.body',['plan'=>'P','amount'=>'1 RON','period'=>'D','number'=>'N','renewal'=>'R']),'{'),'invoice email parameters translated in '.$locale);
    $mail->queue('preview@example.com',$locale,'fiscal_invoice',array_diff_key($data,['fiscal_invoice_id'=>true]),'fiscal-render:'.$locale);
}
try {$profileService->save($uid,array_replace($company,['tax_id'=>'']));check(false,'company validation');}
catch(InvalidArgumentException $e){check(true,'company tax number required');}
try {$profileService->save($uid,array_replace($company,['address'=>"bad\x00data"]));check(false,'billing control characters');}
catch(InvalidArgumentException $e){check(true,'billing control characters rejected');}
check((new Zion\Database(':memory:'))->pdo instanceof PDO,'database constructor remains lightweight');
$mail->deliver();
check((int)$db->one("SELECT COUNT(*) n FROM fiscal_invoices WHERE status='issued' AND completed_at IS NULL")['n']===0,'issued fiscal notifications complete through durable mail outbox');
check((int)$db->one("SELECT COUNT(*) n FROM outbox WHERE dedupe_key LIKE 'fiscal-render:%' AND sent_at IS NOT NULL")['n']===4,'all four fiscal notification HTML templates render');
echo "\n$count total assertions passed including fiscal acceptance scenarios. No production fiscal documents issued.\n";
