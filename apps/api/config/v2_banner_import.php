<?php

return [
    'enabled' => env('V2_BANNER_IMPORT_ENABLED', false) === true,
    'image_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('V2_BANNER_IMPORT_IMAGE_HOSTS', ''))))),
    'url_ttl_seconds' => 1800,
    'storage_generation' => env('V2_BANNER_IMPORT_STORAGE_GENERATION'),
    'header_aliases' => [],
];
