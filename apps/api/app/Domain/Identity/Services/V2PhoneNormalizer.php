<?php

namespace App\Domain\Identity\Services;

use Normalizer;

final class V2PhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $value = trim($phone);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }
        if (! preg_match('/\A(?:070|080|090)(?:[0-9]{8}|-[0-9]{4}-[0-9]{4})\z/', $value)) {
            throw new \InvalidArgumentException('Phone numbers must be Japanese mobile numbers.');
        }

        return '+81'.substr(str_replace('-', '', $value), 1);
    }

    public function toDomestic(string $canonicalPhone): string
    {
        if (! preg_match('/\A\+81(70|80|90)([0-9]{8})\z/', $canonicalPhone, $matches)) {
            throw new \InvalidArgumentException('Canonical phone number is invalid.');
        }

        return '0'.$matches[1].$matches[2];
    }

    public function mask(string $phone): string
    {
        return substr($phone, 0, min(4, strlen($phone) - 4))
            .str_repeat('*', max(0, strlen($phone) - 8))
            .substr($phone, -4);
    }
}
