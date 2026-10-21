<?php
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/fixture-fx.php';
if(PHP_SAPI!=='cli' || env('APP_ENV')!=='test' || !str_starts_with(env('DB_PATH'),sys_get_temp_dir().'/'))throw new RuntimeException('Isolated fixture only');
testFx(new Zion\Database());
