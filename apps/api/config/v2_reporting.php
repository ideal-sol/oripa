<?php

return [
    'business_timezone' => 'Asia/Tokyo',
    'query_version' => 'v1',
    'pagination' => [
        'default' => 50,
        'maximum' => 100,
    ],
    'streaming_max_rows' => 10000,
    'async_row_threshold' => 10001,
    'export_disk' => env('V2_EXPORT_DISK', 'local'),
    'private_prefix' => 'v2/private/exports',
    'signed_url_minutes' => 5,
    'job_expiry_hours' => 24,
    'worker_lease_seconds' => 120,
    'worker_max_attempts' => 3,
    'worker_claim_size' => 5,
];
