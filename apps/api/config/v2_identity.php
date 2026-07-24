<?php

return [
    'password' => [
        'minimum_length' => 8,
        'maximum_length' => 128,
        'algorithm' => 'argon2id',
        'memory_cost_kib' => 65536,
        'time_cost' => 3,
        'threads' => 1,
    ],

    'sessions' => [
        'user' => [
            'table' => 'user_sessions',
            'cookie' => '__Host-oripa_user_session',
            'idle_minutes' => 60,
            'absolute_minutes' => 1440,
            'same_site' => 'lax',
            'remember' => true,
        ],
        'admin' => [
            'table' => 'admin_sessions',
            'cookie' => '__Host-oripa_admin_session',
            'idle_minutes' => 15,
            'absolute_minutes' => 480,
            'same_site' => 'strict',
            'remember' => false,
        ],
    ],

    'cookie_security' => [
        'secure' => true,
        'http_only' => true,
        'host_only' => true,
        'path' => '/',
    ],
];
