<?php
declare(strict_types=1);
namespace Zion;

/** A saved provider is authoritative as a whole, including empty/disabled values. */
final class IntegrationSettings {
 public function __construct(private Database $db) {}
 public function saved(string $provider): ?array {
  if(!in_array($provider,['stripe','oblio'],true))throw new \DomainException('Serviciu invalid.');
  $row=$this->db->one('SELECT value FROM integration_settings WHERE key=?',['provider:'.$provider]);
  return $row?EncryptedPayload::decode($row['value']):null;
 }
 public function stripe(): array {
  return $this->saved('stripe')??['secret_key'=>env('STRIPE_SECRET_KEY'),'webhook_secret'=>env('STRIPE_WEBHOOK_SECRET')];
 }
 public function oblio(): array {
  $saved=$this->saved('oblio');
  return $saved===null?require dirname(__DIR__).'/config/oblio.php':$saved+['currencies'=>['EUR','RON']];
 }
 public function save(string $provider,array $input,string $admin): void {
  $old=$this->saved($provider)??[];
  $secret=function(string $key)use($input,$old):string{
   if(!empty($input['clear_'.$key]))return '';
   return trim((string)($input[$key]??''))?:($old[$key]??'');
  };
  if($provider==='stripe'){
   $data=['secret_key'=>$secret('secret_key'),'webhook_secret'=>$secret('webhook_secret')];
   if($data['secret_key']!=='' && !preg_match('/^(sk|rk)_test_[a-zA-Z0-9]+$/D',$data['secret_key']))throw new \DomainException('Această versiune acceptă numai chei Stripe TEST MODE.');
   if($data['webhook_secret']!=='' && !preg_match('/^whsec_[a-zA-Z0-9]+$/D',$data['webhook_secret']))throw new \DomainException('Secret webhook Stripe invalid.');
  }else{
   $data=['enabled'=>($input['enabled']??'')==='1','allow_test_payments'=>($input['allow_test_payments']??'')==='1','secret'=>$secret('secret')];
   foreach(['email','cif','series','workstation','language','vat_rate','vat_name'] as $key){
    $data[$key]=trim((string)($input[$key]??''));if(strlen($data[$key])>254)throw new \DomainException('Valoare prea lungă.');
   }
   if(!in_array($data['language'],['RO','EN','FR','DE'],true))throw new \DomainException('Limba documentelor Oblio este invalidă.');
   if($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new \DomainException('Email Oblio invalid.');
   if($data['vat_rate']!=='' && (!is_numeric($data['vat_rate']) || (float)$data['vat_rate']<0 || (float)$data['vat_rate']>100))throw new \DomainException('Cota TVA trebuie să fie între 0 și 100.');
   if($data['enabled'])foreach(['email','secret','cif','series','vat_rate','vat_name'] as $key)if($data[$key]==='')throw new \DomainException('Completează autentificarea, firma, seria și TVA înainte de activarea emiterii Oblio.');
  }
  foreach($data as $value)if(is_string($value)&&strlen($value)>2048)throw new \DomainException('Valoare prea lungă.');
  $encoded=EncryptedPayload::encode($data);
  $this->db->transaction(function()use($provider,$encoded,$admin){
   $this->db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['provider:'.$provider,$encoded]);
   $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'integration_settings',$provider.' configuration saved; environment fallback disabled',time()]);
  });
 }
}
