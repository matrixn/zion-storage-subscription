<?php
namespace Zion;

/** Read-only presentation of existing entitlement/provisioning state. */
final class MobileOnboarding {
 public const SERVER='https://photos.zion3d.ro';
 public const ANDROID='https://play.google.com/store/apps/details?id=app.alextran.immich';
 public const IOS='https://apps.apple.com/us/app/immich/id1613945652';
 public function __construct(private Database $db) {}
 public function view(?array $user,bool $eligible,bool $banned,bool $automatic): array {
  $result=['state'=>'session','firstFile'=>null];
  if(!$user)return $result;
  if($banned)return array_replace($result,['state'=>'suspended']);
  if(!$user['email_verified_at'])return array_replace($result,['state'=>'verification']);
  $subscription=$this->db->one('SELECT status FROM subscriptions WHERE user_id=?',[$user['id']]);
  if(in_array($user['status'],['suspended','inactive','canceled'],true) || (!$eligible && ($user['storage_user_id'] || in_array($subscription['status']??'',['canceled','unpaid','past_due','paused','incomplete_expired'],true))))return array_replace($result,['state'=>'inactive']);
  if(!$eligible)return array_replace($result,['state'=>'payment']);
  if($user['storage_user_id'] && $user['status']==='active'){
   $result['state']='ready';
   $row=$this->db->one('SELECT photos,videos FROM immich_statistics WHERE user_id=? AND storage_user_id=? AND available=1 AND synced_at>=? AND config_hash=?',[$user['id'],$user['storage_user_id'],time()-600,(new ImmichStatistics($this->db))->fingerprint()]);
   if($row)$result['firstFile']=(int)$row['photos']+(int)$row['videos']>0;
   return $result;
  }
  $event=$this->db->one("SELECT status FROM provisioning_events WHERE user_id=? AND action='create' ORDER BY id DESC LIMIT 1",[$user['id']]);
  if(($event['status']??'')==='retry_required')return array_replace($result,['state'=>'failed']);
  return array_replace($result,['state'=>$automatic && $user['terms_at'] && $user['privacy_at']?'provisioning':'password']);
 }
}
