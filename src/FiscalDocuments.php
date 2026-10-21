<?php
declare(strict_types=1);
namespace Zion;

/** Fetch an existing document; never issue or modify an invoice. */
class FiscalDocuments {
 public function __construct(private Database $db,private ?OblioClient $client=null) {}
 public function latest(string $uid): ?array {
  return $this->db->one("SELECT id FROM fiscal_invoices WHERE user_id=? AND status IN ('issued','cancelled') AND oblio_series IS NOT NULL AND oblio_number IS NOT NULL ORDER BY COALESCE(issued_at,created_at) DESC,created_at DESC,id DESC LIMIT 1",[$uid]);
 }
 public function pdf(string $id,string $uid): array {
  $row=$this->db->one("SELECT * FROM fiscal_invoices WHERE id=? AND user_id=? AND status IN ('issued','cancelled')",[$id,$uid]);
  if(!$row || !$row['oblio_series'] || !$row['oblio_number'])throw new \DomainException('Factura nu are un document Oblio disponibil.');
  $snapshot=$row['request_snapshot']?EncryptedPayload::decode($row['request_snapshot']):[];
  $config=(new IntegrationSettings($this->db))->oblio();
  $cif=$snapshot['cif']??$config['cif'];
  if(!$cif || $cif==='SIMULATION')throw new \DomainException('Emitentul facturii nu poate fi identificat.');
  $doc=($this->client??new OblioClient($config))->getInvoice($cif,$row['oblio_series'],$row['oblio_number']);
  if($doc['series']!==$row['oblio_series'] || ltrim($doc['number'],'0')!==ltrim($row['oblio_number'],'0'))throw new \DomainException('Documentul returnat de Oblio nu corespunde facturii selectate.');
  $url=OblioClient::safeDocumentUrl($doc['url']??null);
  if(!$url || rtrim(parse_url($url,PHP_URL_PATH)??'','/')!=='/utils/show_file')throw new \DomainException('Oblio nu a returnat un link PDF valid.');
  $bytes=$this->fetchPdf($url);
  if(strlen($bytes)>10485760 || !str_starts_with($bytes,'%PDF-'))throw new \DomainException('Documentul Oblio nu este un PDF valid sau depășește 10 MB.');
  $name=preg_replace('/[^a-zA-Z0-9_-]/','-',$row['oblio_series'].'-'.$row['oblio_number']);
  return ['bytes'=>$bytes,'filename'=>'Zion-Storage-'.$name.'.pdf','type'=>'application/pdf'];
 }
 protected function fetchPdf(string $url): string {
  if(env('APP_ENV')==='test')throw new \RuntimeException('test_network_forbidden');
  $ch=curl_init($url);$bytes='';$started=microtime(true);
  curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Accept: application/pdf'],CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$bytes):int{if(strlen($bytes)+strlen($chunk)>10485760)return 0;$bytes.=$chunk;return strlen($chunk);}]);
  $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  $failed=$ok===false || $status!==200 || !str_starts_with($bytes,'%PDF-');
  ApiLog::record('oblio','GET','https://www.oblio.eu/utils/show_file/',$status,$started,['failed'=>$failed,'code'=>$failed?'document_download_failed':'document_downloaded','message'=>$failed?'Descărcarea PDF-ului Oblio a eșuat.':'PDF Oblio descărcat.']);
  if($failed)throw new \DomainException('PDF-ul Oblio nu poate fi descărcat. Consultă jurnalul API.');
  return $bytes;
 }
}
