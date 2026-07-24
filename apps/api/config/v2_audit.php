<?php

return [
    'active_hmac_key_version' => env('V2_AUDIT_HMAC_KEY_VERSION', 'v1'),
    'hmac_keys' => [
        'v1' => env('V2_AUDIT_HMAC_KEY'),
    ],
    'business_timezone' => env('V2_AUDIT_BUSINESS_TIMEZONE', 'Asia/Tokyo'),
];
