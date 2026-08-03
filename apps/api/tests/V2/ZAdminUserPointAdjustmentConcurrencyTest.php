<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditHasher;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2AdminPointAdjustmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class ZAdminUserPointAdjustmentConcurrencyTest extends TestCase
{
    private const PASSWORD = 'valid concurrency admin password';

    public function test_concurrent_same_key_is_exactly_once_with_canonical_replay(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for point adjustment concurrency verification.');
        }
        $this->configureBoundary();
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        [$context, $userPublicId] = $this->fixture();
        $key = 'concurrent-point-adjustment-key';
        $token = (string) Str::uuid7();
        $startPath = "/tmp/mig061h-concurrency-{$token}.start";
        $resultPaths = [
            "/tmp/mig061h-concurrency-{$token}-a.json",
            "/tmp/mig061h-concurrency-{$token}-b.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($resultPaths as $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the point adjustment concurrency process.');
                }
                if ($pid === 0) {
                    $this->runAdjustment(
                        $context,
                        $userPublicId,
                        $key,
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
            self::assertSame(['success', 'success'], array_column($results, 'result'));
            $replays = array_column($results, 'replay');
            sort($replays);
            self::assertSame([false, true], $replays);

            DB::reconnect();
            self::assertSame(50, (int) DB::table('wallets')->value('paid_balance'));
            self::assertSame(1, DB::table('point_adjustments')->count());
            self::assertSame(1, DB::table('point_operations')->where('source_type', 'admin_adjustment')->count());
            self::assertSame(1, DB::table('point_ledger_entries')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action_code', 'point.admin_adjusted')->count());
            self::assertSame(1, DB::table('idempotency_records')->where('scope', 'point.admin_adjustment')->count());
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

    private function runAdjustment(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $key,
        string $startPath,
        string $resultPath
    ): never {
        DB::purge();
        DB::reconnect();
        $this->configureBoundary();
        while (! file_exists($startPath)) {
            usleep(1000);
        }

        try {
            $result = app(V2AdminPointAdjustmentService::class)->execute(
                $context,
                $userPublicId,
                $key,
                [
                    'point_type' => 'paid',
                    'direction' => 'grant',
                    'amount' => 50,
                    'reason' => 'Concurrent synthetic correction.',
                ],
                self::PASSWORD
            );
            $payload = ['result' => 'success', 'replay' => $result['idempotent_replay']];
        } catch (Throwable $exception) {
            $payload = ['result' => 'failure', 'code' => $exception::class];
        }
        file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
        DB::disconnect();
        exit(0);
    }

    /** @return array{V2AdminAuthorizationContext, string} */
    private function fixture(): array
    {
        $passwords = app(V2PasswordPolicy::class);
        $adminPublicId = (string) Str::uuid7();
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $adminPublicId,
            'email_display' => 'point-adjustment-concurrency-admin@example.test',
            'email_normalized' => 'point-adjustment-concurrency-admin@example.test',
            'email_verified_at' => now(),
            'password_hash' => $passwords->hash(self::PASSWORD),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $sessionToken = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId($sessionToken);
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
        ]);
        $userPublicId = (string) Str::uuid7();
        DB::table('users')->insert([
            'public_id' => $userPublicId,
            'display_name' => 'Concurrency target',
            'email_display' => 'point-adjustment-concurrency-user@example.test',
            'email_normalized' => 'point-adjustment-concurrency-user@example.test',
            'email_verified_at' => now(),
            'password_hash' => $passwords->hash('valid concurrency user password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            new V2AdminAuthorizationContext(
                $adminId,
                $adminPublicId,
                V2AdminRole::Owner,
                $sessionHash,
                app(V2AuditHasher::class)->correlation($sessionHash),
                (string) Str::uuid7()
            ),
            $userPublicId,
        ];
    }

    private function configureBoundary(): void
    {
        config([
            'cache.default' => 'array',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'oripa.free_point_expiration_days' => 180,
        ]);
    }
}
