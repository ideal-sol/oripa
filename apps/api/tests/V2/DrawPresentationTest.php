<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawPresentationResolver;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\DrawResult;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DrawPresentationTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const HIGHEST_ID = '0198a001-0000-7000-8000-000000000099';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-09-25T00:00:00Z');
        config(['cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_single_draw_projects_exact_awarded_rank_and_video(): void
    {
        $user = $this->fixture([PHP_INT_MAX]);
        $response = $this->draw($user, 1);
        self::assertEquals([
            'rank' => $response['results'][0]['rank'],
            'video_snapshot' => $response['results'][0]['video_snapshot'],
        ], $response['presentation']);
        self::assertSame(self::HIGHEST_ID, $response['presentation']['rank']['id']);
        self::assertArrayHasKey('result_image_snapshot', $response['results'][0]);
        self::assertArrayHasKey('high_rank_results', $response);
    }

    public function test_three_ranks_ignore_id_name_sequence_and_collection_order(): void
    {
        $user = $this->fixture([1, 1500, 2000, PHP_INT_MAX, 1]);
        $response = $this->draw($user, 5);
        self::assertSame(['Alpha', 'Middle', 'Zulu'], array_column(array_slice(array_column($response['results'], 'rank'), 0, 3), 'name'));
        self::assertSame(self::HIGHEST_ID, $response['presentation']['rank']['id']);
        self::assertEquals($response['results'][2]['video_snapshot'], $response['presentation']['video_snapshot']);
        self::assertNotSame($response['results'][0]['video_snapshot'], $response['presentation']['video_snapshot']);
        self::assertNotSame($response['results'][2]['prize']['id'], $response['results'][3]['prize']['id']);
        self::assertCount(3, array_unique(array_column(array_column($response['results'], 'video_snapshot'), 'id')));
        $results = DrawResult::query()->orderByDesc('request_sequence')->get();
        self::assertEquals($response['presentation'], app(V2DrawPresentationResolver::class)->resolve($results));
        $firstHighest = $results->firstWhere('request_sequence', 3);
        $snapshot = $firstHighest->display_snapshot;
        $snapshot['video_snapshot']['path'] = '/assets/first-highest.mp4';
        $firstHighest->display_snapshot = $snapshot;
        self::assertSame('/assets/first-highest.mp4', app(V2DrawPresentationResolver::class)->resolve($results)['video_snapshot']['path']);
        self::assertSame('/assets/first-highest.mp4', app(V2DrawPresentationResolver::class)->resolve($results->reverse())['video_snapshot']['path']);
    }

    public function test_projection_fails_safe_without_falling_back_or_mutating_results(): void
    {
        $user = $this->fixture([1, PHP_INT_MAX]);
        $this->draw($user, 5);
        $original = DB::table('draw_results')->orderBy('id')->get()->toJson();
        foreach ([null, [], ['path' => '//unsafe.example/video.mp4']] as $video) {
            $results = DrawResult::query()->orderBy('request_sequence')->get();
            $selected = $results->firstWhere('request_sequence', 2);
            $snapshot = $selected->display_snapshot;
            $snapshot['video_snapshot'] = $video;
            $selected->display_snapshot = $snapshot;
            self::assertNull(app(V2DrawPresentationResolver::class)->resolve($results));
        }
        $results = DrawResult::query()->get();
        $results->first()->rank_master_revision_id = null;
        self::assertNull(app(V2DrawPresentationResolver::class)->resolve($results));
        self::assertSame($original, DB::table('draw_results')->orderBy('id')->get()->toJson());
    }

    public function test_duplicate_minimum_rank_order_returns_null_and_draw_succeeds(): void
    {
        $user = $this->fixture([1, 1500, PHP_INT_MAX, 1, 1]);
        $this->reviseRank('Middle', 1);
        $response = $this->draw($user, 5);
        self::assertNull($response['presentation']);
        self::assertSame('completed', $response['status']);
        self::assertCount(5, $response['results']);
        self::assertSame(5, DB::table('user_prizes')->count());
        self::assertSame(999500, $response['wallet_after']['free_points']);
        self::assertSame(5, (int) DB::table('prize_inventories')->sum('awarded_count'));
    }

    public function test_video_configuration_remains_required_before_draw(): void
    {
        $user = $this->fixture([1]);
        DB::table('catalog_gacha_ranks')->update([
            'current_video_revision_id' => null, 'revision' => DB::raw('revision + 1'),
        ]);
        try {
            $this->draw($user, 1);
            self::fail('Missing video configuration must still reject the Draw.');
        } catch (V2DrawException $exception) {
            self::assertSame('PRESENTATION_CONFIGURATION_INVALID', $exception->errorCode);
        }
        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(1000000, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
    }

    public function test_full_bulk_post_replay_get_are_stable_after_rank_and_video_changes(): void
    {
        $user = $this->fixture([...array_fill(0, 999, 1), PHP_INT_MAX, ...array_fill(0, 99, 1), PHP_INT_MAX]);
        foreach ([1000, 100] as $count) {
            $beforeMemory = memory_get_usage(true);
            $created = $this->postDraw($user, $count);
            self::assertCount($count, $created['results']);
            self::assertSame(self::HIGHEST_ID, $created['presentation']['rank']['id']);
            self::assertEquals($created['results'][$count - 1]['video_snapshot'], $created['presentation']['video_snapshot']);
            self::assertCount(20, $created['high_rank_results']);
            self::assertTrue($created['high_rank_results_truncated']);
            self::assertNotContains(self::HIGHEST_ID, array_column(array_column($created['high_rank_results'], 'rank'), 'id'));
            $request = DB::table('draw_requests')->where('public_id', $created['id'])->first();
            $persisted = DB::table('draw_results')->where('draw_request_id', $request->id)->orderBy('request_sequence')->get();
            self::assertSame($persisted->pluck('public_id')->all(), array_column($created['results'], 'id'));
            self::assertSame($persisted->pluck('draw_sequence_number')->all(), array_column($created['results'], 'sequence_number'));
            self::assertCount($count, array_unique(array_column($created['results'], 'id')));
            self::assertEquals($created, json_decode($request->response_data, true, flags: JSON_THROW_ON_ERROR));
            $encoded = json_encode($created, JSON_THROW_ON_ERROR);
            self::assertLessThan(4_000_000, strlen($encoded));
            self::assertLessThan(128 * 1024 * 1024, memory_get_usage(true) - $beforeMemory);
            if ($count === 1000) {
                $this->reviseRank('Zulu', 50, 'Renamed current rank');
                $this->replaceVideo();
                self::assertEquals($created['presentation'], app(V2DrawPresentationResolver::class)->resolve(
                    DrawResult::query()->where('draw_request_id', $request->id)->get()
                ));
            }
            $read = $this->getJson('/api/v2/draw-requests/'.$created['id'])->assertOk()->json();
            $replay = $this->postDraw($user, $count);
            self::assertTrue($replay['idempotent_replay']);
            self::assertEquals($created['results'], $read['results']);
            self::assertEquals($created['results'], $replay['results']);
            self::assertEquals($created['presentation'], $read['presentation']);
            self::assertEquals($created['presentation'], $replay['presentation']);
            self::assertSame($request->response_data, DB::table('draw_requests')->where('id', $request->id)->value('response_data'));
            fwrite(STDOUT, "\nDRAW_FULL_RESULTS=".json_encode([
                'count' => $count, 'json_bytes' => strlen($encoded),
                'memory_delta_bytes' => memory_get_usage(true) - $beforeMemory,
                'post_replay_get' => 'PASS',
            ], JSON_THROW_ON_ERROR)."\n");
            if ($count === 1000) {
                $this->reviseRank('Renamed current rank', 1, 'Zulu');
            }
        }
    }

    public function test_legacy_response_is_not_backfilled_or_mutated(): void
    {
        $user = $this->fixture([PHP_INT_MAX]);
        $legacy = $this->draw($user, 100);
        unset($legacy['presentation'], $legacy['results']);
        DB::table('draw_requests')->where('public_id', $legacy['id'])->update([
            'response_data' => json_encode($legacy, JSON_THROW_ON_ERROR),
        ]);
        $before = DB::table('draw_requests')->where('public_id', $legacy['id'])->value('response_data');
        $resultsBefore = DB::table('draw_results')->orderBy('id')->get()->toJson();
        Auth::guard('v2_user')->setUser($user);
        $read = $this->getJson('/api/v2/draw-requests/'.$legacy['id'])->assertOk()->json();
        $replay = $this->draw($user, 100);
        foreach ([$read, $replay] as $response) {
            self::assertArrayNotHasKey('presentation', $response);
            self::assertArrayNotHasKey('results', $response);
        }
        self::assertSame($before, DB::table('draw_requests')->where('public_id', $legacy['id'])->value('response_data'));
        self::assertSame($resultsBefore, DB::table('draw_results')->orderBy('id')->get()->toJson());
    }

    private function fixture(array $values): User
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixture['ranks'][0]['name'] = 'Alpha';
        $fixture['ranks'][0]['sort_order'] = 20;
        $fixture['ranks'][1]['name'] = 'Middle';
        $fixture['ranks'][1]['sort_order'] = 10;
        $fixture['ranks'][] = [...$fixture['ranks'][0], 'public_id' => self::HIGHEST_ID, 'code' => 'Z', 'name' => 'Zulu', 'sort_order' => 1];
        foreach (['A', 'Z'] as $offset => $code) {
            $asset = $fixture['assets'][1];
            $asset['public_id'] = '0198a001-0000-7000-8000-00000000008'.($offset + 1);
            $asset['storage_identifier'] = 'fixture/video-'.$code.'.mp4';
            $asset['public_path'] = '/assets/fixture/video-'.$code.'.mp4';
            $fixture['assets'][] = $asset;
            $fixture['rank_assets'][] = [...$fixture['rank_assets'][0], 'rank_code' => $code, 'asset_storage_identifier' => $asset['storage_identifier']];
        }
        $fixture['prizes'][] = [...$fixture['prizes'][0], 'public_id' => '0198a001-0000-7000-8000-000000000088', 'code' => 'fixture-z-1', 'rank_code' => 'Z'];
        $fixture['gacha_prizes'][] = [...$fixture['gacha_prizes'][0], 'prize_code' => 'fixture-z-1', 'sort_order' => 30];
        $fixture['prizes'][] = [...$fixture['prizes'][2], 'public_id' => '0198a001-0000-7000-8000-000000000089', 'code' => 'fixture-z-2'];
        $fixture['gacha_prizes'][] = [...$fixture['gacha_prizes'][2], 'prize_code' => 'fixture-z-2', 'sort_order' => 40];
        $fixture['expected_record_count'] += 9;
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 3000;
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10, 100, 1000];
        foreach ([1500, 500, 500, 500] as $index => $quantity) {
            $fixture['gacha_prizes'][$index]['initial_inventory'] = $quantity;
        }
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $this->random($values);
        $user = User::query()->create([
            'email_display' => 'presentation@example.test',
            'email_normalized' => 'presentation@example.test',
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic presentation password'),
            'email_verified_at' => now(), 'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree($user->id, 1000000, 'presentation-fixture-points');

        return $user;
    }

    private function random(array $values): void
    {
        $index = 0;
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(
            static function (int $minimum, int $maximum) use (&$index, $values): int {
                return min($maximum, max($minimum, $values[$index++ % count($values)]));
            }
        ));
    }

    private function draw(User $user, int $count): array
    {
        return app(V2DrawService::class)->create($user, self::GACHA_ID, $count, 'presentation-key-'.$count, (string) Str::uuid7());
    }

    private function postDraw(User $user, int $count): array
    {
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        Auth::guard('v2_user')->setUser($user);
        $csrf = str_repeat('a', 64);

        return $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => 'presentation-http-'.$count,
            ])->postJson('/api/v2/gachas/'.self::GACHA_ID.'/draws', ['draw_count' => $count])
            ->assertOk()->json();
    }

    private function reviseRank(string $name, int $order, ?string $newName = null): void
    {
        $master = DB::table('catalog_rank_masters as master')
            ->join('catalog_rank_master_revisions as revision', 'revision.id', '=', 'master.current_revision_id')
            ->where('revision.rank_name', $name)->first(['master.*']);
        $revision = (array) DB::table('catalog_rank_master_revisions')->where('id', $master->current_revision_id)->first();
        unset($revision['id']);
        $revision['revision_number']++;
        $revision['display_order'] = $order;
        $revision['rank_name'] = $newName ?? $name;
        $revisionId = DB::table('catalog_rank_master_revisions')->insertGetId($revision);
        DB::table('catalog_rank_masters')->where('id', $master->id)->update([
            'current_revision_id' => $revisionId, 'revision' => $master->revision + 1,
        ]);
    }

    private function replaceVideo(): void
    {
        $rank = DB::table('catalog_gacha_ranks')->where('rank_master_id', DB::table('catalog_rank_masters')->where('public_id', self::HIGHEST_ID)->value('id'))->first();
        $revision = (array) DB::table('catalog_gacha_rank_video_revisions')->where('id', $rank->current_video_revision_id)->first();
        unset($revision['id']);
        $revision['revision_number']++;
        $revision['video_asset_id'] = DB::table('catalog_presentation_assets')->where('storage_identifier', 'fixture/video-A.mp4')->value('id');
        $revisionId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId($revision);
        DB::table('catalog_gacha_ranks')->where('id', $rank->id)->update([
            'current_video_revision_id' => $revisionId, 'revision' => $rank->revision + 1,
        ]);
    }
}
