<?php
namespace Zion;
final class TranslationService {
 public const LOCALES=['ro','en','fr','de'];
 public array $messages;
 public function __construct(public string $locale) {
  if (!in_array($locale,self::LOCALES,true)) throw new \InvalidArgumentException('locale');
  $this->messages=require dirname(__DIR__).'/translations/'.$locale.'.php';
  $this->messages=array_replace($this->messages,(require dirname(__DIR__).'/translations/account_changes.php')[$locale]);
  $this->messages=array_replace($this->messages,(require dirname(__DIR__).'/translations/pricing.php')[$locale]);
  $this->messages=array_replace($this->messages,(require dirname(__DIR__).'/translations/carousel.php')[$locale]);
  $this->messages=array_replace($this->messages,(require dirname(__DIR__).'/translations/billing.php')[$locale]);
  $this->messages=array_replace($this->messages,(require dirname(__DIR__).'/translations/onboarding.php')[$locale]);
 }
 public function get(string $key,array $vars=[]): string {
  $s=$this->messages[$key]??throw new \RuntimeException('Missing translation: '.$key);
  foreach($vars as $k=>$v) $s=str_replace('{'.$k.'}',(string)$v,$s);
  return $s;
 }
 public static function preferred(string $cookie,string $accept): string {
  if(in_array($cookie,self::LOCALES,true)) return $cookie;
  $items=[]; foreach(explode(',',$accept) as $i=>$s) {
   $parts=explode(';',trim($s)); $q=isset($parts[1])?(float)substr(trim($parts[1]),2):1;
   $l=strtolower(substr($parts[0],0,2)); if(in_array($l,self::LOCALES,true)&&$q>0) $items[]=[$l,$q,$i];
  }
  usort($items,fn($a,$b)=>($b[1]<=>$a[1])?:($a[2]<=>$b[2]));
  return $items[0][0]??'en';
 }
}
