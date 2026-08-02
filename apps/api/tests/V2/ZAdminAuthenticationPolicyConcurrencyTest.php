<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminAuthenticationPolicyService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class ZAdminAuthenticationPolicyConcurrencyTest extends TestCase
{
    private const PASSWORD = 'valid concurrent owner password';

    public function test_concurrent_policy_update_has_one_revision_winner(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for authentication policy concurrency verification.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations-v2',
            '--force' => true,
        ]);
        $context = $this->ownerContext();
        $token = (string) Str::uuid7();
        $start = "/tmp/mig061b-policy-{$token}.start";
        $paths = [
            "/tmp/mig061b-policy-{$token}-0.json",
            "/tmp/mig061b-policy-{$token}-1.json",
        ];
        DB::disconnect();

        try {
            $children = [];
            foreach ($paths as $worker => $path) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::fail('Unable to start the policy process.');
                }
                if ($pid === 0) {
                    DB::purge();
                    DB::reconnect();
                    while (! file_exists($start)) {
                        usleep(1000);
                    }
                    file_put_contents($path, json_encode(
                        $this->update($context, $worker),
                        JSON_THROW_ON_ERROR
                    ));
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            file_put_contents($start, 'start');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::reconnect();
            $results = array_map(static fn (string $path): array => json_decode(
                file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR
            ), $paths);
            self::assertSame(1, count(array_filter(
                $results,
                static fn (array $result): bool => $result['result'] === 'success'
            )));
            self::assertSame(1, count(array_filter(
                $results,
                static fn (array $result): bool => $result['code']
                    === 'ADMIN_AUTHENTICATION_POLICY_REVISION_CONFLICT'
            )));
            self::assertSame(2, (int) DB::table('admin_authentication_policy')
                ->where('id', 1)->value('revision'));
        } finally {
            DB::reconnect();
            @unlink($start);
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
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

    /** @return array{result: string, code: ?string} */
    private function update(V2AdminAuthorizationContext $context, int $worker): array
    {
        try {
            app(V2AdminAuthenticationPolicyService::class)->update(
                $context,
                "auth-policy-concurrent-{$worker}",
                [
                    'expected_revision' => 1,
                    'mfa_required' => false,
                    'invitation_required' => true,
                ],
                self::PASSWORD
            );

            return ['result' => 'success', 'code' => null];
        } catch (V2AuthenticationException $exception) {
            return ['result' => 'failure', 'code' => $exception->errorCode];
        } catch (Throwable $exception) {
            return ['result' => 'unexpected', 'code' => $exception::class];
        }
    }

    private function ownerContext(): V2AdminAuthorizationContext
    {
        $publicId = (string) Str::uuid7();
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => $publicId,
            'email_display' => 'policy-concurrency@example.test',
            'email_normalized' => 'policy-concurrency@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash(self::PASSWORD),
            'role' => V2AdminRole::Owner->value,
            'state' => 'active',
        ]);
        $raw = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $hash = app(V2SessionPolicy::class)->hashSessionId($raw);
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
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
            $hash,
            hash('sha256', $hash),
            (string) Str::uuid7()
        );
    }
}
