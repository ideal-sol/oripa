<?php

namespace App\Domain\Line\Services;

final class V2LineProfileName
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) return null;
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (! is_string($value)) return null;
        $value = trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value));
        return $value === '' || mb_strlen($value) > 255 ? null : $value;
    }

    public static function forUser(?object $user): string
    {
        return self::normalize($user?->display_name) ?? self::normalize($user?->full_name) ?? 'お客様';
    }
}
