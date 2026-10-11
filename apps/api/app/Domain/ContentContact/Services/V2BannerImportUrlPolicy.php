<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use SensitiveParameter;

final class V2BannerImportUrlPolicy
{
    public function validate(#[SensitiveParameter] string $url): string
    {
        $parts = parse_url($url);
        if (strlen($url) > 8192 || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->reject('IMAGE_URL_INVALID');
        }
        $host = strtolower($parts['host'] ?? '');
        $allowed = config('v2_banner_import.image_hosts', []);
        if (! is_array($allowed) || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)
            || ! in_array($host, $allowed, true)) {
            $this->reject('IMAGE_HOST_NOT_ALLOWED');
        }
        return $host;
    }

    public function assertPublicIp(string $address): void
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            $this->reject('IMAGE_ADDRESS_NOT_ALLOWED');
        }
        if (strlen($packed) === 4) {
            $ranges = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        } else {
            if (! $this->within($packed, '2000::/3')) {
                $this->reject('IMAGE_ADDRESS_NOT_ALLOWED');
            }
            $ranges = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        }
        foreach ($ranges as $range) {
            if ($this->within($packed, $range)) {
                $this->reject('IMAGE_ADDRESS_NOT_ALLOWED');
            }
        }
    }

    private function within(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $networkBytes = inet_pton($network);
        if (strlen($packed) !== strlen($networkBytes)) {
            return false;
        }
        $whole = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;
        return substr($packed, 0, $whole) === substr($networkBytes, 0, $whole)
            && ($remaining === 0 || (ord($packed[$whole]) >> (8 - $remaining)) === (ord($networkBytes[$whole]) >> (8 - $remaining)));
    }

    private function reject(string $code): never
    {
        throw new V2ContentContactException($code, 422, '画像URLを利用できません。');
    }
}
