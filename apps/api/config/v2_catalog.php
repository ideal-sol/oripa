<?php

return [
    'timezone' => 'Asia/Tokyo',
    'default_page_size' => 20,
    'maximum_page_size' => 100,
    'collection_cache_control' => 'public, max-age=60, stale-while-revalidate=300',
    'master_cache_control' => 'public, max-age=300, stale-while-revalidate=600',
    'fixture_import_tool_version' => '2.0.0-alpha.1',
    'mutation' => [
        'rate_limit' => [30, 600],
        'maximum_attempts' => 3,
    ],
];
