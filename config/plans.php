<?php
$plans = [
 ['slug'=>'free','name'=>'Free','storage_bytes'=>10_000_000_000,'capacity'=>'10 GB','monthly_price'=>0,'stripe_price_id'=>'','recommended'=>false],
 ['slug'=>'120gb','name'=>'Personal','storage_bytes'=>120_000_000_000,'capacity'=>'120 GB','monthly_price'=>199,'stripe_price_id'=>'','recommended'=>true],
 ['slug'=>'250gb','name'=>'Plus','storage_bytes'=>250_000_000_000,'capacity'=>'250 GB','monthly_price'=>359,'stripe_price_id'=>'','recommended'=>false],
 ['slug'=>'500gb','name'=>'Titan','storage_bytes'=>500_000_000_000,'capacity'=>'500 GB','monthly_price'=>599,'stripe_price_id'=>'','recommended'=>false],
];
return array_map(static function(array $plan): array {
 $plan['yearly_price']=$plan['monthly_price']*11;
 $plan['currency']='eur';
 $plan['features']=match($plan['slug']) {
  'free'=>['free_capacity','mobile_backup','original_storage','devices','one_user','free_forever'],
  '120gb'=>['personal_capacity','mobile_backup','original_media','devices','private_library','cancel'],
  '250gb'=>['paid_capacity','media_backup','original_storage','private_library','devices','cancel'],
  '500gb'=>['paid_capacity','media_backup','original_storage','devices','cancel'],
 };
 $plan['invoice_name_ro'] = $plan['slug']==='free' ? '' : 'Abonament Zion Storage '.$plan['name'].' '.$plan['capacity'];
 $plan['invoice_unit'] = 'abonament';
 $plan['invoice_vat_rate'] = env('OBLIO_VAT_RATE');
 $plan['invoice_product_code'] = 'ZS_'.strtoupper($plan['slug']);
 return $plan;
}, $plans);
