<?php
declare(strict_types=1);
namespace Zion;

class OblioClient
{
    private ?string $token = null;
    private int $tokenExpires = 0;
    public function __construct(protected array $config) {}

    protected function transport(string $method, string $path, array $data, ?string $token, bool $json = true): array
    {
        // Tests must inject a fixture transport. There is no network-enabled test path.
        if (env('APP_ENV') === 'test') throw new OblioException('test_network_forbidden');
        $url = 'https://www.oblio.eu/api'.$path;
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer '.$token;
        if ($method === 'GET') $url .= '?'.http_build_query($data);
        else $headers[] = 'Content-Type: '.($json ? 'application/json' : 'application/x-www-form-urlencoded');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($data, JSON_THROW_ON_ERROR) : http_build_query($data));
        $started = microtime(true);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        ApiLog::curlResult('oblio',$method,$url,$ch,$body,$started,[$data,$token??'',$this->config['secret']??'']);
        curl_close($ch);
        if (self::missingDocumentResponse($method,$path,(int)$status,$body)) throw new OblioException('provider_document_missing');
        if ($status === 400 && is_string($body) && is_array(json_decode($body,true))) throw new OblioException('provider_request_rejected');
        if ($body === false || $status < 200 || $status >= 300) throw new OblioException('provider_transport_failed');
        try { $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new OblioException('provider_invalid_response'); }
        if (!is_array($result)) throw new OblioException('provider_invalid_response');
        if (isset($result['status']) && (int)$result['status'] !== 200) throw new OblioException('provider_request_rejected');
        return $result;
    }

    /** Only the documented missing-document response; auth, validation and outages stay errors. */
    public static function missingDocumentResponse(string $method,string $path,int $status,string|false $body): bool
    {
        if ($method !== 'GET' || $path !== '/docs/invoice' || !in_array($status,[200,400,404],true) || !is_string($body)) return false;
        $json=json_decode($body,true);
        if (!is_array($json)) return false;
        $message=$json['statusMessage']??$json['message']??null;
        if (!is_string($message)) return false;
        $message=strtr(mb_strtolower(trim($message)),['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t']);
        return (bool)preg_match('/^nu exista document cu seria\b.+\bsi numarul\b.+/u',$message);
    }

    public function authenticate(): string
    {
        if (!$this->config['enabled']) throw new OblioException('provider_disabled');
        if ($this->token && $this->tokenExpires > time()+60) return $this->token;
        if (!$this->config['email'] || !$this->config['secret']) throw new OblioException('provider_credentials_missing');
        $r = $this->transport('POST', '/authorize/token', ['client_id'=>$this->config['email'], 'client_secret'=>$this->config['secret']], null, false);
        if (empty($r['access_token']) || !is_string($r['access_token'])) throw new OblioException('provider_auth_failed');
        $this->token = $r['access_token'];
        $this->tokenExpires = time() + (int)($r['expires_in'] ?? 3600);
        return $this->token;
    }

    public function createInvoice(array $payload): array
    {
        return $this->document($this->transport('POST', '/docs/invoice', $payload, $this->authenticate()));
    }

    /** Explicit TEST reset only; Oblio permits deletion of the last invoice in a series. */
    public function deleteInvoice(string $cif,string $series,string $number,string $key): array
    {
        $result=$this->transport('DELETE','/docs/invoice',['cif'=>$cif,'seriesName'=>$series,'number'=>$number,'deleteCollect'=>1,'idempotencyKey'=>$key],$this->authenticate(),false);
        $data=$result['data']??[];
        if(($data['seriesName']??'')!==$series || ltrim((string)($data['number']??''),'0')!==ltrim($number,'0'))throw new OblioException('provider_delete_not_confirmed');
        return $data;
    }

    public function getInvoice(string $cif, string $series, string $number): array
    {
        return $this->document($this->transport('GET', '/docs/invoice', ['cif'=>$cif,'seriesName'=>$series,'number'=>$number], $this->authenticate()));
    }

    /** Operator-only capability. Never called on payment failure or cancellation. */
    public function cancelInvoice(string $cif, string $series, string $number): array
    {
        return $this->document($this->transport('PUT', '/docs/invoice/cancel', ['cif'=>$cif,'seriesName'=>$series,'number'=>$number], $this->authenticate(), false));
    }

    public static function safeDocumentUrl(?string $url): ?string
    {
        if (!$url) return null;
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['www.oblio.eu','oblio.eu'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            ? $url : null;
    }

    private function document(array $response): array
    {
        $d = $response['data'] ?? null;
        if (!is_array($d) || empty($d['seriesName']) || !isset($d['number']) || (string)$d['number'] === '') throw new OblioException('provider_missing_document');
        return ['id'=>isset($d['id'])?(string)$d['id']:null, 'series'=>(string)$d['seriesName'],
            'number'=>(string)$d['number'], 'url'=>self::safeDocumentUrl($d['link'] ?? null)];
    }
}
