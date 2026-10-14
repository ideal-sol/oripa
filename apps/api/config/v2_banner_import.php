<?php

return [
    'enabled' => env('V2_BANNER_IMPORT_ENABLED', false) === true,
    'url_ttl_seconds' => 1800,
    'storage_generation' => env('V2_BANNER_IMPORT_STORAGE_GENERATION'),
    'header_aliases' => [],
];
