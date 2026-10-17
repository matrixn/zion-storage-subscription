<?php
declare(strict_types=1);
namespace Zion;

final class OblioInvoiceService
{
    private array $config;
    private OblioClient $client;

    public function __construct(private Database $db, private MailerService $mail, ?OblioClient $client = null, ?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__).'/config/oblio.php';
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
        return $id;
    }

    public function captureUpgrade(array $invoice,array $user,array $change,array $plan): string
    {
        $invoice['payment_intent'] ??= $change['payment_intent_id'];
        return $this->db->transaction(function()use($invoice,$user,$change,$plan){
            $old=$this->db->one('SELECT id FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoice['id']]);
            if($old)return $old['id'];
            $plan['invoice_name_ro']='Upgrade Zion Storage '.$change['from_plan'].' → '.$plan['name'].' '.$plan['capacity'];
            $plan['invoice_product_code'].='_UPGRADE';
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
        $rate = $source['plan']['invoice_vat_rate'];
        if ($rate === '') $rate = $this->config['vat_rate'];
        $vatName = $this->config['vat_name'];
        if (!$simulated && ($this->config['cif'] === '' || $this->config['series'] === '' || $rate === '' || $vatName === '')) throw new OblioException('fiscal_configuration_missing');
        if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100)) throw new OblioException('invalid_vat_configuration');
        if (!in_array($this->config['language'], ['RO','EN','FR','DE'], true)) throw new OblioException('unsupported_document_language');
        $client = [
            'name'=>$profile['type'] === 'company' ? $profile['company_name'] : $profile['name'],
            'cif'=>$profile['type'] === 'company' ? $profile['tax_id'] : '',
            'rc'=>$profile['registration_number'], 'code'=>'zs-'.$row['user_id'],
            'address'=>$profile['address'], 'state'=>$profile['county'], 'city'=>$profile['city'],
            'country'=>$profile['country'], 'postalCode'=>$profile['postal_code'], 'email'=>$profile['billing_email'],
            'phone'=>$profile['phone'], 'contact'=>$profile['name'], 'save'=>0, 'autocomplete'=>0,
        ];
        $paidAt = $source['invoice']['status_transitions']['paid_at'] ?? $source['invoice']['created'] ?? null;
        if (!is_int($paidAt) || $paidAt <= 0) throw new OblioException('payment_date_missing');
        $payload = [
            'cif'=>$this->config['cif'] ?: 'SIMULATION', 'client'=>$client,
            'clientLocation'=>[
                'name'=>$client['name'], 'identity'=>'zs-'.$row['user_id'],
                'country'=>$profile['country'], 'state'=>$profile['county'], 'city'=>$profile['city'],
                'address'=>$profile['address'], 'postalCode'=>$profile['postal_code'],
            ],
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
                if (($source['invoice']['livemode'] ?? false) === false && !$this->config['allow_test_payments']) throw new OblioException('real_document_for_test_payment_blocked');
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
                error_log('Fiscal invoice pending; id='.$id.'; code='.$safe);
                return false;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
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
