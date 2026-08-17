<?php

namespace Tests\V2;

use Tests\TestCase;

final class V1DrawCharacterizationTest extends TestCase
{
    public function test_approved_v1_bulk_draw_characterization_is_fixed_for_v2(): void
    {
        $evidence = json_decode(
            file_get_contents(__DIR__.'/Fixtures/v1-draw-characterization.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'fcaf0b9bb320aa479738e9be6d7b8465114f6226',
            $evidence['source_commit']
        );
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $evidence['draw_service_sha256']
        );
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $evidence['draw_service_test_sha256']
        );
        self::assertSame([1, 5, 10, 100, 1000], $evidence['allowed_counts']);
        self::assertNotContains(false, array_values($evidence['behavior']), true);
        self::assertSame(250, $evidence['behavior']['chunked_insert_size']);

        $service = file_get_contents(
            app_path('Domain/Draw/Services/V2DrawService.php')
        );
        $config = require config_path('v2_draw.php');
        self::assertIsString($service);
        self::assertSame($evidence['allowed_counts'], $config['allowed_counts']);
        foreach ([
            'V2CryptographicRandomSource',
            'remainingInventory',
            'pickInventory',
            'persistResults',
            'persistUserPrizes',
            'idempotent_replay',
        ] as $boundary) {
            self::assertStringContainsString($boundary, $service);
        }
    }
}
