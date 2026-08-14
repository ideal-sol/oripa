<?php

return [
    'currency' => 'JPY',
    'paid_point_per_jpy' => 1,
    // The canonical baseline requires expiry but does not define its duration.
    'purchase_bonus_expiry_days' => env('V2_PURCHASE_BONUS_EXPIRY_DAYS'),
    'mock_driver' => env('PAYMENT_DRIVER') === 'mock',
    'provider_call_in_transaction' => false,
    'refund_mode' => 'single_full_unused',
    'chargeback_reversal' => 'manual_review',
    'point_product_collection_cache_control' => 'public, max-age=60, stale-while-revalidate=300',
];
