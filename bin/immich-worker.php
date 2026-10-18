#!/usr/bin/env php
<?php
require dirname(__DIR__).'/src/bootstrap.php';
// A dedicated compose service; failures never stop the next scheduled attempt.
while(true){
 try{$result=(new Zion\ImmichStatistics(new Zion\Database()))->synchronize(true);echo gmdate('c').' '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;}
 catch(Throwable $e){error_log('Immich statistics worker requires migration/configuration or retry.');}
 sleep(max(1,300-(time()%300)));
}
