<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class ZPreRegistrationConcurrencyTest extends TestCase
{
    public function test_competing_admins_and_durable_partial_retry_use_real_transactions(): void
    {
        if (getenv('V2_PREREGISTRATION_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable pre-registration concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_prereg_v14_concurrency_synthetic', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::table('pre_registration_grants')->count());
        self::assertTrue(function_exists('pcntl_fork'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => []]);
        Carbon::setTestNow('2026-10-21T00:00:00Z');
        CarbonImmutable::setTestNow('2026-10-21T00:00:00Z');
        DB::table('pre_registration_settings')->where('id', 1)->update(['cutoff_at' => now(), 'is_enabled' => true]);
        $users = [];
        foreach ([1, 2, 3] as $number) {
            $email = 'prereg-race-'.$number.'@example.test';
            $user = new User(['email_display' => $email, 'email_normalized' => $email,
                'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic password'), 'state' => 'active',
                'pre_registered_at' => now()->subDays(2), 'email_verified_at' => now()->subDay(),
                'first_registration_qualified_at' => now()->subDay(), 'first_sms_verified_at' => now()->subDay()]);
            $user->forceFill(['created_at' => now()->subDays(2)])->save();
            $phone = '+81901234000'.$number;
            UserPhoneNumber::query()->create(['user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString($phone),
                'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => now()->subDay()]);
            $users[] = $user;
        }
        DB::unprepared("CREATE FUNCTION prereg_race_failure() RETURNS trigger AS \$\$ BEGIN IF NEW.source_type = 'pre_registration' THEN IF NEW.user_id = {$users[1]->id} THEN RAISE EXCEPTION 'synthetic partial failure'; END IF; PERFORM pg_sleep(1); END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER prereg_race_failure BEFORE INSERT ON point_operations FOR EACH ROW EXECUTE FUNCTION prereg_race_failure();");
        $contexts = [$this->context(), $this->context()];
        $keys = [(string) Str::uuid7(), (string) Str::uuid7()];
        $processes = [];
        $startAt = microtime(true) + 0.5;
        DB::disconnect();
        foreach ($contexts as $index => $context) {
            $path = tempnam(sys_get_temp_dir(), 'prereg-race-');
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                while (microtime(true) < $startAt) usleep(1000);
                DB::reconnect();
                try {
                    $response = app(V2PreRegistrationService::class)->activate($context, $keys[$index], ['expected_revision' => 1]);
                    $outcome = ['status' => 'completed', 'summary' => $response['data']['summary']];
                } catch (V2AuthenticationException $exception) {
                    $outcome = ['status' => $exception->errorCode];
                } catch (\Throwable $exception) {
                    $outcome = ['status' => get_class($exception)];
                }
                file_put_contents($path, json_encode($outcome, JSON_THROW_ON_ERROR));
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
            $outcomes[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            unlink($path);
        }
        DB::reconnect();
        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        self::assertSame(['IDEMPOTENCY_REQUEST_IN_PROGRESS', 'completed'], $statuses);
        self::assertSame(2, DB::table('pre_registration_grants')->count());
        self::assertSame(1000, (int) DB::table('wallets')->sum('free_balance'));
        self::assertEquals($users[1]->created_at, $users[1]->fresh()->created_at);
        self::assertDatabaseMissing('wallets', ['user_id' => $users[1]->id]);
        $timestamps = [$users[0]->fresh()->created_at, $users[2]->fresh()->created_at];
        DB::unprepared('DROP TRIGGER prereg_race_failure ON point_operations; DROP FUNCTION prereg_race_failure();');
        Carbon::setTestNow('2026-10-21T00:02:00Z');
        CarbonImmutable::setTestNow('2026-10-21T00:02:00Z');
        $result = app(V2PreRegistrationService::class)->activate($contexts[0], (string) Str::uuid7(), ['expected_revision' => 1]);
        self::assertSame(3, $result['data']['summary']['activated']);
        self::assertSame(0, $result['data']['summary']['failed']);
        self::assertSame(1500, (int) DB::table('wallets')->sum('free_balance'));
        self::assertEquals(now(), $users[1]->fresh()->created_at);
        self::assertEquals($timestamps[0], $users[0]->fresh()->created_at);
        self::assertEquals($timestamps[1], $users[2]->fresh()->created_at);
        foreach ($contexts as $index => $context) {
            app(V2PreRegistrationService::class)->activate($context, $keys[$index], ['expected_revision' => 1]);
        }
        self::assertSame(3, DB::table('pre_registration_grants')->count());
        self::assertSame(3, DB::table('point_operations')->where('source_type', 'pre_registration')->count());
        self::assertSame(3, DB::table('point_ledger_entries')->count());
        self::assertSame(3, DB::table('point_lots')->count());
        self::assertSame(3, DB::table('pre_registration_grants')->distinct()->count('phone_hmac'));
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function context(): V2AdminAuthorizationContext
    {
        $email = 'race-admin-'.Str::uuid7().'@example.test';
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
        return new V2AdminAuthorizationContext($admin->id, $admin->public_id, V2AdminRole::Admin, $hash, hash('sha256', $hash), (string) Str::uuid7());
    }
}
