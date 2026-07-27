<?php

return [
    'allowed_counts' => [1, 5, 10, 100, 1000],
    'bulk_threshold' => 100,
    'insert_chunk_size' => 250,
    'idempotency_retention_hours' => 24,
    'point_back_expiry_days' => 180,
    'high_rank_sort_order_max' => 20,
    'high_rank_result_limit' => 20,
    'response_cache_control' => 'no-store',
];
