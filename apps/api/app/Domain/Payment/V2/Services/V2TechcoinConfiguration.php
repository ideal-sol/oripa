<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use Symfony\Component\HttpFoundation\IpUtils;

final class V2TechcoinConfiguration
{
    public const ENDPOINT = 'https://secure.tc-site.com/access';

    public function available(): bool
    {
        return is_string(config('v2_techcoin.client_ip'))
            && preg_match('/\A[0-9]{10}\z/', config('v2_techcoin.client_ip')) === 1
            && config('v2_techcoin.endpoint') === self::ENDPOINT
            && config('v2_techcoin.payment_code') === '10055'
            && $this->tokenValid()
            && $this->allowedIps() !== null
            && in_array(config('v2_techcoin.mode'), ['test', 'live'], true);
    }

    public function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.');
        }
    }

    public function authenticToken(string $token): bool
    {
        return $this->tokenValid() && hash_equals(config('v2_techcoin.kickback_path_token'), $token);
    }

    public function acceptsIp(?string $ip): bool
    {
        $allowed = $this->allowedIps();
        return $allowed === [] || ($allowed !== null && is_string($ip) && IpUtils::checkIp($ip, $allowed));
    }

    private function tokenValid(): bool
    {
        $token = config('v2_techcoin.kickback_path_token');
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', $token) === 1;
    }

    private function allowedIps(): ?array
    {
        $setting = config('v2_techcoin.kickback_allowed_ips');
        if (! is_string($setting)) return null;
        if (trim($setting) === '') return [];
        $addresses = array_map('trim', explode(',', $setting));
        foreach ($addresses as $address) {
            $parts = explode('/', $address);
            if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) return null;
            if (isset($parts[1]) && (! preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $parts[1])
                || (int) $parts[1] > (str_contains($parts[0], ':') ? 128 : 32))) return null;
        }
        return $addresses;
    }
}
