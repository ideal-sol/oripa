<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicPrizePresentationReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-09-24T00:00:00Z');
        Storage::fake('local');
        config(['filesystems.default' => 'local', 'cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_detail_returns_every_prize_in_version_order_with_its_own_total_and_snapshot(): void
    {
        [$user, $gachaId, $fixture] = $this->fixture();
        $this->draw($user, $gachaId, 1);
        $originalAsset = $fixture['assets'][2]['public_id'];
        DB::table('catalog_prizes')->where('public_id', $fixture['prizes'][0]['public_id'])
            ->update([
                'presentation_asset_id' => DB::table('catalog_presentation_assets')
                    ->where('public_id', $fixture['assets'][3]['public_id'])->value('id'),
                'display_name' => 'Later master presentation',
                'revision' => DB::raw('revision + 1'),
            ]);

        $detail = $this->getJson('/api/v2/gachas/'.$gachaId)->assertOk()
            ->assertJsonCount(4, 'data.prizes')->json('data');
        $rank = $detail['ranks'][0];
        self::assertTrue($rank['show_total_stock']);
        self::assertSame(10, $rank['total_stock']);
        $prizes = array_values(array_filter($detail['prizes'],
            fn (array $prize): bool => $prize['rank_id'] === $rank['rank_id']));
        self::assertSame([3, 5, 2], array_column($prizes, 'total_inventory'));
        self::assertSame([10, 20, 30], array_column($prizes, 'display_order'));
        self::assertSame([
            $fixture['prizes'][0]['public_id'], $fixture['prizes'][2]['public_id'],
            $fixture['prizes'][3]['public_id'],
        ], array_column($prizes, 'id'));
        self::assertSame($fixture['prizes'][0]['name'], $prizes[0]['name']);
        self::assertSame($originalAsset, $prizes[0]['presentation_asset']['id']);
        self::assertCount(3, array_unique(array_column(array_column($prizes, 'presentation_asset'), 'id')));
        self::assertFalse($detail['ranks'][1]['show_total_stock']);
        self::assertNull($detail['ranks'][1]['total_stock']);
        self::assertSame($detail['ranks'][1]['rank_id'], $detail['prizes'][3]['rank_id']);
        self::assertSame(4, $detail['prizes'][3]['total_inventory']);
        self::assertSame(13, $detail['remaining_count']);
        self::assertSame(100, $detail['price_points']);
        foreach ($prizes as $prize) {
            self::assertStringStartsWith('/api/v2/content/assets/', $prize['presentation_asset']['path']);
            $this->get($prize['presentation_asset']['path'])->assertOk();
            self::assertArrayNotHasKey('available_quantity', $prize);
        }
        $portrait = $this->get($prizes[0]['presentation_asset']['path'])->getContent();
        $landscape = $this->get($prizes[1]['presentation_asset']['path'])->getContent();
        self::assertSame([1, 2], array_slice(getimagesizefromstring($portrait), 0, 2));
        self::assertSame([2, 1], array_slice(getimagesizefromstring($landscape), 0, 2));
    }

    public function test_detail_preserves_missing_private_archived_and_missing_file_fallbacks(): void
    {
        [, $gachaId, $fixture] = $this->fixture();
        $asset = (array) DB::table('catalog_presentation_assets')
            ->where('public_id', $fixture['assets'][2]['public_id'])->first();
        unset($asset['id']);
        $assetId = (string) Str::uuid7();
        $asset['public_id'] = $assetId;
        $asset['storage_identifier'] = 'fixture/unreferenced.png';
        $asset['public_path'] = '/api/v2/content/assets/'.$assetId;
        DB::table('catalog_presentation_assets')->insert($asset);
        DB::table('catalog_presentation_assets')->where('public_id', $assetId)
            ->update(['is_public' => false, 'revision' => DB::raw('revision + 1')]);
        $this->get('/api/v2/content/assets/'.$assetId)->assertNotFound();
        DB::table('catalog_presentation_assets')->where('public_id', $assetId)
            ->update(['archived_at' => now(), 'revision' => DB::raw('revision + 1')]);
        $this->get('/api/v2/content/assets/'.$assetId)->assertNotFound();
        Storage::disk('local')->delete($fixture['assets'][4]['storage_identifier']);
        $this->getJson('/api/v2/gachas/'.$gachaId)->assertOk()->assertJsonCount(4, 'data.prizes');
        $this->get('/api/v2/content/assets/'.$fixture['assets'][4]['public_id'])->assertNotFound();
        $this->get('/api/v2/content/assets/'.Str::uuid7())->assertNotFound();
    }

    public function test_single_draw_get_uses_awarded_prize_and_rank_revision_without_mutating_snapshots(): void
    {
        [$user, $gachaId, $fixture] = $this->fixture();
        $created = $this->draw($user, $gachaId, 1);
        $before = $this->drawState();
        Auth::guard('v2_user')->setUser($user);
        $read = $this->getJson('/api/v2/draw-requests/'.$created['id'])->assertOk()->json();
        $result = $read['results'][0];
        self::assertSame($fixture['prizes'][0]['public_id'], $result['prize']['id']);
        self::assertSame($fixture['prizes'][0]['name'], $result['prize']['name']);
        self::assertSame($created['results'][0]['rank'], $result['rank']);
        self::assertSame($created['results'][0]['sequence_number'], $result['sequence_number']);
        self::assertSame($fixture['assets'][2]['public_id'], $result['prize']['presentation_asset']['id']);
        self::assertStringStartsWith('/api/v2/content/assets/', $result['prize']['presentation_asset']['path']);
        $this->get($result['prize']['presentation_asset']['path'])->assertOk();
        $rankRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', DB::table('draw_results')->value('rank_master_revision_id'))->first();
        self::assertSame(
            DB::table('catalog_presentation_assets')->where('id', $rankRevision->lineup_image_asset_id)->value('public_id'),
            $result['rank_lineup_image']['id']
        );
        self::assertSame($result['rank_lineup_image'], $read['prize_counts'][0]['rank_lineup_image']);
        self::assertSame($before, $this->drawState());
        self::assertArrayNotHasKey('total_inventory', $result);
        self::assertArrayNotHasKey('show_total_stock', $result);
        self::assertEquals($created['wallet_after'], $read['wallet_after']);
        self::assertSame($created['executed_count'], $read['executed_count']);
        self::assertEquals($created['results'][0]['result_image_snapshot'], $result['result_image_snapshot']);
    }

    public function test_multiple_awards_in_same_rank_keep_distinct_thumbnails_and_read_order(): void
    {
        [$user, $gachaId, $fixture] = $this->fixture();
        $created = $this->draw($user, $gachaId, 5);
        Auth::guard('v2_user')->setUser($user);
        $read = $this->getJson('/api/v2/draw-requests/'.$created['id'])->assertOk()->json();
        self::assertSame(array_column($created['results'], 'id'), array_column($read['results'], 'id'));
        self::assertSame([1, 2, 3, 4, 5], array_column($read['results'], 'sequence_number'));
        $first = $read['results'][0];
        $fourth = $read['results'][3];
        self::assertSame($first['rank'], $fourth['rank']);
        self::assertNotSame($first['prize']['id'], $fourth['prize']['id']);
        self::assertNotSame($first['prize']['presentation_asset']['id'], $fourth['prize']['presentation_asset']['id']);
        self::assertSame($first['rank_lineup_image'], $fourth['rank_lineup_image']);
        self::assertSame([3, 2], array_column($read['prize_counts'], 'count'));
        foreach ($read['high_rank_results'] as $result) {
            self::assertSame($first['rank_lineup_image'], $result['rank_lineup_image']);
        }
        $snapshot = $read['results'];
        DB::table('catalog_prizes')->update([
            'presentation_asset_id' => null,
            'revision' => DB::raw('revision + 1'),
        ]);
        $this->reviseRank('Sランク', false, (int) DB::table('catalog_presentation_assets')
            ->where('public_id', $fixture['assets'][3]['public_id'])->value('id'));
        $this->getJson('/api/v2/draw-requests/'.$created['id'])->assertOk()->assertJsonPath('results', $snapshot);
        $replay = app(V2DrawService::class)->create($user, $gachaId, 5, 'presentation-draw-5', (string) Str::uuid7());
        self::assertTrue($replay['idempotent_replay']);
        self::assertEquals($created['results'], $replay['results']);
    }

    private function fixture(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 14;
        $fixture['gacha_prizes'][0]['initial_inventory'] = 3;
        $fixture['gacha_prizes'][1]['initial_inventory'] = 4;
        $fixture['gacha_prizes'][1]['sort_order'] = 40;
        foreach ([5, 2] as $index => $quantity) {
            $asset = $fixture['assets'][2];
            $asset['public_id'] = (string) Str::uuid7();
            $asset['storage_identifier'] = 'fixture/catalog/extra-'.$index.'.png';
            $fixture['assets'][] = $asset;
            $prize = $fixture['prizes'][0];
            $prize['public_id'] = (string) Str::uuid7();
            $prize['code'] = 'extra-'.$index;
            $prize['name'] = 'Extra '.$index;
            $prize['asset_storage_identifier'] = $asset['storage_identifier'];
            $fixture['prizes'][] = $prize;
            $relation = $fixture['gacha_prizes'][0];
            $relation['prize_code'] = $prize['code'];
            $relation['initial_inventory'] = $quantity;
            $relation['sort_order'] = 20 + 10 * $index;
            $fixture['gacha_prizes'][] = $relation;
        }
        $fixture['expected_record_count'] += 6;
        foreach ($fixture['assets'] as $index => &$asset) {
            if ($asset['media_type'] !== 'image') {
                continue;
            }
            $content = $this->png($index === 4 ? 2 : 1, $index === 4 ? 1 : 2);
            $asset['checksum_sha256'] = hash('sha256', $content);
            $asset['fixture_content_base64'] = base64_encode($content);
            $asset['public_path'] = '/admin/api/v2/catalog/presentation-assets/'.$asset['public_id'].'/content';
        }
        unset($asset);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        foreach ($fixture['assets'] as $asset) {
            Storage::disk('local')->put($asset['storage_identifier'], base64_decode($asset['fixture_content_base64']));
        }
        $this->reviseRank('Sランク', true);
        $calls = 0;
        $this->app->instance(V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(static function (int $minimum, int $maximum) use (&$calls): int {
                return ++$calls <= 3 ? $minimum : min(5, $maximum);
            }));
        $user = User::query()->create([
            'display_name' => 'Synthetic prize reader',
            'email_display' => 'prize-reader@example.test',
            'email_normalized' => 'prize-reader@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree($user->id, 10000, 'presentation-free-points');

        return [$user, $fixture['gachas'][0]['public_id'], $fixture];
    }

    private function reviseRank(string $name, bool $showStock, ?int $lineupAssetId = null): void
    {
        $master = DB::table('catalog_rank_masters as master')
            ->join('catalog_rank_master_revisions as revision', 'revision.id', '=', 'master.current_revision_id')
            ->where('revision.rank_name', $name)->first(['master.*']);
        $revision = (array) DB::table('catalog_rank_master_revisions')->where('id', $master->current_revision_id)->first();
        unset($revision['id']);
        $revision['revision_number']++;
        $revision['show_total_stock'] = $showStock;
        $revision['lineup_image_asset_id'] = $lineupAssetId ?? $revision['lineup_image_asset_id'];
        $revisionId = DB::table('catalog_rank_master_revisions')->insertGetId($revision);
        DB::table('catalog_rank_masters')->where('id', $master->id)->update([
            'current_revision_id' => $revisionId, 'revision' => $master->revision + 1,
        ]);
    }

    private function draw(User $user, string $gachaId, int $count): array
    {
        return app(V2DrawService::class)->create($user, $gachaId, $count,
            'presentation-draw-'.$count, (string) Str::uuid7());
    }

    private function drawState(): string
    {
        return json_encode(array_map(static fn (string $table): array =>
            DB::table($table)->orderBy('id')->get()->all(), [
                'draw_requests', 'draw_results', 'user_prizes', 'prize_inventories', 'wallets',
            ]), JSON_THROW_ON_ERROR);
    }

    private function png(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string =>
            pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\x00".str_repeat("\xff\x00\x00", $width), $height)))
            .$chunk('IEND', '');
    }
}
