<?php

return [
    'enabled' => env('V2_NOTION_ENABLED', false),
    'token' => env('V2_NOTION_TOKEN'),
    'data_source_id' => env('V2_NOTION_DATA_SOURCE_ID'),
    'api_version' => '2026-03-11',
    'properties' => [
        'external_id' => env('V2_NOTION_PROPERTY_EXTERNAL_ID', '管理ID'),
        'on_hand' => env('V2_NOTION_PROPERTY_ON_HAND', '手持ち枚数'),
        'available' => env('V2_NOTION_PROPERTY_AVAILABLE', '空き在庫'),
        'required_quantity' => env('V2_NOTION_PROPERTY_REQUIRED', '確保必要数'),
        'allocation' => env('V2_NOTION_PROPERTY_ALLOCATION', '企画割当'),
        'stored' => env('V2_NOTION_PROPERTY_STORED', '当選保管中'),
        'shipped' => env('V2_NOTION_PROPERTY_SHIPPED'),
        'synced_at' => env('V2_NOTION_PROPERTY_SYNCED_AT'),
    ],
    'sync_interval_minutes' => (int) env('V2_NOTION_SYNC_INTERVAL_MINUTES', 15),
    'requests_per_second' => (float) env('V2_NOTION_REQUESTS_PER_SECOND', 3),
    'max_retries' => (int) env('V2_NOTION_MAX_RETRIES', 5),
    'timeout_seconds' => (int) env('V2_NOTION_TIMEOUT_SECONDS', 20),
    'lease_seconds' => (int) env('V2_NOTION_LEASE_SECONDS', 120),
    'failure_backoff_seconds' => (int) env('V2_NOTION_FAILURE_BACKOFF_SECONDS', 300),
];
