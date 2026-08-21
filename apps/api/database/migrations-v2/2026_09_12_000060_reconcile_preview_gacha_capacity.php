<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    private const TARGET_GACHAS = [
        10 => 'mig062j-partial',
        11 => 'mig062m-qa-guarantee',
        12 => 'mig062m-qa-guarantee-02',
    ];

    private const TARGET_VERSIONS = [
        8 => ['gacha_id' => 10, 'status' => 'published'],
        9 => ['gacha_id' => 10, 'status' => 'draft'],
        10 => ['gacha_id' => 10, 'status' => 'draft'],
        11 => ['gacha_id' => 11, 'status' => 'published'],
        12 => ['gacha_id' => 12, 'status' => 'published'],
    ];

    private const DRAW_STATE_VERSIONS = [8 => 10, 11 => 11, 12 => 12];

    public function up(): void
    {
        if (! $this->lockExactPreviewFixture()) {
            return;
        }

        $this->assertExactPreconditions();

        $publishedGuard = $this->guardDefinition('v2_catalog_protect_published()');
        $draftGuard = $this->guardDefinition('v2_catalog_protect_gacha_draft_mutation()');

        try {
            DB::unprepared($this->withExactCapacityException($publishedGuard));
            DB::unprepared($this->withExactCapacityException($draftGuard));

            $updatedVersions = DB::update(<<<'SQL'
                UPDATE catalog_gacha_versions
                SET total_count = 18
                WHERE total_count = 9
                  AND (id, gacha_id) IN ((8, 10), (9, 10), (10, 10), (11, 11), (12, 12))
                SQL);
            if ($updatedVersions !== 5) {
                throw new RuntimeException(
                    'OPS-019 exact Gacha Version reconciliation did not update five rows.'
                );
            }

            $updatedDrawStates = DB::update(<<<'SQL'
                UPDATE gacha_draw_states
                SET total_count = 18
                WHERE total_count = 9
                  AND (gacha_version_id, gacha_id) IN ((8, 10), (11, 11), (12, 12))
                SQL);
            if ($updatedDrawStates !== 3) {
                throw new RuntimeException(
                    'OPS-019 exact Draw State reconciliation did not update three rows.'
                );
            }
        } finally {
            DB::unprepared($publishedGuard);
            DB::unprepared($draftGuard);
        }

        if ($this->guardDefinition('v2_catalog_protect_published()') !== $publishedGuard
            || $this->guardDefinition('v2_catalog_protect_gacha_draft_mutation()') !== $draftGuard) {
            throw new RuntimeException('OPS-019 Catalog guards were not restored exactly.');
        }

        $this->assertReconciledState();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'OPS-019 capacity reconciliation cannot be reversed without rewriting protected history.'
        );
    }

    private function lockExactPreviewFixture(): bool
    {
        $rows = DB::table('catalog_gachas')
            ->whereIn('code', array_values(self::TARGET_GACHAS))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code']);

        if ($rows->isEmpty()) {
            return false;
        }
        if ($rows->count() !== count(self::TARGET_GACHAS)) {
            throw new RuntimeException('OPS-019 Preview Gacha fixture is only partially present.');
        }
        foreach ($rows as $row) {
            if ((self::TARGET_GACHAS[(int) $row->id] ?? null) !== $row->code) {
                throw new RuntimeException('OPS-019 Preview Gacha fixture identity does not match.');
            }
        }

        return true;
    }

    private function assertExactPreconditions(): void
    {
        $versionIds = array_keys(self::TARGET_VERSIONS);
        $versions = DB::table('catalog_gacha_versions')
            ->whereIn('id', $versionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'gacha_id', 'status', 'total_count']);
        if ($versions->count() !== count(self::TARGET_VERSIONS)) {
            throw new RuntimeException('OPS-019 exact Gacha Version set is incomplete.');
        }

        $relations = DB::table('catalog_gacha_version_prizes')
            ->whereIn('gacha_version_id', $versionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'gacha_version_id', 'initial_inventory']);
        $relationIds = $relations->pluck('id')->all();
        $inventories = DB::table('prize_inventories')
            ->whereIn('gacha_version_prize_id', $relationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['gacha_version_prize_id', 'total_quantity']);
        $snapshotCapacity = $relations->groupBy('gacha_version_id')
            ->map(fn ($rows): int => (int) $rows->sum('initial_inventory'));
        $relationVersions = $relations->pluck('gacha_version_id', 'id');
        $operationalCapacity = $inventories
            ->groupBy(fn ($row): int => (int) $relationVersions[(int) $row->gacha_version_prize_id])
            ->map(fn ($rows): int => (int) $rows->sum('total_quantity'));

        foreach ($versions as $version) {
            $expected = self::TARGET_VERSIONS[(int) $version->id] ?? null;
            if ($expected === null
                || (int) $version->gacha_id !== $expected['gacha_id']
                || $version->status !== $expected['status']
                || (int) $version->total_count !== 9
                || (int) ($snapshotCapacity[(int) $version->id] ?? 0) !== 18
                || (int) ($operationalCapacity[(int) $version->id] ?? 0) !== 18) {
                throw new RuntimeException('OPS-019 exact capacity precondition does not match.');
            }
        }

        $drawStates = DB::table('gacha_draw_states')
            ->whereIn('gacha_version_id', array_keys(self::DRAW_STATE_VERSIONS))
            ->orderBy('gacha_version_id')
            ->lockForUpdate()
            ->get(['gacha_id', 'gacha_version_id', 'total_count']);
        if ($drawStates->count() !== count(self::DRAW_STATE_VERSIONS)) {
            throw new RuntimeException('OPS-019 exact Draw State set is incomplete.');
        }
        foreach ($drawStates as $drawState) {
            if ((self::DRAW_STATE_VERSIONS[(int) $drawState->gacha_version_id] ?? null)
                    !== (int) $drawState->gacha_id
                || (int) $drawState->total_count !== 9) {
                throw new RuntimeException('OPS-019 exact Draw State precondition does not match.');
            }
        }
    }

    private function assertReconciledState(): void
    {
        if (DB::table('catalog_gacha_versions')
            ->whereIn('id', array_keys(self::TARGET_VERSIONS))
            ->where('total_count', '<>', 18)
            ->exists()) {
            throw new RuntimeException('OPS-019 Gacha Version capacity reconciliation is incomplete.');
        }
        if (DB::table('gacha_draw_states')
            ->whereIn('gacha_version_id', array_keys(self::DRAW_STATE_VERSIONS))
            ->where('total_count', '<>', 18)
            ->exists()) {
            throw new RuntimeException('OPS-019 Draw State capacity reconciliation is incomplete.');
        }
    }

    private function guardDefinition(string $signature): string
    {
        $row = DB::selectOne(
            'SELECT pg_get_functiondef(CAST(? AS regprocedure)) AS definition',
            [$signature]
        );
        if (! is_object($row) || ! is_string($row->definition ?? null)) {
            throw new RuntimeException('OPS-019 required Catalog guard is unavailable.');
        }

        return $row->definition;
    }

    private function withExactCapacityException(string $definition): string
    {
        $exception = <<<'SQL'

                IF TG_TABLE_NAME = 'catalog_gacha_versions'
                   AND TG_OP = 'UPDATE'
                   AND OLD.id = ANY (ARRAY[8, 9, 10, 11, 12]::bigint[]) THEN
                    IF OLD.gacha_id = (CASE OLD.id
                            WHEN 8 THEN 10
                            WHEN 9 THEN 10
                            WHEN 10 THEN 10
                            WHEN 11 THEN 11
                            WHEN 12 THEN 12
                        END)
                       AND OLD.total_count = 9
                       AND NEW.total_count = 18
                       AND (to_jsonb(NEW) - 'total_count')
                           IS NOT DISTINCT FROM (to_jsonb(OLD) - 'total_count') THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'OPS-019 exact capacity reconciliation rejected';
                END IF;
            SQL;
        $count = 0;
        $patched = preg_replace_callback(
            '/\bBEGIN\b/',
            static fn (): string => 'BEGIN'.$exception,
            $definition,
            1,
            $count
        );
        if (! is_string($patched) || $count !== 1) {
            throw new RuntimeException('OPS-019 Catalog guard could not be scoped safely.');
        }

        return $patched;
    }
};
