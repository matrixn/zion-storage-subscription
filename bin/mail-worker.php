#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=new Zion\Database();
while(true){
 $settings=new Zion\IntegrationSettings($db);
 foreach(['test','live'] as $mode){
  $key=$settings->stripe($mode)['secret_key'];
  if($key)try{(new Zion\RonRenewals($db,new Stripe\StripeClient($key),$mode))->work();}
  catch(Throwable $e){error_log('RON renewal worker requires retry');}
 }
 $mail=new Zion\MailerService($db);
 try{(new Zion\OblioInvoiceService($db,$mail))->work();}catch(Throwable $e){error_log('Fiscal worker requires retry');}
 try{$mail->work();}catch(Throwable $e){error_log('Mail worker requires retry');}
 sleep(15);
}
