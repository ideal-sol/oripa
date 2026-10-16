<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class ZMemberRankSettingsConcurrencyTest extends TestCase
{
    public function test_competing_saves_and_same_key_replays_commit_once(): void
    {
        if (getenv('V2_MEMBER_RANK_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable member rank concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_memberrank_synthetic', DB::connection()->getDatabaseName());
        self::assertTrue(function_exists('pcntl_fork'));
        $email = 'member-rank-race@example.test';
        $admin = Admin::query()->create(['email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic password'),
            'role' => V2AdminRole::Admin, 'state' => 'active']);
        $policy = app(V2SessionPolicy::class);
        $hash = $policy->hashSessionId($policy->issueOpaqueSessionId());
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $hash, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->subSecond()->addHours(8),
        ]));
        $context = new V2AdminAuthorizationContext($admin->id, $admin->public_id, V2AdminRole::Admin, $hash, hash('sha256', $hash), (string) Str::uuid7());
        $service = app(V2MemberRankSettingsService::class);
        foreach ([false, true] as $sameKey) {
            $before = $service->read($context);
            $payload = ['expected_revision' => $before['revision'], 'enabled' => false,
                'default_skip_count' => 2, 'ranks' => $before['current']['ranks']];
            $key = (string) Str::uuid7();
            $processes = [];
            $startAt = microtime(true) + 0.5;
            DB::disconnect();
            foreach ([0, 1] as $index) {
                $path = tempnam(sys_get_temp_dir(), 'member-rank-race-');
                $processId = pcntl_fork();
                self::assertNotSame(-1, $processId);
                if ($processId === 0) {
                    while (microtime(true) < $startAt) {
                        usleep(1000);
                    }
                    DB::reconnect();
                    try {
                        $response = app(V2MemberRankSettingsService::class)->update($context, $sameKey || $index === 0 ? $key : (string) Str::uuid7(), $payload);
                        $outcome = $response['idempotent_replay'] ? 'replay' : 'saved';
                    } catch (V2MemberRankException $exception) {
                        $outcome = $exception->errorCode;
                    } catch (\Throwable $exception) {
                        $outcome = get_class($exception);
                    }
                    file_put_contents($path, $outcome);
                    DB::disconnect();
                    exit(0);
                }
                $processes[] = [$processId, $path];
            }
            $outcomes = [];
            foreach ($processes as [$processId, $path]) {
                pcntl_waitpid($processId, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
                $outcomes[] = file_get_contents($path);
                unlink($path);
            }
            DB::reconnect();
            sort($outcomes);
            self::assertSame($sameKey ? ['replay', 'saved'] : ['MEMBER_RANK_SETTINGS_REVISION_CONFLICT', 'saved'], $outcomes);
            self::assertSame($before['revision'] + 1, (int) DB::table('member_rank_settings')->value('revision'));
            self::assertSame($before['revision'], DB::table('audit_logs')->where('action_code', 'member_rank.settings.updated')->count());
            self::assertSame(7, DB::table('member_rank_setting_values')->where('version_revision', $before['revision'] + 1)->count());
        }
    }
}
