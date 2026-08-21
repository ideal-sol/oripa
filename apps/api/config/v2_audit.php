<?php

$activeVersion = env('V2_AUDIT_HMAC_KEY_VERSION', 'v1');
$keys = [$activeVersion => env('V2_AUDIT_HMAC_KEY')];
$previousVersion = env('V2_AUDIT_HMAC_PREVIOUS_KEY_VERSION');
$previousKey = env('V2_AUDIT_HMAC_PREVIOUS_KEY');
if (
    is_string($previousVersion)
    && $previousVersion !== ''
    && ! array_key_exists($previousVersion, $keys)
) {
    $keys[$previousVersion] = $previousKey;
}

return [
    'active_hmac_key_version' => $activeVersion,
    'hmac_keys' => $keys,
    'business_timezone' => env('V2_AUDIT_BUSINESS_TIMEZONE', 'Asia/Tokyo'),
];
