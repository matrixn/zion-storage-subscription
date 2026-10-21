<?php
declare(strict_types=1);
namespace Zion;

/** Convert the editable catalog once; never rewrite historical billing contracts. */
final class CatalogCurrencyMigration {
 public static function run(Database $db,bool $wasInitialized=true): void {
  if($db->one("SELECT key FROM integration_settings WHERE key='catalog:ron-primary-v1'"))return;
  $old=$db->run('SELECT * FROM plan_settings')->fetchAll();
  $existing=$wasInitialized;
  $rate=$existing?(new BnrRates($db))->current():null;
  if($existing && !$rate)throw new \DomainException('Migrarea catalogului necesită un curs BNR recent. Rulează fx:update, apoi migrate. Datele istorice sunt păstrate.');
  $db->transaction(function()use($db,$old,$existing,$rate){
   if($db->one("SELECT key FROM integration_settings WHERE key='catalog:ron-primary-v1'"))return;
   $rows=array_column($old,null,'slug');
   if($existing)foreach(require dirname(__DIR__).'/config/plans.php' as $p){
    $r=$rows[$p['slug']]??null;
    if($r && $r['price_currency']==='ron')continue;
    $eur=$r['monthly_price']??$p['legacy_eur_monthly'];
    $ron=BnrRates::convert($eur,$rate['eur_ron']);
    $db->run("INSERT INTO plan_settings(slug,name,monthly_price,storage_bytes,updated_at,price_currency) VALUES(?,?,?,?,?,'ron') ON CONFLICT(slug) DO UPDATE SET monthly_price=excluded.monthly_price,price_currency='ron',updated_at=excluded.updated_at",[$p['slug'],$r['name']??$p['name'],$ron,$r['storage_bytes']??$p['storage_bytes'],time()]);
   }
   $db->run('INSERT INTO integration_settings(key,value) VALUES(?,?)',['catalog:ron-primary-v1',json_encode(['converted_at'=>time(),'source'=>'BNR','rate_date'=>$rate['rate_date']??null,'eur_ron'=>$rate['eur_ron']??null,'previous_settings'=>$old],JSON_THROW_ON_ERROR)]);
  });
 }
}
