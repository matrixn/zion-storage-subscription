<?php
declare(strict_types=1);
namespace Zion;

/** EUR reference prices for new purchases; provider price history stays immutable. */
final class PlanSettings {
 public function __construct(private Database $db) {}
 public function all(): array {
  $plans=require dirname(__DIR__).'/config/plans.php';
  $rows=[];foreach($this->db->run('SELECT * FROM plan_settings')->fetchAll() as $row)$rows[$row['slug']]=$row;
  foreach($plans as &$p){
   if(isset($rows[$p['slug']])){$p['name']=$rows[$p['slug']]['name'];$p['monthly_price']=$rows[$p['slug']]['monthly_price'];}
   $p['yearly_price']=$p['monthly_price']*11;
   $p['invoice_name_ro']=$p['slug']==='free'?'':'Abonament Zion Storage '.$p['name'].' '.$p['capacity'];
  }unset($p);return $plans;
 }
 public function save(string $slug,array $input,string $admin): void {
  $before=null;foreach($this->all() as $p)if($p['slug']===$slug)$before=$p;
  if(!$before)throw new \DomainException('Pachet invalid.');
  $name=is_string($input['name']??null)?trim($input['name']):'';
  if(!mb_check_encoding($name,'UTF-8') || mb_strlen($name)<2 || mb_strlen($name)>60 || preg_match('/[<>\x00-\x1F\x7F]/u',$name))throw new \DomainException('Denumirea trebuie să aibă 2–60 de caractere, fără etichete HTML sau caractere de control.');
  $amount=is_string($input['monthly_price']??null)?trim($input['monthly_price']):'';
  if(!preg_match('/^(0|[1-9][0-9]{0,3})(?:[.,]([0-9]{1,2}))?$/D',$amount,$m))throw new \DomainException('Introdu prețul lunar în EUR, cu maximum două zecimale.');
  $monthly=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
  if(($slug==='free' && $monthly!==0) || ($slug!=='free' && ($monthly<100 || $monthly>999999)))throw new \DomainException('Pachetul gratuit rămâne 0 EUR. Pentru pachetele plătite, prețul lunar trebuie să fie între 1,00 și 9.999,99 EUR.');
  $this->db->transaction(function()use($slug,$name,$monthly,$admin,$before){
   $this->db->run('INSERT INTO plan_settings(slug,name,monthly_price,updated_at) VALUES(?,?,?,?) ON CONFLICT(slug) DO UPDATE SET name=excluded.name,monthly_price=excluded.monthly_price,updated_at=excluded.updated_at',[$slug,$name,$monthly,time()]);
   $this->db->run('INSERT INTO admin_audit(admin_id,action,detail,created_at) VALUES(?,?,?,?)',[$admin,'plan_update',json_encode(['plan'=>$slug,'before'=>['name'=>$before['name'],'monthly_eur'=>$before['monthly_price']],'after'=>['name'=>$name,'monthly_eur'=>$monthly,'yearly_eur'=>$monthly*11]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()]);
  });
 }
}
