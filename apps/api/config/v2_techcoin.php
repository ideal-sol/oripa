<?php

return [
    'client_ip' => env('V2_TECHCOIN_CLIENT_IP', ''),
    'endpoint' => env('V2_TECHCOIN_ENDPOINT', 'https://secure.tc-site.com/access'),
    'payment_code' => env('V2_TECHCOIN_NETRIDECASH_PAYMENT_CODE', '10055'),
    'kickback_path_token' => env('V2_TECHCOIN_KICKBACK_PATH_TOKEN', ''),
    'kickback_allowed_ips' => env('V2_TECHCOIN_KICKBACK_ALLOWED_IPS', ''),
    // No provider test/live switch is documented. Explicit accounting classification is required.
    'mode' => env('V2_TECHCOIN_MODE', ''),
];
