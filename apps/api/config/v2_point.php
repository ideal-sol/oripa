<?php

return [
    'business_timezone' => 'Asia/Tokyo',
    'transaction_retry' => [
        'max_attempts' => 3,
        'sqlstates' => ['40001', '40P01'],
    ],
    'consumption_order' => [
        'all_lots' => ['expire_at_nulls_last', 'granted_at', 'id'],
    ],
    'expiry_days' => 180,
    'paid_grant' => [
        'normal_source' => 'succeeded_payment_only',
        'enabled' => false,
    ],
];
