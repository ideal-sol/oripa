<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankPaymentOriginPolicy;
use App\Domain\MemberRank\Services\V2MemberRankPrelaunchActivation;
use App\Domain\MemberRank\Services\V2MemberRankPrelaunchInspection;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Support\MemberRankGachaFixture;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class ZMemberRankPrelaunchActivationTest extends TestCase
{
    use MemberRankGachaFixture;

    public function test_atomic_publication_rejects_old_snapshots_and_preserves_exact_origins(): void
    {
        if (getenv('V2_PRELAUNCH_ACTIVATION_TEST') !== '1') {
            self::markTestSkipped('Requires a fresh dedicated disposable activation database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        Http::preventStrayRequests();
        $this->configureRankFixture();
        $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
        $count = getenv('V2_PRELAUNCH_ORIGIN_COUNT') === false ? 10 : (int) getenv('V2_PRELAUNCH_ORIGIN_COUNT');
        self::assertContains($count, [0, 10, 100, 1000, 10000]);
        $rewardAmount = (int) (getenv('V2_PRELAUNCH_BONUS_AMOUNT') ?: 500);
        self::assertContains($rewardAmount, [500, 750]);
        $admin = $this->rankAdmin();
        $token = $this->httpSession($admin);
        $this->postJson('/admin/api/v2/settings/member-ranks/prelaunch-activation', [])->assertUnauthorized();
        $this->httpActivation($token, [], false)->assertForbidden();
        $unapproved = ['authorization_id' => (string) Str::uuid7(), 'expected_revision' => 1];
        $this->httpActivation($token, $unapproved)->assertStatus(409)->assertJsonPath('code', 'MEMBER_RANK_PRELAUNCH_HOLD');
        $tokenHash = app(V2SessionPolicy::class)->hashSessionId($token);
        DB::table('admin_sessions')->where('session_id_hash', $tokenHash)->update(['mfa_verified_at' => now()->subMinutes(5)]);
        $this->httpActivation($token, $unapproved)->assertForbidden()->assertJsonPath('code', 'FRESH_MFA_REQUIRED');
        DB::table('admin_sessions')->where('session_id_hash', $tokenHash)->update(['mfa_verified_at' => now()]);
        $nonOwner = $this->rankAdmin();
        DB::table('admins')->where('id', $nonOwner->adminId)->update(['role' => 'admin']);
        $this->httpActivation($this->httpSession($nonOwner), $unapproved)->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        $applicant = $this->prelaunchApplicant();
        DB::table('pre_registration_settings')->where('id', 1)->update([
            'is_enabled' => false, 'cutoff_at' => now()->subSecond(), 'point_amount' => $rewardAmount,
        ]);
        app(V2PreRegistrationService::class)->activate($admin, (string) Str::uuid7(), [
            'expected_revision' => (int) DB::table('pre_registration_settings')->value('revision'),
        ]);
        self::assertSame($rewardAmount, (int) DB::table('wallets')->where('user_id', $applicant->id)->value('free_balance'));
        $oldUser = $this->rankUser();
        $otherUser = $this->rankUser();
        $oldPayments = [];
        $seedStarted = hrtime(true);
        for ($index = 0; $index < $count; $index++) {
            $oldPayments[] = $this->settleRankPayment($index % 2 === 0 ? $oldUser : $otherUser, 100);
        }
        $seedSeconds = (hrtime(true) - $seedStarted) / 1e9;
        self::assertSame([], app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        $authorityId = (string) Str::uuid7();
        $setting = DB::table('member_rank_settings')->sole();
        $authority = [
            'public_id' => $authorityId, 'site_code' => 'oripoke-prelaunch', 'setting_public_id' => $setting->public_id,
            'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
            'expected_revision' => $setting->revision,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
        ];
        $obsoleteId = (string) Str::uuid7();
        DB::table('member_rank_prelaunch_authorizations')->insert([
            ...$authority, 'public_id' => $obsoleteId, 'origin_digest' => hash('sha256', 'obsolete synthetic manifest'),
        ]);
        $this->httpActivation($token, ['authorization_id' => $obsoleteId, 'expected_revision' => (int) $setting->revision])
            ->assertStatus(409)->assertJsonPath('code', 'MEMBER_RANK_PRELAUNCH_HOLD');
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        DB::table('member_rank_prelaunch_authorizations')->insert($authority);
        self::assertSame(2, DB::table('member_rank_prelaunch_authorizations')->count());
        $service = app(V2MemberRankPrelaunchActivation::class);
        $input = ['authorization_id' => $authorityId, 'expected_revision' => (int) $setting->revision];
        $key = (string) Str::uuid7();
        $this->httpActivation($token, [...$input, 'expected_revision' => $input['expected_revision'] + 1])
            ->assertStatus(409)->assertJsonPath('code', 'MEMBER_RANK_SETTINGS_REVISION_CONFLICT');
        $default = DB::getDefaultConnection();
        config(['database.connections.activation_writer' => config('database.connections.'.$default)]);
        $writer = DB::connection('activation_writer');
        self::assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $before = $this->financialSnapshot();
        $auditBefore = DB::table('audit_logs')->count();
        try {
            $guardedTables = DB::select("SELECT relation.relname FROM pg_trigger trigger
                JOIN pg_class relation ON relation.oid = trigger.tgrelid
                JOIN pg_proc procedure ON procedure.oid = trigger.tgfoid
                WHERE procedure.proname = 'v2_financial_write_guard' ORDER BY relation.relname");
            self::assertCount(33, $guardedTables);
            foreach ($guardedTables as $table) {
                self::assertMatchesRegularExpression('/\A[a-z_]+\z/', $table->relname);
                $writer->beginTransaction();
                $writer->delete('DELETE FROM '.$table->relname.' WHERE false');
                try {
                    $service->activate($admin, $key, $input);
                    self::fail('Every guarded direct writer must prevent publication: '.$table->relname);
                } catch (V2MemberRankException $exception) {
                    self::assertSame('MEMBER_RANK_ACTIVATION_BUSY', $exception->errorCode);
                } finally {
                    $writer->rollBack();
                }
            }
            $writer->beginTransaction();
            $writer->select('SELECT v2_financial_admit()');
            $started = hrtime(true);
            try {
                $service->activate($admin, $key, $input);
                self::fail('A live financial transaction must prevent publication.');
            } catch (V2MemberRankException $exception) {
                self::assertSame('MEMBER_RANK_ACTIVATION_BUSY', $exception->errorCode);
            }
            $busySeconds = (hrtime(true) - $started) / 1e9;
            $writer->rollBack();
            self::assertSame($before, $this->financialSnapshot());
            self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
            $crash = true;
            DB::listen(function (QueryExecuted $query) use (&$crash, $default): void {
                if ($crash && $query->connectionName === $default && str_starts_with($query->sql, 'update "member_rank_settings"')) {
                    $crash = false;
                    throw new \RuntimeException('synthetic publication failure');
                }
            });
            $started = hrtime(true);
            try {
                $service->activate($admin, $key, $input);
                self::fail('Injected failure must roll back all publication state.');
            } catch (\RuntimeException $exception) {
                self::assertSame('synthetic publication failure', $exception->getMessage());
            }
            $failedSeconds = (hrtime(true) - $started) / 1e9;
            self::assertSame($before, $this->financialSnapshot());
            self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
            self::assertSame(0, DB::table('member_rank_prelaunch_excluded_origins')->count());
            self::assertSame(0, (int) DB::table('financial_admission_generations')->value('generation'));
            self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
            self::assertSame($auditBefore, DB::table('audit_logs')->count());
            $commitFault = true;
            DB::listen(function (QueryExecuted $query) use (&$commitFault, $default, $oldUser): void {
                if ($commitFault && $query->connectionName === $default && str_starts_with($query->sql, 'update "member_rank_settings"')) {
                    $commitFault = false;
                    $this->rankPayment($oldUser, 100);
                }
            });
            try {
                $service->activate($admin, $key, $input);
                self::fail('The final deferred publication constraint must reject a changed manifest.');
            } catch (\PDOException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
                self::assertStringContainsString('prelaunch publication is incomplete', $exception->getMessage());
            }
            self::assertSame($before, $this->financialSnapshot());
            self::assertSame($auditBefore, DB::table('audit_logs')->count());
            self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
            $terminatedBackend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $terminate = true;
            DB::listen(function (QueryExecuted $query) use (&$terminate, $default, $writer, $terminatedBackend): void {
                if ($terminate && $query->connectionName === $default
                    && str_starts_with($query->sql, 'update "member_rank_settings"')) {
                    $terminate = false;
                    self::assertTrue($writer->selectOne('SELECT pg_terminate_backend(?) AS terminated', [$terminatedBackend])->terminated);
                }
            });
            try {
                $service->activate($admin, $key, $input);
                self::fail('Loss of the publication backend must not commit an activation.');
            } catch (\PDOException $exception) {
                self::assertFalse($terminate);
            } finally {
                DB::purge($default);
            }
            self::assertNotSame($terminatedBackend, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
            self::assertSame($before, $this->financialSnapshot());
            self::assertSame($auditBefore, DB::table('audit_logs')->count());
            self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
            self::assertSame(0, DB::table('member_rank_prelaunch_excluded_origins')->count());
            self::assertSame(0, (int) DB::table('financial_admission_generations')->value('generation'));
            self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
            $writer->beginTransaction();
            $writer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertFalse((bool) $writer->table('member_rank_settings')->value('enabled'));
            $visibilityObserved = false;
            DB::listen(function (QueryExecuted $query) use ($writer, $default, &$visibilityObserved): void {
                if (! $visibilityObserved && $query->connectionName === $default
                    && str_starts_with($query->sql, 'update "member_rank_settings"')) {
                    $visibilityObserved = true;
                    self::assertFalse((bool) $writer->table('member_rank_settings')->value('enabled'));
                    self::assertSame(0, $writer->table('member_rank_prelaunch_runs')->count());
                }
            });
            $started = hrtime(true);
            $result = $this->httpActivation($token, $input, true, $key)->assertOk()->json();
            $publicationSeconds = (hrtime(true) - $started) / 1e9;
            self::assertTrue($visibilityObserved);
            self::assertTrue($result['data']['enabled']);
            self::assertSame($count, $result['data']['excluded_origin_count']);
            self::assertSame($before, $this->financialSnapshot());
            self::assertSame(0, DB::table('member_rank_point_entries')->count());
            self::assertSame(0, DB::table('member_rank_purchase_bonuses')->count());
            DB::setDefaultConnection('activation_writer');
            try {
                $this->settleRankPayment($oldUser, 10000);
                $writer->commit();
                self::fail('An old RR snapshot must not commit a paid grant without rank origin.');
            } catch (\Throwable $exception) {
                $error = $exception;
                while ($error->getPrevious() !== null) {
                    $error = $error->getPrevious();
                }
                self::assertSame('40001', $error->errorInfo[0] ?? $error->getCode());
            } finally {
                while ($writer->transactionLevel() > 0) {
                    $writer->rollBack();
                }
                DB::setDefaultConnection($default);
            }
            self::assertTrue((bool) $writer->table('member_rank_settings')->value('enabled'));
            self::assertSame($count, $writer->table('member_rank_prelaunch_excluded_origins')->count());
            $this->httpActivation($token, $input, true, $key)->assertOk()->assertJsonPath('idempotent_replay', true);
            $this->httpActivation($token, [...$input, 'expected_revision' => 2], true, $key)
                ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
            try {
                $service->activate($admin, (string) Str::uuid7(), $input);
                self::fail('A second activation must fail.');
            } catch (V2MemberRankException $exception) {
                self::assertSame('MEMBER_RANK_ALREADY_ACTIVATED', $exception->errorCode);
            }
            try {
                DB::transaction(fn () => DB::table('wallets')->where('user_id', $applicant->id)->update(['free_balance' => $rewardAmount]));
                self::fail('An unadmitted direct financial writer must fail closed.');
            } catch (QueryException $exception) {
                self::assertSame('40001', $exception->errorInfo[0]);
            }
            $origin = app(V2MemberRankPaymentOriginPolicy::class);
            foreach ($oldPayments as $oldPayment) {
                self::assertFalse($origin->eligible($oldPayment->id));
            }
            $grantFault = true;
            DB::listen(function (QueryExecuted $query) use (&$grantFault, $default): void {
                if ($grantFault && $query->connectionName === $default
                    && str_starts_with($query->sql, 'insert into "member_rank_purchase_bonuses"')) {
                    $grantFault = false;
                    throw new \RuntimeException('synthetic M4 failure');
                }
            });
            [$failedPayment, $failedEvent] = $this->rankPayment($applicant, 10000);
            try {
                app(V2PaymentService::class)->confirmSucceeded($failedEvent->id);
                self::fail('M4 failure must roll back paid Wallet/Lot and rank accounting.');
            } catch (\RuntimeException $exception) {
                self::assertSame('synthetic M4 failure', $exception->getMessage());
            }
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $failedPayment->id)->count());
            self::assertSame(0, DB::table('member_rank_point_entries')->count());
            self::assertSame($rewardAmount, (int) DB::table('wallets')->where('user_id', $applicant->id)->value('free_balance'));
            app(V2PaymentService::class)->confirmSucceeded($failedEvent->id);
            app(V2PaymentService::class)->confirmSucceeded($failedEvent->id);
            $newPayment = $failedPayment;
            self::assertTrue($origin->eligible($newPayment->id));
            self::assertSame(10000, (int) DB::table('member_rank_month_totals')->where('user_id', $applicant->id)->value('points'));
            self::assertSame(1, DB::table('member_rank_point_entries')->where('payment_id', $newPayment->id)->count());
            self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('payment_id', $newPayment->id)->count());
            self::assertSame($rewardAmount + 500, (int) DB::table('wallets')->where('user_id', $applicant->id)->value('free_balance'));
            if ($oldPayments !== []) {
                self::assertSame(DB::table('payment_point_grants')->where('payment_id', $oldPayments[0]->id)->value('granted_at'),
                    DB::table('payment_point_grants')->where('payment_id', $newPayment->id)->value('granted_at'));
                $oldRefund = app(V2AdminPaymentAdjustmentService::class)->write($admin, $oldPayments[0]->public_id,
                    'chargeback', ['reason' => 'Synthetic prelaunch test reversal'], (string) Str::uuid7());
                self::assertSame(0, $oldRefund['data']['impact']['rank_adjustment']['applied_points']);
            }
            $newRefund = app(V2AdminPaymentAdjustmentService::class)->write($admin, $newPayment->public_id,
                'chargeback', ['reason' => 'Synthetic post-cutover reversal'], (string) Str::uuid7());
            self::assertSame(10000, $newRefund['data']['impact']['rank_adjustment']['applied_points']);
            self::assertSame($rewardAmount, (int) DB::table('wallets')->where('user_id', $applicant->id)->value('free_balance'));
            self::assertSame(0, (int) DB::table('member_rank_month_totals')->where('user_id', $applicant->id)->value('points'));
            $this->importRankGachas();
            $gacha = $this->rankGacha('login_daily');
            $draw = $this->rankDraw($applicant, $gacha);
            self::assertNotEmpty($draw);
            self::assertSame(1, DB::table('draw_requests')->where('user_id', $applicant->id)->count());
            foreach (['member_rank_prelaunch_runs', 'member_rank_prelaunch_authorizations', 'member_rank_prelaunch_excluded_origins'] as $table) {
                if (DB::table($table)->exists()) {
                    try {
                        DB::transaction(fn () => DB::table($table)->delete());
                        self::fail('Published cutover records must be immutable.');
                    } catch (QueryException $exception) {
                        self::assertSame('P0001', $exception->errorInfo[0]);
                    }
                }
            }
            fwrite(STDOUT, "\nPRELAUNCH_IMPLEMENTATION ".json_encode([
                'origins' => $count, 'users' => 3, 'reward' => $rewardAmount, 'seed_seconds' => $seedSeconds,
                'busy_rejection_seconds' => $busySeconds, 'failed_publication_including_rollback_seconds' => $failedSeconds,
                'publication_including_commit_seconds' => $publicationSeconds,
            ], JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::setDefaultConnection($default);
            while ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            DB::purge('activation_writer');
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Http::assertNothingSent();
        }
    }

    private function prelaunchApplicant(): User
    {
        $origin = CarbonImmutable::parse('2026-10-21T01:00:00Z');
        $email = 'prelaunch-'.Str::uuid7().'@example.test';
        $user = new User([
            'email_display' => $email, 'email_normalized' => $email,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'), 'state' => 'active',
            'pre_registered_at' => $origin, 'email_verified_at' => $origin->addSecond(),
            'first_sms_verified_at' => $origin->addSeconds(2), 'first_registration_qualified_at' => $origin->addSecond(),
        ]);
        $user->forceFill(['created_at' => $origin])->save();
        $phone = '+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
        UserPhoneNumber::query()->create([
            'user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString($phone),
            'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => $origin->addSeconds(2),
        ]);

        return $user;
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'user_phone_numbers', 'pre_registration_grants', 'payments', 'payment_point_grants',
            'wallets', 'point_lots', 'point_operations', 'point_ledger_entries'] as $table) {
            $snapshot[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $snapshot;
    }

    private function httpSession(V2AdminAuthorizationContext $context): string
    {
        $policy = app(V2SessionPolicy::class);
        $token = $policy->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $policy->hashSessionId($token), 'admin_id' => $context->adminId,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false,
            'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(8),
        ]));

        return $token;
    }

    private function httpActivation(string $token, array $input, bool $csrfValid = true, ?string $key = null)
    {
        Auth::forgetGuards();
        $csrf = str_repeat('c', 64);

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $token)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrfValid ? $csrf : '', 'Idempotency-Key' => $key ?? (string) Str::uuid7()])
            ->postJson('/admin/api/v2/settings/member-ranks/prelaunch-activation', $input);
    }
}
