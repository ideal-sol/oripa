<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZDrawConcurrencyLoadTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_concurrent_draw_and_presentation_update_snapshot_one_committed_revision_pair(): void
    {
        if (getenv('V2_RANK_PRESENTATION_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('MIG-099の明示的Presentation Concurrency検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Canonical Rank presentation concurrency verification.');
        }
        $this->configureInventoryBoundary();
        $gachaId = $this->importGachas(1, totalCount: 100)[0];
        $user = $this->inventoryUser('rank-presentation-concurrency');
        $relation = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_gacha_ranks as gacha_rank', 'gacha_rank.id', '=', 'prize.gacha_rank_id')
            ->join('catalog_rank_masters as rank_master', 'rank_master.id', '=', 'gacha_rank.rank_master_id')
            ->where('relation.gacha_version_id', DB::table('catalog_gacha_versions')
                ->where('gacha_id', DB::table('catalog_gachas')->where('public_id', $gachaId)->value('id'))
                ->value('id'))
            ->orderBy('relation.id')
            ->firstOrFail([
                'rank_master.id as rank_master_id',
                'rank_master.current_revision_id as rank_master_revision_id',
                'rank_master.revision as rank_master_revision',
                'gacha_rank.id as gacha_rank_id',
                'gacha_rank.current_video_revision_id',
                'gacha_rank.revision as gacha_rank_revision',
            ]);
        $rankRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', $relation->rank_master_revision_id)->firstOrFail();
        $videoRevision = DB::table('catalog_gacha_rank_video_revisions')
            ->where('id', $relation->current_video_revision_id)->firstOrFail();
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int => $minimum
            )
        );
        $drawResultsBefore = DB::table('draw_results')->count();
        $soldBefore = (int) DB::table('gacha_draw_states')->value('sold_count');
        $awardedBefore = (int) DB::table('prize_inventories')->sum('awarded_count');
        $directory = sys_get_temp_dir().'/mig099-rank-presentation-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $idempotencyKey = 'rank-presentation-concurrent-draw';
        $requestToken = (string) Str::uuid7();
        $children = [];
        foreach (['draw', 'admin'] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Canonical Rank presentation worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                try {
                    if ($worker === 'draw') {
                        $response = app(V2DrawService::class)->create(
                            User::query()->findOrFail($user->id),
                            $gachaId,
                            1,
                            $idempotencyKey,
                            $requestToken
                        );
                        $result = [
                            'status' => 'completed',
                            'draw_id' => $response['id'],
                        ];
                    } else {
                        $result = DB::transaction(function () use ($relation, $rankRevision, $videoRevision): array {
                            $now = now()->startOfSecond();
                            $rankRevisionId = DB::table('catalog_rank_master_revisions')->insertGetId([
                                'rank_master_id' => $relation->rank_master_id,
                                'revision_number' => (int) $rankRevision->revision_number + 1,
                                'rank_name' => 'Concurrent Rank Presentation',
                                'lineup_image_asset_id' => $rankRevision->lineup_image_asset_id,
                                'result_image_asset_id' => $rankRevision->result_image_asset_id,
                                'show_total_stock' => (bool) $rankRevision->show_total_stock,
                                'display_order' => $rankRevision->display_order,
                                'created_at' => $now,
                            ]);
                            $videoAssetId = DB::table('catalog_presentation_assets')->insertGetId([
                                'public_id' => (string) Str::uuid7(),
                                'storage_identifier' => 'tests/concurrent-rank-video-'.Str::uuid7().'.mp4',
                                'public_path' => '/assets/tests/concurrent-rank-video-'.Str::uuid7().'.mp4',
                                'checksum_sha256' => hash('sha256', 'concurrent-rank-video-update'),
                                'media_type' => 'video',
                                'mime_type' => 'video/mp4',
                                'byte_size' => 1,
                                'alt_text' => 'Concurrent Rank Video',
                                'is_public' => true,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $videoRevisionId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([
                                'gacha_rank_id' => $relation->gacha_rank_id,
                                'revision_number' => (int) $videoRevision->revision_number + 1,
                                'video_asset_id' => $videoAssetId,
                                'created_at' => $now,
                            ]);
                            DB::table('catalog_rank_masters')
                                ->where('id', $relation->rank_master_id)
                                ->update([
                                    'current_revision_id' => $rankRevisionId,
                                    'revision' => (int) $relation->rank_master_revision + 1,
                                    'updated_at' => $now,
                                ]);
                            DB::table('catalog_gacha_ranks')
                                ->where('id', $relation->gacha_rank_id)
                                ->update([
                                    'current_video_revision_id' => $videoRevisionId,
                                    'revision' => (int) $relation->gacha_rank_revision + 1,
                                    'updated_at' => $now,
                                ]);

                            return [
                                'status' => 'completed',
                                'rank_revision_id' => $rankRevisionId,
                                'video_revision_id' => $videoRevisionId,
                            ];
                        }, 3);
                    }
                } catch (\Throwable $exception) {
                    $result = [
                        'status' => 'failed',
                        'class' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ];
                }
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit($result['status'] === 'completed' ? 0 : 1);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        DB::reconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $results = [];
        foreach (['draw', 'admin'] as $worker) {
            $path = "{$directory}/{$worker}.json";
            $results[$worker] = json_decode(
                file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            unlink($path);
        }
        rmdir($directory);

        $drawResult = DB::table('draw_results as result')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->where('request.public_id', $results['draw']['draw_id'])
            ->where('result.result_type', 'prize')
            ->firstOrFail(['result.*']);
        $validPairs = [
            [
                (int) $relation->rank_master_revision_id,
                (int) $relation->current_video_revision_id,
            ],
            [
                (int) $results['admin']['rank_revision_id'],
                (int) $results['admin']['video_revision_id'],
            ],
        ];
        self::assertContains([
            (int) $drawResult->rank_master_revision_id,
            (int) $drawResult->gacha_rank_video_revision_id,
        ], $validPairs);
        $snapshot = json_decode(
            (string) $drawResult->display_snapshot,
            true,
            flags: JSON_THROW_ON_ERROR
        );
        if ((int) $drawResult->rank_master_revision_id === (int) $results['admin']['rank_revision_id']) {
            self::assertSame('Concurrent Rank Presentation', $snapshot['rank_name_snapshot']);
            self::assertStringStartsWith('/assets/tests/concurrent-rank-video-', $snapshot['video_snapshot']['path']);
        } else {
            self::assertSame($rankRevision->rank_name, $snapshot['rank_name_snapshot']);
        }
        $replay = app(V2DrawService::class)->create(
            User::query()->findOrFail($user->id),
            $gachaId,
            1,
            $idempotencyKey,
            $requestToken
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(1, DB::table('draw_requests')->where('public_id', $results['draw']['draw_id'])->count());
        self::assertSame($drawResultsBefore + 1, DB::table('draw_results')->count());
        self::assertSame($soldBefore + 1, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($awardedBefore + 1, (int) DB::table('prize_inventories')->sum('awarded_count'));
    }

    public function test_operational_inventory_migration_backfills_successful_draw_history(): void
    {
        $this->configureInventoryBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        $this->importGachas(1, totalCount: 100);
        $user = $this->inventoryUser('backfill');
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int => $maximum
            )
        );
        app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'operational-inventory-backfill-draw',
            (string) Str::uuid7()
        );
        $relationId = (int) DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', self::PRIZE_A_ID)
            ->value('relation.id');

        try {
            Artisan::call('migrate:rollback', [
                '--path' => 'database/migrations-v2/'.
                    '2026_09_08_000053_operational_gacha_inventory.php',
                '--force' => true,
            ]);
            $legacy = DB::table('prize_inventories')
                ->where('gacha_version_prize_id', $relationId)
                ->firstOrFail();
            self::assertSame(90, (int) $legacy->initial_quantity);
            self::assertSame(1, (int) $legacy->won_count);

            Artisan::call('migrate', [
                '--path' => 'database/migrations-v2/'.
                    '2026_09_08_000053_operational_gacha_inventory.php',
                '--force' => true,
            ]);
            $inventory = DB::table('prize_inventories')
                ->where('gacha_version_prize_id', $relationId)
                ->firstOrFail();
            self::assertSame(90, (int) $inventory->total_quantity);
            self::assertSame(1, (int) $inventory->awarded_count);
            self::assertSame(89, (int) $inventory->available_quantity);
            self::assertSame(0, (int) $inventory->withdrawn_quantity);
            self::assertSame(
                (int) $inventory->total_quantity,
                (int) $inventory->awarded_count
                    + (int) $inventory->available_quantity
                    + (int) $inventory->withdrawn_quantity
            );
        } finally {
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
        }
    }

    public function test_concurrent_draw_and_inventory_adjustment_have_one_canonical_winner(): void
    {
        if (getenv('V2_OPERATIONAL_INVENTORY_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('MIG-062Sの明示的Concurrency検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Operational Inventory concurrency verification.');
        }
        $this->configureInventoryBoundary();
        $this->importGachas(1, totalCount: 1);
        $user = $this->inventoryUser('concurrency');
        $context = $this->inventoryAdminContext();
        $payload = $this->publishedInventoryPayload();
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int => $maximum
            )
        );

        $directory = sys_get_temp_dir().'/mig062s-inventory-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach (['draw', 'admin'] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Operational Inventory worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                try {
                    if ($worker === 'draw') {
                        app(V2DrawService::class)->create(
                            User::query()->findOrFail($user->id),
                            self::GACHA_ID,
                            1,
                            'operational-inventory-concurrent-draw',
                            (string) Str::uuid7()
                        );
                    } else {
                        app(V2CatalogMasterMutationService::class)->updateGachaDraftPrize(
                            $context,
                            self::GACHA_ID,
                            self::VERSION_ID,
                            self::PRIZE_A_ID,
                            'operational-inventory-concurrent-adjustment',
                            $payload
                        );
                    }
                    $result = ['status' => 'completed'];
                } catch (V2DrawException|V2CatalogException $exception) {
                    $result = [
                        'status' => 'rejected',
                        'code' => $exception->errorCode,
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'status' => 'failed',
                        'class' => get_class($exception),
                    ];
                }
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit($result['status'] === 'failed' ? 1 : 0);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        DB::reconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $results = [];
        foreach (['draw', 'admin'] as $worker) {
            $path = "{$directory}/{$worker}.json";
            $results[$worker] = json_decode(
                file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            unlink($path);
        }
        rmdir($directory);
        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'completed'
        ), json_encode($results, JSON_THROW_ON_ERROR));
        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'rejected'
        ), json_encode($results, JSON_THROW_ON_ERROR));

        $inventory = DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', self::PRIZE_A_ID)
            ->firstOrFail(['inventory.*']);
        self::assertSame(1, (int) $inventory->total_quantity);
        self::assertSame(0, (int) $inventory->available_quantity);
        self::assertSame(
            1,
            (int) $inventory->awarded_count + (int) $inventory->withdrawn_quantity
        );
        self::assertSame((int) $inventory->awarded_count, DB::table('draw_results')->count());
        self::assertSame(
            (int) $inventory->awarded_count,
            (int) DB::table('gacha_draw_states')->value('sold_count')
        );
        self::assertSame(
            (int) $inventory->withdrawn_quantity,
            DB::table('prize_inventory_adjustments')->count()
        );
    }

    public function test_concurrent_requests_cannot_exceed_daily_draw_limit(): void
    {
        if (getenv('V2_DRAW_LIMIT_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('MIG-061Jの明示的Concurrency検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for daily Draw limit concurrency verification.');
        }
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);

        $gachaId = $this->importGachas(1, 10)[0];
        $user = User::query()->create([
            'email_display' => 'daily-limit-'.Str::uuid().'@example.test',
            'email_normalized' => 'daily-limit-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            10_000,
            'daily-limit-concurrency-points'
        );

        $directory = sys_get_temp_dir().'/mig061j-concurrency-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Concurrency worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                try {
                    app(V2DrawService::class)->create(
                        User::query()->findOrFail($user->id),
                        $gachaId,
                        10,
                        "daily-limit-concurrent-{$index}",
                        (string) Str::uuid7()
                    );
                    $result = ['status' => 'completed'];
                } catch (V2DrawException $exception) {
                    $result = ['status' => 'rejected', 'code' => $exception->errorCode];
                }
                file_put_contents(
                    "{$directory}/{$index}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit(0);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        DB::reconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $results = [];
        foreach ([0, 1] as $index) {
            $path = "{$directory}/{$index}.json";
            $results[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            unlink($path);
        }
        rmdir($directory);

        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'completed'
        ));
        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => ($result['code'] ?? null)
                === 'DAILY_DRAW_LIMIT_EXCEEDED'
        ));
        self::assertSame(10, DB::table('draw_results')->count());
        self::assertSame(10, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame(1, DB::table('draw_requests')->where('status', 'completed')->count());
    }

    public function test_concurrent_requests_use_locked_remaining_count_for_partial_execution(): void
    {
        if (getenv('V2_DRAW_PARTIAL_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('MIG-062Jの明示的Concurrency検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for partial Draw concurrency verification.');
        }
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);

        $gachaId = $this->importGachas(1, totalCount: 1_000, soldCount: 850)[0];
        $inventories = DB::table('prize_inventories')->orderBy('id')->get();
        DB::table('prize_inventories')->where('id', $inventories[0]->id)->update([
            'available_quantity' => 0,
            'withdrawn_quantity' => 100,
        ]);
        DB::table('prize_inventories')->where('id', $inventories[1]->id)->update([
            'available_quantity' => 150,
            'withdrawn_quantity' => 750,
        ]);
        $user = User::query()->create([
            'email_display' => 'partial-concurrency-'.Str::uuid().'@example.test',
            'email_normalized' => 'partial-concurrency-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            100_000,
            'partial-concurrency-points'
        );

        $directory = sys_get_temp_dir().'/mig062j-concurrency-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Concurrency worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $response = app(V2DrawService::class)->create(
                    User::query()->findOrFail($user->id),
                    $gachaId,
                    100,
                    "partial-concurrent-{$index}",
                    (string) Str::uuid7()
                );
                file_put_contents(
                    "{$directory}/{$index}.json",
                    json_encode([
                        'requested' => $response['requested_count'],
                        'executed' => $response['executed_count'],
                    ], JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit(0);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        DB::reconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $responses = [];
        foreach ([0, 1] as $index) {
            $path = "{$directory}/{$index}.json";
            $responses[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            unlink($path);
        }
        rmdir($directory);

        self::assertSame([100, 100], array_column($responses, 'requested'));
        $executed = array_column($responses, 'executed');
        sort($executed);
        self::assertSame([50, 100], $executed);
        self::assertSame(150, DB::table('draw_results')->count());
        self::assertSame(150, DB::table('draw_results')->where('result_type', 'prize')->count());
        self::assertSame(0, DB::table('draw_results')->where('result_type', 'point_back')->count());
        self::assertSame(150, DB::table('user_prizes')->count());
        self::assertSame(150, (int) DB::table('prize_inventories')->sum('awarded_count'));
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('available_quantity'));
        self::assertSame(1_000, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame('sold_out', DB::table('gacha_draw_states')->value('status'));
    }

    public function test_same_and_separate_gacha_load_meets_merge_thresholds(): void
    {
        if (getenv('V2_DRAW_LOAD_TEST') !== '1') {
            self::markTestSkipped('Final候補Headの明示的Load検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for V2 Draw load verification.');
        }
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('l', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);

        $gachas = $this->importGachas(20);
        $users = [];
        foreach (range(1, 20) as $number) {
            $user = User::query()->create([
                'email_display' => "load-{$number}-".Str::uuid().'@example.test',
                'email_normalized' => "load-{$number}-".Str::uuid().'@example.test',
                'email_verified_at' => now(),
                'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
                'state' => V2UserState::Active,
            ]);
            app(V2PointService::class)->grantFree(
                $user->id,
                6_000_000,
                "draw-load-user-{$number}"
            );
            $users[] = $user->id;
        }

        $evidence = [];
        foreach ([5, 10, 20] as $concurrency) {
            $targets = array_fill(0, $concurrency, $gachas[0]);
            $evidence["same_gacha_{$concurrency}"] = $this->runConcurrent(
                array_slice($users, 0, $concurrency),
                $targets,
                "same-{$concurrency}"
            );
        }
        $evidence['separate_gacha_20'] = $this->runConcurrent(
            $users,
            array_slice($gachas, 0, 20),
            'separate-20'
        );

        fwrite(STDOUT, "\nMIG-051_CONCURRENCY_PERFORMANCE=".json_encode(
            $evidence,
            JSON_THROW_ON_ERROR
        )."\n");
        self::assertLessThanOrEqual(
            20_000,
            $evidence['same_gacha_10']['last_completion_ms']
        );
        foreach (['same_gacha_5', 'same_gacha_10', 'same_gacha_20', 'separate_gacha_20'] as $key) {
            self::assertSame(0, $evidence[$key]['failures']);
            self::assertSame(0, $evidence[$key]['unresolved_deadlocks']);
        }
        self::assertSame(55_000, DB::table('draw_results')->count());
        self::assertSame(
            55_000,
            (int) DB::table('gacha_draw_states')->sum('sold_count')
        );
        self::assertSame(
            DB::table('draw_results')->where('result_type', 'prize')->count(),
            DB::table('user_prizes')->count()
        );
        self::assertSame(
            0,
            DB::table('wallets')->where('paid_balance', '<', 0)
                ->orWhere('free_balance', '<', 0)->count()
        );
        self::assertSame(
            0,
            DB::table('prize_inventories')
                ->whereRaw('total_quantity <> awarded_count + available_quantity + withdrawn_quantity')->count()
        );
    }

    /**
     * @param list<int> $userIds
     * @param list<string> $gachaIds
     * @return array<string, int|float>
     */
    private function runConcurrent(
        array $userIds,
        array $gachaIds,
        string $scenario
    ): array {
        $directory = sys_get_temp_dir().'/mig051-load-'.getmypid().'-'.$scenario;
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 1.0;
        $children = [];
        foreach ($userIds as $index => $userId) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Load worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $queries = 0;
                $queryTypes = [];
                $lockWaitMs = 0.0;
                DB::listen(static function ($query) use (
                    &$queries,
                    &$queryTypes,
                    &$lockWaitMs
                ): void {
                    $queries++;
                    $type = strtoupper((string) strtok(ltrim($query->sql), " \t\n"));
                    $queryTypes[$type] = ($queryTypes[$type] ?? 0) + 1;
                    $sql = strtolower($query->sql);
                    if (
                        str_contains($sql, 'gacha_draw_states')
                        && str_contains($sql, 'for update')
                    ) {
                        $lockWaitMs += (float) $query->time;
                    }
                });
                $started = hrtime(true);
                try {
                    $response = app(V2DrawService::class)->create(
                        User::query()->findOrFail($userId),
                        $gachaIds[$index],
                        1000,
                        "load-{$scenario}-user-{$userId}",
                        (string) Str::uuid7()
                    );
                    $result = [
                        'status' => 'ok',
                        'duration_ms' => (hrtime(true) - $started) / 1_000_000,
                        'queries' => $queries,
                        'query_types' => $queryTypes,
                        'lock_wait_ms' => $lockWaitMs,
                        'response_size' => strlen(json_encode($response, JSON_THROW_ON_ERROR)),
                        'peak_memory' => memory_get_peak_usage(true),
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'status' => 'failed',
                        'class' => get_class($exception),
                    ];
                }
                file_put_contents(
                    "{$directory}/{$index}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit($result['status'] === 'ok' ? 0 : 1);
            }
            $children[$pid] = $index;
        }

        DB::disconnect();
        DB::reconnect();
        $unresolvedDeadlocks = 0;
        $peakConnections = 0;
        $pending = $children;
        while ($pending !== []) {
            $connections = DB::selectOne(
                'SELECT count(*)::int AS count FROM pg_stat_activity '.
                'WHERE datname = current_database()'
            );
            $peakConnections = max($peakConnections, (int) $connections->count);
            foreach ($pending as $pid => $_index) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === 0) {
                    continue;
                }
                unset($pending[$pid]);
                if (
                    $result === -1
                    || ! pcntl_wifexited($status)
                    || pcntl_wexitstatus($status) !== 0
                ) {
                    $unresolvedDeadlocks++;
                }
            }
            if ($pending !== []) {
                usleep(10_000);
            }
        }
        $rows = [];
        foreach (array_keys($userIds) as $index) {
            $path = "{$directory}/{$index}.json";
            $rows[] = is_file($path)
                ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['status' => 'failed'];
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
        $durations = array_column(
            array_filter($rows, static fn (array $row): bool => $row['status'] === 'ok'),
            'duration_ms'
        );
        $successful = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['status'] === 'ok'
        ));
        $lockWaits = array_column($successful, 'lock_wait_ms');
        sort($durations);
        sort($lockWaits);
        $failures = count($rows) - count($durations);

        return [
            'requests' => count($rows),
            'failures' => $failures,
            'unresolved_deadlocks' => $unresolvedDeadlocks,
            'p50_ms' => $this->percentile($durations, 0.50),
            'p95_ms' => $this->percentile($durations, 0.95),
            'max_ms' => $durations === [] ? 0 : round(max($durations), 3),
            'first_completion_ms' => $durations === [] ? 0 : round(min($durations), 3),
            'last_completion_ms' => $durations === [] ? 0 : round(max($durations), 3),
            'transaction_time_p95_ms' => $this->percentile($durations, 0.95),
            'lock_wait_p50_ms' => $this->percentile($lockWaits, 0.50),
            'lock_wait_p95_ms' => $this->percentile($lockWaits, 0.95),
            'lock_wait_max_ms' => $lockWaits === [] ? 0 : round(max($lockWaits), 3),
            'query_count_max' => $successful === []
                ? 0
                : max(array_column($successful, 'queries')),
            'query_types_max' => $this->queryTypeMaximums($successful),
            'response_size_max' => $successful === []
                ? 0
                : max(array_column($successful, 'response_size')),
            'peak_memory_max' => $successful === []
                ? 0
                : max(array_column($successful, 'peak_memory')),
            'postgres_connections_peak' => $peakConnections,
        ];
    }

    /**
     * @return list<string>
     */
    private function importGachas(
        int $count,
        int $dailyDrawLimit = 0,
        int $totalCount = 100_000,
        int $soldCount = 0
    ): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = $soldCount;
        $fixture['versions'][0]['total_count'] = $totalCount;
        $fixture['versions'][0]['daily_draw_limit'] = $dailyDrawLimit;
        $fixture['versions'][0]['allowed_draw_counts'] = [1, 5, 10, 100, 1000];
        $inventoryQuantities = [intdiv($totalCount, 10), $totalCount - intdiv($totalCount, 10)];
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $inventoryQuantities[$index] ?? 0;
        }
        unset($relation);
        $baseGacha = $fixture['gachas'][0];
        $baseVersion = $fixture['versions'][0];
        $baseRanks = $fixture['ranks'];
        $basePrizes = $fixture['prizes'];
        $baseRelations = $fixture['gacha_prizes'];
        $baseProbability = $fixture['probability_versions'][0];
        $cloneRecordCount = 3
            + (count($baseGacha['tag_codes']) * 2)
            + count($baseRanks)
            + count($basePrizes)
            + count($baseRelations);
        foreach ($baseProbability['stages'] as $stage) {
            $cloneRecordCount += count($stage['entries']) + 2;
        }
        for ($number = 2; $number <= $count; $number++) {
            $code = "fixture-catalog-{$number}";
            $gacha = $baseGacha;
            $gacha['public_id'] = $this->uuid($number, 11);
            $gacha['public_code'] = sprintf('Load%07d', $number);
            $gacha['code'] = $code;
            $gacha['slug'] = $code;
            $fixture['gachas'][] = $gacha;

            $version = $baseVersion;
            $version['public_id'] = $this->uuid($number, 12);
            $version['gacha_code'] = $code;
            $version['title'] = "Fixture Catalog Gacha {$number}";
            $fixture['versions'][] = $version;

            foreach ($baseRanks as $index => $rank) {
                $rank['public_id'] = $this->uuid($number, 90 + $index);
                $rank['gacha_code'] = $code;
                $fixture['ranks'][] = $rank;
            }

            $prizeCodes = [];
            foreach ($basePrizes as $index => $prize) {
                $sourceCode = $prize['code'];
                $prize['public_id'] = $this->uuid($number, 100 + $index);
                $prize['code'] = "{$sourceCode}-{$number}";
                $prizeCodes[$sourceCode] = $prize['code'];
                $fixture['prizes'][] = $prize;
            }
            foreach ($baseRelations as $relation) {
                $relation['gacha_code'] = $code;
                $relation['prize_code'] = $prizeCodes[$relation['prize_code']];
                $fixture['gacha_prizes'][] = $relation;
            }
            $probability = $baseProbability;
            $probability['public_id'] = $this->uuid($number, 13);
            $probability['gacha_code'] = $code;
            foreach ($probability['stages'] as $index => &$stage) {
                $stage['public_id'] = $this->uuid($number, 14 + $index);
                foreach ($stage['entries'] as &$entry) {
                    if ($entry['result_type'] === 'prize') {
                        $entry['prize_code'] = $prizeCodes[$entry['prize_code']];
                    }
                }
                unset($entry);
                if ($stage['minimum_guarantee']['result_type'] === 'prize') {
                    $stage['minimum_guarantee']['prize_code'] =
                        $prizeCodes[$stage['minimum_guarantee']['prize_code']];
                }
            }
            unset($stage);
            $fixture['probability_versions'][] = $probability;
        }
        $publicCodes = array_column($fixture['gachas'], 'public_code');
        self::assertCount($count, array_unique($publicCodes));
        foreach ($publicCodes as $publicCode) {
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{11}\z/', $publicCode);
        }
        $fixture['expected_record_count'] += ($count - 1) * $cloneRecordCount;
        app(V2CatalogFixtureImporter::class)->import($fixture);

        return array_column($fixture['gachas'], 'public_id');
    }

    private function uuid(int $group, int $suffix): string
    {
        return sprintf('0198a%03d-0000-7000-8000-%012d', $group, $suffix);
    }

    private function configureInventoryBoundary(): void
    {
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    private function inventoryUser(string $suffix): User
    {
        $email = "inventory-{$suffix}-".Str::uuid().'@example.test';
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            1_000,
            "operational-inventory-{$suffix}-points-".Str::uuid()
        );

        return $user;
    }

    private function inventoryAdminContext(): V2AdminAuthorizationContext
    {
        $publicId = (string) Str::uuid7();
        $email = 'operational-inventory-admin-'.Str::uuid().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $publicId,
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId(
            app(V2SessionPolicy::class)->issueOpaqueSessionId()
        );
        $createdAt = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return new V2AdminAuthorizationContext(
            $adminId,
            $publicId,
            V2AdminRole::Owner,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }

    /** @return array<string, mixed> */
    private function publishedInventoryPayload(): array
    {
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', self::VERSION_ID)
            ->firstOrFail();
        $prize = DB::table('catalog_prizes')
            ->where('public_id', self::PRIZE_A_ID)
            ->firstOrFail();
        $relation = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $version->id)
            ->where('prize_id', $prize->id)
            ->firstOrFail();
        $rankId = DB::table('catalog_ranks')
            ->where('id', $relation->rank_id)
            ->value('public_id');
        $assetId = $relation->presentation_asset_id === null
            ? null
            : DB::table('catalog_presentation_assets')
                ->where('id', $relation->presentation_asset_id)
                ->value('public_id');
        $inventoryRevision = DB::table('prize_inventories')
            ->where('gacha_version_prize_id', $relation->id)
            ->value('lock_version');

        return [
            'rank_id' => $rankId,
            'presentation_asset_id' => $assetId,
            'name' => $relation->display_name,
            'total_inventory' => 1,
            'available_inventory' => 0,
            'exchange_points' => (int) $relation->exchange_points,
            'cost_price' => (int) $relation->cost_price,
            'is_active' => (bool) $relation->is_visible,
            'expected_revision' => (int) $prize->revision,
            'expected_version_revision' => (int) $version->revision,
            'expected_inventory_revision' => (int) $inventoryRevision,
            'inventory_reason' => 'Concurrent operational inventory withdrawal',
        ];
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0;
        }
        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return round($values[$index], 3);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function queryTypeMaximums(array $rows): array
    {
        $maximums = [];
        foreach ($rows as $row) {
            foreach ($row['query_types'] as $type => $count) {
                $maximums[$type] = max($maximums[$type] ?? 0, (int) $count);
            }
        }
        ksort($maximums);

        return $maximums;
    }
}
