<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Draw\Exceptions\V2DrawException;
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
use Throwable;

final class ZAdminGachaProbabilitySelectionConcurrencyTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PUBLISHED_GACHA_VERSION_ID =
        '0198a001-0000-7000-8000-000000000012';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_concurrent_selection_has_one_canonical_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Gacha selection concurrency verification.');
        }
        $this->configureTestBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $context = $this->createAdminContext();
        $service = app(V2CatalogMasterMutationService::class);
        $draft = $service->cloneGachaDraft(
            $context,
            self::GACHA_ID,
            self::PUBLISHED_GACHA_VERSION_ID,
            'gacha-selection-concurrency-clone',
            []
        )['data'];
        $probabilityDraft = $service->createProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-selection-concurrency-probability-create',
            []
        )['data'];
        $probabilityDraft = $service->replaceProbabilityEntries(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-selection-concurrency-probability-entries',
            [
                'expected_revision' => $probabilityDraft['revision'],
                'stages' => [[
                    'code' => 'stage-1',
                    'name' => 'Stage 1',
                    'min_draw_number' => 1,
                    'max_draw_number' => null,
                    'entries' => [[
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_S_ID,
                        'point_amount' => null,
                        'probability_ppm' => 600000,
                    ]],
                    'minimum_guarantee' => [
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_A_ID,
                        'point_amount' => null,
                        'probability_ppm' => 400000,
                    ],
                ]],
            ]
        )['data'];
        $published = $service->publishProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-selection-concurrency-probability-publish',
            ['expected_revision' => $probabilityDraft['revision']]
        )['data'];
        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060i-selection-{$token}.start";
        $resultPaths = [
            "/tmp/mig060i-selection-{$token}-a.json",
            "/tmp/mig060i-selection-{$token}-b.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the Gacha selection process.');
                }
                if ($pid === 0) {
                    $this->runSelection(
                        $context,
                        $draft['id'],
                        $published['id'],
                        (int) $draft['revision'],
                        "gacha-selection-concurrency-{$index}",
                        $startPath,
                        $resultPath
                    );
                }
                $children[] = $pid;
            }
            file_put_contents($startPath, 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            $results = array_map(
                static fn (string $path): array => json_decode(
                    file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $resultPaths
            );
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            ));
            $failures = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            ));
            self::assertCount(1, $failures);
            self::assertSame('CATALOG_REVISION_CONFLICT', $failures[0]['code']);

            DB::reconnect();
            $selected = DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])
                ->first(['published_probability_version_id', 'revision']);
            self::assertSame((int) $draft['revision'] + 1, (int) $selected->revision);
            self::assertSame(
                (int) DB::table('catalog_probability_versions')
                    ->where('public_id', $published['id'])
                    ->value('id'),
                (int) $selected->published_probability_version_id
            );
            self::assertSame(
                1,
                DB::table('outbox_messages')
                    ->where('aggregate_public_id', $draft['id'])
                    ->where('topic', 'catalog.change')
                    ->where(
                        'event_type',
                        'catalog.master.probability_selected'
                    )
                    ->count()
            );
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
            @unlink($startPath);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    public function test_concurrent_immediate_publish_has_one_atomic_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Gacha publish concurrency verification.');
        }
        $this->configureTestBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $context = $this->createAdminContext();
        $service = app(V2CatalogMasterMutationService::class);
        $draft = $service->cloneGachaDraft(
            $context,
            self::GACHA_ID,
            self::PUBLISHED_GACHA_VERSION_ID,
            'gacha-publish-concurrency-clone',
            []
        )['data'];
        $probabilityDraft = $service->createProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-publish-concurrency-probability-create',
            []
        )['data'];
        $probabilityDraft = $service->replaceProbabilityEntries(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-publish-concurrency-probability-entries',
            [
                'expected_revision' => $probabilityDraft['revision'],
                'stages' => [[
                    'code' => 'stage-1',
                    'name' => 'Stage 1',
                    'min_draw_number' => 1,
                    'max_draw_number' => null,
                    'entries' => [[
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_S_ID,
                        'point_amount' => null,
                        'probability_ppm' => 600000,
                    ]],
                    'minimum_guarantee' => [
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_A_ID,
                        'point_amount' => null,
                        'probability_ppm' => 400000,
                    ],
                ]],
            ]
        )['data'];
        $published = $service->publishProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-publish-concurrency-probability-publish',
            ['expected_revision' => $probabilityDraft['revision']]
        )['data'];
        $selected = $service->selectPublishedProbability(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-publish-concurrency-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $published['id'],
            ]
        )['data'];
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->value('revision');

        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060j-publish-{$token}.start";
        $resultPaths = [
            "/tmp/mig060j-publish-{$token}-a.json",
            "/tmp/mig060j-publish-{$token}-b.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the Gacha publish process.');
                }
                if ($pid === 0) {
                    $this->runImmediatePublish(
                        $context,
                        $draft['id'],
                        (int) $selected['revision'],
                        $gachaRevision,
                        "gacha-publish-concurrency-{$index}",
                        $startPath,
                        $resultPath
                    );
                }
                $children[] = $pid;
            }
            file_put_contents($startPath, 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            $results = array_map(
                static fn (string $path): array => json_decode(
                    file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $resultPaths
            );
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            ));
            $failures = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            ));
            self::assertCount(1, $failures);
            self::assertSame('CATALOG_REVISION_CONFLICT', $failures[0]['code']);

            DB::reconnect();
            $gacha = DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->first(['published_version_id', 'active_draw_state_id']);
            $version = DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])
                ->first(['id', 'status']);
            self::assertSame('published', $version->status);
            self::assertSame((int) $version->id, (int) $gacha->published_version_id);
            self::assertSame(
                (int) $version->id,
                (int) DB::table('gacha_draw_states')
                    ->where('id', $gacha->active_draw_state_id)
                    ->value('gacha_version_id')
            );
            self::assertSame(
                2,
                DB::table('gacha_draw_states')
                    ->where('gacha_id', DB::table('catalog_gachas')
                        ->where('public_id', self::GACHA_ID)
                        ->value('id'))
                    ->count()
            );
            self::assertSame(
                1,
                DB::table('outbox_messages')
                    ->where('aggregate_public_id', $draft['id'])
                    ->where('topic', 'catalog.change')
                    ->where(
                        'event_type',
                        'catalog.master.immediately_published'
                    )
                    ->count()
            );
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
            @unlink($startPath);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    public function test_concurrent_sales_pause_and_draw_have_one_atomic_order(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Gacha sales pause concurrency verification.');
        }
        $this->configureTestBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $context = $this->createAdminContext();
        $user = User::query()->create([
            'email_display' => 'gacha-sales-pause-draw@example.test',
            'email_normalized' => 'gacha-sales-pause-draw@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid gacha sales pause draw test password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            10_000,
            now()->addYear(),
            'gacha-sales-pause-draw-fixture'
        );
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->value('revision');
        $walletBefore = (int) DB::table('wallets')
            ->where('user_id', $user->id)
            ->value('free_balance');
        $resultsBefore = DB::table('draw_results')->count();
        $wonBefore = (int) DB::table('prize_inventories')->sum('won_count');

        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060l-pause-draw-{$token}.start";
        $pauseResultPath = "/tmp/mig060l-pause-draw-{$token}-pause.json";
        $drawResultPath = "/tmp/mig060l-pause-draw-{$token}-draw.json";
        DB::disconnect();

        try {
            $pausePid = pcntl_fork();
            if ($pausePid === -1) {
                self::fail('Unable to start the Gacha sales pause process.');
            }
            if ($pausePid === 0) {
                $this->runSalesPause(
                    $context,
                    $gachaRevision,
                    'gacha-sales-pause-draw-pause',
                    $startPath,
                    $pauseResultPath
                );
            }
            $drawPid = pcntl_fork();
            if ($drawPid === -1) {
                self::fail('Unable to start the Gacha Draw process.');
            }
            if ($drawPid === 0) {
                $this->runDraw(
                    $user->id,
                    'gacha-sales-pause-draw-request',
                    $startPath,
                    $drawResultPath
                );
            }

            file_put_contents($startPath, 'start');
            foreach ([$pausePid, $drawPid] as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            $pauseResult = json_decode(
                file_get_contents($pauseResultPath),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $drawResult = json_decode(
                file_get_contents($drawResultPath),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            self::assertSame('success', $pauseResult['result']);
            self::assertContains(
                $drawResult['result'],
                ['success', 'sales_paused']
            );

            DB::reconnect();
            self::assertTrue((bool) DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->value('sales_paused'));
            $drawSucceeded = $drawResult['result'] === 'success';
            self::assertSame(
                $resultsBefore + ($drawSucceeded ? 1 : 0),
                DB::table('draw_results')->count()
            );
            $createdResult = $drawSucceeded
                ? DB::table('draw_results')->orderByDesc('id')->first()
                : null;
            $pointBack = $createdResult === null
                ? 0
                : (int) ($createdResult->point_back_amount ?? 0);
            self::assertSame(
                $walletBefore - ($drawSucceeded ? 100 : 0) + $pointBack,
                (int) DB::table('wallets')
                    ->where('user_id', $user->id)
                    ->value('free_balance')
            );
            self::assertSame(
                $wonBefore + (
                    $createdResult !== null
                    && $createdResult->result_type === 'prize'
                        ? 1
                        : 0
                ),
                (int) DB::table('prize_inventories')->sum('won_count')
            );
            self::assertSame(
                1,
                DB::table('outbox_messages')
                    ->where('aggregate_public_id', self::GACHA_ID)
                    ->where('event_type', 'catalog.master.sales_paused')
                    ->count()
            );
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
            @unlink($startPath);
            @unlink($pauseResultPath);
            @unlink($drawResultPath);
        }
    }

    public function test_concurrent_unpublish_has_one_atomic_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Gacha unpublish concurrency verification.');
        }
        $this->configureTestBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $context = $this->createAdminContext();
        $service = app(V2CatalogMasterMutationService::class);
        $draft = $service->cloneGachaDraft(
            $context,
            self::GACHA_ID,
            self::PUBLISHED_GACHA_VERSION_ID,
            'gacha-unpublish-concurrency-clone',
            []
        )['data'];
        $probabilityDraft = $service->createProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-unpublish-concurrency-probability-create',
            []
        )['data'];
        $probabilityDraft = $service->replaceProbabilityEntries(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-unpublish-concurrency-probability-entries',
            [
                'expected_revision' => $probabilityDraft['revision'],
                'stages' => [[
                    'code' => 'stage-1',
                    'name' => 'Stage 1',
                    'min_draw_number' => 1,
                    'max_draw_number' => null,
                    'entries' => [[
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_S_ID,
                        'point_amount' => null,
                        'probability_ppm' => 600000,
                    ]],
                    'minimum_guarantee' => [
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_A_ID,
                        'point_amount' => null,
                        'probability_ppm' => 400000,
                    ],
                ]],
            ]
        )['data'];
        $probability = $service->publishProbabilityDraft(
            $context,
            self::GACHA_ID,
            $draft['id'],
            $probabilityDraft['id'],
            'gacha-unpublish-concurrency-probability-publish',
            ['expected_revision' => $probabilityDraft['revision']]
        )['data'];
        $selected = $service->selectPublishedProbability(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-unpublish-concurrency-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ]
        )['data'];
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->value('revision');
        $service->publishGachaVersionImmediately(
            $context,
            self::GACHA_ID,
            $draft['id'],
            'gacha-unpublish-concurrency-publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaRevision,
            ]
        );
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->value('revision');
        $paused = $service->pauseGachaSales(
            $context,
            self::GACHA_ID,
            'gacha-unpublish-concurrency-pause',
            [
                'expected_gacha_revision' => $gachaRevision,
                'reason_code' => 'operations_review',
            ]
        )['data'];

        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060m-unpublish-{$token}.start";
        $resultPaths = [
            "/tmp/mig060m-unpublish-{$token}-a.json",
            "/tmp/mig060m-unpublish-{$token}-b.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the Gacha unpublish process.');
                }
                if ($pid === 0) {
                    $this->runUnpublish(
                        $context,
                        (int) $paused['gacha_revision'],
                        "gacha-unpublish-concurrency-{$index}",
                        $startPath,
                        $resultPath
                    );
                }
                $children[] = $pid;
            }
            file_put_contents($startPath, 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map(
                static fn (string $path): array => json_decode(
                    file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $resultPaths
            );
            self::assertCount(1, array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            ));
            $failures = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            ));
            self::assertCount(1, $failures);
            self::assertSame('CATALOG_REVISION_CONFLICT', $failures[0]['code']);

            DB::reconnect();
            $gacha = DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)->firstOrFail();
            self::assertNull($gacha->published_version_id);
            self::assertNull($gacha->active_draw_state_id);
            self::assertTrue((bool) $gacha->sales_paused);
            self::assertSame(
                1,
                DB::table('outbox_messages')
                    ->where('aggregate_public_id', self::GACHA_ID)
                    ->where('event_type', 'catalog.master.unpublished')
                    ->count()
            );
        } finally {
            DB::reconnect();
            Artisan::call('migrate:fresh', [
                '--path' => 'database/migrations-v2',
                '--force' => true,
            ]);
            @unlink($startPath);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    private function runSelection(
        V2AdminAuthorizationContext $context,
        string $gachaVersionId,
        string $probabilityVersionId,
        int $revision,
        string $idempotencyKey,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            app(V2CatalogMasterMutationService::class)->selectPublishedProbability(
                $context,
                self::GACHA_ID,
                $gachaVersionId,
                $idempotencyKey,
                [
                    'expected_revision' => $revision,
                    'probability_version_id' => $probabilityVersionId,
                ]
            );
            $result = ['result' => 'success', 'code' => null];
        } catch (V2CatalogException $exception) {
            $result = ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    private function runImmediatePublish(
        V2AdminAuthorizationContext $context,
        string $gachaVersionId,
        int $revision,
        int $gachaRevision,
        string $idempotencyKey,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            app(V2CatalogMasterMutationService::class)
                ->publishGachaVersionImmediately(
                    $context,
                    self::GACHA_ID,
                    $gachaVersionId,
                    $idempotencyKey,
                    [
                        'expected_revision' => $revision,
                        'expected_gacha_revision' => $gachaRevision,
                    ]
                );
            $result = ['result' => 'success', 'code' => null];
        } catch (V2CatalogException $exception) {
            $result = ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    private function runSalesPause(
        V2AdminAuthorizationContext $context,
        int $gachaRevision,
        string $idempotencyKey,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            app(V2CatalogMasterMutationService::class)->pauseGachaSales(
                $context,
                self::GACHA_ID,
                $idempotencyKey,
                [
                    'expected_gacha_revision' => $gachaRevision,
                    'reason_code' => 'operations_review',
                ]
            );
            $result = ['result' => 'success', 'code' => null];
        } catch (V2CatalogException $exception) {
            $result = ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    private function runDraw(
        int $userId,
        string $idempotencyKey,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            app(V2DrawService::class)->create(
                User::query()->findOrFail($userId),
                self::GACHA_ID,
                1,
                $idempotencyKey,
                (string) Str::uuid7()
            );
            $result = ['result' => 'success', 'code' => null];
        } catch (V2DrawException $exception) {
            $result = [
                'result' => $exception->errorCode === 'GACHA_SALES_PAUSED'
                    ? 'sales_paused'
                    : 'failure',
                'code' => $exception->errorCode,
            ];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    private function runUnpublish(
        V2AdminAuthorizationContext $context,
        int $gachaRevision,
        string $idempotencyKey,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            app(V2CatalogMasterMutationService::class)->unpublishGacha(
                $context,
                self::GACHA_ID,
                $idempotencyKey,
                ['expected_gacha_revision' => $gachaRevision]
            );
            $result = ['result' => 'success', 'code' => null];
        } catch (V2CatalogException $exception) {
            $result = ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            $result = ['result' => 'unexpected', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    private function createAdminContext(): V2AdminAuthorizationContext
    {
        $adminPublicId = (string) Str::uuid7();
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $adminPublicId,
            'email_display' => 'gacha-selection-concurrency@example.test',
            'email_normalized' => 'gacha-selection-concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid gacha selection concurrency test password'),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $sessionToken = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId($sessionToken);
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

        return new V2AdminAuthorizationContext(
            $adminId,
            $adminPublicId,
            V2AdminRole::Owner,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function configureTestBoundary(): void
    {
        config([
            'cache.default' => 'array',
            'v2_audit.hmac_keys.v1' =>
                'base64:'.base64_encode(str_repeat('i', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }
}
