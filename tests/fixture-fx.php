<?php
/** Deliberately synthetic 1:1 rate keeps legacy fixture arithmetic easy to inspect. */
function testFx(Zion\Database $db): void {
 if(env('APP_ENV')!=='test')throw new RuntimeException('Fixture rate is test-only');
 foreach(require dirname(__DIR__).'/config/plans.php' as $p)$db->run("INSERT OR IGNORE INTO plan_settings(slug,name,monthly_price,storage_bytes,updated_at,price_currency) VALUES(?,?,?,?,?,'ron')",[$p['slug'],$p['name'],$p['legacy_eur_monthly'],$p['storage_bytes'],time()]);
 $db->run('INSERT OR IGNORE INTO exchange_rates(rate_date,eur_ron,fetched_at,source_url,content_hash) VALUES(?,?,?,?,?)',[Zion\BnrRates::date(),10000,time(),Zion\BnrRates::SOURCE,str_repeat('0',64)]);
 $db->run("INSERT INTO integration_settings(key,value) VALUES('bnr:last_attempt',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
}
