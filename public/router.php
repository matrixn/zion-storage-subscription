<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/assets/') && is_file(__DIR__.$path) && realpath(__DIR__.$path) && str_starts_with(realpath(__DIR__.$path),__DIR__.'/assets/'))return false;
require __DIR__.'/index.php';
