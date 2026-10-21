<?php
namespace Zion;
final class AdminApplication {
 private Database $db;private Plans $plans;private SubscriptionChanges $changes;private AdminService $service;private BillingCatalog $catalog;
 private string $section='dashboard';
 private string $stripeMode='test';
 private string $customerId='';
 public function __construct(){
  $this->db=new Database();$this->plans=new Plans($this->db);
 }
 private function initializeBilling(): void {
  $mail=new MailerService($this->db);$storage=new StorageProvisioningService();
  $billing=new StripeService($this->db,$this->plans,$mail,$storage);$this->changes=$billing->changes();$this->catalog=$billing->catalog();
  $this->service=new AdminService($this->db,$this->plans,$mail,$storage,$this->changes);
 }
 private function redirect(): never {header('Location: /admin?section='.$this->section.($this->section==='client'?'&user_id='.rawurlencode($this->customerId):'').(in_array($this->section,['integrations','catalog'],true)?'&stripe_mode='.$this->stripeMode:''),true,303);exit;}
 public function handle(): void {
  header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
  if(production())header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
  if(!in_array($path,['/admin','/admin/'],true)){http_response_code(404);return;}
  $sections=['dashboard'=>'Dashboard','clients'=>'Clienți','client'=>'Detalii client','new-client'=>'Client nou','subscriptions'=>'Abonamente','changes'=>'Upgrade-uri','invoices'=>'Facturi fiscale','operations'=>'Operațiuni','integrations'=>'API-uri Stripe și Oblio','catalog'=>'Catalog Stripe','tax'=>'TVA produse','immich'=>'API Immich','immich-general'=>'Immich · Configurare generală','listmonk'=>'Email / Listmonk','legal-requests'=>'Retrageri consumatori','audit'=>'Acțiuni administrare','api-log'=>'Comunicare API'];
  $action=(string)($_POST['action']??'');
  $default=match(true){$action==='withdrawal_resolve'=>'legal-requests',in_array($action,['fiscal_retry','fiscal_generate'],true)=>'invoices',$action==='mail_retry'=>'listmonk',str_starts_with($action,'listmonk_')=>'listmonk',str_starts_with($action,'immich_general_')=>'immich-general',$action==='immich_save'||str_starts_with($action,'immich_')=>'immich',$action==='tax_save'=>'tax',$action==='stripe_event_replay'=>'api-log',$action==='settings_save'||str_starts_with($action,'stripe_')=>'integrations',str_starts_with($action,'catalog_')=>'catalog',$action==='create'=>'new-client',in_array($action,['plan','ban','unban','sync','test_account_reset','archive','restore'],true)=>'clients',in_array($action,['retry','cancel'],true)=>'changes',default=>'dashboard'};
  $this->section=(string)($_GET['section']??$_POST['section']??$default);
  if(!isset($sections[$this->section])){http_response_code(404);return;}
  $section=$this->section;
  $this->customerId=(string)($_POST['user_id']??$_GET['user_id']??'');
  $attentionOnly=($_GET['attention']??'')==='1';$archivedOnly=($_GET['archived']??'')==='1';
  $stripeActiveMode=(new IntegrationSettings($this->db))->stripe()['mode'];
  $this->stripeMode=(string)($_POST['credential_mode']??$_GET['stripe_mode']??$stripeActiveMode);
  if(!in_array($this->stripeMode,['test','live'],true)){http_response_code(404);return;}
  $stripeMode=$this->stripeMode;
  $apiFilters=[];$apiEntries=null;$activationLink=null;
  $admin=null;$error=null;$notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);
  if(($_SESSION['admin_until']??0)>time() && isset($_SESSION['admin_id']))$admin=$this->db->one('SELECT id,email FROM administrators WHERE id=?',[$_SESSION['admin_id']]);
  $method=$_SERVER['REQUEST_METHOD'];
  if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);return;}
  if($admin && in_array($method,['GET','HEAD'],true) && isset($_GET['download_invoice'])){
   try{
    $docs=new FiscalDocuments($this->db);$id=(string)$_GET['download_invoice'];
    if($id==='latest')$id=($docs->latest($this->customerId)??[])['id']??'';
    $pdf=$docs->pdf($id,$this->customerId);
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$pdf['filename'].'"');header('Content-Length: '.strlen($pdf['bytes']));
    if($method==='GET')echo $pdf['bytes'];return;
   }catch(\Throwable $e){http_response_code(502);$error='Factura nu poate fi descărcată. Verifică jurnalul API Oblio și reîncearcă.';}
  }
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
    if(in_array($action,['customer_contact','customer_contact_retry','customer_billing','customer_guide','customer_invoice_email'],true)){
     $uid=$this->customerId;$mail=new MailerService($this->db);$manager=new AdminCustomer($this->db,$mail);$manager->customer($uid);
     $this->section='client';
     if(!Security::limit($this->db,'customer-action:'.$uid.':'.$action,6,900))throw new \DomainException('Prea multe cereri pentru acest client. Reîncearcă mai târziu.');
     if($action==='customer_contact' || $action==='customer_contact_retry'){
      $manager->saveContact($uid,$_POST,$admin['id'],$action==='customer_contact_retry');
      $message='Datele de contact au fost actualizate și sincronizate. Schimbarea emailului invalidează sesiunile și linkurile anterioare; generează un link nou dacă este necesar.';
     }elseif($action==='customer_billing'){
      $manager->saveBilling($uid,is_array($_POST['billing']??null)?$_POST['billing']:[],$admin['id']);$message='Datele de facturare au fost actualizate. Facturile deja emise sunt păstrate.';
     }elseif($action==='customer_invoice_email'){
      $message=MailerService::dispatchNotice($manager->invoiceEmail($uid,(string)($_POST['invoice_id']??''),$admin['id']));
     }else{
      $link=(new OnboardingLinks($this->db,$mail))->issue($uid,true);$manager->audit($uid,$admin['id'],'onboarding_link_regenerated');
      $message='Am regenerat linkul, valabil încă 3 zile. '.MailerService::dispatchNotice($mail->dispatch('onboarding:'.$link['token_hash']));
     }
     $_SESSION['admin_notice']=$message;$this->redirect();
    }
    if($action==='logout'){$_SESSION=[];session_regenerate_id(true);$this->section='dashboard';$this->redirect();}
    if($action==='withdrawal_resolve'){
     $id=(string)($_POST['request_id']??'');$note=trim((string)($_POST['notes']??''));
     if(!preg_match('/^[a-f0-9]{32}$/D',$id) || mb_strlen($note)<3 || mb_strlen($note)>1000)throw new \DomainException('Introdu un motiv de soluționare de 3–1000 caractere.');
     $this->db->run("UPDATE withdrawal_requests SET status='resolved',resolved_at=?,notes=? WHERE id=? AND status='new'",[time(),$note,$id]);
     $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin['id'],'withdrawal_resolve',$id,time()]);
     $_SESSION['admin_notice']='Cererea a fost marcată soluționată. Această acțiune nu execută plăți sau operațiuni Stripe.';$this->redirect();
    }
    if($action==='fiscal_generate'){
     if(!Security::limit($this->db,'fiscal-generate:'.$admin['id'],10,900))throw new \DomainException('Prea multe generări. Reîncearcă mai târziu.');
     $invoiceId=(string)($_POST['invoice_id']??'');if(!preg_match('/^in_[a-zA-Z0-9_]+$/D',$invoiceId))throw new \DomainException('Referință Stripe invalidă.');
     $payment=$this->db->one('SELECT stripe_mode FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoiceId])??$this->db->one('SELECT stripe_mode FROM invoices WHERE id=?',[$invoiceId])??$this->db->one('SELECT stripe_mode FROM plan_changes WHERE invoice_id=? AND paid_at IS NOT NULL',[$invoiceId]);
     if(!$payment)throw new \DomainException('Plata nu există.');
     $mail=new MailerService($this->db);$fiscal=new OblioInvoiceService($this->db,$mail);
     $existing=$this->db->one('SELECT id FROM fiscal_invoices WHERE stripe_invoice_id=?',[$invoiceId]);
     $id=$existing['id']??(new StripeService($this->db,$this->plans,$mail,new StorageProvisioningService(),$fiscal,$payment['stripe_mode']))->prepareFiscalInvoice($invoiceId);
     $ok=$fiscal->generateManually($id,$admin['id']);$row=$this->db->one('SELECT status,error_message FROM fiscal_invoices WHERE id=?',[$id]);
     $_SESSION['admin_notice']=$ok?'Factura a fost generată în Oblio.':'Factura nu a fost emisă. '.OblioInvoiceService::errorDescription($row['error_message']??'');$this->redirect();
    }
    if($action==='fiscal_retry'){
     if(!Security::limit($this->db,'fiscal-retry:'.$admin['id'],5,900))throw new \DomainException('Prea multe reîncercări de facturare. Reîncearcă mai târziu.');
     $id=(string)($_POST['fiscal_id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \DomainException('Factură invalidă.');
     $fiscal=new OblioInvoiceService($this->db,new MailerService($this->db));
     if(!$this->db->one("SELECT id FROM fiscal_invoices WHERE id=? AND status IN ('pending','failed')",[$id]))throw new \DomainException('Se pot relua doar facturile în așteptare sau cu eroare.');
     $ok=$fiscal->process($id);$row=$this->db->one('SELECT status,error_message FROM fiscal_invoices WHERE id=?',[$id]);
     $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin['id'],'fiscal_retry',$id.'; '.$row['status'],time()]);
     $_SESSION['admin_notice']=$ok?'Procesare finalizată: '.$row['status'].'.':'Factura a rămas în așteptare. '.OblioInvoiceService::errorDescription($row['error_message']??'');$this->redirect();
    }
    if($action==='mail_retry'){
     if(!Security::limit($this->db,'mail-retry:'.$admin['id'],3,900))throw new \DomainException('Prea multe reluări ale cozii. Reîncearcă mai târziu.');
     $mail=new MailerService($this->db);$accepted=$mail->deliver(5,null,true);$pending=(int)$mail->diagnostics()['pending']['total'];
     $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin['id'],'mail_retry','accepted='.$accepted.'; pending='.$pending,time()]);
     $_SESSION['admin_notice']='Mesaje acceptate la trimitere: '.$accepted.'. În așteptare: '.$pending.'. Linkurile expirate sunt eliminate; clientul poate folosi „Retrimite” pentru un link nou. Verifică jurnalul API pentru erori.';$this->redirect();
    }
    if(in_array($action,['listmonk_save','listmonk_check','listmonk_test'],true)){
     $settings=new IntegrationSettings($this->db);
     if($action==='listmonk_save'){
      $settings->save('listmonk',$_POST,$admin['id']);
      $_SESSION['admin_notice']='Configurația Listmonk a fost salvată. Variabilele LISTMONK_* din .env sunt ignorate.';
     }else{
      if(!Security::limit($this->db,'listmonk-actions:'.$admin['id'],8,900))throw new \DomainException('Prea multe teste Listmonk. Reîncearcă mai târziu.');
      $client=new ListmonkClient($settings->listmonk());
      if($action==='listmonk_check'){$client->check();$message='Conexiunea și șablonul tranzacțional sunt confirmate. Livrarea emailului se verifică separat printr-un mesaj de test.';}
      else{
       $to=trim((string)($_POST['test_email']??''));
       $client->send($to,'Zion Storage — test Listmonk','<html lang="ro"><body><h1>Zion Storage</h1><p>Conexiunea Listmonk funcționează. Acesta este un email de test.</p></body></html>','Conexiunea Listmonk funcționează. Acesta este un email de test.','ro','test');
       $message='Listmonk a acceptat emailul de test. Verifică inboxul, spamul și jurnalul SMTP din Listmonk; acceptarea API nu garantează livrarea în inbox.';
      }
      $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin['id'],$action,'accepted',time()]);$_SESSION['admin_notice']=$message;
     }
     $this->redirect();
    }
    if(in_array($action,['immich_general_defaults','immich_general_server'],true)){
     $this->section='immich-general';
     if(!Security::limit($this->db,'immich-configuration:'.$admin['id'],12,900))throw new \DomainException('Prea multe salvări Immich. Reîncearcă mai târziu.');
     $configuration=new ImmichConfiguration($this->db);
     try{
      if($action==='immich_general_defaults'){
       $configuration->saveAccountDefaults(is_array($_POST['defaults']??null)?$_POST['defaults']:[],$admin['id']);
       $_SESSION['admin_notice']='Preferințele au fost salvate pentru conturile noi. Conturile deja activate își păstrează alegerile.';
      }else{
       $configuration->saveServer(is_array($_POST['features']??null)?$_POST['features']:[],is_string($_POST['revision']??null)?$_POST['revision']:'',$admin['id']);
       $_SESSION['admin_notice']='Configurația serverului Immich a fost verificată. Clienții deschiși pot necesita reîncărcarea aplicației.';
      }
     }catch(\DomainException $e){throw $e;}
     catch(\Throwable $e){throw new \DomainException(ImmichConfiguration::error($e));}
     $this->redirect();
    }
    if(in_array($action,['immich_save','immich_check','immich_sync'],true)){
     if($action==='immich_save'){
      (new IntegrationSettings($this->db))->save('immich',$_POST,$admin['id']);
      $result=['message'=>'Configurația Immich a fost salvată. Valorile STORAGE_API_URL și STORAGE_API_KEY din .env sunt ignorate.'];
     }else{
      if(!Security::limit($this->db,'immich-actions:'.$admin['id'],12,900))throw new \DomainException('Prea multe verificări Immich. Reîncearcă mai târziu.');
      $statistics=new ImmichStatistics($this->db);$result=$action==='immich_check'?$statistics->check():$statistics->synchronize();
      $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin['id'],$action,$result['status'],time()]);
     }
     $_SESSION['admin_notice']=$result['message'];$this->redirect();
    }
    if($action==='tax_save'){
     (new BillingTax($this->db))->save((string)($_POST['vat_rate']??''),$admin['id']);
     $_SESSION['admin_notice']='Cota TVA a fost salvată. Se adaugă peste prețul de bază pentru achizițiile și upgrade-urile noi; abonamentele și sesiunile de plată existente își păstrează cota.';$this->redirect();
    }
    if($action==='settings_save'){
     (new IntegrationSettings($this->db))->save((string)($_POST['provider']??''),$_POST,$admin['id']);
     $message='Configurația a fost salvată. Valorile din .env pentru acest serviciu sunt ignorate.';
     if(($_POST['provider']??'')==='stripe' && (new IntegrationSettings($this->db))->stripe($stripeMode)['secret_key']){
      $result=(new StripeIntegration($this->db,$this->plans,null,$stripeMode))->synchronize($admin['id']);$message.=' '.$result['message'];
     }
     $_SESSION['admin_notice']=$message;$this->redirect();
    }
    if($action==='stripe_event_replay'){
     if(!Security::limit($this->db,'stripe-replay:'.$admin['id'],10,900))throw new \DomainException('Prea multe reprocesări. Reîncearcă mai târziu.');
     $service=new StripeService($this->db,$this->plans,new MailerService($this->db),new StorageProvisioningService(),null,$stripeMode);
     $result=$service->replayEvent((string)($_POST['event_id']??''),$admin['id']);
     $_SESSION['admin_notice']=WebhookDiagnostics::result($result).' Reprocesarea nu confirmă configurarea destinației webhook.';$this->section='api-log';$this->redirect();
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
    if(in_array($action,['plan','unban','sync'],true) && $this->db->one('SELECT id FROM users WHERE id=? AND is_deleted=1',[(string)($_POST['user_id']??'')]))throw new \DomainException('Restaurează clientul arhivat înainte de modificarea accesului sau planului.');
    $this->initializeBilling();
    if($action==='test_account_reset'){
     (new TestAccountReset($this->db,$this->changes,new StorageProvisioningService()))->reset((string)($_POST['user_id']??''),(string)($_POST['confirm_email']??''),($_POST['confirm_reset']??'')==='1',($_POST['delete_storage']??'')==='1',$admin['id']);
     unset($_SESSION['admin_activation_link']);$_SESSION['admin_notice']='Contul TEST și tranzacțiile locale au fost șterse. Te poți înregistra din nou cu același email. Facturile TEST asociate au fost eliminate din Oblio când au existat. Istoricul plăților de test rămâne în Stripe.';$this->section='clients';$this->redirect();
    }
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
    elseif(in_array($action,['archive','restore'],true)){$this->service->archive($uid,$action==='archive',$reason,$admin['id']);$notice=$action==='archive'?'Client LIVE arhivat. Accesul este suspendat; abonamentul și facturile sunt păstrate.':'Client restaurat în listă. Ridică separat suspendarea dacă dorești reactivarea accesului.';}
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
    foreach(['provider','status','mode','direction','event'] as $key)$apiFilters[$key]=is_string($_GET[$key]??null)?$_GET[$key]:'';
    $apiEntries=ApiLog::page($apiFilters,max(1,(int)($_GET['page']??1)));
   }
   $integrations=new IntegrationSettings($this->db);$stripeSettings=$integrations->stripe($section==='integrations'?$stripeMode:$stripeActiveMode);$oblioSettings=$integrations->oblio();$stripeProfiles=$integrations->stripeProfiles();
   $mailDiagnostics=(new MailerService($this->db))->diagnostics();
   $listmonkSettings=$integrations->listmonk();$listmonkSaved=$integrations->saved('listmonk')!==null;
   $immichSettings=$integrations->immich();$immichSaved=$integrations->saved('immich')!==null;
   $immichOverview=(new ImmichStatistics($this->db))->overview();
   if($section==='immich-general'){
    $configuration=new ImmichConfiguration($this->db);$immichDefaults=$configuration->accountDefaults();$immichServer=null;$immichGeneralError=null;
    try{$immichServer=$configuration->server();}catch(\Throwable $e){$immichGeneralError=ImmichConfiguration::error($e);}
   }
   $stripeSaved=$integrations->saved('stripe')!==null;$oblioSaved=$integrations->saved('oblio')!==null;
   $stripeState=(new StripeIntegration($this->db,$this->plans,null,$stripeMode))->state();
  }
  if($admin && $section==='client'){
   try{
    $customer=(new AdminCustomer($this->db,new MailerService($this->db)))->customer($this->customerId);
    $customerBilling=(new BillingProfileService($this->db))->get($customer['id']);
    $customerSubscription=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$customer['id']]);
    $customerControl=(new AccountControl($this->db))->get($customer['id']);
    $customerStats=$this->db->one('SELECT * FROM immich_statistics WHERE user_id=? AND storage_user_id=?',[$customer['id'],$customer['storage_user_id']]);
    $customerInvoices=$this->db->run('SELECT id,oblio_series,oblio_number,status,amount,currency,stripe_mode,created_at,stripe_invoice_id FROM fiscal_invoices WHERE user_id=? ORDER BY created_at DESC,id DESC',[$customer['id']])->fetchAll();
    $customerLink=(new OnboardingLinks($this->db,new MailerService($this->db)))->current($customer['id']);
    $contactPending=$this->db->one('SELECT created_at FROM customer_contact_changes WHERE user_id=?',[$customer['id']]);
   }catch(\DomainException $e){http_response_code(404);$error=$e->getMessage();$customer=null;}
  }
  if($admin && $section==='clients'){
   $clients=$this->db->run("SELECT u.*,c.banned_at,c.reason,c.manual_plan,c.access_sync,c.quota_sync,s.status AS subscription_status,s.renewal_at,ist.used_bytes AS immich_used_bytes,ist.photos AS immich_photos,ist.videos AS immich_videos,ist.synced_at AS immich_synced_at,ist.available AS immich_available,r.status AS reset_status,r.step AS reset_step FROM users u LEFT JOIN account_controls c ON c.user_id=u.id LEFT JOIN subscriptions s ON s.user_id=u.id LEFT JOIN test_account_resets r ON r.user_id=u.id LEFT JOIN immich_statistics ist ON ist.user_id=u.id AND ist.storage_user_id=u.storage_user_id AND ist.config_hash=? WHERE u.is_deleted=CAST(? AS INTEGER) AND (?='' OR instr(lower(u.email),lower(?))>0 OR instr(lower(u.name),lower(?))>0) AND (CAST(? AS INTEGER)=0 OR c.quota_sync<>'ready' OR c.access_sync<>'ready') ORDER BY u.created_at DESC LIMIT 100",[(new ImmichStatistics($this->db))->fingerprint(),(int)$archivedOnly,$search,$search,$search,(int)$attentionOnly])->fetchAll();
  }
  if($admin && $section==='changes'){
   $changes=$this->db->run("SELECT p.*,u.email FROM plan_changes p JOIN users u ON u.id=p.user_id WHERE (CAST(? AS INTEGER)=0 OR p.status IN ('creating','paid','applying','retry','review')) ORDER BY p.created_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll();
  }
  if($admin && $section==='legal-requests'){$legalRequests=$this->db->run('SELECT * FROM withdrawal_requests ORDER BY created_at DESC LIMIT 100')->fetchAll();}
  if($admin && $section==='audit'){
   $audit=$this->db->run('SELECT a.*,ad.email AS admin_email,u.email AS customer_email FROM admin_audit a JOIN administrators ad ON ad.id=a.admin_id LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
  }
  if($admin){
   $dashboard=in_array($section,['dashboard','operations'],true)?(new AdminDashboard($this->db))->snapshot():[];
   $subscriptionRows=$section==='subscriptions'?$this->db->run("SELECT s.*,u.email FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE (CAST(? AS INTEGER)=0 OR s.status IN ('past_due','unpaid','incomplete')) ORDER BY s.renewal_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll():[];
   $fiscalRows=$section==='invoices'?$this->db->run("SELECT f.id,f.user_id,f.stripe_invoice_id,f.amount,f.currency,f.stripe_mode,f.status,f.error_message,f.oblio_series,f.oblio_number,f.created_at,u.email FROM fiscal_invoices f JOIN users u ON u.id=f.user_id WHERE (CAST(? AS INTEGER)=0 OR f.status IN ('pending','processing','failed')) ORDER BY f.created_at DESC LIMIT 100",[(int)$attentionOnly])->fetchAll():[];
   $unbilledPayments=$section==='invoices'?$this->db->run("SELECT p.*,u.email FROM (SELECT id,user_id,amount,currency,stripe_mode,created_at FROM invoices UNION ALL SELECT invoice_id,user_id,amount,currency,stripe_mode,paid_at FROM plan_changes c WHERE invoice_id IS NOT NULL AND paid_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM invoices i WHERE i.id=c.invoice_id)) p JOIN users u ON u.id=p.user_id LEFT JOIN fiscal_invoices f ON f.stripe_invoice_id=p.id WHERE p.amount>0 AND f.id IS NULL ORDER BY p.created_at DESC LIMIT 100")->fetchAll():[];
   $fiscalDiagnostics=$section==='invoices'?(new OblioInvoiceService($this->db,new MailerService($this->db)))->diagnostics():[];
   $mailRows=$section==='operations'?$this->db->run('SELECT recipient,kind,attempts,created_at FROM outbox WHERE sent_at IS NULL ORDER BY created_at LIMIT 30')->fetchAll():[];
   $eventRows=$section==='operations'?$this->db->run("SELECT id,type,status,created_at FROM billing_events WHERE status<>'complete' ORDER BY created_at LIMIT 30")->fetchAll():[];
  }
  require dirname(__DIR__).'/templates/admin.php';
 }
}
