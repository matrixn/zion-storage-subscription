<?php
declare(strict_types=1);
return [
    'enabled' => env('OBLIO_ENABLED', 'false') === 'true',
    'email' => env('OBLIO_EMAIL'),
    'secret' => env('OBLIO_API_SECRET'),
    'cif' => env('OBLIO_CIF', env('OBLIO_COMPANY_CIF')),
    'series' => env('OBLIO_SERIES', env('OBLIO_SERIES_NAME')),
    'workstation' => env('OBLIO_WORKSTATION'),
    'language' => env('OBLIO_LANGUAGE', 'RO'),
    'vat_rate' => env('OBLIO_VAT_RATE'),
    'vat_name' => env('OBLIO_VAT_NAME'),
    'currencies' => ['EUR','RON'],
    'allow_test_payments' => env('OBLIO_ALLOW_TEST_PAYMENTS', 'false') === 'true',
];
