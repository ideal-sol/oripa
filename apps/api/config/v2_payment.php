<?php

return [
    'methods_enabled' => env('V2_PAYMENT_METHODS_ENABLED', 'credit_card,paypay,konbini,virtual_account'),
    'credit_card_provider' => env('V2_PAYMENT_CREDIT_CARD_PROVIDER', 'fincode'),
    'saved_cards_enabled' => env('V2_PAYMENT_SAVED_CARDS_ENABLED', true),
    'event_retention_days' => env('V2_PAYMENT_EVENT_RETENTION_DAYS', 365),
    'progress_purge_enabled' => env('V2_PAYMENT_PROGRESS_PURGE_ENABLED', false),
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
