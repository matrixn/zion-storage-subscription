<?php
$cdb=new Zion\Database(':memory:');$cdb->migrate();testFx($cdb);seedCatalog($cdb);
$cdb->run('INSERT INTO administrators(id,email,password_hash,created_at) VALUES(?,?,?,?)',['capacity-admin','capacity-admin@example.test','fixture',time()]);
$cp=new Zion\Plans($cdb);$cs=new Zion\PlanSettings($cdb);$ch=new CatalogHttp();Stripe\ApiRequestor::setHttpClient($ch);
$cc=new Zion\BillingCatalog($cdb,$cp,new Stripe\StripeClient((new Zion\IntegrationSettings($cdb))->stripe('test')['secret_key']),'test');
$cs->save('120gb',['name'=>'Personal','monthly_price'=>'3.99','storage_gb'=>'150'],'capacity-admin');
foreach(['month','year'] as $period)$cc->synchronize('120gb',$period,'capacity-admin');
$oldPrice=$cp->get('120gb')['stripe_price_id'];$oldYear=$cp->get('120gb','year')['stripe_price_id'];
$oldProduct=$ch->prices[$oldPrice]['product'];$oldSignature=Zion\BnrRates::signature($cp->get('120gb'),'capacity-user');
check($cp->get('120gb')['storage_bytes']===150_000_000_000 && $cp->get('120gb')['capacity']==='150 GB','administrator edits capacity without changing stable plan slug');
$cmail=new Zion\MailerService($cdb);$cstore=new TestStorage();$ca=new Zion\AccountService($cdb,$cp,$cmail,$cstore);
$data=['name'=>'Capacity fixture','email'=>'capacity-old@example.test','password'=>'capacity-fixture-password','locale'=>'ro','plan'=>'120gb'];
$cid=$ca->register($data);$cdb->run('UPDATE users SET email_verified_at=? WHERE id=?',[time(),$cid]);
$cdb->run("INSERT INTO subscriptions(user_id,stripe_subscription_id,stripe_customer_id,stripe_price_id,status,paid_confirmed,plan,currency) VALUES(?,'sub_capacity','cus_capacity',?,'active',1,'120gb','ron')",[$cid,$oldPrice]);
$free=$ca->register(array_replace($data,['email'=>'capacity-free-old@example.test','plan'=>'free']));
$cs->save('120gb',['name'=>'Personal','monthly_price'=>'3.99','storage_gb'=>'250'],'capacity-admin');
$cs->save('free',['name'=>'Free','monthly_price'=>'0','storage_gb'=>'10'],'capacity-admin');
foreach(['month','year'] as $period)$cc->synchronize('120gb',$period,'capacity-admin');
$newPrice=$cp->get('120gb')['stripe_price_id'];
check($newPrice!==$oldPrice && $cp->get('120gb')['eur_amount']===399,'capacity-only edit creates a new Stripe Price even when the EUR amount stays the same');
check($cp->byPrice($oldPrice)['storage_bytes']===150_000_000_000 && $cp->byPrice($oldYear)['storage_bytes']===150_000_000_000,'monthly and annual historical prices retain the purchased capacity');
check($cp->byPrice('price_120')['storage_bytes']===120_000_000_000,'pre-versioning prices are pinned before the first capacity edit');
check(str_contains($ch->products[$oldProduct]['name'],'150 GB') && $ch->prices[$newPrice]['product']!==$oldProduct,'old Stripe product descriptions are not relabeled with the new capacity');
check(!hash_equals($oldSignature,Zion\BnrRates::signature($cp->get('120gb'),'capacity-user')),'capacity edit requires a previously displayed checkout quote to be reconfirmed');
try{$cc->save('120gb','month',$oldPrice,'capacity-admin');check(false,'old price rebound to new capacity');}catch(DomainException $e){check($cp->byPrice($oldPrice)['storage_bytes']===150_000_000_000,'historical price cannot be rebound to a different capacity');}
check($ca->provision($cid,$data['password']) && $cstore->users['store-'.$cid]['quota']===150_000_000_000,'delayed initial activation uses purchased capacity after a catalog edit');
(new Zion\BillingProfileService($cdb))->save($cid,['type'=>'individual','name'=>'Capacity fixture','billing_email'=>$data['email'],'country'=>'RO','county'=>'VL','city'=>'Ramnicu Valcea','address'=>'Fixture street 1','postal_code'=>'240001']);
$billing=new TestStripe($cdb,$cp,$cmail,$cstore);
$now=time();$invoice=['id'=>'in_capacity_renewal','livemode'=>false,'customer'=>'cus_capacity','subscription'=>'sub_capacity','status'=>'paid','amount_paid'=>399,'currency'=>'ron','billing_reason'=>'subscription_cycle','created'=>$now,'status_transitions'=>['paid_at'=>$now],'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>$oldPrice],'quantity'=>1,'period'=>['start'=>$now,'end'=>$now+30*86400]]]]];
$billing->snapshot=['id'=>'sub_capacity','livemode'=>false,'customer'=>'cus_capacity','status'=>'active','metadata'=>['user_id'=>$cid,'plan'=>'120gb'],'items'=>['data'=>[['price'=>['id'=>$oldPrice],'current_period_end'=>$now+30*86400]]],'latest_invoice'=>$invoice];
$billing->invoices[$invoice['id']]=$invoice;
$billing->process(['id'=>'evt_capacity_renewal','type'=>'invoice.paid','data'=>['object'=>$invoice]]);
check($cstore->users['store-'.$cid]['quota']===150_000_000_000 && $cdb->one('SELECT storage_quota_bytes FROM users WHERE id=?',[$cid])['storage_quota_bytes']===150_000_000_000,'paid renewal and webhook reconciliation preserve the original capacity');
$admin=new Zion\AdminService($cdb,$cp,$cmail,$cstore,$billing->changes());$admin->syncQuota($cid,'capacity-admin');
$admin->ban($cid,true,'Capacity fixture ban','capacity-admin');$admin->ban($cid,false,'Capacity fixture unban','capacity-admin');
check($cstore->users['store-'.$cid]['quota']===150_000_000_000,'admin quota retry and unban do not apply current public package capacity');
$new=$ca->register(array_replace($data,['email'=>'capacity-new@example.test']));$cdb->run('UPDATE users SET email_verified_at=? WHERE id=?',[time(),$new]);
$cdb->run("INSERT INTO subscriptions(user_id,stripe_price_id,status,paid_confirmed,plan,currency) VALUES(?,?,'active',1,'120gb','ron')",[$new,$newPrice]);
check($ca->provision($new,$data['password']) && $cstore->users['store-'.$new]['quota']===250_000_000_000,'new purchase activates with the new capacity');
check($cp->forUser($cdb->one('SELECT * FROM users WHERE id=?',[$free]))['storage_bytes']===5_000_000_000,'previous free registrations retain their capacity');
$newFree=$ca->register(array_replace($data,['email'=>'capacity-free-new@example.test','plan'=>'free']));
check($cp->forUser($cdb->one('SELECT * FROM users WHERE id=?',[$newFree]))['storage_bytes']===10_000_000_000,'new free registrations use the edited capacity');
foreach(['0','-1','1.5','1000001',[],null] as $invalid){
 try{$cs->save('120gb',['name'=>'Personal','monthly_price'=>'3.99','storage_gb'=>$invalid],'capacity-admin');check(false,'invalid capacity accepted');}catch(DomainException $e){check($cp->get('120gb')['storage_bytes']===250_000_000_000,'invalid GB input cannot change saved capacity');}
}
$cdb->migrate();check($cp->byPrice($oldPrice)['storage_bytes']===150_000_000_000,'rerunning migrations cannot overwrite capacity history');
Stripe\ApiRequestor::setHttpClient(new NoNetworkStripe());
echo "\n$count total assertions including immutable purchased capacities.\n";
