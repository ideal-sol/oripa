<?php

return [
    'enabled' => (bool) env('FINCODE_PAYMENT_ENABLED', false),
    'allow_test_in_production' => env('FINCODE_ALLOW_TEST_IN_PRODUCTION', false),
    'base_url' => env('FINCODE_API_BASE_URL', 'https://api.test.fincode.jp'),
    'secret_api_key' => env('FINCODE_SECRET_API_KEY'),
    'public_api_key' => env('FINCODE_PUBLIC_API_KEY'),
    'webhook_signature' => env('FINCODE_WEBHOOK_SIGNATURE'),
    'timeout_seconds' => (int) env('FINCODE_API_TIMEOUT_SECONDS', 10),
    'card_registration_intent_minutes' => 15,
    'konbini_payment_term_days' => 3,
    'virtual_account_payment_term_days' => 3,
    'platform_origin' => rtrim((string) env('FINCODE_PLATFORM_ORIGIN', ''), '/'),
    'storefront_origin' => rtrim((string) env('FINCODE_STOREFRONT_ORIGIN', ''), '/'),
    'admin_origin' => rtrim((string) env('V2_ADMIN_ORIGIN', ''), '/'),
];
