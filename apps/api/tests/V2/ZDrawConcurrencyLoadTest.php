<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZDrawConcurrencyLoadTest extends TestCase
{
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
            now()->addYear(),
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
            now()->addYear(),
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
                now()->addYear(),
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
                ->whereColumn('won_count', '>', 'initial_quantity')->count()
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
        foreach ($fixture['gacha_prizes'] as &$relation) {
            $relation['initial_inventory'] = $totalCount;
        }
        unset($relation);
        $baseGacha = $fixture['gachas'][0];
        $baseVersion = $fixture['versions'][0];
        $basePrizes = $fixture['prizes'];
        $baseRelations = $fixture['gacha_prizes'];
        $baseProbability = $fixture['probability_versions'][0];
        $cloneRecordCount = 3
            + (count($baseGacha['tag_codes']) * 2)
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
