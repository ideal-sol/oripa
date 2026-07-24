<?php

return [
    'business_timezone' => 'Asia/Tokyo',
    'transaction_retry' => [
        'max_attempts' => 3,
        'sqlstates' => ['40001', '40P01'],
    ],
    'consumption_order' => [
        'free' => ['expire_at', 'granted_at', 'id'],
        'paid' => ['granted_at', 'id'],
    ],
    'paid_grant' => [
        'normal_source' => 'succeeded_payment_only',
        'enabled' => false,
    ],
];
