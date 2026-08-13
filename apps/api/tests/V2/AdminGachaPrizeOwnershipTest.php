<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogReadService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGachaPrizeOwnershipTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000009';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_prize_has_one_gacha_owner_and_can_be_reused_within_that_gacha(): void
    {
        $gacha = DB::table('catalog_gachas')->where('public_id', self::GACHA_ID)->first();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', self::VERSION_ID)->first();
        $prize = DB::table('catalog_prizes')->where('public_id', self::PRIZE_ID)->first();
        $relation = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $version->id)
            ->where('prize_id', $prize->id)
            ->first();

        self::assertSame((int) $gacha->id, (int) $prize->gacha_id);

        $sameGachaVersionId = $this->copyVersion($version, (int) $gacha->id, 2);
        $sameGachaRelation = $this->relationCopy($relation, $sameGachaVersionId);
        DB::table('catalog_gacha_version_prizes')->insert($sameGachaRelation);
        self::assertDatabaseHas('catalog_gacha_version_prizes', [
            'gacha_version_id' => $sameGachaVersionId,
            'prize_id' => $prize->id,
        ]);

        $otherGachaId = $this->copyGacha($gacha);
        $otherVersionId = $this->copyVersion($version, $otherGachaId, 1);
        try {
            DB::transaction(function () use ($relation, $otherVersionId): void {
                DB::table('catalog_gacha_version_prizes')->insert(
                    $this->relationCopy($relation, $otherVersionId)
                );
            });
            self::fail('Cross-Gacha Prize association was accepted.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'Cross-Gacha Prize association is not allowed',
                $exception->getMessage()
            );
        }
    }

    public function test_published_snapshot_does_not_change_when_prize_master_changes(): void
    {
        $prize = DB::table('catalog_prizes')->where('public_id', self::PRIZE_ID)->first();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', self::VERSION_ID)->first();
        $before = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $version->id)
            ->where('prize_id', $prize->id)
            ->first();

        DB::table('catalog_prizes')->where('id', $prize->id)->update([
            'display_name' => '編集中の景品名',
            'exchange_points' => 999,
            'revision' => (int) $prize->revision + 1,
            'updated_at' => now()->startOfSecond(),
        ]);
        $after = DB::table('catalog_gacha_version_prizes')
            ->where('id', $before->id)->first();
        self::assertSame($before->display_name, $after->display_name);
        self::assertSame((int) $before->exchange_points, (int) $after->exchange_points);
        self::assertSame('Fixture S景品', $after->display_name);
        self::assertSame('Sランク', $after->rank_display_name);

        $detail = app(V2CatalogReadService::class)->getByPublicId(self::GACHA_ID);
        self::assertSame('Sランク', $detail['ranks'][0]['name']);
        self::assertSame('Fixture S景品', $detail['ranks'][0]['prizes'][0]['name']);
    }

    private function copyGacha(object $source): int
    {
        $values = (array) $source;
        unset($values['id']);
        $values['public_id'] = (string) Str::uuid7();
        $values['public_code'] = 'Z'.substr(str_replace('-', '', (string) Str::uuid()), 0, 10);
        $values['code'] = 'ownership-'.Str::lower(Str::random(12));
        $values['slug'] = $values['code'];
        $values['published_version_id'] = null;
        $values['active_draw_state_id'] = null;
        $values['management_status'] = 'draft';
        $values['state'] = 'draft';
        $values['sold_count'] = 0;
        $values['sales_paused'] = false;
        $values['sales_paused_at'] = null;
        $values['sales_paused_by_admin_public_id'] = null;
        $values['sales_pause_reason_code'] = null;
        $values['sales_resumed_at'] = null;
        $values['sales_last_mutation_request_id'] = null;
        $values['public_deactivated_at'] = null;
        $values['public_deactivated_by_admin_public_id'] = null;
        $values['public_deactivation_request_id'] = null;
        $values['revision'] = 1;

        return (int) DB::table('catalog_gachas')->insertGetId($values);
    }

    private function copyVersion(object $source, int $gachaId, int $number): int
    {
        $values = (array) $source;
        unset($values['id']);
        $values['public_id'] = (string) Str::uuid7();
        $values['gacha_id'] = $gachaId;
        $values['version_number'] = $number;
        $values['status'] = 'draft';
        $values['published_probability_version_id'] = null;
        $values['published_at'] = null;
        $values['archived_at'] = null;
        $values['cloned_from_version_id'] = null;
        $values['revision'] = 1;

        return (int) DB::table('catalog_gacha_versions')->insertGetId($values);
    }

    /** @return array<string, mixed> */
    private function relationCopy(object $source, int $versionId): array
    {
        $values = (array) $source;
        unset($values['id']);
        $values['gacha_version_id'] = $versionId;

        return $values;
    }
}
