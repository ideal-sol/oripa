<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;

final class V2FixedPercentage
{
    public const SCALE = 1_000_000_000_000;

    private const PERCENT_UNIT = 10_000_000_000;

    public static function parse(mixed $percentage): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new \LogicException('Login Draw requires 64-bit integers.');
        }
        if (! is_string($percentage) || ! preg_match('/\A(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,10}))?\z/', $percentage, $parts)) {
            throw self::invalid();
        }
        $units = (int) $parts[1] * self::PERCENT_UNIT
            + (int) str_pad($parts[2] ?? '', 10, '0');
        if ($units < 1 || $units > self::SCALE) {
            throw self::invalid();
        }

        return $units;
    }

    public static function format(int $units): string
    {
        if ($units < 1 || $units > self::SCALE) {
            throw self::invalid();
        }
        $whole = intdiv($units, self::PERCENT_UNIT);
        $fraction = rtrim(str_pad((string) ($units % self::PERCENT_UNIT), 10, '0', STR_PAD_LEFT), '0');

        return (string) $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function assertTotal(array $units): void
    {
        $total = 0;
        foreach ($units as $rate) {
            if (! is_int($rate) || $rate < 1 || $rate > self::SCALE - $total) {
                throw self::invalid();
            }
            $total += $rate;
        }
        if ($total !== self::SCALE) {
            throw self::invalid();
        }
    }

    public static function select(array $rates, int $ticket): int
    {
        self::assertTotal(array_values($rates));
        if ($ticket < 1 || $ticket > self::SCALE) {
            throw self::invalid();
        }
        $cumulative = 0;
        foreach ($rates as $relationId => $units) {
            $cumulative += $units;
            if ($ticket <= $cumulative) {
                return (int) $relationId;
            }
        }
        throw self::invalid();
    }

    private static function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'Fixed percentages require positive values with at most ten decimal places and an exact total of 100.');
    }
}
