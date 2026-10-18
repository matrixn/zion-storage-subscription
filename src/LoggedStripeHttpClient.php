<?php
declare(strict_types=1);
namespace Zion;

/** Captures all SDK operations (Checkout, billing, catalog, retries and workers). */
final class LoggedStripeHttpClient implements \Stripe\HttpClient\ClientInterface {
 public function __construct(private \Stripe\HttpClient\ClientInterface $inner) {}
 public function request($method,$absUrl,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null) {
  $start=microtime(true);$mode='';$sensitive=[$params];
  foreach($headers as $header){if(preg_match('/(?:sk|rk)_(test|live)_/',$header,$match))$mode=$match[1];if(preg_match('/^(?:Authorization|Stripe-Account):\s*(?:Bearer\s+)?(.+)$/i',$header,$match))$sensitive[]=$match[1];}
  try{
   $result=$this->inner->request($method,$absUrl,$headers,$params,$hasFile,$apiMode,$maxNetworkRetries);
   [$body,$status,$responseHeaders]=$result;
   $details=ApiLog::responseDetails($body,(int)$status,0,'',$sensitive)+['mode'=>$mode];
   foreach($responseHeaders as $key=>$value)if(strtolower((string)$key)==='request-id')$details['request_id']=$value;
   ApiLog::record('stripe',$method,$absUrl,(int)$status,$start,$details);
   return $result;
  }catch(\Throwable $e){
   $code=preg_match('/errno\s+(\d+)/i',$e->getMessage(),$match)?'curl_'.$match[1]:(new \ReflectionClass($e))->getShortName();
   ApiLog::record('stripe',$method,$absUrl,0,$start,['mode'=>$mode,'failed'=>true,'code'=>$code,'message'=>$e->getMessage(),'sensitive'=>$sensitive]);
   throw $e;
  }
 }
}
