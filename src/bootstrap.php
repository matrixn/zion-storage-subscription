<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
function env(string $key, string $fallback=''): string { $value=getenv($key); if($value===false)$value=$_ENV[$key]??$fallback; return $value===''?$fallback:(string)$value; }
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(): string { return rtrim(env('APP_URL','https://storage.zion3d.ro'),'/'); }
function production(): bool { return env('APP_ENV')==='production'; }
if (production() && (strlen(env('APP_KEY'))<32 || !str_starts_with(base_url(),'https://') || !in_array(env('MAIL_TRANSPORT'),['smtp','listmonk'],true))) { throw new RuntimeException('Invalid production configuration'); }
date_default_timezone_set('UTC');
Stripe\ApiRequestor::setHttpClient(new Zion\LoggedStripeHttpClient(Stripe\HttpClient\CurlClient::instance()));
