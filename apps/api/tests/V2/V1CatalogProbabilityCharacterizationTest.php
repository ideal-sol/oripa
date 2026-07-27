<?php

namespace Tests\V2;

use Tests\TestCase;

final class V1CatalogProbabilityCharacterizationTest extends TestCase
{
    public function test_v1_catalog_probability_behavior_remains_a_reference_only(): void
    {
        $resource = file_get_contents(app_path('Http/Resources/GachaDetailResource.php'));
        $validator = file_get_contents(
            app_path('Domain/Probability/Services/ProbabilityValidator.php')
        );
        $characterization = file_get_contents(
            base_path('tests/Feature/GachaApiTest.php')
        );
        self::assertIsString($resource);
        self::assertIsString($validator);
        self::assertIsString($characterization);

        foreach ([
            'current_stage',
            'next_stage',
            'minimum_guarantee',
            'stage_total_ppm',
            'remaining_count',
            'ranks',
            'prizes',
        ] as $behavior) {
            self::assertStringContainsString($behavior, $resource);
        }
        self::assertStringContainsString('TOTAL_PPM = 1_000_000', $validator);
        self::assertStringContainsString('test_show_returns_ranks_prizes', $characterization);
        self::assertStringContainsString('970000', $characterization);
        self::assertStringContainsString('930000', $characterization);
    }
}
