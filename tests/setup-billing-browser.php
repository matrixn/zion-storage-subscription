<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if (env('APP_ENV')!=='test' || env('OBLIO_ENABLED','false')!=='false') throw new RuntimeException('Fixture setup requires an isolated test environment');
$db=new Zion\Database();$db->migrate();
$mail=new Zion\MailerService($db);$plans=new Zion\Plans();
$account=new Zion\AccountService($db,$plans,$mail,new Zion\StorageProvisioningService());
$id=$account->register(['name'=>'Browser Billing','email'=>'billing-'.bin2hex(random_bytes(6)).'@example.com','password'=>'billing-browser-password','locale'=>'en','plan'=>'120gb','billing'=>[
 'type'=>'individual','name'=>'Browser Billing','billing_email'=>'billing@example.com','country'=>'RO',
 'county'=>'Bucuresti','city'=>'Bucuresti','address'=>'Strada Amintirilor 16','postal_code'=>'010101',
]]);
$row=$db->one("SELECT payload FROM outbox WHERE kind='verify' AND recipient=(SELECT email FROM users WHERE id=?) ORDER BY id DESC",[$id]);
$link=Zion\EncryptedPayload::decode($row['payload'])['url'];
$fiscal=new Zion\OblioInvoiceService($db,$mail);
$invoice=['id'=>'in_browser_'.bin2hex(random_bytes(4)),'status'=>'paid','livemode'=>false,'customer'=>'cus_browser','amount_paid'=>$plans->get('120gb')['monthly_price'],'currency'=>'ron','created'=>1792108800,'lines'=>['has_more'=>false,'data'=>[['price'=>['id'=>$plans->get('120gb')['stripe_price_id']],'period'=>['start'=>1792108800,'end'=>1794787200],'quantity'=>1]]]];
$fid=$fiscal->capture($invoice,$db->one('SELECT * FROM users WHERE id=?',[$id]),['id'=>'sub_browser','current_period_end'=>1794787200],$plans->get('120gb'));
$fiscal->process($fid);
echo json_encode(['verification_url'=>$link],JSON_THROW_ON_ERROR);
