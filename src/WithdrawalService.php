<?php
namespace Zion;
final class WithdrawalService {
 public function __construct(private Database $db,private MailerService $mail){}
 public function submit(array $input,string $locale): string {
  $d=[];foreach(['name','email','contract'] as $key)$d[$key]=is_string($input[$key]??null)?trim($input[$key]):'';
  if(!empty($input['company']) || mb_strlen($d['name'])<2 || mb_strlen($d['name'])>150 || !filter_var($d['email'],FILTER_VALIDATE_EMAIL) || strlen($d['email'])>254 || mb_strlen($d['contract'])<3 || mb_strlen($d['contract'])>500 || preg_match('/[\x00-\x1f\x7f]/',implode('',$d)) || !Security::captcha((string)($input['cf-turnstile-response']??'')))throw new \InvalidArgumentException('invalid_withdrawal');
  $id=bin2hex(random_bytes(16));$now=time();$tr=new TranslationService($locale);
  $receipt=$tr->get('withdrawal.receipt',['id'=>$id,'name'=>$d['name'],'email'=>$d['email'],'contract'=>$d['contract'],'date'=>(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i:s T')]);
  $this->db->transaction(function()use($id,$d,$now,$receipt,$locale){
   $this->db->run('INSERT INTO withdrawal_requests(id,payload,locale,created_at) VALUES(?,?,?,?)',[$id,EncryptedPayload::encode($d+['receipt'=>$receipt]),$locale,$now]);
   $this->mail->queue(LegalDocuments::operator()['email'],$locale,'support',['name'=>$d['name'],'email'=>$d['email'],'subject'=>'Zion Storage — withdrawal '.$id,'message'=>$receipt,'legal_receipt'=>$receipt],'withdrawal-support:'.$id);
   $this->mail->queue($d['email'],$locale,'contact',['legal_receipt'=>$receipt],'withdrawal-confirmation:'.$id);
  });return $receipt;
 }
}
