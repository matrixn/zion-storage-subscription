#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db = new Zion\Database();
$service = new Zion\OblioInvoiceService($db, new Zion\MailerService($db));
$result = $service->retryPending(in_array('--due', $argv, true));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['pending'] > 0 ? 1 : 0);
