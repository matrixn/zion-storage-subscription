<?php
declare(strict_types=1);
namespace Zion;

final class OblioInvoiceService
{
    private array $config;
    private OblioClient $client;

    public function __construct(private Database $db, private MailerService $mail, ?OblioClient $client = null, ?array $config = null)
    {
        $this->config = $config ?? (new IntegrationSettings($db))->oblio();
        $this->client = $client ?? new OblioClient($this->config);
    }

    /** Called in the same DB transaction that records the successful Stripe invoice. */
    public function capture(array $invoice, array $user, array $subscription, array $plan): ?string
    {
        if ($plan['slug'] === 'free' || ($invoice['status'] ?? '') !== 'paid') return null;
        $existing = $this->db->one('SELECT id FROM fiscal_invoices WHERE stripe_invoice_id=?', [$invoice['id']]);
        if ($existing) return $existing['id'];
        $profile = (new BillingProfileService($this->db))->get($user['id']);
        $source = ['simulated'=>!$this->config['enabled'], 'invoice'=>$invoice, 'profile'=>$profile, 'plan'=>$plan, 'user'=>[
            'id'=>$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'locale'=>$user['locale'],
        ], 'renewal_at'=>$subscription['items']['data'][0]['current_period_end'] ?? $subscription['current_period_end'] ?? null];
        $id = bin2hex(random_bytes(16));
        $period = $this->period($source, false);
        $amount = $invoice['amount_paid'] ?? null;
        if (!is_int($amount) || $amount < 0) throw new \RuntimeException('invalid_paid_amount');
        $paymentIntent = $invoice['payment_intent'] ?? $invoice['payments']['data'][0]['payment']['payment_intent'] ?? null;
        if (is_array($paymentIntent)) $paymentIntent = $paymentIntent['id'] ?? null;
        $this->db->run(
            'INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,stripe_payment_intent_id,billing_period_start,billing_period_end,amount,currency,plan,idempotency_key,source_snapshot,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id,$user['id'],$invoice['id'],$subscription['id'],(string)$invoice['customer'],$paymentIntent,$period[0],$period[1],
             $amount,strtoupper($invoice['currency'] ?? ''),$plan['slug'],'zion-storage:'.$invoice['id'],EncryptedPayload::encode($source),
             'pending',time(),time()]
        );
        $this->db->run('UPDATE fiscal_invoices SET stripe_mode=? WHERE id=?',[($invoice['livemode']??false)?'live':'test',$id]);
        return $id;
    }

    public function captureUpgrade(array $invoice,array $user,array $change,array $plan): string
    {
        $invoice['payment_intent'] ??= $change['payment_intent_id'];
        return $this->db->transaction(function()use($invoice,$user,$change,$plan){
            $old=$this->db->one('SELECT id FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoice['id']]);
            if($old)return $old['id'];
            if($change['fx_snapshot']??null){$fx=json_decode($change['fx_snapshot'],true);$plan['fx']=$fx;$plan['eur_amount']=$fx['eur_amount'];$plan['price_amount']=$fx['ron_amount'];}
            $plan['invoice_name_ro']='Upgrade '.(($plan['billing_interval']??'month')==='year'?'anual ':'').'Zion Storage '.$change['from_plan'].' → '.$plan['name'].' '.$plan['capacity'];
            $plan['invoice_product_code'].='_UPGRADE';
            if(($change['base_amount']??null)!==null){$plan['invoice_vat_rate']=BillingTax::percentage($change['tax_bps']);$plan['invoice_vat_name']='TVA';}
            $id=$this->capture($invoice,$user,['id'=>$change['stripe_subscription_id'],'current_period_end'=>$change['period_end']],$plan);
            $row=$this->db->one('SELECT source_snapshot FROM fiscal_invoices WHERE id=?',[$id]);
            $source=EncryptedPayload::decode($row['source_snapshot']);
            $source['upgrade']=['id'=>$change['id'],'start'=>$change['paid_at'],'end'=>$change['period_end'],'amount'=>$change['amount']];
            $this->db->run('UPDATE fiscal_invoices SET source_snapshot=?,billing_period_start=?,billing_period_end=? WHERE id=?',[EncryptedPayload::encode($source),$change['paid_at'],$change['period_end'],$id]);
            return $id;
        });
    }

    private function period(array $source, bool $strict = true): array
    {
        if(isset($source['upgrade'])) {
            $u=$source['upgrade'];
            if(!is_int($u['start']) || !is_int($u['end']) || $u['end']<=$u['start'] || $u['amount']!==$source['invoice']['amount_paid'])throw new OblioException('invalid_upgrade_snapshot');
            return [$u['start'],$u['end']];
        }
        $lines = $source['invoice']['lines']['data'] ?? [];
        if(isset($source['plan']['fx']['adjustment_ron'])){try{$lines=RonRenewals::baseLines($source['invoice'],$source['plan']['fx']);}catch(\Throwable $e){if($strict)throw new OblioException('unsupported_invoice_period_or_lines');return [null,null];}}
        $line = count($lines) === 1 ? $lines[0] : null;
        $price = $line['price']['id'] ?? $line['pricing']['price_details']['price'] ?? null;
        $period = $line['period'] ?? [];
        $proration = $line['proration'] ?? $line['parent']['subscription_item_details']['proration'] ?? false;
        if (!$line || $price !== $source['plan']['stripe_price_id'] || $proration
            || ($source['invoice']['lines']['has_more'] ?? false)
            || ($line['quantity'] ?? 1) !== 1 || !is_int($period['start'] ?? null) || !is_int($period['end'] ?? null)
            || $period['end'] <= $period['start']) {
            if ($strict) throw new OblioException('unsupported_invoice_period_or_lines');
            return [null, null];
        }
        return [$period['start'], $period['end']];
    }

    public static function coveredPeriod(int $start, int $end): string
    {
        $zone = new \DateTimeZone('Europe/Bucharest');
        $first = (new \DateTimeImmutable('@'.$start))->setTimezone($zone);
        $last = (new \DateTimeImmutable('@'.$end))->setTimezone($zone)->modify('-1 day');
        if ($last < $first) $last = (new \DateTimeImmutable('@'.($end-1)))->setTimezone($zone);
        return $first->format('d.m.Y').' - '.$last->format('d.m.Y');
    }

    private static function fiscalDate(int $timestamp, string $format = 'Y-m-d'): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format($format);
    }

    private function payload(array $row, array $source): array
    {
        $simulated = $source['simulated'] ?? !$this->config['enabled'];
        if (!in_array($row['currency'], $this->config['currencies'], true)) throw new OblioException('unsupported_invoice_currency');
        [$start,$end] = $this->period($source);
        $profile = $source['profile'];
        if (!$profile) throw new OblioException('billing_profile_missing');
        $profile = BillingProfileService::validate($profile, $source['user']);
        $address=OblioAddress::normalize($profile);
        $rate = $source['plan']['invoice_vat_rate'];
        if ($rate === '') $rate = $this->config['vat_rate'];
        $vatName = $source['plan']['invoice_vat_name'] ?? $this->config['vat_name'];
        if (!$simulated && ($this->config['cif'] === '' || $this->config['series'] === '' || $rate === '' || $vatName === '')) throw new OblioException('fiscal_configuration_missing');
        if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100)) throw new OblioException('invalid_vat_configuration');
        if (!in_array($this->config['language'], ['RO','EN','FR','DE'], true)) throw new OblioException('unsupported_document_language');
        $client = [
            'name'=>$profile['type'] === 'company' ? $profile['company_name'] : $profile['name'],
            'cif'=>$profile['type'] === 'company' ? $profile['tax_id'] : '',
            'rc'=>$profile['registration_number'], 'code'=>'zs-'.$row['user_id'],
            'address'=>$profile['address'], 'state'=>$address['state'], 'city'=>$address['city'],
            'country'=>$address['country'], 'postalCode'=>$profile['postal_code'], 'email'=>$profile['billing_email'],
            'phone'=>$profile['phone'], 'contact'=>$profile['name'], 'save'=>1, 'autocomplete'=>0,
        ];
        $paidAt = $source['invoice']['status_transitions']['paid_at'] ?? $source['invoice']['created'] ?? null;
        if (!is_int($paidAt) || $paidAt <= 0) throw new OblioException('payment_date_missing');
        $payload = [
            'cif'=>$this->config['cif'] ?: 'SIMULATION', 'client'=>$client,
            'issueDate'=>self::fiscalDate(time()), 'dueDate'=>self::fiscalDate(time()),
            'collectDate'=>self::fiscalDate($paidAt), 'seriesName'=>$this->config['series'] ?: 'SIMULATION',
            'language'=>$this->config['language'], 'precision'=>2, 'currency'=>$row['currency'],
            'idempotencyKey'=>$row['idempotency_key'], 'orderNumber'=>$row['stripe_invoice_id'],
            'internalNote'=>'Zion Storage / '.$row['stripe_invoice_id'], 'sendEmail'=>0, 'useStock'=>0,
            'collect'=>['type'=>'Card','documentNumber'=>$row['stripe_invoice_id'],'value'=>$row['amount']/100],
            'products'=>[[
                'name'=>$source['plan']['invoice_name_ro'].' - perioada '.self::coveredPeriod($start,$end),
                'code'=>$source['plan']['invoice_product_code'], 'measuringUnit'=>$source['plan']['invoice_unit'],
                'price'=>$row['amount']/100, 'quantity'=>1, 'currency'=>$row['currency'],
                'vatName'=>$vatName ?: 'SIMULATION', 'vatPercentage'=>$rate === '' ? 0 : (float)$rate,
                'vatIncluded'=>1, 'productType'=>'Serviciu', 'save'=>0,
            ]],
        ];
        if($row['currency']==='RON' && !empty($source['plan']['fx']['rate_date']))$payload['mentions']=RonRenewals::note($source['plan']['fx']+['eur_amount'=>$source['plan']['eur_amount'],'ron_amount'=>$source['plan']['price_amount']]);
        if ($this->config['workstation'] !== '') $payload['workStation'] = $this->config['workstation'];
        return $payload;
    }

    /** Provider failures are contained here: successful payment/entitlement stays successful. */
    public function process(string $id): bool
    {
        $lock = fopen(dirname(__DIR__).'/storage/fiscal-'.hash('sha256',$id).'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $row = $this->db->one('SELECT * FROM fiscal_invoices WHERE id=?', [$id]);
            if (!$row) return false;
            if($this->db->one("SELECT user_id FROM test_account_resets WHERE user_id=? AND status='pending'",[$row['user_id']]))return false;
            if (in_array($row['status'], ['issued','cancelled','simulated','no_charge'], true)) return true;
            $this->db->run("UPDATE fiscal_invoices SET status='processing',attempts=attempts+1,updated_at=? WHERE id=?", [time(),$id]);
            try {
                $source = EncryptedPayload::decode($row['source_snapshot']);
                // A missing legacy profile can be supplied later. Existing snapshots are immutable.
                if (!$source['profile']) {
                    $source['profile'] = (new BillingProfileService($this->db))->get($row['user_id']);
                    if ($source['profile']) $this->db->run('UPDATE fiscal_invoices SET source_snapshot=? WHERE id=?', [EncryptedPayload::encode($source),$id]);
                }
                if ($row['amount'] === 0) {
                    $this->db->run("UPDATE fiscal_invoices SET status='no_charge',completed_at=?,error_message=NULL,updated_at=? WHERE id=?", [time(),time(),$id]);
                    return true;
                }
                if ($row['request_snapshot']) $payload = EncryptedPayload::decode($row['request_snapshot']);
                else {
                    $payload = $this->payload($row,$source);
                    // Freeze the exact request before ANY remote side effect.
                    $this->db->run('UPDATE fiscal_invoices SET request_snapshot=?,updated_at=? WHERE id=?', [EncryptedPayload::encode($payload),time(),$id]);
                }
                if ($source['simulated'] ?? !$this->config['enabled']) {
                    $this->db->run("UPDATE fiscal_invoices SET status='simulated',simulation_reference=?,error_message=NULL,updated_at=? WHERE id=?", ['SIM-'.substr(hash('sha256',$row['stripe_invoice_id']),0,12),time(),$id]);
                    return true;
                }
                if (!$this->config['enabled']) throw new OblioException('provider_disabled');
                if (($source['invoice']['livemode'] ?? false) === false && !$this->config['allow_test_payments'] && !($source['manual_test_authorized']??false)) throw new OblioException('real_document_for_test_payment_blocked');
                if (!Security::limit($this->db,'oblio:create:'.$payload['cif'],30,100)) throw new OblioException('provider_rate_limit');
                $document = $this->client->createInvoice($payload);
                if (empty($document['series']) || !isset($document['number'])) throw new OblioException('provider_missing_document');
                $document['url'] = OblioClient::safeDocumentUrl($document['url'] ?? null);
                $this->db->transaction(function() use($row,$id,$source,$document) {
                    $this->db->run("UPDATE fiscal_invoices SET status='issued',oblio_invoice_id=?,oblio_series=?,oblio_number=?,invoice_url=?,issued_at=?,notification_queued_at=?,error_message=NULL,next_attempt_at=NULL,updated_at=? WHERE id=?", [
                        $document['id'] ?? null,$document['series'],(string)$document['number'],$document['url'],time(),time(),time(),$id,
                    ]);
                    $period = $this->period($source);
                    $data = [
                        'plan'=>$source['plan']['name'].' '.$source['plan']['capacity'],
                        'amount'=>number_format($row['amount']/100,2,',','').' '.$row['currency'],
                        'period'=>self::coveredPeriod($period[0],$period[1]),
                        'number'=>$document['series'].' '.$document['number'],
                        'renewal'=>$source['renewal_at'] ? self::fiscalDate((int)$source['renewal_at'],'d.m.Y') : '—',
                        'url'=>$document['url'] ?? '', 'fiscal_invoice_id'=>$id,
                    ];
                    $this->mail->queue($source['profile']['billing_email'],$source['user']['locale'],'fiscal_invoice',$data,'fiscal:'.$row['stripe_invoice_id']);
                });
                return true;
            } catch (\Throwable $e) {
                $safe = $e instanceof OblioException ? $e->getMessage() : 'fiscal_processing_failed';
                $this->db->run("UPDATE fiscal_invoices SET status='failed',error_message=?,next_attempt_at=?,updated_at=? WHERE id=?", [$safe,time()+min(86400,60*(2**min((int)$row['attempts'],10))),time(),$id]);
                ApiLog::record('oblio','POST','/fiscal/process',0,microtime(true),['failed'=>true,'code'=>$safe,'message'=>self::errorDescription($safe),'context'=>['stage'=>'fiscal','invoice_id'=>$row['stripe_invoice_id']]]);
                error_log('Fiscal invoice pending; id='.$id.'; code='.$safe);
                return false;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Explicit per-document authorization, including Stripe TEST; automatic policy stays unchanged. */
    public function generateManually(string $id,string $admin): bool
    {
        if(!$this->config['enabled'])throw new \DomainException('Activează emiterea reală în configurarea Oblio înainte de generare.');
        $lock=fopen(dirname(__DIR__).'/storage/fiscal-'.hash('sha256',$id).'.lock','c');flock($lock,LOCK_EX);
        try{
            $row=$this->db->one('SELECT * FROM fiscal_invoices WHERE id=?',[$id]);
            if(!$row)throw new \DomainException('Factura nu există.');
            if($row['status']==='issued')return true;
            if(in_array($row['status'],['cancelled','no_charge'],true) || $row['amount']<=0)throw new \DomainException('Documentul nu poate fi generat pentru această plată.');
            if($this->db->one("SELECT user_id FROM test_account_resets WHERE user_id=? AND status='pending'",[$row['user_id']]))throw new \DomainException('Clientul are o resetare TEST în curs.');
            $source=EncryptedPayload::decode($row['source_snapshot']);
            $simulated=$source['simulated']??false;
            // A definitive rejection did not issue a document. An explicit admin retry
            // may correct its buyer data; uncertain transport outcomes keep their frozen request.
            $rebuild=$simulated || !$row['request_snapshot'] || $row['error_message']==='provider_request_rejected';
            if($rebuild){$profile=(new BillingProfileService($this->db))->get($row['user_id']);if($profile)$source['profile']=BillingProfileService::validate($profile,$source['user']);}

            $source['simulated']=false;$source['manual_test_authorized']=true;$source['manual_authorized_at']=time();
            $this->db->transaction(function()use($id,$row,$source,$simulated,$admin,$rebuild){
                $this->db->run("UPDATE fiscal_invoices SET source_snapshot=?,request_snapshot=CASE WHEN ? THEN NULL ELSE request_snapshot END,status='pending',simulation_reference=NULL,error_message=NULL,next_attempt_at=NULL,updated_at=? WHERE id=?",[EncryptedPayload::encode($source),(int)$rebuild,time(),$id]);
                $this->db->run('INSERT INTO admin_audit(admin_id,user_id,action,detail,created_at) VALUES(?,?,?,?,?)',[$admin,$row['user_id'],'fiscal_manual_generate',$row['stripe_invoice_id'].'; mode='.$row['stripe_mode'].'; real issuance explicitly requested',time()]);
            });
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return $this->process($id);
    }

    /** Invoked only by an actual CLI worker; connection checks do not mark activity. */
    public function work(): array
    {
        $started=time();$this->workerState(['started_at'=>$started]);
        $result=$this->retryPending(true);
        $this->workerState(['started_at'=>$started,'finished_at'=>time()]+$result);
        return $result;
    }
    private function workerState(array $state): void
    {
        $this->db->run("INSERT INTO integration_settings(key,value) VALUES('oblio:worker',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[json_encode($state,JSON_THROW_ON_ERROR)]);
    }
    public function diagnostics(): array
    {
        $counts=array_fill_keys(['pending','processing','failed','issued','simulated','no_charge','cancelled'],0);
        foreach($this->db->run('SELECT status,COUNT(*) total FROM fiscal_invoices GROUP BY status')->fetchAll() as $row)$counts[$row['status']]=(int)$row['total'];
        $row=$this->db->one("SELECT value FROM integration_settings WHERE key='oblio:worker'");$worker=$row?json_decode($row['value'],true):[];
        return ['counts'=>$counts,'worker'=>$worker?:[],'worker_recent'=>(int)($worker['started_at']??0)>time()-180];
    }
    public static function errorDescription(string $code): string
    {
        return match($code){
            'real_document_for_test_payment_blocked'=>'Plată Stripe TEST: emiterea unui document fiscal real este dezactivată pentru acest mod.',
            'provider_disabled'=>'Emiterea reală Oblio este dezactivată.',
            'billing_profile_missing'=>'Lipsesc datele de facturare ale clientului.',
            'fiscal_configuration_missing'=>'Completează firma emitentă, seria și cota/denumirea TVA în configurarea Oblio.',
            'provider_credentials_missing','provider_auth_failed'=>'Autentificarea Oblio nu este configurată sau a fost respinsă.',
            'provider_transport_failed','provider_request_rejected','provider_invalid_response'=>'Oblio nu a acceptat cererea. Vezi răspunsul în jurnalul API.',
            'unsupported_invoice_period_or_lines'=>'Perioada sau liniile facturii Stripe necesită verificare înainte de emitere.',
            'interrupted_worker'=>'Procesarea a fost întreruptă; factura poate fi reluată.',
            default=>$code,
        };
    }

    public function retryPending(bool $dueOnly = false, int $limit = 20): array
    {
        // Recover interrupted workers only after acquiring their released process lock.
        foreach ($this->db->run("SELECT id FROM fiscal_invoices WHERE status='processing'")->fetchAll() as $r) {
            $lock=fopen(dirname(__DIR__).'/storage/fiscal-'.hash('sha256',$r['id']).'.lock','c');
            if (flock($lock, LOCK_EX|LOCK_NB)) {
                $this->db->run("UPDATE fiscal_invoices SET status='failed',error_message='interrupted_worker',updated_at=? WHERE id=? AND status='processing'",[time(),$r['id']]);
                flock($lock,LOCK_UN);
            }
            fclose($lock);
        }
        $rows=$this->db->run("SELECT id FROM fiscal_invoices WHERE status IN ('pending','failed')".($dueOnly?' AND (next_attempt_at IS NULL OR next_attempt_at<=?)':'').' ORDER BY created_at LIMIT '.max(1,min(100,$limit)),$dueOnly?[time()]:[])->fetchAll();
        $ok=0; foreach($rows as $r) if($this->process($r['id'])) $ok++;
        return ['processed'=>count($rows),'succeeded'=>$ok,'pending'=>count($rows)-$ok];
    }
}
