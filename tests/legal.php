<?php
check(Zion\LegalDocuments::ready(),'all twelve localized legal documents are installed');
foreach(Zion\TranslationService::LOCALES as $locale)foreach(['privacy','terms','cookies'] as $document){$text=Zion\LegalDocuments::read($locale,$document);check(strlen($text)>1500 && str_contains($text,'contact@zion3d.ro'),'legal content and verified contact present '.$locale.'/'.$document);}
check(Zion\LegalDocuments::read('../ro','privacy')===null && Zion\LegalDocuments::read('ro','../.env')===null,'legal document lookup rejects traversal');
check(!Zion\LegalDocuments::operator()['vat_registered'] && Zion\LegalDocuments::operator()['registration']==='F38/36/2024','operator identity and non-VAT status match supplied facts');
$legalDb=new Zion\Database(':memory:');$legalDb->migrate();$legalDb->migrate();$legalMail=new Zion\MailerService($legalDb);$withdrawal=new Zion\WithdrawalService($legalDb,$legalMail);
foreach([['name'=>'A','email'=>'bad','contract'=>'x'],['name'=>'Test','email'=>'ok@example.com','contract'=>"ref\r\nInjected"],['name'=>'Test','email'=>'ok@example.com','contract'=>'Valid reference','company'=>'bot']] as $bad){try{$withdrawal->submit($bad,'ro');check(false,'invalid withdrawal');}catch(InvalidArgumentException $e){check(true,'invalid or bot withdrawal is rejected');}}
check(!$legalDb->one('SELECT id FROM withdrawal_requests LIMIT 1'),'invalid withdrawal never persists or sends a confirmation');
$receipt=$withdrawal->submit(['name'=>'Legal User','email'=>'legal-user@example.com','contract'=>'Subscription Personal / initial invoice 123'],'en');
$row=$legalDb->one('SELECT * FROM withdrawal_requests');
check(str_contains($receipt,$row['id']) && str_contains($receipt,'Received:') && str_contains($receipt,'I withdraw'),'withdrawal receipt contains declaration timestamp and reference');
check(!str_contains($row['payload'],'legal-user@example.com') && Zion\EncryptedPayload::decode($row['payload'])['email']==='legal-user@example.com','withdrawal evidence is encrypted');
check((int)$legalDb->one('SELECT COUNT(*) n FROM outbox')['n']===2 && $legalDb->one("SELECT recipient FROM outbox WHERE dedupe_key LIKE 'withdrawal-support:%'")['recipient']==='contact@zion3d.ro','operator and customer confirmation are queued atomically');
check((int)$legalDb->one('SELECT COUNT(*) n FROM subscriptions')['n']===0 && (int)$legalDb->one('SELECT COUNT(*) n FROM invoices')['n']===0,'withdrawal intake never creates charges refunds or subscription changes');
$legalAccounts=new Zion\AccountService($legalDb,new Zion\Plans($legalDb),$legalMail,new TestStorage());$legalId=$legalAccounts->register(['name'=>'Legal Signup','email'=>'signup-legal@example.com','password'=>'legal-signup-password','locale'=>'ro','plan'=>'free']);
$accepted=$legalDb->one('SELECT terms_version,privacy_version FROM users WHERE id=?',[$legalId]);
check($accepted['terms_version']===Zion\LegalDocuments::VERSION && $accepted['privacy_version']===Zion\LegalDocuments::VERSION,'new account stores terms and privacy acceptance versions');
echo "$count total assertions passed including legal documents and withdrawal intake.\n";
