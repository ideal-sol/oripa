<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2FixedPercentage;
use PHPUnit\Framework\TestCase;

final class FixedPercentageTest extends TestCase
{
    public function test_ten_decimal_precision_and_closed_integer_intervals_are_exact(): void
    {
        self::assertSame(1, V2FixedPercentage::parse('0.0000000001'));
        self::assertSame(999999999999, V2FixedPercentage::parse('99.9999999999'));
        self::assertSame(V2FixedPercentage::SCALE, V2FixedPercentage::parse('100.0000000000'));
        self::assertSame('0.0000000001', V2FixedPercentage::format(1));
        self::assertSame('99.9999999999', V2FixedPercentage::format(999999999999));
        self::assertSame('100', V2FixedPercentage::format(V2FixedPercentage::SCALE));
        $rates = [10 => 1, 20 => 999999999998, 30 => 1];
        self::assertSame(10, V2FixedPercentage::select($rates, 1));
        self::assertSame(20, V2FixedPercentage::select($rates, 2));
        self::assertSame(20, V2FixedPercentage::select($rates, 999999999999));
        self::assertSame(30, V2FixedPercentage::select($rates, V2FixedPercentage::SCALE));
    }

    public function test_invalid_precision_float_notation_zero_and_inexact_totals_fail_closed(): void
    {
        foreach ([0.1, 100, null, '', '0', '-1', '+1', '1e-10', '1.00000000001', '100.0000000001', '01', '1x5', ' 1', '1 '] as $value) {
            try {
                V2FixedPercentage::parse($value);
                self::fail('Invalid rate must be rejected: '.json_encode($value));
            } catch (V2CatalogException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
        foreach ([[], [0, V2FixedPercentage::SCALE], [1, V2FixedPercentage::SCALE], [1, V2FixedPercentage::SCALE - 2]] as $rates) {
            try {
                V2FixedPercentage::assertTotal($rates);
                self::fail('Invalid total must be rejected.');
            } catch (V2CatalogException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }
}
