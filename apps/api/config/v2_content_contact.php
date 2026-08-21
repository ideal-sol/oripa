<?php

return [
    'cursor_page_size' => 20,
    'cursor_maximum' => 100,
    'public_cache_seconds' => 60,
    'contact_hmac_key' => env('V2_CONTACT_HMAC_KEY', env('V2_PII_CORRELATION_KEY')),
    'contact_previous_hmac_keys' => array_values(array_filter(
        explode(',', (string) env('V2_PII_CORRELATION_PREVIOUS_KEYS', ''))
    )),
    'contact_retention_days' => 365,
    'contact_body_max_bytes' => 20_000,
    'legal_slugs' => [
        'terms',
        'privacy',
        'commercial-law',
        'point-terms',
    ],
];
