<?php
namespace Zion;
final class LegalDocuments {
 public const VERSION='2026-10-19';
 public static function operator(): array {return require dirname(__DIR__).'/config/operator.php';}
 public static function read(string $locale,string $document): ?string {
  if(!in_array($locale,TranslationService::LOCALES,true) || !in_array($document,['privacy','terms','cookies'],true))return null;
  $file=dirname(__DIR__).'/legal/'.$locale.'/'.$document.'.txt';return is_file($file)?file_get_contents($file):null;
 }
 public static function ready(): bool {
  foreach(TranslationService::LOCALES as $locale)foreach(['privacy','terms','cookies'] as $doc)if(strlen(self::read($locale,$doc)??'')<500)return false;
  return true;
 }
}
