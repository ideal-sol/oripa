<?php

return [
    'secret_key' => env('V2_STRIPE_SECRET_KEY'),
    'publishable_key' => env('V2_STRIPE_PUBLISHABLE_KEY'),
    'webhook_secret' => env('V2_STRIPE_WEBHOOK_SECRET'),
    'mode' => env('V2_STRIPE_MODE'),
    'checkout_expires_minutes' => env('V2_STRIPE_CHECKOUT_EXPIRES_MINUTES', 35),
    'create_timeout_seconds' => 10,
    'start_limit_per_hour' => env('V2_STRIPE_START_LIMIT_PER_HOUR', 10),
    'worker_enabled' => env('V2_STRIPE_WORKER_ENABLED', false),
];
