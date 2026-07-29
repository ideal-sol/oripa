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

    public function test_v1_catalog_master_mutation_is_create_update_only(): void
    {
        $routes = file_get_contents(base_path('routes/admin.php'));
        $category = file_get_contents(
            app_path('Http/Controllers/Admin/Gacha/AdminGachaCategoryController.php')
        );
        $tag = file_get_contents(
            app_path('Http/Controllers/Admin/Gacha/AdminGachaTagController.php')
        );
        $rank = file_get_contents(
            app_path('Http/Controllers/Admin/Gacha/AdminGachaRankController.php')
        );
        foreach ([$routes, $category, $tag, $rank] as $source) {
            self::assertIsString($source);
        }
        self::assertStringContainsString(
            "Route::post('/gacha-categories'",
            $routes
        );
        self::assertStringContainsString(
            "Route::put('/gacha-categories/{category}'",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/gacha-tags'",
            $routes
        );
        self::assertStringContainsString(
            "Route::put('/gacha-tags/{tag}'",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/gachas/{gacha}/ranks'",
            $routes
        );
        self::assertStringContainsString(
            "Route::put('/gacha-ranks/{rank}'",
            $routes
        );
        self::assertStringNotContainsString(
            "Route::delete('/gacha-categories",
            $routes
        );
        self::assertStringNotContainsString(
            "Route::delete('/gacha-tags",
            $routes
        );
        self::assertStringNotContainsString(
            "Route::delete('/gacha-ranks",
            $routes
        );
        self::assertStringContainsString("'sort_order'", $category);
        self::assertStringContainsString("'sort_order'", $tag);
        self::assertStringContainsString('syncRankAssets', $rank);
    }
}
