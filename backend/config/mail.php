<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'mailpit'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],
        'mailgun' => [
            'transport' => 'mailgun',
        ],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.local'),
        'name' => env('MAIL_FROM_NAME', 'Oripa Local'),
    ],
];
