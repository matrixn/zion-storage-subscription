<?php
namespace Zion;
final class Application {
 public Database $db; public Plans $plans; public MailerService $mail; public AccountService $accounts; public BillingService $billing;
 private string $locale='en'; private string $page='home'; private TranslationService $tr;
 public function __construct() {
  $this->db=new Database(); $this->plans=new Plans($this->db); $this->mail=new MailerService($this->db);
  $storage=new StorageProvisioningService(); $this->accounts=new AccountService($this->db,$this->plans,$this->mail,$storage);
  $this->billing=new StripeService($this->db,$this->plans,$this->mail,$storage);
 }
 private function redirect(string $path): never { header('Location: '.$path,true,303); exit; }
 private function user(): ?array {
  if(empty($_SESSION['uid']) || ($_SESSION['auth_until']??0)<time()) return null;
  return $this->db->one('SELECT * FROM users WHERE id=?',[$_SESSION['uid']]);
 }
 private function authenticate(string $uid): void { session_regenerate_id(true); $_SESSION['uid']=$uid; $_SESSION['auth_until']=time()+3600*4; }
 private function fail(string $key,int $status=422): void { http_response_code($status); $this->render($status===404?'error':$this->page,['error'=>$key]); }
 public function handle(): void {
  header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
  $captcha=env('CAPTCHA_ENABLED')==='true'?" https://challenges.cloudflare.com":'';
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'".$captcha."; frame-src 'self'".$captcha."; connect-src 'self'".$captcha."; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com");
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  if(production()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/'; $method=$_SERVER['REQUEST_METHOD'];
  if($path==='/webhooks/stripe') {
   header('Cache-Control: no-store');
   if($method!=='POST') {ApiLog::record('stripe',$method,'/webhooks/stripe',405,microtime(true),['direction'=>'in','code'=>'method_not_allowed','message'=>'Webhookul acceptă doar POST.','context'=>['stage'=>'request','signature'=>'not_checked']]);http_response_code(405);return;}
   if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576){ApiLog::record('stripe','POST','/webhooks/stripe',413,microtime(true),['direction'=>'in','code'=>'payload_too_large','message'=>'Corpul webhookului depășește limita de 1 MB.','context'=>['stage'=>'request','signature'=>'not_checked']]);http_response_code(413);return;}
   $code=204;
   try{$this->billing->receive(file_get_contents('php://input',false,null,0,1048577),$_SERVER['HTTP_STRIPE_SIGNATURE']??'');}
   catch(\Stripe\Exception\SignatureVerificationException|\UnexpectedValueException|\JsonException $e){$code=400;}
   catch(\Throwable $e){error_log('Billing event requires retry');$code=503;}
   http_response_code($code);
   return;
  }
  if($path==='/sitemap.xml') {$this->sitemap();return;}
  if($path==='/robots.txt') {header('Content-Type: text/plain');echo "User-agent: *\nAllow: /\nDisallow: /webhooks/\nSitemap: ".base_url()."/sitemap.xml\n";return;}
  if($path==='/') $this->redirect(Router::path(TranslationService::preferred($_COOKIE['locale']??'',$_SERVER['HTTP_ACCEPT_LANGUAGE']??''),'home'));
  $route=Router::match($path);
  if(!$route){$this->tr=new TranslationService('en');$this->fail('error.notfound',404);return;}
  [$this->locale,$this->page]=$route; $this->tr=new TranslationService($this->locale);
  setcookie('locale',$this->locale,['expires'=>time()+31536000,'path'=>'/','secure'=>str_starts_with(base_url(),'https://'),'httponly'=>true,'samesite'=>'Lax']);
  header('Cache-Control: private, no-store');
  if($method==='POST') {
   if(!Security::checkCsrf()){$this->fail('error.csrf',419);return;}
   if(!Security::limit($this->db,'post:'.$this->page.':'.Security::clientIp(),$this->page==='contact'?5:((($_POST['automatic']??'')==='1' && $this->page==='activate')?20:15),900)){$this->fail('error.rate',429);return;}
   try{$this->post();}catch(\Throwable $e){error_log('Request failed; page='.$this->page);$this->fail($this->page==='activate'?'status.retry':'error.generic',503);}
   return;
  }
  if($method!=='GET' && $method!=='HEAD'){http_response_code(405);return;}
  if($this->page==='verify') {
   $token=$_GET['token']??'';
   // Do not consume tokens on GET: mail scanners must not activate accounts.
   $this->render('verify',['token'=>is_string($token)?$token:'']);return;
  }
  if(in_array($this->page,['status','checkout','cancel','activate','portal','billing-profile','upgrade'],true)&&!$this->user()){
   $this->render('resend',['notice'=>'status.recover']);return;
  }
  if($this->page==='checkout' && $this->user() && !(new BillingProfileService($this->db))->get($this->user()['id'])) $this->redirect(Router::path($this->locale,'billing-profile'));
  $this->render($this->page);
 }
 private function post(): void {
  if($this->page==='register') {
   $d=[]; foreach(['name','email','password','confirm','locale','plan'] as $k) $d[$k]=is_string($_POST[$k]??null)?(in_array($k,['password','confirm'],true)?$_POST[$k]:trim($_POST[$k])):'';
   if(!empty($_POST['company'])) {$this->render('message',['message'=>'register.sent']);return;}
   if(production() && env('LEGAL_READY')!=='true') {$this->fail('legal.pending',503);return;}
   if(mb_strlen($d['name'])<2 || mb_strlen($d['name'])>150 || !filter_var($d['email'],FILTER_VALIDATE_EMAIL) || strlen($d['email'])>254 || !in_array($d['locale'],TranslationService::LOCALES,true) || empty($_POST['terms']) || empty($_POST['privacy'])){$this->fail('error.validation');return;}
   if(!AccountService::passwordValid($d['password']) || $d['password']!==$d['confirm']){$this->fail('error.password');return;}
   if(!$this->plans->get($d['plan'])){$this->fail('error.plan');return;}
   $d['billing_interval']=(string)($_POST['billing_interval']??'month');
   if(!in_array($d['billing_interval'],['month','year'],true)){$this->fail('error.plan');return;}
   if($d['plan']!=='free') {
    try { $d['billing']=BillingProfileService::validate(is_array($_POST['billing']??null)?$_POST['billing']:[], $d); }
    catch(\InvalidArgumentException $e) { $this->fail('billing.invalid');return; }
   }
   if(!Security::captcha((string)($_POST['cf-turnstile-response']??''))){$this->fail('error.captcha');return;}
   // Same response for existing accounts. No session privilege is granted before verification.
   $this->accounts->register($d);
   $this->render('message',['message'=>'register.sent']);return;
  }
  if($this->page==='verify') {
   $uid=$this->accounts->verify((string)($_POST['token']??''));
   if(!$uid){$this->fail('error.token');return;}
   $this->authenticate($uid);$u=$this->user();
   $this->redirect(Router::path($u['locale'],$this->accounts->eligible($u) || (new AccountControl($this->db))->banned($uid)?'status':'checkout'));
  }
  if($this->page==='resend') {
   $email=strtolower(trim((string)($_POST['email']??'')));
   if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$this->fail('error.validation');return;}
   if(Security::limit($this->db,'resend:'.$email,3,3600)) {
    $u=$this->db->one('SELECT * FROM users WHERE email=?',[$email]);
    if($u && !$u['email_verified_at']) $this->accounts->issueVerification($u['id']);
    elseif($u) {
     $token=bin2hex(random_bytes(32));
     $this->db->transaction(function() use($u,$token) {
      $this->db->run('DELETE FROM email_verifications WHERE user_id=?',[$u['id']]);
      $this->db->run('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)',[hash('sha256',$token),$u['id'],time()+3600]);
      $this->mail->queue($u['email'],$u['locale'],'verify',['url'=>base_url().Router::path($u['locale'],'verify').'?token='.$token],'access:'.hash('sha256',$token));
     });
    }
   }
   $this->render('message',['message'=>'resend.sent']);return;
  }
  if($this->page==='contact') {
   if(!empty($_POST['company'])){$this->render('message',['message'=>'contact.sent']);return;}
   $d=[];foreach(['name','email','subject','message'] as $k)$d[$k]=trim((string)($_POST[$k]??''));
   if(mb_strlen($d['name'])<2||mb_strlen($d['name'])>150||!filter_var($d['email'],FILTER_VALIDATE_EMAIL)||strlen($d['email'])>254||mb_strlen($d['subject'])<2||mb_strlen($d['subject'])>200||mb_strlen($d['message'])<10||mb_strlen($d['message'])>5000){$this->fail('error.validation');return;}
   if(!Security::captcha((string)($_POST['cf-turnstile-response']??''))){$this->fail('error.captcha');return;}
   $id=bin2hex(random_bytes(16));
   $this->db->transaction(function() use($id,$d) {
    $this->db->run('INSERT INTO contact_messages(id,name,email,subject,message,locale,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$d['name'],$d['email'],$d['subject'],$d['message'],$this->locale,time()]);
    $this->mail->queue(env('SUPPORT_EMAIL','support@zion3d.ro'),$this->locale,'support',$d,'support:'.$id);
    $this->mail->queue($d['email'],$this->locale,'contact',[],'contact:'.$id);
   });
   $this->render('message',['message'=>'contact.sent']);return;
  }
  $u=$this->user();if(!$u){$this->fail('error.csrf',401);return;}
  if($this->page==='upgrade') {
   if(!($this->billing instanceof StripeService))throw new \RuntimeException('billing_unavailable');
   try {
    if(($_POST['action']??'')==='cancel') {
     $id=(string)($_POST['change_id']??'');$r=$this->billing->changes()->record($id);
     if($r['user_id']!==$u['id']){$this->fail('error.validation',403);return;}
     $this->billing->changes()->cancel($id);$this->redirect(Router::path($this->locale,'upgrade'));
    }
    $this->redirect($this->billing->changes()->checkout($u,(string)($_POST['plan']??'')));
   }catch(\DomainException $e){$key=$e->getMessage();$this->fail(in_array($key,['upgrade.unavailable','upgrade.pending','upgrade.closing','upgrade.unconfigured','billing.required'],true)?$key:'error.generic');}
   return;
  }
  if($this->page==='billing-profile') {
   try { (new BillingProfileService($this->db))->save($u['id'],is_array($_POST['billing']??null)?$_POST['billing']:[]); }
   catch(\InvalidArgumentException $e) { $this->fail('billing.invalid');return; }
   $this->render('billing-profile',['notice'=>'billing.saved']);return;
  }
  if($this->page==='checkout'){
   if(!(new BillingProfileService($this->db))->get($u['id']))$this->redirect(Router::path($this->locale,'billing-profile'));
   try{$this->redirect($this->billing->checkout($u));}
   catch(\DomainException $e){$this->fail($e->getMessage()==='checkout.pending_quote'?'checkout.pending_quote':'error.generic');}
  }
  if($this->page==='portal'){$this->redirect($this->billing->portal($u));}
  if($this->page==='activate') {
   if(!$u['terms_at'] || !$u['privacy_at']) {
    if(empty($_POST['accept']) || (production() && env('LEGAL_READY')!=='true')){$this->fail('error.validation');return;}
    $this->db->run('UPDATE users SET terms_at=?,privacy_at=? WHERE id=?',[time(),time(),$u['id']]);
   }
   if(($_POST['automatic']??'')==='1') {
    $this->accounts->automatic($u['id']);$this->redirect(Router::path($this->locale,'status'));
   }
   if(!Security::limit($this->db,'activate:'.$u['id'],8,900)){$this->fail('error.rate',429);return;}
   if(!$this->accounts->provision($u['id'],(string)($_POST['password']??''))){$this->fail('error.validation');return;}
   $this->redirect(Router::path($this->locale,'status'));
  }
  http_response_code(405);
 }
 private function render(string $view,array $data=[]): void {
  $locale=$this->locale; $page=$this->page; $t=fn(string $k,array $v=[])=>$this->tr->get($k,$v); $url=fn(string $p,string $l='')=>Router::path($l?:$locale,$p);
  $billingInterval=(string)($_POST['billing_interval']??$_GET['billing_interval']??'month');if(!in_array($billingInterval,['month','year'],true))$billingInterval='month';
  $plans=$this->plans->all($billingInterval);$user=$this->user();$eligible=$user?$this->accounts->eligible($user):false;
  $automaticActivation=$user && !$user['storage_user_id'] && $this->accounts->automaticPending($user['id']);
  $activationDue=$automaticActivation && !(bool)$this->db->one('SELECT user_id FROM activation_credentials WHERE user_id=? AND next_attempt_at>?',[$user['id'],time()]);
  $stripeMode=(new IntegrationSettings($this->db))->stripe()['mode'];
  $banned=$user?(new AccountControl($this->db))->banned($user['id']):false;
  $upgradeOffers=$user && $this->billing instanceof StripeService?$this->billing->changes()->offers($user):[];
  $latestChange=$user?$this->db->one('SELECT * FROM plan_changes WHERE user_id=? ORDER BY created_at DESC,rowid DESC LIMIT 1',[$user['id']]):null;
  $profile=$user?(new BillingProfileService($this->db))->get($user['id']):null;
  $billing=is_array($_POST['billing']??null)?$_POST['billing']:($profile??[]);
  $fiscalInvoices=$user?$this->db->run('SELECT oblio_series,oblio_number,invoice_url,amount,currency,status,billing_period_start,billing_period_end FROM fiscal_invoices WHERE user_id=? ORDER BY created_at DESC LIMIT 50',[$user['id']])->fetchAll():[];
  $error=$data['error']??null;$message=$data['message']??null;
  $selected=(string)($_GET['plan']??$_POST['plan']??'free'); if(!$this->plans->get($selected))$selected='free';
  $canonicalPage=in_array($page,['home','how','register','contact'],true)?$page:'home';
  $title=$t(match($page){'home'=>'hero.title','how'=>'how.title','register'=>'register.title','contact'=>'contact.title','legal'=>'legal.title',default=>'status.title'});
  $description=$t(match($page){'home'=>'hero.description','how'=>'how.description','register'=>'register.desc','contact'=>'contact.desc',default=>'footer.tag'});
  ob_start(); $file=dirname(__DIR__).'/templates/'.$view.'.php'; if(!is_file($file))$file=dirname(__DIR__).'/templates/status.php'; require $file; $content=ob_get_clean();
  require dirname(__DIR__).'/templates/layout.php';
 }
 private function sitemap(): void {
  header('Content-Type: application/xml; charset=utf-8');echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
  foreach(TranslationService::LOCALES as $l)foreach(['home','how','register','contact'] as $p){
   echo '<url><loc>'.e(base_url().Router::path($l,$p)).'</loc>';
   foreach(TranslationService::LOCALES as $alt)echo '<xhtml:link rel="alternate" hreflang="'.$alt.'" href="'.e(base_url().Router::path($alt,$p)).'"/>';
   echo '<xhtml:link rel="alternate" hreflang="x-default" href="'.e(base_url().Router::path('en',$p)).'"/></url>';
  }echo '</urlset>';
 }
}
