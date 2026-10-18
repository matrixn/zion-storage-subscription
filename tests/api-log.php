<?php
// Isolated logging tests: no requests, real credentials or existing log files.
$oldLog=$_ENV['API_LOG_PATH']??null;
$logPath=sys_get_temp_dir().'/zion-api-log-'.bin2hex(random_bytes(8)).'.sqlite';$_ENV['API_LOG_PATH']=$logPath;
try{
 $fakeTransport=new class implements Stripe\HttpClient\ClientInterface {
  public bool $fail=false;
  public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
   if($this->fail)throw new Stripe\Exception\ApiConnectionException('Could not resolve host: api.stripe.com; sk_test_fixtureSecret secret-access-password');
   return [json_encode(['error'=>['type'=>'invalid_request_error','code'=>'resource_missing','message'=>'Missing <script>alert(1)</script> for private@example.com secret-access-password https://example.com/?token=private']]),404,['Request-Id'=>'req_fixtureLog']];
  }
 };
 $transport=new Zion\LoggedStripeHttpClient($fakeTransport);
 $headers=['Authorization: Bearer sk_test_fixtureSecret'];$params=['customer'=>'cus_private','password'=>'secret-access-password'];
 $result=$transport->request('get','https://api.stripe.com/v1/customers/cus_private?email=private@example.com',$headers,$params,false);
 $page=Zion\ApiLog::page(['provider'=>'stripe','status'=>'error','mode'=>'test']);$row=$page['rows'][0];
 check($page['total']===1 && $row['http_status']===404 && $row['request_id']==='req_fixtureLog' && $row['error_code']==='resource_missing','Stripe transport logs status, mode, provider error and request ID');
 $raw=json_encode($row);
 check(!str_contains($raw,'sk_test_fixtureSecret') && !str_contains($raw,'secret-access-password') && !str_contains($raw,'private@example.com') && !str_contains($raw,'cus_private') && !str_contains($row['endpoint'],'?'),'API log redacts credentials, personal request values, query parameters and resource IDs');
 check($result[1]===404,'logging preserves original provider response');
 $fakeTransport->fail=true;
 try{$transport->request('get','https://api.stripe.com/v1/prices',$headers,$params,false);check(false,'transport exception swallowed');}catch(Stripe\Exception\ApiConnectionException $e){check(str_contains($e->getMessage(),'Could not resolve host'),'logging preserves original transport exception');}
 $row=Zion\ApiLog::page(['provider'=>'stripe'])['rows'][0];
 check($row['http_status']===0 && str_contains($row['message'],'Could not resolve host') && !str_contains($row['message'],'fixtureSecret'),'connection diagnostics retain useful DNS failure and redact credentials');
 $tx=new Zion\Database(':memory:');
 try{$tx->transaction(function(){Zion\ApiLog::record('oblio','POST','https://www.oblio.eu/api/docs/invoice',500,microtime(true),['message'=>'Provider unavailable']);throw new RuntimeException('rollback fixture');});}catch(RuntimeException $e){}
 check(Zion\ApiLog::page(['provider'=>'oblio','status'=>'error'])['total']===1,'API errors survive billing transaction rollback');
 Zion\ApiLog::record('immich','GET','https://example.com/api/users/me',200,microtime(true),['message'=>'Cerere finalizată.']);
 check(Zion\ApiLog::page(['provider'=>'immich','status'=>'ok'])['total']===1 && Zion\ApiLog::page(['mode'=>'live'])['total']===0,'success and mode filters isolate communication records');
 $oldTransport=Stripe\ApiRequestor::httpClient();$fakeTransport->fail=false;Stripe\ApiRequestor::setHttpClient($transport);
 try{(new Stripe\StripeClient('sk_live_fixtureLog'))->prices->retrieve('price_private');check(false,'SDK error ignored');}
 catch(Stripe\Exception\InvalidRequestException $e){$row=Zion\ApiLog::page(['provider'=>'stripe','mode'=>'live'])['rows'][0];check($row['http_status']===404 && $row['request_id']==='req_fixtureLog','global Stripe SDK instrumentation covers arbitrary clients and binds mode to credentials');}
 finally{Stripe\ApiRequestor::setHttpClient($oldTransport);}
 $details=Zion\ApiLog::responseDetails(false,0,60,'SSL certificate problem: unable to get local issuer certificate');
 check($details['failed'] && $details['code']==='curl_60' && str_contains($details['message'],'issuer certificate'),'cURL diagnostic captures CA certificate errors');
 $details=Zion\ApiLog::responseDetails('{"error":"invalid_client","error_description":"Invalid credentials"}',401,0,'');
 check($details['failed'] && $details['code']==='invalid_client' && $details['message']==='Invalid credentials','Oblio OAuth failures preserve provider error code and description');
 check(Zion\ApiLog::responseDetails('{invalid',200,0,'')['failed'] && Zion\ApiLog::responseDetails('{"status":400,"statusMessage":"Bad input"}',200,0,'')['failed'],'malformed JSON and semantic API rejections are logged as errors');
 check(!Zion\ApiLog::responseDetails('',204,0,'')['failed'] && !Zion\ApiLog::responseDetails('{"status":201}',201,0,'')['failed'],'valid empty and created responses are recorded as successful');
 Zion\ApiLog::record('stripe','POST','https://api.stripe.com/v1/checkout/sessions/cs_test_fixturePrivate',200,microtime(true));
 check(Zion\ApiLog::page(['provider'=>'stripe'])['rows'][0]['endpoint']==='api.stripe.com/v1/checkout/sessions/{id}','Checkout session identifiers are masked while retaining operation route');
 $logDb=new PDO('sqlite:'.$logPath);$logDb->exec('UPDATE api_log SET created_at=1');
 for($i=0;$i<52;$i++)Zion\ApiLog::record('immich','GET','https://example.com/api/users/me',200,microtime(true));
 $page=Zion\ApiLog::page(['provider'=>'immich'],2);
 check($page['total']===52 && count($page['rows'])===2 && $page['page']===2,'retention removes expired records and pagination is bounded');
 $logDb->exec("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<10001) INSERT INTO api_log(created_at,provider,mode,direction,method,endpoint,status,http_status,duration_ms,error_code,message,request_id) SELECT ".time().",'immich','','out','GET','example.com/api/users/me','ok',200,1,'','','' FROM n");
 Zion\ApiLog::record('immich','GET','https://example.com/api/users/me',200,microtime(true));
 check(Zion\ApiLog::page([])['total']===10000,'API journal enforces a hard ten-thousand-record bound');
 $_ENV['API_LOG_PATH']=$logPath.'/not-a-directory.sqlite';$fakeTransport->fail=false;
 check($transport->request('get','https://api.stripe.com/v1/prices',$headers,[],false)[1]===404,'logging failure never interrupts API operations');
}finally{if($oldLog===null)unset($_ENV['API_LOG_PATH']);else $_ENV['API_LOG_PATH']=$oldLog;unset($logDb);@unlink($logPath);}
echo "\n$count total assertions passed, including sanitized API communication logs.\n";
