<?php
ob_start();require __DIR__.'/setup-onboarding.php';$fixtures=json_decode(ob_get_clean(),true);
$ready=$db->one("SELECT id FROM users WHERE email='paid-ready@example.test'")['id'];
$edit=$db->one("SELECT id FROM users WHERE email='password@example.test'")['id'];
foreach([1,2] as $number)$db->run("INSERT INTO fiscal_invoices(id,user_id,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,source_snapshot,oblio_series,oblio_number,amount,currency,plan,status,idempotency_key,request_snapshot,created_at,updated_at,issued_at) VALUES(?,?,?,'sub_fixture','cus_fixture','{}','ZS',?,199,'eur','120gb','issued',?,?,?,?,?)",['invoice-fixture-'.$number,$ready,'in_fixture_'.$number,(string)$number,'fixture-'.$number,Zion\EncryptedPayload::encode(['cif'=>'49490924']),$now+$number,$now+$number,$now+$number]);
$mail=new Zion\MailerService($db);
$link=(new Zion\OnboardingLinks($db,$mail))->issue($ready);
$fixtures['customer_id']=$ready;$fixtures['edit_id']=$edit;$fixtures['guide_url']=$link['url'];
// Disable inherited Listmonk config for private local HTML previews.
$db->run('INSERT INTO integration_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',['provider:listmonk',Zion\EncryptedPayload::encode(['enabled'=>false,'url'=>'','api_user'=>'','api_token'=>'','template_id'=>'','from_email'=>''])]);
echo json_encode($fixtures,JSON_THROW_ON_ERROR);
