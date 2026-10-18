<?php
namespace Zion;
final class AdminApplication {
 private Database $db;private Plans $plans;private SubscriptionChanges $changes;private AdminService $service;private BillingCatalog $catalog;
 public function __construct(){
  $this->db=new Database();$this->plans=new Plans($this->db);
 }
 private function initializeBilling(): void {
  $mail=new MailerService($this->db);$storage=new StorageProvisioningService();
  $billing=new StripeService($this->db,$this->plans,$mail,$storage);$this->changes=$billing->changes();$this->catalog=$billing->catalog();
  $this->service=new AdminService($this->db,$this->plans,$mail,$storage,$this->changes);
 }
 private function redirect(): never {header('Location: /admin',true,303);exit;}
 public function handle(): void {
  header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'none'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
  if(production())header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
  if(!in_array($path,['/admin','/admin/'],true)){http_response_code(404);return;}
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
    if($action==='logout'){$_SESSION=[];session_regenerate_id(true);$this->redirect();}
    if($action==='settings_save'){
     (new IntegrationSettings($this->db))->save((string)($_POST['provider']??''),$_POST,$admin['id']);
     $_SESSION['admin_notice']='Configurația a fost salvată. Valorile din .env pentru acest serviciu sunt ignorate. Salvarea nu trimite cereri către furnizor.';$this->redirect();
    }
    $this->initializeBilling();
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
  $plans=$this->plans->all();$yearPlans=$this->plans->all('year');$catalogRows=$admin?$this->db->run('SELECT * FROM billing_prices ORDER BY plan,interval,created_at DESC')->fetchAll():[];$clients=[];$changes=[];$audit=[];$search=trim((string)($_GET['q']??''));
  if($admin){
   $integrations=new IntegrationSettings($this->db);$stripeSettings=$integrations->stripe();$oblioSettings=$integrations->oblio();
   $stripeSaved=$integrations->saved('stripe')!==null;$oblioSaved=$integrations->saved('oblio')!==null;
  }
  if($admin){
   $clients=$this->db->run("SELECT u.*,c.banned_at,c.reason,c.manual_plan,c.access_sync,c.quota_sync,s.status AS subscription_status,s.renewal_at FROM users u LEFT JOIN account_controls c ON c.user_id=u.id LEFT JOIN subscriptions s ON s.user_id=u.id WHERE (?='' OR instr(lower(u.email),lower(?))>0 OR instr(lower(u.name),lower(?))>0) ORDER BY u.created_at DESC LIMIT 100",[$search,$search,$search])->fetchAll();
   $changes=$this->db->run('SELECT p.*,u.email FROM plan_changes p JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC LIMIT 100')->fetchAll();
   $audit=$this->db->run('SELECT a.*,ad.email AS admin_email,u.email AS customer_email FROM admin_audit a JOIN administrators ad ON ad.id=a.admin_id LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
  }
  require dirname(__DIR__).'/templates/admin.php';
 }
}
