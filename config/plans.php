<?php
$plans = [
 ['slug'=>'free','name'=>'Free','storage_bytes'=>10_000_000_000,'capacity'=>'10 GB','monthly_price'=>0,'stripe_price_id'=>'','recommended'=>false],
 ['slug'=>'120gb','name'=>'Personal','storage_bytes'=>120_000_000_000,'capacity'=>'120 GB','monthly_price'=>999,'stripe_price_id'=>env('STRIPE_PRICE_120GB'),'recommended'=>true],
 ['slug'=>'250gb','name'=>'Plus','storage_bytes'=>250_000_000_000,'capacity'=>'250 GB','monthly_price'=>1999,'stripe_price_id'=>env('STRIPE_PRICE_250GB'),'recommended'=>false],
 ['slug'=>'500gb','name'=>'Family','storage_bytes'=>500_000_000_000,'capacity'=>'500 GB','monthly_price'=>2999,'stripe_price_id'=>env('STRIPE_PRICE_500GB'),'recommended'=>false],
];
return array_map(static function(array $plan): array {
 $plan['invoice_name_ro'] = $plan['slug']==='free' ? '' : 'Abonament Zion Storage '.$plan['name'].' '.$plan['capacity'];
 $plan['invoice_unit'] = 'abonament';
 $plan['invoice_vat_rate'] = env('OBLIO_VAT_RATE');
 $plan['invoice_product_code'] = 'ZS_'.strtoupper($plan['slug']);
 return $plan;
}, $plans);
