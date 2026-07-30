<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Domain\QaDraw\Services\V2QaPlanManagementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class ZQaPlanManagementConcurrencyTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000010';

    public function test_concurrent_plan_update_has_one_revision_winner(): void
    {
        [$context, $plan] = $this->fixturePlan();

        $results = $this->concurrent(
            fn (int $worker): array => $this->updatePlan(
                $context,
                $plan['id'],
                $worker
            ),
            'plan-update'
        );

        self::assertSame(1, $this->successCount($results));
        self::assertSame(['QA_REVISION_CONFLICT'], $this->failureCodes($results));
        self::assertSame(
            2,
            (int) DB::table('qa_draw_plans')
                ->where('public_id', $plan['id'])->value('revision')
        );
    }

    public function test_concurrent_assignment_has_one_revision_winner(): void
    {
        [$context, $plan] = $this->fixturePlan();
        $candidate = $this->createUser('candidate');
        app(V2QaDrawAdminService::class)->saveMode(
            $context,
            $candidate,
            'Concurrent assignment',
            null,
            now()->addHours(2)->toIso8601String()
        );

        $results = $this->concurrent(
            fn (int $worker): array => $this->assign(
                $context,
                $plan['id'],
                $candidate,
                $worker
            ),
            'assignment'
        );

        self::assertSame(1, $this->successCount($results));
        self::assertSame(['QA_REVISION_CONFLICT'], $this->failureCodes($results));
        self::assertSame(
            1,
            DB::table('qa_draw_plan_assignments as assignment')
                ->join('users as user', 'user.id', '=', 'assignment.user_id')
                ->where('user.public_id', $candidate)
                ->where('assignment.status', 'assigned')
                ->count()
        );
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

    /** @return array{V2AdminAuthorizationContext, array<string, mixed>} */
    private function fixturePlan(): array
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for QA management concurrency verification.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        app(V2CatalogFixtureImporter::class)->import(json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        ));
        $context = $this->createAdminContext();
        $user = $this->createUser('owner');
        $admin = app(V2QaDrawAdminService::class);
        $admin->saveMode(
            $context,
            $user,
            'Concurrent owner',
            null,
            now()->addHours(2)->toIso8601String()
        );

        return [
            $context,
            $admin->createPlan(
                $context,
                $user,
                self::GACHA_ID,
                'Concurrent QA Plan',
                'Concurrent verification',
                null,
                now()->addHours(2)->toIso8601String(),
                [[
                    'prize_id' => self::PRIZE_ID,
                    'quantity' => 10,
                    'sort_order' => 1,
                    'fixed_image_asset_id' => null,
                    'fixed_video_asset_id' => null,
                ]]
            ),
        ];
    }

    /** @return list<array{result: string, code: ?string}> */
    private function concurrent(callable $operation, string $scenario): array
    {
        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig060n-{$scenario}-{$token}.start";
        $resultPaths = [
            "/tmp/mig060n-{$scenario}-{$token}-0.json",
            "/tmp/mig060n-{$scenario}-{$token}-1.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $worker => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the QA management process.');
                }
                if ($pid === 0) {
                    DB::purge();
                    DB::reconnect();
                    while (! file_exists($startPath)) {
                        usleep(1000);
                    }
                    file_put_contents(
                        $resultPath,
                        json_encode($operation($worker), JSON_THROW_ON_ERROR)
                    );
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            file_put_contents($startPath, 'start');
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
                $resultPaths
            );
        } finally {
            DB::reconnect();
            @unlink($startPath);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    /** @return array{result: string, code: ?string} */
    private function updatePlan(
        V2AdminAuthorizationContext $context,
        string $planId,
        int $worker
    ): array {
        try {
            app(V2QaPlanManagementService::class)->updatePlan(
                $context,
                $planId,
                "qa-plan-concurrent-update-{$worker}",
                [
                    'revision' => 1,
                    'title' => "Concurrent QA Plan {$worker}",
                    'reason' => 'Concurrent verification',
                    'starts_at' => null,
                    'ends_at' => now()->addHours(2)->toIso8601String(),
                ]
            );

            return ['result' => 'success', 'code' => null];
        } catch (V2QaDrawException $exception) {
            return ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            return ['result' => 'unexpected', 'code' => $exception::class];
        }
    }

    /** @return array{result: string, code: ?string} */
    private function assign(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $userId,
        int $worker
    ): array {
        try {
            app(V2QaPlanManagementService::class)->assign(
                $context,
                $planId,
                "qa-plan-concurrent-assignment-{$worker}",
                ['revision' => 1, 'user_id' => $userId]
            );

            return ['result' => 'success', 'code' => null];
        } catch (V2QaDrawException $exception) {
            return ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            return ['result' => 'unexpected', 'code' => $exception::class];
        }
    }

    /** @param list<array{result: string, code: ?string}> $results */
    private function successCount(array $results): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => $result['result'] === 'success'
        ));
    }

    /** @param list<array{result: string, code: ?string}> $results */
    private function failureCodes(array $results): array
    {
        $codes = array_values(array_map(
            static fn (array $result): ?string => $result['code'],
            array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'failure'
            )
        ));
        sort($codes);

        return $codes;
    }

    private function createUser(string $suffix): string
    {
        $publicId = (string) Str::uuid7();
        DB::table('users')->insert([
            'public_id' => $publicId,
            'email_display' => "qa-concurrency-{$suffix}-{$publicId}@example.test",
            'email_normalized' => "qa-concurrency-{$suffix}-{$publicId}@example.test",
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid qa management concurrency password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function createAdminContext(): V2AdminAuthorizationContext
    {
        $publicId = (string) Str::uuid7();
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $publicId,
            'email_display' => 'qa-management-concurrency@example.test',
            'email_normalized' => 'qa-management-concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid qa management admin password'),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId($token);
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
            $publicId,
            V2AdminRole::Owner,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }
}
