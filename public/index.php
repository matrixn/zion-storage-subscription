<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
ini_set('display_errors','0'); ini_set('log_errors','1');
if(!is_dir(dirname(__DIR__).'/storage/logs'))mkdir(dirname(__DIR__).'/storage/logs',0700,true);
ini_set('error_log',dirname(__DIR__).'/storage/logs/application.log');
ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/internal/storage-access'){
 try{(new Zion\StorageGateway(new Zion\Database()))->handle();}catch(Throwable $e){http_response_code(403);}return;
}
$admin=preg_match('~^/admin(?:/|$)~',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/')===1;
session_name($admin?'zion_admin_session':'zion_session');
session_set_cookie_params(['lifetime'=>0,'path'=>$admin?'/admin':'/','secure'=>str_starts_with(base_url(),'https://'),'httponly'=>true,'samesite'=>'Lax']);
session_start();
try{($admin?new Zion\AdminApplication():new Zion\Application())->handle();}catch(Throwable $e){error_log('Application unavailable');http_response_code(503);echo 'Zion Storage';}
