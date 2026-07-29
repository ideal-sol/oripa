<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class ZAdminProbabilityConcurrencyTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const GACHA_VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_concurrent_entry_replacement_has_one_canonical_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for Probability concurrency verification.');
        }
        $this->configureTestBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $context = $this->createAdminContext();
        $service = app(V2CatalogMasterMutationService::class);
        $draft = $service->createProbabilityDraft(
            $context,
            self::GACHA_ID,
            self::GACHA_VERSION_ID,
            'probability-concurrency-create',
            []
        )['data'];
        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060g-concurrency-{$token}.start";
        $resultPaths = [
            "/tmp/mig060g-concurrency-{$token}-a.json",
            "/tmp/mig060g-concurrency-{$token}-b.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the Probability concurrency process.');
                }
                if ($pid === 0) {
                    $this->runReplacement(
                        $context,
                        $draft['id'],
                        600000 - ($index * 100000),
                        "probability-concurrency-update-{$index}",
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
            self::assertSame(
                2,
                (int) DB::table('catalog_probability_versions')
                    ->where('public_id', $draft['id'])
                    ->value('revision')
            );
            self::assertSame(
                1,
                DB::table('catalog_probability_stages as stage')
                    ->join(
                        'catalog_probability_versions as version',
                        'version.id',
                        '=',
                        'stage.probability_version_id'
                    )
                    ->where('version.public_id', $draft['id'])
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

    private function runReplacement(
        V2AdminAuthorizationContext $context,
        string $probabilityVersionId,
        int $entryPpm,
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
            app(V2CatalogMasterMutationService::class)->replaceProbabilityEntries(
                $context,
                self::GACHA_ID,
                self::GACHA_VERSION_ID,
                $probabilityVersionId,
                $idempotencyKey,
                [
                    'expected_revision' => 1,
                    'stages' => [[
                        'code' => 'stage-1',
                        'name' => 'Stage 1',
                        'min_draw_number' => 1,
                        'max_draw_number' => null,
                        'entries' => [[
                            'result_type' => 'prize',
                            'prize_id' => self::PRIZE_S_ID,
                            'point_amount' => null,
                            'probability_ppm' => $entryPpm,
                        ]],
                        'minimum_guarantee' => [
                            'result_type' => 'prize',
                            'prize_id' => self::PRIZE_A_ID,
                            'point_amount' => null,
                            'probability_ppm' => 1000000 - $entryPpm,
                        ],
                    ]],
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

    private function createAdminContext(): V2AdminAuthorizationContext
    {
        $adminPublicId = (string) Str::uuid7();
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $adminPublicId,
            'email_display' => 'probability-concurrency@example.test',
            'email_normalized' => 'probability-concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid probability concurrency test password'),
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
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }
}
