<?php
namespace Zion;
final class AdminApplication {
 private Database $db;private Plans $plans;private SubscriptionChanges $changes;private AdminService $service;private BillingCatalog $catalog;
 private string $section='dashboard';
 private string $stripeMode='test';
 public function __construct(){
  $this->db=new Database();$this->plans=new Plans($this->db);
 }
 private function initializeBilling(): void {
  $mail=new MailerService($this->db);$storage=new StorageProvisioningService();
  $billing=new StripeService($this->db,$this->plans,$mail,$storage);$this->changes=$billing->changes();$this->catalog=$billing->catalog();
  $this->service=new AdminService($this->db,$this->plans,$mail,$storage,$this->changes);
 }
 private function redirect(): never {header('Location: /admin?section='.$this->section.(in_array($this->section,['integrations','catalog'],true)?'&stripe_mode='.$this->stripeMode:''),true,303);exit;}
 public function handle(): void {
  header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
  if(production())header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
  if(!in_array($path,['/admin','/admin/'],true)){http_response_code(404);return;}
  $sections=['dashboard'=>'Dashboard','clients'=>'Clienți','new-client'=>'Client nou','subscriptions'=>'Abonamente','changes'=>'Upgrade-uri','invoices'=>'Facturi fiscale','operations'=>'Operațiuni','integrations'=>'API-uri Stripe și Oblio','catalog'=>'Catalog Stripe','audit'=>'Acțiuni administrare','api-log'=>'Comunicare API'];
  $action=(string)($_POST['action']??'');
  $default=match(true){$action==='settings_save'||str_starts_with($action,'stripe_')=>'integrations',str_starts_with($action,'catalog_')=>'catalog',$action==='create'=>'new-client',in_array($action,['plan','ban','unban','sync'],true)=>'clients',in_array($action,['retry','cancel'],true)=>'changes',default=>'dashboard'};
  $this->section=(string)($_GET['section']??$_POST['section']??$default);
  if(!isset($sections[$this->section])){http_response_code(404);return;}
  $section=$this->section;
  $attentionOnly=($_GET['attention']??'')==='1';
  $stripeActiveMode=(new IntegrationSettings($this->db))->stripe()['mode'];
  $this->stripeMode=(string)($_POST['credential_mode']??$_GET['stripe_mode']??$stripeActiveMode);
  if(!in_array($this->stripeMode,['test','live'],true)){http_response_code(404);return;}
  $stripeMode=$this->stripeMode;
  $apiFilters=[];$apiEntries=null;$activationLink=null;
  $admin=null;$error=null;$notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);
  if(($_SESSION['admin_until']??0)>time() && isset($_SESSION['admin_id']))$admin=$this->db->one('SELECT id,email FROM administrators WHERE id=?',[$_SESSION['admin_id']]);
  $method=$_SERVER['REQUEST_METHOD'];
  if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);return;}
  if($method==='POST'){
   if(!Security::checkCsrf()){http_response_code(419);$error='Sesiunea formularului a expirat. Reîncarcă pagina.';}
   elseif(!Security::limit($this->db,'admin-post:'.Security::clientIp(),60,900)){http_response_code(429);$error='Prea multe cereri. Încearcă mai târziu.';}
   else try {
    $action=(string)($_POST['action']??'');
    if(!$admin){
     $email=strtolower(trim((string)($_POST['email']??'')));
     $allowed=Security::limit($this->db,'admin-login-ip:'.Security::clientIp(),10,900);
     $allowed=Security::limit($this->db,'admin-login-email:'.$email,5,900)&&$allowed;
     $row=$this->db->one('SELECT * FROM administrators WHERE email=?',[$email]);
     $hash=$row['password_hash']??'$argon2id$v=19$m=65536,t=4,p=1$Zml4dHVyZXNhbHRmb3JkdW1teQ$F6fA5n/0FkCjGBPL4vuBfaK/YV6Qk8lJ3FfRU12u5wM';
     $valid=password_verify((string)($_POST['password']??''),$hash);
     if($action!=='login' || !$allowed || !$row || !$valid)throw new \DomainException('Autentificare nereușită. Verifică datele sau încearcă mai târziu.');
     session_regenerate_id(true);$_SESSION=['admin_id'=>$row['id'],'admin_until'=>time()+3600,'csrf'=>bin2hex(random_bytes(32))];$this->redirect();
    }
    if($action==='logout'){$_SESSION=[];session_regenerate_id(true);$this->section='dashboard';$this->redirect();}
    if($action==='settings_save'){
     (new IntegrationSettings($this->db))->save((string)($_POST['provider']??''),$_POST,$admin['id']);
     $message='Configurația a fost salvată. Valorile din .env pentru acest serviciu sunt ignorate.';
     if(($_POST['provider']??'')==='stripe' && (new IntegrationSettings($this->db))->stripe($stripeMode)['secret_key']){
      $result=(new StripeIntegration($this->db,$this->plans,null,$stripeMode))->synchronize($admin['id']);$message.=' '.$result['message'];
     }
     $_SESSION['admin_notice']=$message;$this->redirect();
    }
    if(in_array($action,['stripe_check','stripe_sync','stripe_activate'],true)){
     if(!Security::limit($this->db,'stripe-actions:'.$admin['id'],12,900))throw new \DomainException('Prea multe verificări Stripe. Reîncearcă mai târziu.');
     $integration=new StripeIntegration($this->db,$this->plans,null,$stripeMode);
     $result=$action==='stripe_check'?$integration->check():$integration->synchronize($admin['id']);
     if($action==='stripe_activate'){
      if($result['status']!=='ok')throw new \DomainException('Modul nu a fost activat: conexiunea și toate cele șase prețuri trebuie verificate.');
      (new IntegrationSettings($this->db))->activateStripe($stripeMode,$admin['id']);
      $result['message']='Modul Stripe '.strtoupper($stripeMode).' este acum activ pentru înregistrările noi.';
     }
     $_SESSION['admin_notice']=$result['message'];$this->redirect();
    }
    $this->initializeBilling();
    if($action==='activation_link'){
     $link=$this->service->activationLink((string)($_POST['user_id']??''),$admin['id']);
     $_SESSION['admin_activation_link']=EncryptedPayload::encode($link);$this->section='clients';$this->redirect();
    }
    if(str_starts_with($action,'catalog_'))$this->catalog=new BillingCatalog($this->db,$this->plans,new \Stripe\StripeClient((new IntegrationSettings($this->db))->stripe($stripeMode)['secret_key']?:'sk_'.$stripeMode.'_unconfigured'),$stripeMode);
    $uid=(string)($_POST['user_id']??'');$reason=trim((string)($_POST['reason']??''));
    if(!in_array($action,['create','retry','cancel','sync','catalog_save','catalog_create','catalog_disable','catalog_legacy'],true) && (mb_strlen($reason)<3 || mb_strlen($reason)>500))throw new \DomainException('Introdu un motiv de 3–500 caractere pentru jurnal.');
    if(str_starts_with($action,'catalog_')){
     $slug=(string)($_POST['plan']??'');$interval=(string)($_POST['billing_interval']??'month');$id=trim((string)($_POST['price_id']??''));
     if(!in_array($interval,['month','year'],true))throw new \DomainException('Interval invalid.');
     if($action==='catalog_save')$this->catalog->save($slug,$interval,$id,$admin['id']);
     elseif($action==='catalog_create')$this->catalog->create($slug,$interval,$admin['id']);
     elseif($action==='catalog_disable')$this->catalog->disable($slug,$interval,$admin['id']);
     elseif($action==='catalog_legacy')$this->catalog->legacy($slug,$id,$admin['id']);
     else throw new \DomainException('Acțiune necunoscută.');
     $notice='Configurația Stripe a fost actualizată. Abonamentele existente nu au fost repricate.';
    }
    elseif($action==='create'){$this->service->create($_POST,$admin['id']);$notice='Client creat cu plan manual. Confirmarea emailului și acceptarea termenilor sunt necesare înainte de activare. Comunică parola inițială printr-un canal sigur.';}
    elseif($action==='plan'){$this->changes->adminPlan($uid,(string)($_POST['plan']??''),$admin['id'],$reason);$notice='Plan actualizat. Data de reînnoire este păstrată; nicio încasare sau rambursare imediată.';}
    elseif($action==='sync'){$this->service->syncQuota($uid,$admin['id']);$notice='Cota a fost sincronizată.';}
    elseif(in_array($action,['ban','unban'],true)){$this->service->ban($uid,$action==='ban',$reason,$admin['id']);$notice='Starea contului a fost actualizată. Verifică starea protecției Immich în tabel.';}
    elseif(in_array($action,['retry','cancel'],true)){
     $id=(string)($_POST['change_id']??'');$r=$this->changes->record($id);
     if($action==='retry')$this->changes->retry($id);else $this->changes->cancel($id);
     $this->changes->audit($admin['id'],$r['user_id'],'change_'.$action,$id);$notice='Operațiune procesată.';
    }else throw new \DomainException('Acțiune necunoscută.');
    $_SESSION['admin_notice']=$notice;$this->redirect();
   }catch(\DomainException $e){http_response_code(422);$error=$e->getMessage();}
   catch(\Throwable $e){error_log('Admin action requires retry');http_response_code(503);$error='Operațiunea nu s-a finalizat. Verifică istoricul și reîncearcă; o plată confirmată nu trebuie plătită din nou.';}
  }
  $catalogMode=$section==='catalog'?$stripeMode:$stripeActiveMode;
  $plans=$this->plans->all('month',$catalogMode);$yearPlans=$this->plans->all('year',$catalogMode);$catalogRows=$admin?$this->db->run('SELECT * FROM billing_prices WHERE stripe_mode=? ORDER BY plan,interval,created_at DESC',[$catalogMode])->fetchAll():[];$clients=[];$changes=[];$audit=[];$search=trim((string)($_GET['q']??''));
  if($admin){
   $linkFlash=$_SESSION['admin_activation_link']??null;unset($_SESSION['admin_activation_link']);
   if($section==='clients' && is_string($linkFlash)){
    $candidate=EncryptedPayload::decode($linkFlash);
    if($this->db->one('SELECT v.token_hash FROM email_verifications v JOIN users u ON u.id=v.user_id LEFT JOIN account_controls c ON c.user_id=u.id WHERE v.token_hash=? AND v.user_id=? AND v.used_at IS NULL AND v.expires_at>? AND u.email_verified_at IS NULL AND c.banned_at IS NULL',[$candidate['token_hash'],$candidate['user_id'],time()]))$activationLink=$candidate;
   }
   if($section==='api-log'){
    foreach(['provider','status','mode','direction'] as $key)$apiFilters[$key]=is_string($_GET[$key]??null)?$_GET[$key]:'';
    $apiEntries=ApiLog::page($apiFilters,max(1,(int)($_GET['page']??1)));
   }
   $integrations=new IntegrationSettings($this->db);$stripeSettings=$integrations->stripe($section==='integrations'?$stripeMode:$stripeActiveMode);$oblioSettings=$integrations->oblio();$stripeProfiles=$integrations->stripeProfiles();
   $stripeSaved=$integrations->saved('stripe')!==null;$oblioSaved=$integrations->saved('oblio')!==null;
   $stripeState=(new StripeIntegration($this->db,$this->plans,null,$stripeMode))->state();
  }
  if($admin && $section==='clients'){
   $clients=$this->db->run("SELECT u.*,c.banned_at,c.reason,c.manual_plan,c.access_sync,c.quota_sync,s.status AS subscription_status,s.renewal_at FROM users u LEFT JOIN account_controls c ON c.user_id=u.id LEFT JOIN subscriptions s ON s.user_id=u.id WHERE (?='' OR instr(lower(u.email),lower(?))>0 OR instr(lower(u.name),lower(?))>0) AND (CAST(? AS INTEGER)=0 OR c.quota_sync<>'ready' OR c.access_sync<>'ready') ORDER BY u.created_at DESC LIMIT 100",[$search,$search,$search,(int)$attentionOnly])->fetchAll();
  }
  if($admin && $section==='changes'){
   $changes=$this->db->run("SELECT p.*,u.email FROM plan_changes p JOIN users u ON u.id=p.user_id WHERE (CAST(? AS INTEGER)=0 OR p.status IN ('creating','paid','applying','retry','review')) ORDER BY p.created_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll();
  }
  if($admin && $section==='audit'){
   $audit=$this->db->run('SELECT a.*,ad.email AS admin_email,u.email AS customer_email FROM admin_audit a JOIN administrators ad ON ad.id=a.admin_id LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
  }
  if($admin){
   $dashboard=in_array($section,['dashboard','operations'],true)?(new AdminDashboard($this->db))->snapshot():[];
   $subscriptionRows=$section==='subscriptions'?$this->db->run("SELECT s.*,u.email FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE (CAST(? AS INTEGER)=0 OR s.status IN ('past_due','unpaid','incomplete')) ORDER BY s.renewal_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll():[];
   $fiscalRows=$section==='invoices'?$this->db->run("SELECT f.id,f.stripe_invoice_id,f.amount,f.currency,f.stripe_mode,f.status,f.error_message,f.oblio_series,f.oblio_number,f.created_at,u.email FROM fiscal_invoices f JOIN users u ON u.id=f.user_id WHERE (CAST(? AS INTEGER)=0 OR f.status IN ('pending','processing','failed')) ORDER BY f.created_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll():[];
   $mailRows=$section==='operations'?$this->db->run('SELECT recipient,kind,attempts,created_at FROM outbox WHERE sent_at IS NULL ORDER BY created_at LIMIT 30')->fetchAll():[];
   $eventRows=$section==='operations'?$this->db->run("SELECT id,type,status,created_at FROM billing_events WHERE status<>'complete' ORDER BY created_at LIMIT 30")->fetchAll():[];
  }
  require dirname(__DIR__).'/templates/admin.php';
 }
}
