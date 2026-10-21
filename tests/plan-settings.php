<?php
$pdb=new Zion\Database(':memory:');$pdb->migrate();testFx($pdb);seedCatalog($pdb);
$pdb->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',['plan-admin','plan-admin@example.test','fixture',time()]);
$settings=new Zion\PlanSettings($pdb);$plans=new Zion\Plans($pdb);
$old=$plans->byPrice('price_120');$signature=Zion\BnrRates::signature($plans->get('120gb'),'fixture-user');
$settings->save('120gb',['name'=>'Zion Essential','monthly_price'=>'5,49','yearly_price'=>'0'],'plan-admin');
$updated=$plans->get('120gb');
check($updated['name']==='Zion Essential' && $updated['eur_amount']===549 && $updated['price_amount']===549,'saved plan name and comma-decimal EUR price apply to current catalog');
check($plans->get('120gb','year')['eur_amount']===6039 && $updated['yearly_price']===6039,'annual price remains eleven monthly payments, ignoring tampered annual input');
check($updated['storage_bytes']===120_000_000_000 && $updated['slug']==='120gb' && str_contains($updated['invoice_name_ro'],'Zion Essential'),'editing name/price preserves quota and stable plan identity');
check($plans->byPrice('price_120')['eur_amount']===$old['eur_amount'] && $plans->byPrice('price_120')['price_amount']===$old['price_amount'],'catalog edit preserves the EUR contract and RON amount of historical Stripe prices');
check(!hash_equals($signature,Zion\BnrRates::signature($updated,'fixture-user')),'a price edit invalidates a previously displayed checkout quote');
foreach([['name'=>'X','monthly_price'=>'5.49'],['name'=>'<b>X</b>','monthly_price'=>'5.49'],['name'=>"Invalid\nname",'monthly_price'=>'5.49'],['name'=>'Valid','monthly_price'=>'-1'],['name'=>'Valid','monthly_price'=>'0.99'],['name'=>'Valid','monthly_price'=>'10000'],['name'=>'Valid','monthly_price'=>'3.999'],['name'=>'Valid','monthly_price'=>['4.99']]] as $input){
 try{$settings->save('120gb',$input,'plan-admin');check(false,'invalid catalog edit accepted');}catch(DomainException $e){check($plans->get('120gb')['monthly_price']===549,'invalid input leaves saved plan intact');}
}
try{$settings->save('unknown',['name'=>'Unknown','monthly_price'=>'5.49'],'plan-admin');check(false,'unknown plan saved');}catch(DomainException $e){check(true,'unknown plan cannot be added through existing-plan editor');}
try{$settings->save('free',['name'=>'Starter','monthly_price'=>'1.00'],'plan-admin');check(false,'free plan charged');}catch(DomainException $e){check(true,'free plan remains free even with a tampered request');}
$settings->save('free',['name'=>'Starter','monthly_price'=>'0'],'plan-admin');
check($plans->get('free')['name']==='Starter' && $plans->get('free')['yearly_price']===0,'free package can be renamed without enabling paid billing');
check(count($pdb->run("SELECT * FROM admin_audit WHERE action='plan_update'")->fetchAll())===2,'only successful plan edits are audited');
$key=(new Zion\IntegrationSettings($pdb))->stripe('test')['secret_key'];$http=new CatalogHttp();Stripe\ApiRequestor::setHttpClient($http);
$catalog=new Zion\BillingCatalog($pdb,$plans,new Stripe\StripeClient($key),'test');
$catalog->synchronize('120gb','month','plan-admin');$catalog->synchronize('120gb','year','plan-admin');
check($http->created===2 && $plans->byPrice('price_120')['legacy'],'price edit creates new monthly/yearly mappings and retains historical prices');
$settings->save('120gb',['name'=>'Essential Prime','monthly_price'=>'5.49'],'plan-admin');
$catalog->synchronize('120gb','month','plan-admin');$catalog->synchronize('120gb','year','plan-admin');
check($http->created===2 && $http->products['zs_ron_120gb']['name']==='Zion Storage Essential Prime 120 GB','renaming updates existing Stripe product without creating redundant Prices');
$catalog->disable('120gb','year','plan-admin');$settings->save('120gb',['name'=>'Essential Prime','monthly_price'=>'6.49'],'plan-admin');
check($catalog->synchronize('120gb','year','plan-admin')==='paused','plan edits cannot reactivate manually disabled purchase options');
[$rdb,$ruid,$rhttp,$worker]=ronFixture();
$rdb->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',['plan-admin','plan-admin@example.test','fixture',time()]);
(new Zion\PlanSettings($rdb))->save('120gb',['name'=>'Essential New','monthly_price'=>'9.99'],'plan-admin');
$worker->process('in_ron_fixture');
check($rhttp->invoice['amount_paid']===2126,'existing renewal keeps its contracted EUR reference after a catalog price increase');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
echo "\n$count total assertions including editable plan catalog.\n";
