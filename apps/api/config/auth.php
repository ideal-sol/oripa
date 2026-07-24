<?php

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'v2_user' => [
            'driver' => 'v2_realm_session',
            'provider' => 'v2_user',
            'realm' => 'user',
        ],
        'v2_admin' => [
            'driver' => 'v2_realm_session',
            'provider' => 'v2_admin',
            'realm' => 'admin',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        'v2_user' => [
            'driver' => 'eloquent',
            'model' => App\Models\V2\User::class,
        ],
        'v2_admin' => [
            'driver' => 'eloquent',
            'model' => App\Models\V2\Admin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
