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
            'idle_minutes' => 720,
            'absolute_minutes' => 1440,
            'same_site' => 'lax',
            'remember' => true,
        ],
        'admin' => [
            'table' => 'admin_sessions',
            'cookie' => '__Host-oripa_admin_session',
            'csrf_cookie' => '__Host-oripa_admin_xsrf',
            'idle_minutes' => 360,
            'absolute_minutes' => 720,
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
        'redirect_allowlist' => ['/', '/mypage'],
    ],

    'password_reset' => [
        'ttl_minutes' => 60,
        'maximum_attempts' => 5,
        'redirect_allowlist' => ['/'],
    ],

    'email_change' => [
        'ttl_minutes' => 60,
        'maximum_attempts' => 5,
        'redirect_allowlist' => ['/'],
    ],

    'sms_verification' => [
        'ttl_minutes' => 5,
        'maximum_attempts' => 5,
        'code_digits' => 6,
        'phone_hmac_key' => env('V2_PII_CORRELATION_KEY'),
        'phone_hmac_previous_keys' => array_values(array_filter(
            explode(',', (string) env('V2_PII_CORRELATION_PREVIOUS_KEYS', ''))
        )),
    ],

    'user_fresh_auth' => [
        'minutes' => 10,
    ],

    'external_identity' => [
        'transaction_ttl_minutes' => 10,
        'clock_skew_seconds' => 60,
        'recent_auth_minutes' => 5,
        'transaction_cookie' => '__Host-oripa_oidc_transaction',
        'return_path_allowlist' => ['/'],
        'google' => [
            'client_id' => env('V2_GOOGLE_OIDC_CLIENT_ID'),
            'client_secret' => env('V2_GOOGLE_OIDC_CLIENT_SECRET'),
            'redirect_uri' => env('V2_GOOGLE_OIDC_REDIRECT_URI'),
        ],
        'line' => [
            'client_id' => env('LINE_LOGIN_CHANNEL_ID'),
            'client_secret' => env('LINE_LOGIN_CHANNEL_SECRET'),
            'redirect_uri' => env('V2_LINE_LOGIN_REDIRECT_URI'),
            'email_scope_enabled' => (bool) env(
                'V2_LINE_LOGIN_EMAIL_SCOPE_ENABLED',
                false
            ),
        ],
    ],

    'transactions' => [
        'store' => env('V2_AUTH_TRANSACTION_STORE', 'redis'),
        'admin_preauth_ttl_seconds' => 300,
        'webauthn_ttl_seconds' => 300,
        'totp_enrollment_ttl_seconds' => 300,
    ],

    'fresh_mfa' => [
        'minutes' => 5,
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
        'critical_admin_mutation' => [10, 600],
        'financial_export' => [5, 3600],
        'register_ip' => [5, 3600],
        'register_email' => [3, 3600],
        'verification_resend_hour' => [3, 3600],
        'verification_resend_day' => [10, 86400],
        'draw_mutation' => [20, 60],
        'contact_ip' => [5, 3600],
        'contact_email' => [3, 3600],
        'password_reset_account' => [3, 3600],
        'password_reset_ip' => [10, 3600],
        'password_reset_confirm' => [5, 1800],
        'email_change_hour' => [3, 3600],
        'email_change_day' => [10, 86400],
        'email_change_confirm' => [5, 1800],
        'password_change' => [5, 900],
        'sms_phone_hour' => [3, 3600],
        'sms_phone_day' => [10, 86400],
        'sms_ip' => [5, 3600],
        'sms_verify' => [5, 300],
        'oidc_login_start' => [10, 600],
        'oidc_callback_failure' => [5, 600],
        'oidc_link_start' => [5, 600],
        'user_password_reauthentication' => [5, 300],
        'oidc_unlink' => [5, 3600],
    ],

    'audit_persistence_ready' => true,
];
