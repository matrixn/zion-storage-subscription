<?php
declare(strict_types=1);
namespace Zion;
final class WebhookDiagnostics {
 public static function error(\Throwable $e): array {
  if($e instanceof \JsonException)return ['code'=>'invalid_json','message'=>'Corpul cererii nu este JSON valid.'];
  if($e instanceof \Stripe\Exception\SignatureVerificationException){
   $expired=str_contains($e->getMessage(),'tolerance');
   return ['code'=>$expired?'signature_expired':'signature_invalid','message'=>$expired?'Semnătură expirată: verifică ora serverului și retrimite evenimentul din Stripe.':'Semnătură Stripe invalidă sau absentă. Verifică secretul whsec al destinației și mediul TEST/LIVE; secretul CLI diferă.'];
  }
  $messages=[
   'webhook_unconfigured'=>'Secretul webhook nu este configurat pentru mediul evenimentului.',
   'webhook_mode_missing'=>'Evenimentul nu precizează modul TEST/LIVE.',
   'webhook_mode_mismatch'=>'Modul evenimentului nu corespunde parametrului mode al endpointului.',
   'event_mode_mismatch'=>'Evenimentul nu corespunde mediului selectat.',
   'unknown_billing_user'=>'Abonamentul Stripe nu are un user_id local recunoscut în metadata.',
   'billing_plan_mismatch'=>'Prețul, planul sau intervalul Stripe nu corespunde catalogului și contului local.',
   'subscription_mode_mismatch'=>'Contul sau abonamentul aparține altui mediu Stripe.',
   'invoice_mode_mismatch'=>'Factura aparține altui mediu Stripe.',
   'invoice_not_paid'=>'Factura verificată prin API nu are starea paid.',
   'invoice_identity_mismatch'=>'Factura nu corespunde clientului Stripe al abonamentului.',
   'invoice_currency_mismatch'=>'Moneda facturii nu corespunde planului.',
   'duplicate_subscription'=>'Contul are deja un alt abonament activ.',
   'upgrade_mode_mismatch'=>'Upgrade-ul aparține altui mediu Stripe.',
   'event_identity_mismatch'=>'Evenimentul returnat de Stripe nu corespunde ID-ului cerut.',
   'unsupported_replay_event'=>'Tipul acestui eveniment nu poate fi reprocesat de integrarea de abonamente.',
   'test_network_forbidden'=>'Reprocesarea externă este dezactivată în testele izolate.',
  ];
  $code=$e->getMessage();
  if(isset($messages[$code]))return ['code'=>$code,'message'=>$messages[$code]];
  if($e instanceof \Stripe\Exception\ApiErrorException)return ['code'=>'stripe_api_error','message'=>StripeIntegration::safeError($e).' Verifică cererile API corelate.'];
  return ['code'=>'processing_'.(new \ReflectionClass($e))->getShortName(),'message'=>'Procesarea s-a oprit la etapa indicată. Verifică cererile API corelate și operațiunile în așteptare.'];
 }
 public static function result(array $context): string {
  return match($context['outcome']??''){
   'received'=>'Webhook primit; procesarea începe.',
   'duplicate'=>'Eveniment deja finalizat; nu a fost aplicat din nou.',
   'ignored'=>'Tip de eveniment nefolosit de integrare; nicio activare efectuată.',
   'no_subscription'=>'Eveniment fără referință la un abonament; nicio activare efectuată.',
   default=>($context['paid_confirmed']??null)===false?'Eveniment procesat; abonamentul/factura nu confirmă încă o plată activă.':'Eveniment procesat. Verifică starea abonamentului și continuă activarea contului.',
  };
 }
}
