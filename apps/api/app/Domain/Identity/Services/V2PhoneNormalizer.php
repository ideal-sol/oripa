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
        $value = preg_replace('/[\x{00A0}\s().-]+/u', '', $value) ?? '';
        if (! preg_match('/\A\+[1-9][0-9]{7,14}\z/', $value)) {
            throw new \InvalidArgumentException('Phone numbers must use E.164 format.');
        }

        return $value;
    }

    public function mask(string $phone): string
    {
        return substr($phone, 0, min(4, strlen($phone) - 4))
            .str_repeat('*', max(0, strlen($phone) - 8))
            .substr($phone, -4);
    }
}
