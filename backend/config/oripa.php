<?php

return [
    'free_point_expiration_days' => (int) env('ORIPA_FREE_POINT_EXPIRATION_DAYS', 180),
    'payment' => [
        'mock_webhook_secret' => env('ORIPA_MOCK_PAYMENT_WEBHOOK_SECRET', 'local-mock-payment-secret'),
    ],
];
