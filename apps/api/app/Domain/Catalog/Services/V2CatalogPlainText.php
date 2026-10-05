<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use Normalizer;

final class V2CatalogPlainText
{
    public static function normalize(mixed $value, int $minimum, int $maximum): string
    {
        $normalized = is_string($value) ? Normalizer::normalize($value, Normalizer::FORM_C) : false;
        if (! is_string($normalized)
            || mb_strlen($normalized) < $minimum
            || mb_strlen($normalized) > $maximum
            || preg_match('/[<>]/u', $normalized) === 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized) === 1) {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The Catalog mutation request is invalid.');
        }

        return $normalized;
    }
}
