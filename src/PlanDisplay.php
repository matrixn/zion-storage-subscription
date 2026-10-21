<?php
declare(strict_types=1);
namespace Zion;
final class PlanDisplay {
 public static function amount(array $p,string $locale,string $period,bool $secondary=false): ?int {
  $ron=($locale==='ro')!==$secondary;
  return $p[($ron?'ron_':'eur_').($period==='year'?'yearly':'monthly')];
 }
 public static function label(array $p,string $locale,string $period,bool $secondary=false): string {
  $amount=self::amount($p,$locale,$period,$secondary);
  if($amount===null)return (new TranslationService($locale))->get('fx.unavailable');
  $currency=(($locale==='ro')!==$secondary)?'RON':'EUR';
  $n=number_format($amount/100,2,$locale==='en'?'.':',','');
  return ($secondary?'≈ ':'').($locale==='en'?$currency.' '.$n:$n.' '.$currency);
 }
}
