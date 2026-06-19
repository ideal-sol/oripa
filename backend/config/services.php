<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => env('MAILGUN_SCHEME', 'https'),
    ],
    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'admin_webhook_url' => env('DISCORD_ADMIN_WEBHOOK_URL', env('DISCORD_WEBHOOK_URL')),
        'daily_report_timezone' => env('DISCORD_DAILY_REPORT_TIMEZONE', env('APP_TIMEZONE', 'Asia/Tokyo')),
    ],
];
