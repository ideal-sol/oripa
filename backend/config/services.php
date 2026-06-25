<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => env('MAILGUN_SCHEME', 'https'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],
    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'admin_webhook_url' => env('DISCORD_ADMIN_WEBHOOK_URL', env('DISCORD_WEBHOOK_URL')),
        'daily_report_timezone' => env('DISCORD_DAILY_REPORT_TIMEZONE', env('APP_TIMEZONE', 'Asia/Tokyo')),
    ],
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'verification_code_ttl_minutes' => (int) env('SMS_VERIFICATION_CODE_TTL_MINUTES', 10),
        'verification_code_max_attempts' => (int) env('SMS_VERIFICATION_CODE_MAX_ATTEMPTS', 5),
    ],
];
