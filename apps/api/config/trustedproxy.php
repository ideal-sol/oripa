<?php

// Comma-separated exact ingress IPs, configured privately per installation.
// Do not use hostnames, wildcard trust, REMOTE_ADDR, or shared-network CIDRs.
$configured = env('V2_TRUSTED_PROXY_IPS', '');
if (! is_string($configured)) {
    throw new InvalidArgumentException('V2_TRUSTED_PROXY_IPS must be an explicit IP list.');
}
$proxies = trim($configured) === '' ? [] : array_map('trim', explode(',', $configured));
foreach ($proxies as $proxy) {
    if (filter_var($proxy, FILTER_VALIDATE_IP) === false) {
        throw new InvalidArgumentException('V2_TRUSTED_PROXY_IPS must contain only explicit IP addresses.');
    }
}

return ['proxies' => array_values(array_unique($proxies))];
