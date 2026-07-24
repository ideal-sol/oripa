<?php

namespace App\Domain\Identity\Services;

use Normalizer;

final class V2EmailNormalizer
{
    public function normalize(string $email): string
    {
        $value = trim($email);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        return mb_strtolower($value, 'UTF-8');
    }
}
