<?php

return [
    'api_base_url' => env('V2_UNIVAPAY_API_BASE_URL', 'https://api.univapay.com'),
    'store_id' => env('V2_UNIVAPAY_STORE_ID'),
    'form_app_token' => env('V2_UNIVAPAY_FORM_APP_TOKEN'),
    'api_app_token' => env('V2_UNIVAPAY_API_APP_TOKEN'),
    'api_secret' => env('V2_UNIVAPAY_API_SECRET'),
    'mode' => env('V2_UNIVAPAY_MODE'),
    'webhook_path_token' => env('V2_UNIVAPAY_WEBHOOK_PATH_TOKEN'),
    'webhook_auth_token' => env('V2_UNIVAPAY_WEBHOOK_AUTH_TOKEN'),
    'notify_allowed_ips' => env('V2_UNIVAPAY_NOTIFY_ALLOWED_IPS', ''),
    // Source delivery never starts an operational worker or scheduler.
    'worker_enabled' => env('V2_UNIVAPAY_WORKER_ENABLED', false),
];
