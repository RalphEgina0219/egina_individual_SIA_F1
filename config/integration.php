<?php

/*
|--------------------------------------------------------------------------
| Team integration (B6 / B7)
|--------------------------------------------------------------------------
| Lahat ng setting ng pagkonekta sa PROC, INV at HR.
| Halimbawa: config('integration.systems.INV.url')
*/

return [

    // Ang code ng system na ito (CRM, PROC, INV o HR).
    'system_code' => env('SYSTEM_CODE', 'CRM'),

    'version' => 'v1',

    // false = walang ipapadala; mananatiling "pending" ang outbox (Yugto 1).
    'enabled' => filter_var(env('INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Ang sariling key: ito ang dapat ipadala ng ibang system sa X-API-KEY.
    'api_key' => env('INTEGRATION_API_KEY'),

    // Ang lahat ng petsa na ipinapadala o ibinabalik ay nasa oras ng Pilipinas (+08:00).
    'timezone' => 'Asia/Manila',

    // Segundo bago sumuko ang isang tawag sa ibang system.
    'timeout' => 10,

    // Base URL (may /api/integration/v1 na) at key ng bawat system.
    'systems' => [
        'CRM' => [
            'url' => env('CRM_API_URL'),
            'key' => env('CRM_API_KEY'),
        ],
        'PROC' => [
            'url' => env('PROC_API_URL'),
            'key' => env('PROC_API_KEY'),
        ],
        'INV' => [
            'url' => env('INV_API_URL'),
            'key' => env('INV_API_KEY'),
        ],
        'HR' => [
            'url' => env('HR_API_URL'),
            'key' => env('HR_API_KEY'),
        ],
    ],

    // Retry rule (B7): 1, 2, 4, 8 minuto; failed pagdating ng 5 attempts.
    'outbox' => [
        'max_attempts' => 5,
        'backoff_minutes' => [1, 2, 4, 8],
    ],

];
