<?php

return [
    'enabled' => (bool) env('FINCODE_PAYMENT_ENABLED', false),
    'base_url' => env('FINCODE_API_BASE_URL', 'https://api.test.fincode.jp'),
    'secret_api_key' => env('FINCODE_SECRET_API_KEY'),
    'public_api_key' => env('FINCODE_PUBLIC_API_KEY'),
    'webhook_signature' => env('FINCODE_WEBHOOK_SIGNATURE'),
    'timeout_seconds' => (int) env('FINCODE_API_TIMEOUT_SECONDS', 10),
    'card_registration_intent_minutes' => 15,
    'konbini_payment_term_days' => 3,
    'virtual_account_payment_term_days' => 3,
    'success_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/')
        .'/points/purchase/thanks',
    'cancel_url' => 'https://luxe-pack.biz/points',
];
