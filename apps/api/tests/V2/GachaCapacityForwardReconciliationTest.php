<?php

namespace Tests\V2;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class GachaCapacityForwardReconciliationTest extends TestCase
{
    private const MIGRATION = '2026_09_12_000060_reconcile_preview_gacha_capacity.php';

    public function test_non_preview_install_is_a_guard_preserving_no_op(): void
    {
        $publishedBefore = $this->guardDefinition('v2_catalog_protect_published()');
        $draftBefore = $this->guardDefinition('v2_catalog_protect_gacha_draft_mutation()');

        $migration = require database_path('migrations-v2/'.self::MIGRATION);
        $migration->up();

        self::assertSame(
            $publishedBefore,
            $this->guardDefinition('v2_catalog_protect_published()')
        );
        self::assertSame(
            $draftBefore,
            $this->guardDefinition('v2_catalog_protect_gacha_draft_mutation()')
        );
    }

    public function test_down_fails_closed_without_rewriting_capacity(): void
    {
        $migration = require database_path('migrations-v2/'.self::MIGRATION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be reversed');
        $migration->down();
    }

    private function guardDefinition(string $signature): string
    {
        return DB::selectOne(
            'SELECT pg_get_functiondef(CAST(? AS regprocedure)) AS definition',
            [$signature]
        )->definition;
    }
}
