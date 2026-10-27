<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Support\Str;

final class V2TechcoinIdentity
{
    public static function cookie(string $paymentId): string
    {
        if (! Str::isUuid($paymentId)) throw new \InvalidArgumentException('Invalid payment identifier.');
        return str_replace('-', '', strtolower($paymentId));
    }

    public static function paymentId(mixed $cookie): ?string
    {
        if (! is_string($cookie) || ! preg_match('/\A[0-9a-f]{32}\z/', $cookie)) return null;
        return substr($cookie, 0, 8).'-'.substr($cookie, 8, 4).'-'.substr($cookie, 12, 4).'-'.substr($cookie, 16, 4).'-'.substr($cookie, 20);
    }

    public static function sendid(string $userId): string
    {
        if (! Str::isUuid($userId)) throw new \InvalidArgumentException('Invalid member identifier.');
        // Injective base32hex encoding of all 128 UUID bits; bounded bit buffer, no bigint/float conversion.
        $bytes = hex2bin(str_replace('-', '', strtolower($userId)));
        $alphabet = '0123456789abcdefghijklmnopqrstuv';
        $buffer = 0; $bits = 0; $result = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) $result .= $alphabet[($buffer << (5 - $bits)) & 31];
        return $result;
    }
}
