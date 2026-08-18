<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZQaDrawConcurrencyLoadTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_qa_draw_load_meets_merge_thresholds_without_normal_draw_regression(): void
    {
        if (getenv('V2_QA_DRAW_LOAD_TEST') !== '1') {
            self::markTestSkipped('Final候補Headの明示的QA Load検証で実行する。');
        }
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for V2 QA Draw load verification.');
        }
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('z', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        $this->importCatalog();
        $owner = $this->owner();

        $single = [];
        $performanceUser = $this->qaUser($owner, 5_500);
        foreach ([100, 1000] as $count) {
            for ($run = 1; $run <= 5; $run++) {
                $single[(string) $count][] = $this->measuredDraw(
                    $performanceUser,
                    $count,
                    "qa-single-{$count}-{$run}"
                );
            }
        }

        $fiveUsers = [];
        foreach (range(1, 5) as $number) {
            $fiveUsers[] = $this->qaUser($owner, 1_000)->id;
        }
        $tenUsers = [];
        foreach (range(1, 10) as $number) {
            $tenUsers[] = $this->qaUser($owner, 1_000)->id;
        }
        $sameFive = $this->concurrent($fiveUsers, 'qa-same-five');
        $sameTen = $this->concurrent($tenUsers, 'qa-same-ten');
        $evidence = [
            'single_100' => $this->summary($single['100']),
            'single_1000' => $this->summary($single['1000']),
            'same_gacha_5' => $sameFive,
            'same_gacha_10' => $sameTen,
            'draw_results' => DB::table('draw_results')->count(),
            'qa_executions' => DB::table('qa_draw_executions')->count(),
            'negative_wallets' => DB::table('wallets')->where('free_balance', '<', 0)->count(),
            'inventory_overflow' => DB::table('prize_inventories')
                ->whereRaw('total_quantity <> awarded_count + available_quantity + withdrawn_quantity')->count(),
        ];
        fwrite(STDOUT, "\nMIG-053_QA_PERFORMANCE=".json_encode(
            $evidence,
            JSON_THROW_ON_ERROR
        )."\n");

        self::assertLessThanOrEqual(2_000, $evidence['single_1000']['p95_ms']);
        self::assertLessThanOrEqual(150, $evidence['single_1000']['query_count_max']);
        self::assertLessThanOrEqual(20_000, $sameTen['last_completion_ms']);
        self::assertSame(0, $sameFive['failures']);
        self::assertSame(0, $sameTen['failures']);
        self::assertSame(0, $sameFive['unresolved_deadlocks']);
        self::assertSame(0, $sameTen['unresolved_deadlocks']);
        self::assertSame(0, $evidence['negative_wallets']);
        self::assertSame(0, $evidence['inventory_overflow']);
        self::assertSame(
            DB::table('draw_results')->where('result_type', 'prize')->count(),
            DB::table('user_prizes')->count()
        );
    }

    /** @return array<string, int|float|array<string, int>> */
    private function measuredDraw(User $user, int $count, string $key): array
    {
        $queries = 0;
        $types = [];
        DB::listen(static function ($query) use (&$queries, &$types): void {
            $queries++;
            $type = strtoupper((string) strtok(ltrim($query->sql), " \t\n"));
            $types[$type] = ($types[$type] ?? 0) + 1;
        });
        $started = hrtime(true);
        $response = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            $key,
            (string) Str::uuid7()
        );

        return [
            'duration_ms' => (hrtime(true) - $started) / 1_000_000,
            'queries' => $queries,
            'query_types' => $types,
            'response_size' => strlen(json_encode($response, JSON_THROW_ON_ERROR)),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * @param list<int> $userIds
     * @return array<string, int|float>
     */
    private function concurrent(array $userIds, string $scenario): array
    {
        $directory = sys_get_temp_dir().'/mig053-qa-load-'.getmypid().'-'.$scenario;
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 1.0;
        $children = [];
        foreach ($userIds as $index => $userId) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('QA load worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $lockWaitMs = 0.0;
                DB::listen(static function ($query) use (&$lockWaitMs): void {
                    if (
                        str_contains(strtolower($query->sql), 'gacha_draw_states')
                        && str_contains(strtolower($query->sql), 'for update')
                    ) {
                        $lockWaitMs += (float) $query->time;
                    }
                });
                $started = hrtime(true);
                try {
                    app(V2DrawService::class)->create(
                        User::query()->findOrFail($userId),
                        self::GACHA_ID,
                        1000,
                        "qa-load-{$scenario}-{$userId}",
                        (string) Str::uuid7()
                    );
                    $result = [
                        'ok' => true,
                        'duration_ms' => (hrtime(true) - $started) / 1_000_000,
                        'lock_wait_ms' => $lockWaitMs,
                    ];
                } catch (\Throwable $exception) {
                    $result = ['ok' => false, 'class' => get_class($exception)];
                }
                file_put_contents(
                    "{$directory}/{$index}.json",
                    json_encode($result, JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit($result['ok'] ? 0 : 1);
            }
            $children[$pid] = $index;
        }

        DB::disconnect();
        DB::reconnect();
        $deadlocks = 0;
        $peakConnections = 0;
        $pending = $children;
        while ($pending !== []) {
            $connection = DB::selectOne(
                'SELECT count(*)::int AS count FROM pg_stat_activity '.
                'WHERE datname = current_database()'
            );
            $peakConnections = max($peakConnections, (int) $connection->count);
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
                    $deadlocks++;
                }
            }
            if ($pending !== []) {
                usleep(10_000);
            }
        }
        $durations = [];
        $lockWaits = [];
        $failures = 0;
        foreach (array_keys($userIds) as $index) {
            $path = "{$directory}/{$index}.json";
            $row = is_file($path)
                ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false];
            if ($row['ok']) {
                $durations[] = (float) $row['duration_ms'];
                $lockWaits[] = (float) $row['lock_wait_ms'];
            } else {
                $failures++;
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
        sort($durations);
        sort($lockWaits);

        return [
            'requests' => count($userIds),
            'failures' => $failures,
            'unresolved_deadlocks' => $deadlocks,
            'p50_ms' => $this->percentile($durations, 0.50),
            'p95_ms' => $this->percentile($durations, 0.95),
            'last_completion_ms' => $durations === [] ? 0 : round(max($durations), 3),
            'lock_wait_p95_ms' => $this->percentile($lockWaits, 0.95),
            'postgres_connections_peak' => $peakConnections,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function summary(array $rows): array
    {
        $durations = array_column($rows, 'duration_ms');
        sort($durations);

        return [
            'runs' => count($rows),
            'p50_ms' => $this->percentile($durations, 0.50),
            'p95_ms' => $this->percentile($durations, 0.95),
            'query_count_max' => max(array_column($rows, 'queries')),
            'response_size_max' => max(array_column($rows, 'response_size')),
            'peak_memory_max' => max(array_column($rows, 'peak_memory')),
        ];
    }

    private function qaUser(Admin $owner, int $quantity): User
    {
        $user = User::query()->create([
            'email_display' => 'qa-load-'.Str::uuid().'@example.test',
            'email_normalized' => 'qa-load-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            2_000_000,
            'qa-load-points-'.$user->public_id
        );
        $service = app(V2QaDrawAdminService::class);
        $context = $this->adminContext($owner);
        $service->saveMode(
            $context,
            $user->public_id,
            'QA load verification'
        );
        $service->createPlan(
            $context,
            $user->public_id,
            self::GACHA_ID,
            'QA load plan',
            'QA load verification',
            null,
            now()->addHours(2)->toIso8601String(),
            [[
                'prize_id' => self::PRIZE_ID,
                'quantity' => $quantity,
                'sort_order' => 1,
            ]]
        );
        app(RateLimiter::class)->clear(
            'critical_admin_mutation:subject:'.hash_hmac(
                'sha256',
                $owner->public_id,
                (string) config('app.key')
            )
        );

        return $user;
    }

    private function adminContext(Admin $admin): V2AdminAuthorizationContext
    {
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)
                ->correlation($hash),
            (string) Str::uuid7()
        );
    }

    private function owner(): Admin
    {
        return Admin::query()->create([
            'email_display' => 'qa-load-owner@example.test',
            'email_normalized' => 'qa-load-owner@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
    }

    private function importCatalog(): void
    {
        if (DB::table('catalog_gachas')->where('public_id', self::GACHA_ID)->exists()) {
            return;
        }
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 100_000;
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $index === 0 ? 10_000 : 90_000;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0;
        }
        $index = (int) ceil(count($values) * $percentile) - 1;

        return round($values[max(0, min($index, count($values) - 1))], 3);
    }
}
