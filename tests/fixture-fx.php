<?php
/** Deliberately synthetic 1:1 rate keeps legacy fixture arithmetic easy to inspect. */
function testFx(Zion\Database $db): void {
 if(env('APP_ENV')!=='test')throw new RuntimeException('Fixture rate is test-only');
 $db->run('INSERT OR IGNORE INTO exchange_rates(rate_date,eur_ron,fetched_at,source_url,content_hash) VALUES(?,?,?,?,?)',[Zion\BnrRates::date(),10000,time(),Zion\BnrRates::SOURCE,str_repeat('0',64)]);
 $db->run("INSERT INTO integration_settings(key,value) VALUES('bnr:last_attempt',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
}
