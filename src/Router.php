<?php
namespace Zion;
final class Router {
 public const SLUGS=[
 'ro'=>['home'=>'','how'=>'cum-functioneaza','register'=>'inregistrare','contact'=>'contact','verify'=>'verificare-email','status'=>'activare','checkout'=>'plata','cancel'=>'plata-anulata','resend'=>'retrimite','activate'=>'finalizare','portal'=>'facturare','legal'=>'documente'],
 'en'=>['home'=>'','how'=>'how-it-works','register'=>'register','contact'=>'contact','verify'=>'verify-email','status'=>'activation','checkout'=>'checkout','cancel'=>'checkout-cancelled','resend'=>'resend','activate'=>'complete','portal'=>'billing','legal'=>'legal'],
 'fr'=>['home'=>'','how'=>'comment-ca-marche','register'=>'inscription','contact'=>'contact','verify'=>'verification-email','status'=>'activation','checkout'=>'paiement','cancel'=>'paiement-annule','resend'=>'renvoyer','activate'=>'finaliser','portal'=>'facturation','legal'=>'documents'],
 'de'=>['home'=>'','how'=>'so-funktioniert-es','register'=>'registrieren','contact'=>'kontakt','verify'=>'email-bestaetigen','status'=>'aktivierung','checkout'=>'zahlung','cancel'=>'zahlung-abgebrochen','resend'=>'erneut-senden','activate'=>'abschliessen','portal'=>'rechnung','legal'=>'rechtliches']];
 public static function path(string $locale,string $page): string { return '/'.$locale.'/'.self::SLUGS[$locale][$page]; }
 public static function match(string $path): ?array {
  foreach(self::SLUGS as $l=>$pages) foreach($pages as $p=>$s) if($path===self::path($l,$p)) return [$l,$p];
  return null;
 }
}
