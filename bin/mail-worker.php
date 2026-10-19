#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=new Zion\Database();
while(true){
 try{(new Zion\MailerService($db))->work();}
 catch(Throwable $e){error_log('Mail worker requires retry');}
 sleep(15);
}
