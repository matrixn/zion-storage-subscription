<?php
return [
 ['slug'=>'free','name'=>'Free','storage_bytes'=>10_000_000_000,'capacity'=>'10 GB','monthly_price'=>0,'stripe_price_id'=>'','recommended'=>false],
 ['slug'=>'120gb','name'=>'Personal','storage_bytes'=>120_000_000_000,'capacity'=>'120 GB','monthly_price'=>799,'stripe_price_id'=>env('STRIPE_PRICE_120GB'),'recommended'=>true],
 ['slug'=>'250gb','name'=>'Plus','storage_bytes'=>250_000_000_000,'capacity'=>'250 GB','monthly_price'=>1499,'stripe_price_id'=>env('STRIPE_PRICE_250GB'),'recommended'=>false],
 ['slug'=>'500gb','name'=>'Family','storage_bytes'=>500_000_000_000,'capacity'=>'500 GB','monthly_price'=>2499,'stripe_price_id'=>env('STRIPE_PRICE_500GB'),'recommended'=>false],
 ['slug'=>'1tb','name'=>'Pro','storage_bytes'=>1_000_000_000_000,'capacity'=>'1 TB','monthly_price'=>3999,'stripe_price_id'=>env('STRIPE_PRICE_1TB'),'recommended'=>false],
];
