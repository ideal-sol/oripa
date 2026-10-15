<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2RankVideoService;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use PHPUnit\Framework\TestCase;

final class RankVideoWeightTest extends TestCase
{
    public function test_integer_boundaries_and_real_random_distribution(): void
    {
        $videos = new V2RankVideoService;
        $items = [['id' => 1, 'weight_units' => 8000], ['id' => 2, 'weight_units' => 2000]];
        foreach ([1 => 1, 8000 => 1, 8001 => 2, 10000 => 2] as $ticket => $expected) {
            self::assertSame($expected, $videos->select($items, new V2CryptographicRandomSource(
                fn (int $minimum, int $maximum): int => $ticket
            ))['id']);
        }
        $first = 0;
        $random = new V2CryptographicRandomSource;
        for ($sample = 0; $sample < 10000; $sample++) {
            $first += $videos->select($items, $random)['id'] === 1 ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(7700, $first, '80% ±3 percentage points, more than seven standard deviations.');
        self::assertLessThanOrEqual(8300, $first, '80% ±3 percentage points, more than seven standard deviations.');
        self::assertSame(1, $videos->select([['id' => 1, 'weight_units' => 10000]], new V2CryptographicRandomSource(
            fn (): int => throw new \LogicException('Single videos must not consume randomness.')
        ))['id']);
    }

    public function test_normalization_rejects_precision_totals_duplicates_and_cardinality(): void
    {
        $videos = new V2RankVideoService;
        $first = ['video_asset_id' => '0198a001-0000-7000-8000-000000000006', 'weight_percent' => '100'];
        self::assertSame('100.00', $videos->normalize([$first])[0]['weight_percent']);
        foreach ([[], array_fill(0, 11, $first), [$first, $first],
            [[...$first, 'weight_percent' => '99.99']], [[...$first, 'weight_percent' => '100.000']],
            [[...$first, 'weight_percent' => '0']], [[...$first, 'weight_percent' => 100]],
            [[...$first, 'weight_percent' => '1e2']], [[...$first, 'extra' => true]],
        ] as $invalid) {
            try {
                $videos->normalize($invalid);
                self::fail('Invalid video set must fail.');
            } catch (V2CatalogException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }
}
