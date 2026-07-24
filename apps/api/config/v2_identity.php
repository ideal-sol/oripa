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
            'csrf_cookie' => '__Host-oripa_user_xsrf',
            'idle_minutes' => 60,
            'absolute_minutes' => 1440,
            'same_site' => 'lax',
            'remember' => true,
        ],
        'admin' => [
            'table' => 'admin_sessions',
            'cookie' => '__Host-oripa_admin_session',
            'csrf_cookie' => '__Host-oripa_admin_xsrf',
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

    'origins' => [
        'user' => env('V2_PUBLIC_ORIGIN'),
        'admin' => env('V2_ADMIN_ORIGIN'),
    ],

    'email_verification' => [
        'ttl_minutes' => 60,
        'redirect_allowlist' => ['/'],
    ],

    'transactions' => [
        'store' => env('V2_AUTH_TRANSACTION_STORE', 'redis'),
        'admin_preauth_ttl_seconds' => 300,
        'webauthn_ttl_seconds' => 300,
        'totp_enrollment_ttl_seconds' => 300,
    ],

    'webauthn' => [
        'rp_name' => env('V2_WEBAUTHN_RP_NAME', 'Oripa Admin'),
        'rp_id' => env('V2_WEBAUTHN_RP_ID'),
        'origin' => env('V2_WEBAUTHN_ORIGIN'),
        'attestation' => 'none',
        'user_verification' => 'required',
    ],

    'rate_limits' => [
        'user_login_failure' => [5, 900],
        'user_login_ip' => [30, 3600],
        'admin_login_failure' => [5, 900],
        'admin_login_ip' => [20, 3600],
        'mfa_verify' => [5, 300],
        'register_ip' => [5, 3600],
        'register_email' => [3, 3600],
        'verification_resend_hour' => [3, 3600],
        'verification_resend_day' => [10, 86400],
    ],

    'audit_persistence_ready' => false,
];
