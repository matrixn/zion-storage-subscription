<?php
declare(strict_types=1);
namespace Zion;

/** Transactional only: never creates subscribers or enrolls customers in campaigns. */
class ListmonkClient {
 public function __construct(private array $config) {}
 private function ready(): void {
  IntegrationSettings::validateListmonk($this->config);
  if(empty($this->config['enabled']))throw new \DomainException('Activează și salvează configurația Listmonk înainte de testare.');
 }
 protected function transport(string $method,string $url,?array $payload): array {
  $ch=curl_init($url);$body='';$multipart=isset($payload['file']) && $payload['file'] instanceof \CURLStringFile;
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$this->config['api_user'].':'.$this->config['api_token'],CURLOPT_HTTPHEADER=>$multipart?['Accept: application/json']:['Content-Type: application/json','Accept: application/json'],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}]);
  if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$multipart?$payload:json_encode($payload,JSON_THROW_ON_ERROR));
  $result=curl_exec($ch);$response=['body'=>$result===false?false:$body,'status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'errno'=>curl_errno($ch),'error'=>curl_error($ch)];curl_close($ch);return $response;
 }
 private function request(string $method,string $path,?array $payload=null): mixed {
  $this->ready();$url=rtrim($this->config['url'],'/').$path;$started=microtime(true);
  $attachmentSecrets=isset($payload['file']) && $payload['file'] instanceof \CURLStringFile?[$payload['file']->data,json_decode($payload['data'],true)??[]]:[];
  $sensitive=[$attachmentSecrets,$this->config['api_user'],$this->config['api_token'],base64_encode($this->config['api_user'].':'.$this->config['api_token']),$payload??[]];
  try{$response=$this->transport($method,$url,$payload);}
  catch(\Throwable $e){ApiLog::record('listmonk',$method,$url,0,$started,['failed'=>true,'code'=>'transport_exception','message'=>'Eroare la transportul cererii Listmonk.']);throw new \DomainException('Listmonk nu poate fi contactat. Consultă jurnalul API.');}
  $json=is_string($response['body'])?json_decode($response['body'],true):null;
  $details=ApiLog::responseDetails($response['body'],$response['status'],$response['errno'],$response['error'],$sensitive);
  $valid=$method==='POST'?($json['data']??null)===true:is_array($json['data']??null) && ($json['data']['type']??'')==='tx' && (int)($json['data']['id']??0)===(int)$this->config['template_id'];
  if(!$details['failed'] && !$valid)$details=array_replace($details,['failed'=>true,'code'=>'invalid_listmonk_response','message'=>$method==='POST'?'Listmonk nu a confirmat acceptarea mesajului.':'Șablonul nu este un șablon tranzacțional valid (type: tx).']);
  ApiLog::record('listmonk',$method,$url,$response['status'],$started,$details);
  if($details['failed'])throw new \DomainException('Listmonk: '.($response['status']?'HTTP '.$response['status'].'. ':'').ApiLog::redact($details['message'],$sensitive).' Consultă jurnalul API.');
  return $json['data'];
 }
 public function check(): void {$this->request('GET','/api/templates/'.(int)$this->config['template_id']);}
 public function send(string $to,string $subject,string $html,string $text,string $locale,string $kind): void {
  if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new \DomainException('Adresa destinatarului este invalidă.');
  $this->request('POST','/api/tx',$this->message($to,$subject,$html,$text,$locale,$kind));
 }
 public function sendWithAttachment(string $to,string $subject,string $html,string $text,string $locale,string $kind,array $pdf): void {
  $data=$this->message($to,$subject,$html,$text,$locale,$kind);
  $this->request('POST','/api/tx',['data'=>json_encode($data,JSON_THROW_ON_ERROR),'file'=>new \CURLStringFile($pdf['bytes'],$pdf['filename'],'application/pdf')]);
 }
 private function message(string $to,string $subject,string $html,string $text,string $locale,string $kind): array {
  if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new \DomainException('Adresa destinatarului este invalidă.');
  return ['subscriber_mode'=>'external','subscriber_emails'=>[$to],'template_id'=>(int)$this->config['template_id'],'from_email'=>'Zion Storage <'.$this->config['from_email'].'>','subject'=>$subject,'data'=>['html'=>$html,'text'=>$text,'subject'=>$subject,'locale'=>$locale,'kind'=>$kind],'content_type'=>'html','altbody'=>$text,'messenger'=>'email'];
 }
}
