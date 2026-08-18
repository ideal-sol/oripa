<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Domain\QaDraw\Services\V2QaPlanManagementService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZQaTestUserGuaranteeConcurrencyTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_concurrent_draw_and_replay_preserve_one_guarantee_per_request(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for QA guarantee concurrency verification.');
        }
        $user = $this->fixture();

        $distinct = $this->concurrent($user->id, ['qa-guarantee-a', 'qa-guarantee-b']);
        self::assertSame(2, count(array_filter($distinct, fn (array $row): bool => $row['ok'])));
        self::assertSame(2, count(array_unique(array_column($distinct, 'draw_id'))));
        self::assertSame(10, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame(10, DB::table('draw_results')->count());
        self::assertSame(2, DB::table('draw_results')->where('is_qa_draw', true)->count());
        self::assertSame(2, DB::table('qa_draw_executions')->count());

        $replay = $this->concurrent($user->id, ['qa-guarantee-replay', 'qa-guarantee-replay']);
        self::assertSame(2, count(array_filter($replay, fn (array $row): bool => $row['ok'])));
        self::assertSame(1, count(array_unique(array_column($replay, 'draw_id'))));
        self::assertSame(15, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame(15, DB::table('draw_results')->count());
        self::assertSame(3, DB::table('draw_results')->where('is_qa_draw', true)->count());
        self::assertSame(3, DB::table('qa_draw_executions')->count());
        self::assertSame(15, DB::table('user_prizes')->count());
        self::assertSame(15, (int) DB::table('prize_inventories')->sum('awarded_count'));
        self::assertSame(198_500, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(0, DB::table('prize_inventories')
            ->whereRaw('total_quantity <> awarded_count + available_quantity + withdrawn_quantity')->count());
    }

    public function test_migration_only_converts_current_modes_to_indefinite(): void
    {
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php',
            '--force' => true,
        ]);
        $admin = Admin::query()->create([
            'email_display' => 'qa-migration-owner@example.test',
            'email_normalized' => 'qa-migration-owner@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        $expired = $this->migrationUser('expired');
        $current = $this->migrationUser('current');
        foreach ([
            [$expired, DB::raw("CURRENT_TIMESTAMP - INTERVAL '2 hours'"), DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 hour'")],
            [$current, DB::raw('CURRENT_TIMESTAMP'), DB::raw("CURRENT_TIMESTAMP + INTERVAL '1 hour'")],
        ] as [$user, $startsAt, $endsAt]) {
            DB::table('qa_test_user_modes')->insert([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'is_enabled' => true,
                'reason' => 'Migration characterization',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'enabled_by_admin_id' => $admin->id,
                'revision' => 1,
                'created_at' => $startsAt,
                'updated_at' => $startsAt,
            ]);
        }

        Artisan::call('migrate', [
            '--path' => 'database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php',
            '--force' => true,
        ]);

        $expiredMode = DB::table('qa_test_user_modes')->where('user_id', $expired->id)->first();
        self::assertFalse((bool) $expiredMode->is_enabled);
        self::assertNotNull($expiredMode->ends_at);
        self::assertNotNull($expiredMode->disabled_at);
        $currentMode = DB::table('qa_test_user_modes')->where('user_id', $current->id)->first();
        self::assertTrue((bool) $currentMode->is_enabled);
        self::assertNull($currentMode->ends_at);
        self::assertNull($currentMode->disabled_at);
    }

    protected function tearDown(): void
    {
        DB::reconnect();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        parent::tearDown();
    }

    private function fixture(): User
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 100;
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $index === 0 ? 10 : 90;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static fn (int $minimum, int $maximum): int => $minimum
            )
        );
        $owner = Admin::query()->create([
            'email_display' => 'qa-concurrency-owner@example.test',
            'email_normalized' => 'qa-concurrency-owner@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        $user = User::query()->create([
            'email_display' => 'qa-concurrency-user@example.test',
            'email_normalized' => 'qa-concurrency-user@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            200_000,
            'qa-guarantee-concurrency-points'
        );
        $context = $this->context($owner);
        app(V2QaDrawAdminService::class)->saveMode(
            $context,
            $user->public_id,
            'QA concurrency verification'
        );
        app(V2QaPlanManagementService::class)->saveGachaGuarantee(
            $context,
            self::GACHA_ID,
            'qa-guarantee-concurrency-assignment',
            ['user_id' => $user->public_id, 'prize_id' => self::PRIZE_ID]
        );

        return $user;
    }

    private function migrationUser(string $suffix): User
    {
        return User::query()->create([
            'email_display' => "qa-migration-{$suffix}@example.test",
            'email_normalized' => "qa-migration-{$suffix}@example.test",
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
    }

    /** @param array{string, string} $keys @return list<array<string, mixed>> */
    private function concurrent(int $userId, array $keys): array
    {
        $token = (string) Str::uuid7();
        $start = "/tmp/mig062m-guarantee-{$token}.start";
        $paths = [
            "/tmp/mig062m-guarantee-{$token}-0.json",
            "/tmp/mig062m-guarantee-{$token}-1.json",
        ];
        DB::disconnect();
        try {
            $children = [];
            foreach ($paths as $worker => $path) {
                $pid = pcntl_fork();
                if ($pid === -1) self::fail('Unable to start QA guarantee worker.');
                if ($pid === 0) {
                    DB::purge();
                    DB::reconnect();
                    while (! file_exists($start)) usleep(1_000);
                    try {
                        $response = app(V2DrawService::class)->create(
                            User::query()->findOrFail($userId),
                            self::GACHA_ID,
                            5,
                            $keys[$worker],
                            (string) Str::uuid7()
                        );
                        $result = ['ok' => true, 'draw_id' => $response['id']];
                    } catch (\Throwable $exception) {
                        $result = ['ok' => false, 'error' => get_class($exception)];
                    }
                    file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
                    DB::disconnect();
                    exit($result['ok'] ? 0 : 1);
                }
                $children[] = $pid;
            }
            file_put_contents($start, 'start', LOCK_EX);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::reconnect();

            return array_map(
                static fn (string $path): array => json_decode(
                    file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $paths
            );
        } finally {
            DB::reconnect();
            @unlink($start);
            foreach ($paths as $path) @unlink($path);
        }
    }

    private function context(Admin $admin): V2AdminAuthorizationContext
    {
        $hash = hash('sha256', random_bytes(32));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(12),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
