<?php

return [
    'storage_days' => 60,
    'exchange_point_expiry_days' => 180,
    'cursor_page_size' => 20,
    'cursor_page_size_maximum' => 100,
    'idempotency_key_minimum' => 16,
    'idempotency_key_maximum' => 128,
    'address_hmac_key' => env('V2_PII_CORRELATION_KEY'),
];
