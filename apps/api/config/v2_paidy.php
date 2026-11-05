<?php

return [
    'public_key' => env('V2_PAIDY_PUBLIC_KEY'),
    'secret_key' => env('V2_PAIDY_SECRET_KEY'),
    'api_base_url' => env('V2_PAIDY_API_BASE_URL', 'https://api.paidy.com'),
    'api_version' => env('V2_PAIDY_API_VERSION', '2018-04-10'),
    'store_name' => env('V2_PAIDY_STORE_NAME'),
    'webhook_path_token' => env('V2_PAIDY_WEBHOOK_PATH_TOKEN'),
    // Paidy documents pk_/sk_, not a reliable test/live suffix. Never infer live.
    'mode' => env('V2_PAIDY_MODE'),
    'worker_enabled' => env('V2_PAIDY_WORKER_ENABLED', false),
    'delivery_locn_type' => env('V2_PAIDY_DELIVERY_LOCN_TYPE', ''),
    'order_item_categories' => env('V2_PAIDY_ORDER_ITEM_CATEGORIES', 'コイン'),
    'authorization_grace_minutes' => env('V2_PAIDY_AUTHORIZATION_GRACE_MINUTES', 30),
];
