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

    public function test_fastpath_exact_legacy_history_commits_through_http(): void
    {
        if (getenv('V2_PRELAUNCH_FASTPATH_TEST') !== '1') {
            self::markTestSkipped('Requires a fresh synthetic database migrated through 099 only.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        Http::preventStrayRequests();
        $this->artisan('migrate', ['--path' => 'database/migrations-v2/2026_10_28_000101_add_v2_account_closure.php', '--force' => true])->assertExitCode(0);
        $this->configureRankFixture();
        $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
        $admin = $this->rankAdmin();
        $token = $this->httpSession($admin);
        $protected = [];
        for ($i = 0; $i < 74; $i++) {
            if ($i < 2) {
                $protected[] = $this->rankUser(true);
            } else {
                // Reuse a synthetic password hash; hashing identical synthetic
                // passwords adds no activation coverage.
                $user = $protected[0]->replicate(['referral_code']);
                $user->created_at = $protected[0]->created_at;
                $user->public_id = (string) Str::uuid7();
                $user->email_display = $user->email_normalized = 'synthetic-'.Str::uuid7().'@example.test';
                $user->save();
                $protected[] = $user;
            }
        }
        foreach (array_slice($protected, 0, 2) as $user) {
            app(\App\Domain\Point\Services\V2AdminPointAdjustmentService::class)->execute(
                $admin, $user->public_id, (string) Str::uuid7(),
                ['point_type' => 'paid', 'direction' => 'grant', 'amount' => 650000, 'reason' => 'Synthetic approved historical test']
            );
        }
        $this->importRankGachas();
        $gacha = $this->rankGacha(overrides: ['price_points' => 4321, 'allowed_draw_counts' => [1, 5, 10, 100]]);
        $special = $this->rankGacha(overrides: ['price_points' => 4366]);
        foreach ([100, 10, 10, 10, 5, 5, 5, 5, 1, 1, 1, 1, 1, 1] as $i => $count) {
            app(\App\Domain\Draw\Services\V2DrawService::class)->create(
                $protected[$i % 2], ($i === 11 ? $special : $gacha)->public_id, $count,
                (string) Str::uuid7(), (string) Str::uuid7()
            );
        }
        self::assertSame(625879, (int) DB::table('wallets')->whereIn('user_id', [$protected[0]->id, $protected[1]->id])->sum('paid_balance'));
        self::assertSame(156, DB::table('user_prizes')->count());
        $shipping = app(\App\Domain\PrizeShipping\Services\V2PrizeShippingService::class);
        foreach (array_slice($protected, 0, 2) as $user) {
            $address = $shipping->createAddress($user, [
                'recipient_name' => 'Synthetic recipient', 'postal_code' => '000-0000',
                'prefecture' => '検証県', 'city' => '検証市', 'street' => '検証町1-2-3',
                'building' => null, 'phone_number' => '000-0000-0000',
            ], (string) Str::uuid7());
            $prizeIds = DB::table('user_prizes')->where('user_id', $user->id)->orderBy('id')->limit(10)->pluck('public_id')->all();
            $request = $shipping->createShippingRequest($user, $address['id'], $prizeIds, (string) Str::uuid7(), (string) Str::uuid7());
            $shipping->transitionShipping(\App\Models\V2\Admin::findOrFail($admin->adminId), $request['id'],
                'packing', null, null, 'Synthetic historical shipment', (string) Str::uuid7());
        }
        self::assertSame(2, DB::table('shipping_requests')->where('status', 'packing')->count());
        $payer = $this->rankUser();
        $phone = '+8190'.str_pad((string) $payer->id, 8, '0', STR_PAD_LEFT);
        UserPhoneNumber::query()->create([
            'user_id' => $payer->id, 'phone_ciphertext' => Crypt::encryptString($phone),
            'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => now(),
        ]);
        $payments = app(V2PaymentService::class);
        $planId = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'code' => 'synthetic-history', 'version_no' => 1,
            'name' => 'Synthetic historical plan', 'audience_code' => 'all_users', 'amount' => 100,
            'paid_point_amount' => 100, 'free_point_amount' => 0, 'currency' => 'JPY',
            'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pending = [];
        for ($i = 0; $i < 19; $i++) {
            $status = $i < 6 ? 'created' : ($i < 12 ? 'failed' : ($i < 18 ? 'processing' : 'requires_action'));
            $id = DB::table('payments')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'user_id' => $payer->id,
                'point_purchase_plan_id' => $planId,
                'provider_code' => 'fincode', 'provider_payment_id' => 'synthetic-history-'.Str::uuid7(),
                'status' => $status, 'amount' => 100, 'currency' => 'JPY', 'paid_point_amount' => 100,
                'free_point_amount' => 0, 'plan_name_snapshot' => 'Synthetic historical plan',
                'plan_code_snapshot' => 'synthetic-history', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('payment_commercial_classifications')->insert([
                'payment_id' => $id, 'classification' => 'test', 'reason' => 'pay1_existing_fincode',
            ]);
            if ($i < 6) {
                $event = $payments->recordVerifiedProviderEvent('fincode', 'synthetic-history-'.Str::uuid7(), 'payment.succeeded', '{}', [], $id, null, now());
                $payments->confirmSucceeded($event->id);
            }
            if ($status === 'processing') {
                $pending[] = $id;
            }
            DB::table('fincode_payment_attempts')->insert([
                'public_id' => (string) Str::uuid7(), 'payment_id' => $id,
                'provider_idempotency_key' => (string) Str::uuid7(),
                'status' => $i < 6 ? 'completed' : ($status === 'processing' ? 'pending' : $status),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        self::assertSame(19, DB::table('fincode_payment_attempts')->count());
        // Ordinary history is real synthetic financial activity, not approved
        // protected history:92 draws +46 grants +2 exchanges +6 paid grants =146 operations.
        $ordinaryGacha = $this->rankGacha();
        $points = app(\App\Domain\Point\Services\V2PointService::class);
        $points->grantFree($payer->id, 1000, 'synthetic-ordinary-fund');
        for ($i = 0; $i < 92; $i++) {
            $this->rankDraw($payer, $ordinaryGacha);
        }
        for ($i = 0; $i < 45; $i++) {
            $points->grantFree($payer->id, 1, 'synthetic-ordinary-history-'.$i);
        }
        foreach (DB::table('user_prizes')->where('user_id', $payer->id)->orderBy('id')->limit(2)->pluck('public_id') as $prizeId) {
            $shipping->exchange($payer, [$prizeId], (string) Str::uuid7(), (string) Str::uuid7());
        }
        self::assertSame(2, DB::table('point_operations')->where('source_type', 'prize_exchange')->count());
        self::assertSame(106, DB::table('draw_requests')->count());
        self::assertSame(162, DB::table('point_operations')->count());
        $paths = array_map(fn ($path) => 'database/migrations-v2/'.basename($path), glob(database_path('migrations-v2/*_00010*.php')));
        $ledger101 = array_values(array_filter($paths, fn ($path) => str_contains($path, '_000100_') || str_contains($path, '_000101_')));
        $this->artisan('migrate', ['--path' => $ledger101, '--force' => true])->assertExitCode(0);
        self::assertSame(101, DB::table('migrations')->count());
        $shadowFile = file_get_contents(base_path('../../docs/operations/deployment/member-rank-history-scope-shadow.sql'));
        $shadowSql = explode('-- SHADOW_SELECT_END', explode('-- SHADOW_SELECT_BEGIN', $shadowFile)[1])[0];
        $shadow = DB::transaction(function () use ($shadowSql): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            DB::statement("SET LOCAL TIME ZONE 'UTC'");

            return json_decode(DB::selectOne($shadowSql)->report, true, flags: JSON_THROW_ON_ERROR);
        });
        self::assertSame('on', $shadow['transaction_read_only']);
        self::assertSame([106, 162, 74, 14, 16, 6, 6], array_map(fn ($key) => $shadow[$key],
            ['global_draws', 'global_operations', 'protected_users', 'protected_draws', 'protected_operations', 'processing_payments', 'pending_attempts']));
        self::assertTrue($shadow['processing_pending_integrity']);
        self::assertSame(0, $shadow['unknown_attempts']);
        self::assertFalse($shadow['scope_and_evidence_match']);
        self::assertFalse($shadow['activation_authorized']);
        $this->artisan('migrate', ['--path' => $paths, '--force' => true])->assertExitCode(0);
        $inspection = app(V2MemberRankPrelaunchInspection::class);
        self::assertSame(['PROTECTED_DRAW_REQUESTS', 'PROTECTED_OTHER_POINT_OPERATIONS', 'PENDING_PAYMENTS', 'PENDING_FINCODE_ATTEMPTS'], $inspection->inspect()['data_holds']);
        self::assertSame(74, $inspection->inspect()['counts']['protected_applicants']);
        self::assertSame(14, $inspection->inspect()['counts']['protected_draw_requests']);
        self::assertSame(16, $inspection->inspect()['counts']['protected_other_point_operations']);
        $authority = [
            'public_id' => (string) Str::uuid7(), 'site_code' => 'oripoke-prelaunch',
            'setting_public_id' => DB::table('member_rank_settings')->value('public_id'),
            'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
            'expected_revision' => 1,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
        ];
        DB::table('member_rank_prelaunch_authorizations')->insert($authority);
        self::assertFalse($inspection->inspect($authority['public_id'])['history_scope_approved']);
        $this->httpActivation($token, ['authorization_id' => $authority['public_id'], 'expected_revision' => 1])->assertStatus(409);
        $scope = DB::selectOne('SELECT v2_prelaunch_history_scope()::text AS scope')->scope;
        $scopeItems = json_decode($scope, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(14, $scopeItems['draws']);
        self::assertSame($shadow['history_scope'], $scopeItems);
        self::assertSame($shadow['history_scope_sha256'], hash('sha256', $scope));
        self::assertCount(16, $scopeItems['operations']);
        self::assertCount(6, $scopeItems['payments']);
        self::assertCount(6, $scopeItems['attempts']);
        self::assertSame(DB::table('draw_requests')->whereIn('user_id', [$protected[0]->id, $protected[1]->id])->orderBy('id')->pluck('id')->all(), array_column($scopeItems['draws'], 'id'));
        self::assertSame(DB::table('point_operations')->whereIn('user_id', [$protected[0]->id, $protected[1]->id])->orderBy('id')->pluck('id')->all(), array_column($scopeItems['operations'], 'id'));
        // Prior approval of12/14 never approves the additional2 draws/2 operations.
        $oldScope = [...$scopeItems, 'draws' => array_slice($scopeItems['draws'], 0, 12),
            'operations' => array_slice($scopeItems['operations'], 0, 14)];
        $oldScope = DB::selectOne('SELECT ?::jsonb::text AS scope', [json_encode($oldScope, JSON_THROW_ON_ERROR)])->scope;
        $deltaSql = str_replace('NULL::jsonb AS approved_scope', '?::jsonb AS approved_scope', $shadowSql);
        $delta = DB::transaction(function () use ($deltaSql, $oldScope): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            DB::statement("SET LOCAL TIME ZONE 'UTC'");

            return json_decode(DB::selectOne($deltaSql, [$oldScope])->report, true, flags: JSON_THROW_ON_ERROR);
        });
        self::assertFalse($delta['scope_and_evidence_match']);
        self::assertCount(4, $delta['history_delta']);
        self::assertSame(['ADDED'], array_values(array_unique(array_column($delta['history_delta'], 'change'))));
        $oldEvidence = hash('sha256', 'Synthetic prior Human evidence for12 draws and14 operations only');
        $oldDigest = DB::selectOne("SELECT encode(sha256(convert_to(? || '|' || ?, 'UTF8')), 'hex') AS digest", [$oldScope, $oldEvidence])->digest;
        $oldId = (string) Str::uuid7();
        DB::table('member_rank_prelaunch_authorizations')->insert([
            ...$authority, 'public_id' => $oldId, 'history_scope' => $oldScope,
            'history_evidence_sha256' => $oldEvidence, 'history_approval_digest' => $oldDigest,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest(?::text) AS digest', [$oldDigest])->digest,
        ]);
        self::assertFalse($inspection->inspect($oldId)['history_scope_approved']);
        $this->httpActivation($token, ['authorization_id' => $oldId, 'expected_revision' => 1])->assertStatus(409);
        self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
        self::assertSame(0, (int) DB::table('financial_admission_generations')->value('generation'));
        // This explicit approval is synthetic only; Production's extra4 rows remain unapproved.
        $evidence = hash('sha256', 'Synthetic Human approval for exact historical test scope; no real site data');
        $approval = DB::selectOne("SELECT encode(sha256(convert_to(? || '|' || ?, 'UTF8')), 'hex') AS digest", [$scope, $evidence])->digest;
        $authority = [...$authority, 'public_id' => (string) Str::uuid7(), 'history_scope' => $scope,
            'history_evidence_sha256' => $evidence, 'history_approval_digest' => $approval,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest(?::text) AS digest', [$approval])->digest];
        DB::table('member_rank_prelaunch_authorizations')->insert($authority);
        self::assertSame([], $inspection->inspect($authority['public_id'])['data_holds']);
        try {
            DB::transaction(fn () => DB::table('member_rank_prelaunch_authorizations')->insert([
                ...$authority, 'public_id' => (string) Str::uuid7(), 'history_approval_digest' => str_repeat('0', 64),
            ]));
            self::fail('A forged scope/evidence digest must be rejected.');
        } catch (\PDOException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
        $input = ['authorization_id' => $authority['public_id'], 'expected_revision' => 1];
        foreach (['draw', 'draw_child', 'draw_prize', 'new_draw', 'operation', 'payment', 'attempt', 'attempt_identity', 'attempt_reference', 'commercial'] as $mutation) {
            try {
                DB::transaction(function () use ($mutation, $pending, $payer, $protected, $admin, $inspection, $authority, $gacha): void {
                    app(\App\Domain\Point\Services\V2FinancialAdmission::class)->close();
                    match ($mutation) {
                        'draw' => DB::table('draw_requests')->where('id', DB::table('draw_requests')->min('id'))->update(['request_id' => (string) Str::uuid7()]),
                        'draw_child' => DB::table('draw_results')->where('id', DB::table('draw_results')->min('id'))->update(['display_snapshot_sha256' => str_repeat('f', 64)]),
                        'draw_prize' => DB::table('user_prizes')->where('id', DB::table('user_prizes')->min('id'))->update(['updated_at' => now()->addSecond()]),
                        'new_draw' => app(\App\Domain\Draw\Services\V2DrawService::class)->create(
                            $protected[0], $gacha->public_id, 1,
                            (string) Str::uuid7(), (string) Str::uuid7()),
                        'attempt_reference' => DB::table('fincode_payment_attempts')->where('payment_id', $pending[0])->update(['payment_id' => $pending[1]]),
                        'attempt_identity' => DB::table('fincode_payment_attempts')->where('payment_id', $pending[0])->update(['public_id' => (string) Str::uuid7()]),
                        'operation' => app(\App\Domain\Point\Services\V2AdminPointAdjustmentService::class)->execute(
                            $admin, $protected[0]->public_id, (string) Str::uuid7(),
                            ['point_type' => 'paid', 'direction' => 'grant', 'amount' => 1, 'reason' => 'Synthetic unapproved operation']),
                        'payment' => DB::table('payments')->where('id', $pending[0])->update(['status' => 'failed']),
                        'attempt' => DB::table('fincode_payment_attempts')->where('payment_id', $pending[0])->update(['status' => 'uncertain']),
                        'commercial' => $this->settleRankPayment($payer, 100),
                    };
                    self::assertNotSame([], $inspection->inspectClosedAdmission($authority['public_id'])['data_holds']);
                    throw new \RuntimeException('synthetic mutation rollback');
                });
            } catch (\PDOException $error) {
                if (! in_array($mutation, ['draw_child', 'attempt_reference'], true)) {
                    throw $error;
                }
                self::assertSame($mutation === 'draw_child' ? 'P0001' : '23505', $error->errorInfo[0]);
                self::assertStringContainsString($mutation === 'draw_child'
                    ? 'draw ownership history is append-only' : 'fincode_payment_attempts_payment_id_unique', $error->getMessage());
            } catch (\RuntimeException $error) {
                self::assertSame('synthetic mutation rollback', $error->getMessage());
            }
            self::assertSame([], $inspection->inspect($authority['public_id'])['data_holds']);
        }
        $this->httpActivation($token, [...$input, 'expected_revision' => 2])->assertStatus(409);
        $before = $this->financialSnapshot();
        $prizes = json_encode(DB::table('user_prizes')->orderBy('id')->get());
        $shippingBefore = json_encode(DB::table('shipping_requests')->orderBy('id')->get());
        $auditBefore = DB::table('audit_logs')->count();
        $commitFault = true;
        DB::listen(function (QueryExecuted $query) use (&$commitFault): void {
            if ($commitFault && str_starts_with($query->sql, 'update "member_rank_settings"')) {
                $commitFault = false;
                DB::table('draw_requests')->where('id', DB::table('draw_requests')->min('id'))
                    ->update(['request_id' => (string) Str::uuid7()]);
            }
        });
        try {
            app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), $input);
            self::fail('A changed scope must fail the deferred Commit constraint.');
        } catch (\PDOException $error) {
            self::assertSame('P0001', $error->errorInfo[0]);
        }
        self::assertFalse($commitFault);
        self::assertSame($before, $this->financialSnapshot());
        self::assertSame($auditBefore, DB::table('audit_logs')->count());
        self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
        self::assertSame(0, DB::table('member_rank_prelaunch_excluded_origins')->count());
        self::assertSame(0, (int) DB::table('financial_admission_generations')->value('generation'));
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        $this->httpActivation($token, $input)->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.excluded_origin_count', 19);
        self::assertSame($before, $this->financialSnapshot());
        self::assertSame($prizes, json_encode(DB::table('user_prizes')->orderBy('id')->get()));
        self::assertSame($shippingBefore, json_encode(DB::table('shipping_requests')->orderBy('id')->get()));
        self::assertSame(1, (int) DB::table('financial_admission_generations')->value('generation'));
        self::assertSame(1, DB::table('member_rank_prelaunch_runs')->count());
        self::assertSame(19, DB::table('member_rank_prelaunch_excluded_origins')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'member_rank.prelaunch.activated')->count());
        self::assertNotNull(DB::table('member_rank_settings')->value('activated_at'));
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        self::assertSame(0, DB::table('member_rank_purchase_bonuses')->count());
        self::assertSame(0, $inspection->inspect()['counts']['financial_discrepancies']);
        $this->httpActivation($token, $input)->assertStatus(409);
        $late = $payments->recordVerifiedProviderEvent('fincode', 'synthetic-late-'.Str::uuid7(), 'payment.succeeded', '{}', [], $pending[0], null, now());
        try {
            $payments->confirmSucceeded($late->id);
            self::fail('Excluded processing origin must never acquire a new grant.');
        } catch (\Throwable $error) {
            self::assertSame('PAYMENT_PRELAUNCH_ORIGIN_EXCLUDED', $error->getMessage());
        }
        self::assertSame(6, DB::table('payment_point_grants')->count());
        $this->settleRankPayment($protected[0], 100);
        self::assertSame(1, DB::table('member_rank_point_entries')->where('reason', 'payment')->count());
        self::assertSame(1, DB::table('member_rank_purchase_bonuses')->count());
        self::assertSame(0, $inspection->inspect()['counts']['financial_discrepancies']);
    }

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
            $oldPayments[] = $this->settleRankPayment($index % 2 === 0 ? $oldUser : $otherUser, 100, commercial: false);
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
            self::assertCount(34, $guardedTables);
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

    public function test_remaining_notification_admission_recovery_and_writer_measurements(): void
    {
        if (getenv('V2_PRELAUNCH_REMAINING_CASE') !== 'notification') {
            self::markTestSkipped('Requires a fresh dedicated disposable activation database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        Http::preventStrayRequests();
        $this->configureRankFixture();
        $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
        config([
            'app.debug' => false,
            'v2_fincode.enabled' => true, 'v2_fincode.base_url' => 'https://api.test.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_test_synthetic',
            'v2_fincode.public_api_key' => 'p_test_synthetic',
            'v2_fincode.platform_origin' => 'https://api.example.test',
            'v2_fincode.storefront_origin' => 'https://example.test',
            'v2_fincode.admin_origin' => 'https://admin.example.test',
            'v2_fincode.webhook_signature' => 'synthetic-acceptance-signature',
            'v2_line.messaging.channel_secret' => 'synthetic-acceptance-secret',
            'v2_line.messaging.channel_access_token' => 'synthetic-token',
            'v2_line.messaging.login_relative_path' => '/login',
        ]);
        $admin = $this->rankAdmin();
        $user = $this->rankUser();
        $old = $this->settleRankPayment($user, 100, commercial: false);
        DB::table('payments')->where('id', $old->id)->update(['provider_payment_id' => 'acceptance-old']);
        $canonical = ['id' => 'acceptance-old', 'pay_type' => 'Card', 'status' => 'CAPTURED',
            'amount' => '100', 'tax' => '0', 'transaction_date' => now()->format('Y/m/d H:i:s')];
        $providerMutations = 0;
        Http::fake(function ($request) use (&$canonical, &$providerMutations) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/sessions')) {
                $providerMutations++;

                return Http::response(['id' => 'synthetic-session', 'link_url' => 'https://pay.test.fincode.jp/synthetic-session']);
            }

            return Http::response($canonical);
        });
        $webhook = function (string $order): \Illuminate\Testing\TestResponse {
            return $this->postJson('/webhooks/v2/fincode', [
                'event' => 'payments.card.capture', 'pay_type' => 'Card', 'order_id' => $order,
            ], ['Fincode-Signature' => 'synthetic-acceptance-signature']);
        };
        $receiptCount = DB::table('payment_provider_events')->count();
        $setting = DB::table('member_rank_settings')->sole();
        $authority = (string) Str::uuid7();
        DB::table('member_rank_prelaunch_authorizations')->insert([
            'public_id' => $authority, 'site_code' => 'oripoke-prelaunch', 'setting_public_id' => $setting->public_id,
            'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
            'expected_revision' => $setting->revision,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
        ]);
        $default = DB::getDefaultConnection();
        config(['database.connections.acceptance_writer' => config('database.connections.'.$default)]);
        $writer = DB::connection('acceptance_writer');
        $publisherPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        self::assertNotSame($publisherPid, $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $metrics = [];
        $observed = false;
        $transactionStarted = null;
        app('events')->listen(\Illuminate\Database\Events\TransactionBeginning::class,
            function ($event) use (&$transactionStarted, $default): void {
                if ($transactionStarted === null && $event->connectionName === $default) {
                    $transactionStarted = hrtime(true);
                }
            });
        app('events')->listen(\Illuminate\Database\Events\TransactionCommitted::class,
            function ($event) use (&$metrics, &$transactionStarted, $default): void {
                if (! isset($metrics['publication_tx_with_probe_seconds']) && $transactionStarted !== null
                    && $event->connectionName === $default && $event->connection->transactionLevel() === 0) {
                    $metrics['publication_tx_with_probe_seconds'] = (hrtime(true) - $transactionStarted) / 1e9;
                }
            });
        $metrics['timeouts'] = (array) $writer->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout,
            current_setting('lock_timeout') AS lock_timeout, current_setting('deadlock_timeout') AS deadlock_timeout");
        $wal = $writer->selectOne('SELECT pg_current_wal_insert_lsn() AS position')->position;
        $sizeBefore = $writer->selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes;
        DB::listen(function (QueryExecuted $query) use (&$observed, &$metrics, $default, $writer, $publisherPid, $webhook, $user, $admin, $authority, $setting): void {
            if ($observed || $query->connectionName !== $default || ! str_starts_with($query->sql, 'update "member_rank_settings"')) {
                return;
            }
            $observed = true;
            $activity = $writer->selectOne('SELECT clock_timestamp() AS observed_at, xact_start, state, wait_event_type,
                EXTRACT(EPOCH FROM clock_timestamp() - xact_start) AS age FROM pg_stat_activity WHERE pid = ?', [$publisherPid]);
            $metrics['publication_before_probe'] = (array) $activity;
            DB::setDefaultConnection('acceptance_writer');
            try {
                $started = hrtime(true);
                $response = $webhook('acceptance-old')->assertStatus(500);
                self::assertArrayNotHasKey('receive', $response->json());
                $metrics['webhook_refusal_seconds'] = (hrtime(true) - $started) / 1e9;
                $metrics['webhook_response'] = $response->json();
                $started = hrtime(true);
                try {
                    app(\App\Domain\Point\Services\V2PointService::class)->grantFree($user->id, 7, 'acceptance-blocked-point');
                    self::fail('A real Point writer must reject the closed gate.');
                } catch (QueryException $exception) {
                    self::assertSame('40001', $exception->errorInfo[0]);
                }
                $metrics['point_refusal_seconds'] = (hrtime(true) - $started) / 1e9;
                self::assertSame(0, $writer->transactionLevel());
                self::assertFalse((bool) $writer->table('member_rank_settings')->value('enabled'));
                self::assertSame(0, $writer->table('member_rank_prelaunch_runs')->count());
                try {
                    app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), [
                        'authorization_id' => $authority, 'expected_revision' => (int) $setting->revision,
                    ]);
                    self::fail('A simultaneous ON transaction must not enter the closed fence.');
                } catch (V2MemberRankException $exception) {
                    self::assertSame('MEMBER_RANK_ACTIVATION_BUSY', $exception->errorCode);
                }
            } finally {
                DB::setDefaultConnection($default);
            }
        });
        $started = hrtime(true);
        app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), [
            'authorization_id' => $authority, 'expected_revision' => (int) $setting->revision,
        ]);
        $metrics['publication_with_probe_seconds'] = (hrtime(true) - $started) / 1e9;
        self::assertTrue($observed);
        self::assertSame($receiptCount, DB::table('payment_provider_events')->count());
        $metrics['wal_bytes'] = (int) $writer->selectOne('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(), ?::pg_lsn) AS bytes', [$wal])->bytes;
        $metrics['database_growth_bytes'] = (int) $writer->selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes - (int) $sizeBefore;
        self::assertNull($writer->selectOne('SELECT xact_start FROM pg_stat_activity WHERE pid = ?', [$publisherPid])->xact_start);
        $webhook('acceptance-old')->assertOk()->assertJsonPath('receive', '0');
        $receiptCount++;
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        self::assertSame(1, DB::table('payment_point_grants')->count());
        $payments = app(V2PaymentService::class);
        $new = $this->createCommercialRankPayment($payments, $user->id, $old->point_purchase_plan_id, 'fincode', 'acceptance-new', (string) Str::uuid7(), 'credit_card');
        app(V2PointTransactionRunner::class)->run(fn () => DB::table('payments')->where('id', $new->id)->update(['provider_payment_id' => 'acceptance-new']));
        $canonical['id'] = 'acceptance-new';
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        $started = hrtime(true);
        $webhook('acceptance-new')->assertStatus(500);
        self::assertSame($receiptCount, DB::table('payment_provider_events')->count());
        $metrics['new_notification_refusal_seconds'] = (hrtime(true) - $started) / 1e9;
        $writer->rollBack();
        $webhook('acceptance-new')->assertOk()->assertJsonPath('receive', '0');
        $snapshot = $this->financialSnapshot();
        $webhook('acceptance-new')->assertOk()->assertJsonPath('receive', '0');
        self::assertSame($snapshot, $this->financialSnapshot());
        self::assertSame(2, DB::table('payment_point_grants')->count());
        self::assertSame(1, DB::table('member_rank_point_entries')->where('payment_id', $new->id)->count());
        self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('payment_id', $new->id)->count());
        foreach (['40001' => 3, '40P01' => 3, '55P03' => 1] as $state => $expectedAttempts) {
            $state = (string) $state;
            $attempts = 0;
            try {
                app(V2PointTransactionRunner::class)->run(function () use (&$attempts, $state): void {
                    $attempts++;
                    DB::unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'synthetic retry exhaustion' USING ERRCODE = '".$state."'; END \$\$");
                });
                self::fail('Retry exhaustion must propagate, not ACK success.');
            } catch (QueryException $exception) {
                self::assertSame($state, $exception->errorInfo[0]);
            }
            self::assertSame($expectedAttempts, $attempts);
            self::assertSame(0, DB::transactionLevel());
            self::assertSame($snapshot, $this->financialSnapshot());
        }
        $refund = $payments->reserveFullRefund($new->id, (string) Str::uuid7());
        $operationKey = (string) Str::uuid7();
        $request = ['amount' => 100];
        $operation = $payments->beginProviderRefundOperation($new->id, $refund->id, $operationKey, $request);
        self::assertSame('pending', $operation->status);
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        foreach ([
            fn () => $payments->beginProviderRefundOperation($new->id, $refund->id, (string) Str::uuid7(), $request),
            fn () => $payments->resolveProviderOperation($operation->id, 'uncertain'),
            fn () => $payments->resolveRefund($refund->id, 'uncertain'),
        ] as $rejected) {
            try {
                $rejected();
                self::fail('Operation persistence must reject the closed fence.');
            } catch (QueryException $exception) {
                self::assertSame('40001', $exception->errorInfo[0]);
            }
        }
        self::assertSame(1, DB::table('payment_provider_operations')->count());
        self::assertSame('pending', DB::table('payment_provider_operations')->where('id', $operation->id)->value('status'));
        $writer->rollBack();
        self::assertSame($operation->id, $payments->beginProviderRefundOperation($new->id, $refund->id, $operationKey, $request)->id);
        self::assertSame('uncertain', $payments->resolveProviderOperation($operation->id, 'uncertain')->status);
        self::assertSame('processing', $payments->resolveRefund($refund->id, 'uncertain')->status);
        self::assertContains('PENDING_PROVIDER_OPERATIONS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        self::assertContains('PENDING_ADJUSTMENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        self::assertSame('succeeded', $payments->resolveProviderOperation($operation->id, 'succeeded', ['confirmed' => true])->status);
        self::assertSame('succeeded', $payments->resolveRefund($refund->id, 'succeeded')->status);
        $refundedSnapshot = $this->financialSnapshot();
        $payments->resolveRefund($refund->id, 'succeeded');
        self::assertSame($refundedSnapshot, $this->financialSnapshot());
        self::assertSame(1, DB::table('member_rank_payment_adjustments')->count());
        $lineUser = $this->rankUser();
        $subject = 'synthetic-acceptance-line';
        \App\Models\V2\ExternalIdentityAccount::query()->create([
            'user_id' => $lineUser->id, 'provider' => 'line', 'issuer' => 'https://access.line.me',
            'subject_hash' => app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject),
            'linked_at' => now(), 'last_authenticated_at' => now(),
        ]);
        DB::table('line_messaging_settings')->where('id', 1)->update(['reward_enabled' => true, 'reward_point_amount' => 100]);
        $transport = new class implements \App\Domain\Line\Contracts\V2LineMessagingTransport {
            public int $sent = 0;

            public function replyText(string $replyToken, string $message): \App\Domain\Line\ValueObjects\V2LineReplyResult
            {
                $this->sent++;

                return new \App\Domain\Line\ValueObjects\V2LineReplyResult(true);
            }
        };
        $this->app->instance(\App\Domain\Line\Contracts\V2LineMessagingTransport::class, $transport);
        $lineBody = json_encode(['events' => [[
            'type' => 'follow', 'timestamp' => now()->getTimestampMs(), 'source' => ['type' => 'user', 'userId' => $subject],
            'replyToken' => 'synthetic-reply', 'webhookEventId' => 'acceptance-follow', 'deliveryContext' => ['isRedelivery' => false],
        ]]], JSON_THROW_ON_ERROR);
        $lineRequest = fn () => $this->call('POST', '/webhooks/v2/line', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $lineBody, 'synthetic-acceptance-secret', true)),
        ], $lineBody);
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        $started = hrtime(true);
        $lineRequest()->assertStatus(500);
        $metrics['line_refusal_seconds'] = (hrtime(true) - $started) / 1e9;
        self::assertSame(0, DB::table('line_webhook_events')->count());
        self::assertSame(0, $transport->sent);
        self::assertSame(0, DB::table('wallets')->where('user_id', $lineUser->id)->count());
        DB::table('users')->where('id', $lineUser->id)->update(['email_verified_at' => now()]);
        self::assertSame($lineUser->id, User::query()->findOrFail($lineUser->id)->id);
        $writer->rollBack();
        $lineRequest()->assertOk()->assertJsonPath('accepted', true);
        $lineRequest()->assertOk()->assertJsonPath('accepted', true);
        self::assertSame(1, DB::table('line_webhook_events')->count());
        self::assertSame(1, $transport->sent);
        self::assertSame(100, (int) DB::table('wallets')->where('user_id', $lineUser->id)->value('free_balance'));
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $lineUser->id)->count());
        $interrupted = $this->createCommercialRankPayment($payments, $user->id, $old->point_purchase_plan_id, 'fincode', 'acceptance-interrupted', (string) Str::uuid7(), 'credit_card');
        app(V2PointTransactionRunner::class)->run(fn () => DB::table('payments')->where('id', $interrupted->id)->update(['provider_payment_id' => 'acceptance-interrupted']));
        $canonical['id'] = 'acceptance-interrupted';
        $closeAfterReceipt = true;
        app('events')->listen(\Illuminate\Database\Events\TransactionCommitted::class,
            function ($event) use (&$closeAfterReceipt, $default, $writer, $interrupted): void {
                if ($closeAfterReceipt && $event->connectionName === $default
                    && $event->connection->transactionLevel() === 0
                    && $writer->table('payment_provider_events')->where('payment_id', $interrupted->id)->exists()) {
                    $closeAfterReceipt = false;
                    $writer->beginTransaction();
                    $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
                }
            });
        $webhook('acceptance-interrupted')->assertStatus(500);
        self::assertFalse($closeAfterReceipt);
        self::assertSame(1, DB::table('payment_provider_events')->where('payment_id', $interrupted->id)->count());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $interrupted->id)->count());
        $writer->rollBack();
        self::assertTrue(function_exists('pcntl_fork'));
        DB::purge($default);
        DB::purge('acceptance_writer');
        $children = [];
        $results = [];
        for ($index = 0; $index < 2; $index++) {
            $path = tempnam(sys_get_temp_dir(), 'activation-replay-');
            $results[] = $path;
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $response = $webhook('acceptance-interrupted');
                    file_put_contents($path, json_encode(['status' => $response->status(), 'body' => $response->json()], JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($path, json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        foreach ($results as $path) {
            $result = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $result['status']);
            self::assertSame('0', $result['body']['receive']);
            unlink($path);
        }
        self::assertSame(1, DB::table('payment_provider_events')->where('payment_id', $interrupted->id)->count());
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $interrupted->id)->count());
        self::assertSame(1, DB::table('member_rank_point_entries')->where('payment_id', $interrupted->id)->count());
        self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('payment_id', $interrupted->id)->count());
        $writer = DB::connection('acceptance_writer');
        $this->importRankGachas();
        $drawUser = $this->prelaunchApplicant();
        $this->settleRankPayment($drawUser, 100);
        $gacha = $this->rankGacha('login_daily', overrides: ['purchase_count_mode' => 'gte', 'purchase_count_value' => 1]);
        $manual = app(\App\Domain\MemberRank\Services\V2MemberRankAdminService::class);
        $manualInput = ['delta' => 1, 'reason' => 'synthetic admission recovery'];
        $preview = $manual->preview($admin, $drawUser->public_id, 'points', $manualInput);
        $manualInput += ['expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        $settings = app(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::class);
        $settingInput = ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 1,
            'ranks' => $settings->effectiveAt(CarbonImmutable::now())['ranks']];
        $consumeKey = (string) Str::uuid7();
        $drawKey = (string) Str::uuid7();
        $manualKey = (string) Str::uuid7();
        $m9Key = (string) Str::uuid7();
        $withdrawKey = (string) Str::uuid7();
        $settingKey = (string) Str::uuid7();
        $writers = [
            'consume' => fn () => app(\App\Domain\Point\Services\V2PointService::class)->consume($drawUser->id, 1, $consumeKey),
            'g2_draw' => fn () => $this->rankDraw($drawUser, $gacha, $drawKey),
            'manual_rank' => fn () => $manual->write($admin, $drawUser->public_id, 'points', $manualInput, $manualKey),
            'lazy' => fn () => app(V2PointTransactionRunner::class)->run(fn () => app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->materialize($user->id)),
            'monthly' => fn () => app(\App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker::class)->run('synthetic-admission', 1),
            'm9' => fn () => app(V2AdminPaymentAdjustmentService::class)->write($admin, $interrupted->public_id, 'chargeback', ['reason' => 'synthetic admission recovery'], $m9Key),
            'withdraw' => fn () => app(\App\Domain\Identity\Services\V2AdminUserStateService::class)->update($admin, $lineUser->public_id,
                ['status' => 'closed', 'expected_revision' => 1, 'reason' => 'synthetic admission recovery'], $withdrawKey),
            'settings' => fn () => $settings->update($admin, $settingKey, $settingInput),
        ];
        $beforeBlocked = $this->financialSnapshot();
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        foreach ($writers as $name => $action) {
            $started = hrtime(true);
            try {
                $action();
                self::fail('The financial entry must reject the fence: '.$name);
            } catch (QueryException $exception) {
                self::assertSame('40001', $exception->errorInfo[0], $name);
            } catch (\App\Domain\Draw\Exceptions\V2DrawException $exception) {
                self::assertSame('g2_draw', $name);
                self::assertSame('DRAW_RETRY_EXHAUSTED', $exception->errorCode);
                self::assertSame(503, $exception->status);
                self::assertTrue($exception->retryable);
            }
            $metrics['writer_refusal_seconds'][$name] = (hrtime(true) - $started) / 1e9;
            self::assertSame(0, DB::transactionLevel());
            self::assertSame($beforeBlocked, $this->financialSnapshot());
        }
        $writer->rollBack();
        foreach ($writers as $name => $action) {
            $action();
            $metrics['writer_recovery'][$name] = 'PASS';
        }
        self::assertSame(1, DB::table('draw_requests')->where('user_id', $drawUser->id)->count());
        self::assertSame('closed', DB::table('users')->where('id', $lineUser->id)->value('state'));
        self::assertSame(2, (int) DB::table('member_rank_settings')->value('revision'));
        $provider = app(\App\Domain\Payment\V2\Services\V2FincodePaymentService::class);
        $productId = DB::table('point_purchase_plans')->where('id', $old->point_purchase_plan_id)->value('public_id');
        $providerKey = (string) Str::uuid7();
        $paymentCount = DB::table('payments')->count();
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        try {
            $provider->start($drawUser, $productId, 'paypay', $providerKey);
            self::fail('Payment start must fail before an external mutation while admission is closed.');
        } catch (QueryException $exception) {
            self::assertSame('40001', $exception->errorInfo[0]);
        }
        self::assertSame(0, $providerMutations);
        self::assertSame($paymentCount, DB::table('payments')->count());
        $writer->rollBack();
        $provider->start($drawUser, $productId, 'paypay', $providerKey);
        $provider->start($drawUser, $productId, 'paypay', $providerKey);
        self::assertSame(1, $providerMutations);
        self::assertSame($paymentCount + 1, DB::table('payments')->count());
        self::assertSame('requires_action', DB::table('fincode_payment_attempts')->sole()->status);
        $barrier = tempnam(sys_get_temp_dir(), 'activation-deadlock-');
        $deadlockUsers = [$user->id, $drawUser->id];
        $deadlockChildren = [];
        DB::purge($default);
        DB::purge('acceptance_writer');
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $attempts = 0;
                $states = [];
                try {
                    app(V2PointTransactionRunner::class)->run(function () use (&$attempts, &$states, $deadlockUsers, $barrier, $index): void {
                        $attempts++;
                        DB::table('users')->where('id', $deadlockUsers[$index])->lockForUpdate()->sole();
                        if ($attempts === 1) {
                            file_put_contents($barrier.'.ready'.$index, 'ready');
                            $deadline = microtime(true) + 30;
                            while (! file_exists($barrier.'.ready'.(1 - $index))) {
                                if (microtime(true) >= $deadline) {
                                    throw new \RuntimeException('Synthetic deadlock barrier timed out.');
                                }
                                usleep(1000);
                            }
                        }
                        try {
                            DB::table('users')->where('id', $deadlockUsers[1 - $index])->lockForUpdate()->sole();
                        } catch (QueryException $exception) {
                            $states[] = $exception->errorInfo[0];
                            throw $exception;
                        }
                        app(\App\Domain\Point\Services\V2PointService::class)->grantFree($deadlockUsers[$index], 3, 'synthetic-deadlock-'.$index);
                    });
                    file_put_contents($barrier.'.result'.$index, json_encode(['attempts' => $attempts, 'states' => $states], JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($barrier.'.result'.$index, json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $deadlockChildren[] = $pid;
        }
        $totalAttempts = 0;
        $observedStates = [];
        foreach ($deadlockChildren as $index => $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($barrier.'.result'.$index), true, flags: JSON_THROW_ON_ERROR);
            self::assertGreaterThanOrEqual(1, $result['attempts']);
            self::assertLessThanOrEqual(3, $result['attempts']);
            $totalAttempts += $result['attempts'];
            $observedStates = [...$observedStates, ...$result['states']];
            unlink($barrier.'.result'.$index);
            unlink($barrier.'.ready'.$index);
        }
        unlink($barrier);
        self::assertGreaterThanOrEqual(3, $totalAttempts);
        self::assertCount($totalAttempts - 2, $observedStates);
        self::assertSame(['40P01'], array_values(array_unique($observedStates)));
        $metrics['actual_deadlock'] = ['attempts' => $totalAttempts, 'states' => $observedStates];
        fwrite(STDOUT, "\nREMAINING_ACCEPTANCE_METRICS ".json_encode($metrics, JSON_THROW_ON_ERROR)."\n");
        DB::purge('acceptance_writer');
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    public function test_remaining_pending_receipts_authorization_and_reward_recovery(): void
    {
        if (getenv('V2_PRELAUNCH_REMAINING_CASE') !== 'pending') {
            self::markTestSkipped('Requires a fresh dedicated disposable activation database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        Http::preventStrayRequests();
        $this->configureRankFixture();
        $instant = DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant;
        $this->rankClock($instant);
        $admin = $this->rankAdmin();
        $setting = DB::table('member_rank_settings')->sole();
        $authority = (string) Str::uuid7();
        DB::table('member_rank_prelaunch_authorizations')->insert([
            'public_id' => $authority, 'site_code' => 'oripoke-prelaunch', 'setting_public_id' => $setting->public_id,
            'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
            'expected_revision' => $setting->revision,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
        ]);
        $input = ['authorization_id' => $authority, 'expected_revision' => (int) $setting->revision];
        $default = DB::getDefaultConnection();
        config(['database.connections.acceptance_writer' => config('database.connections.'.$default)]);
        $writer = DB::connection('acceptance_writer');
        $revoke = true;
        DB::listen(function (QueryExecuted $query) use (&$revoke, $default, $writer, $admin): void {
            if ($revoke && $query->connectionName === $default && $query->sql === 'SELECT pg_try_advisory_xact_lock(740022, 1) AS acquired') {
                $revoke = false;
                $writer->table('admins')->where('id', $admin->adminId)->update(['role' => 'admin']);
            }
        });
        try {
            app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), $input);
            self::fail('A revoked Owner must not publish using stale preflight authorization.');
        } catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $exception) {
            self::assertSame('AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        self::assertFalse($revoke);
        $writer->table('admins')->where('id', $admin->adminId)->update(['role' => 'owner']);
        $expire = true;
        DB::listen(function (QueryExecuted $query) use (&$expire, $default, $instant): void {
            if ($expire && $query->connectionName === $default && str_starts_with($query->sql, 'INSERT INTO member_rank_prelaunch_excluded_origins')) {
                $expire = false;
                $this->rankClock(CarbonImmutable::parse($instant)->addMinutes(5)->toIso8601String());
            }
        });
        try {
            app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), $input);
            self::fail('MFA expiry during publication must roll back before ON.');
        } catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $exception) {
            self::assertSame('FRESH_MFA_REQUIRED', $exception->errorCode);
        }
        self::assertFalse($expire);
        $this->rankClock($instant);
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        self::assertSame(0, DB::table('member_rank_prelaunch_runs')->count());
        self::assertSame(0, DB::table('member_rank_prelaunch_excluded_origins')->count());
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, 100);
        $payments = app(V2PaymentService::class);
        self::assertContains('PENDING_PROVIDER_EVENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        $assertHold = function () use ($admin, $input): void {
            $snapshot = $this->financialSnapshot();
            try {
                app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), $input);
                self::fail('Unsafe receipts or changed manifest must HOLD publication.');
            } catch (V2MemberRankException $exception) {
                self::assertSame('MEMBER_RANK_PRELAUNCH_HOLD', $exception->errorCode);
            }
            self::assertSame($snapshot, $this->financialSnapshot());
            self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        };
        $assertHold();
        $payments->confirmSucceeded($event->id);
        $assertHold();
        $lateEvent = $payments->recordVerifiedProviderEvent('fincode', 'synthetic-late-success', 'payment.succeeded', '{}', [],
            $payment->id, null, now());
        self::assertContains('PENDING_PROVIDER_EVENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        $payments->confirmSucceeded($lateEvent->id);
        self::assertNotContains('PENDING_PROVIDER_EVENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        self::assertSame('success', DB::table('payment_provider_event_attempts')->where('payment_provider_event_id', $lateEvent->id)->value('outcome'));
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        foreach (['failed', 'canceled', 'expired'] as $terminalStatus) {
            $terminal = $this->createCommercialRankPayment($payments, $user->id, $payment->point_purchase_plan_id, 'fincode',
                'synthetic-terminal-'.$terminalStatus, (string) Str::uuid7(), 'credit_card');
            $type = $terminalStatus === 'canceled' ? 'payment.canceled' : 'payment.status_changed';
            $firstEvent = $payments->recordVerifiedProviderEvent('fincode', 'synthetic-first-'.$terminalStatus, $type, '{}', [], $terminal->id);
            $payments->applyVerifiedStatus($firstEvent->id, $terminalStatus);
            $replayEvent = $payments->recordVerifiedProviderEvent('fincode', 'synthetic-late-'.$terminalStatus, $type, '{}', [], $terminal->id);
            self::assertContains('PENDING_PROVIDER_EVENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
            $payments->applyVerifiedStatus($replayEvent->id, $terminalStatus);
            $payments->applyVerifiedStatus($replayEvent->id, $terminalStatus);
            self::assertNotContains('PENDING_PROVIDER_EVENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
            self::assertSame('success', DB::table('payment_provider_event_attempts')->where('payment_provider_event_id', $replayEvent->id)->value('outcome'));
            self::assertSame(1, DB::table('payment_provider_event_attempts')->where('payment_provider_event_id', $replayEvent->id)->count());
            self::assertSame($terminalStatus, DB::table('payments')->where('id', $terminal->id)->value('status'));
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $terminal->id)->count());
        }
        $payments->confirmSucceeded($lateEvent->id);
        self::assertSame(1, DB::table('payment_provider_event_attempts')->where('payment_provider_event_id', $lateEvent->id)->count());
        $refund = $payments->reserveFullRefund($payment->id, (string) Str::uuid7());
        $operation = $payments->beginProviderRefundOperation($payment->id, $refund->id, (string) Str::uuid7(), ['amount' => 100]);
        $assertHold();
        $payments->resolveProviderOperation($operation->id, 'uncertain');
        $payments->resolveRefund($refund->id, 'uncertain');
        $assertHold();
        $payments->resolveProviderOperation($operation->id, 'failed');
        $payments->resolveRefund($refund->id, 'failed');
        self::assertNotContains('PENDING_PROVIDER_OPERATIONS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        self::assertNotContains('PENDING_ADJUSTMENTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        $applicant = $this->prelaunchApplicant();
        DB::table('pre_registration_settings')->where('id', 1)->update([
            'is_enabled' => false, 'cutoff_at' => now()->subSecond(), 'point_amount' => 750,
        ]);
        $preregInput = ['expected_revision' => (int) DB::table('pre_registration_settings')->value('revision')];
        $preregKey = (string) Str::uuid7();
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        app(V2PreRegistrationService::class)->activate($admin, $preregKey, $preregInput);
        self::assertSame('failed', DB::table('pre_registration_targets')->where('user_id', $applicant->id)->value('status'));
        self::assertSame(0, DB::table('pre_registration_grants')->count());
        self::assertSame(0, DB::table('wallets')->where('user_id', $applicant->id)->count());
        $writer->rollBack();
        app(V2PreRegistrationService::class)->activate($admin, (string) Str::uuid7(), $preregInput);
        self::assertSame('activated', DB::table('pre_registration_targets')->where('user_id', $applicant->id)->value('status'));
        self::assertSame(750, (int) DB::table('wallets')->where('user_id', $applicant->id)->value('free_balance'));
        $protected = $this->financialSnapshot();
        app(V2PreRegistrationService::class)->activate($admin, (string) Str::uuid7(), $preregInput);
        self::assertSame($protected, $this->financialSnapshot());
        $referrer = $this->rankUser();
        $referred = $this->rankUser();
        DB::table('referral_point_settings')->where('id', 1)->update([
            'is_enabled' => true, 'referrer_point_amount' => 100, 'referred_user_point_amount' => 50,
        ]);
        $referrals = app(\App\Domain\Referral\Services\V2ReferralRewardService::class);
        $referral = $referrals->record($referred, $referrer->referral_code);
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        try {
            $referrals->rewardForReferredUser($referred);
            self::fail('Referral reward must fail without consuming its durable pending receipt.');
        } catch (QueryException $exception) {
            self::assertSame('40001', $exception->errorInfo[0]);
        }
        self::assertSame('pending', $referral->fresh()->status);
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'referral')->count());
        $writer->rollBack();
        self::assertSame('rewarded', $referrals->rewardForReferredUser($referred)->status);
        $referrals->rewardForReferredUser($referred);
        self::assertSame(2, DB::table('point_operations')->where('source_type', 'referral')->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        $this->settleRankPayment($applicant, 100);
        self::assertContains('PROTECTED_PAID_GRANTS', app(V2MemberRankPrelaunchInspection::class)->inspect()['data_holds']);
        $assertHold();
        DB::purge('acceptance_writer');
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Http::assertNothingSent();
    }

    public function test_nested_sms_verification_preserves_challenge_and_pending_referral(): void
    {
        if (getenv('V2_PRELAUNCH_REMAINING_CASE') !== 'identity') {
            self::markTestSkipped('Requires a fresh dedicated disposable activation database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        Http::preventStrayRequests();
        $this->configureRankFixture();
        $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
        config(['app.debug' => false, 'v2_identity.origins.user' => 'https://example.test']);
        $admin = $this->rankAdmin();
        $setting = DB::table('member_rank_settings')->sole();
        $authority = (string) Str::uuid7();
        DB::table('member_rank_prelaunch_authorizations')->insert([
            'public_id' => $authority, 'site_code' => 'oripoke-prelaunch', 'setting_public_id' => $setting->public_id,
            'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
            'expected_revision' => $setting->revision,
            'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
        ]);
        app(V2MemberRankPrelaunchActivation::class)->activate($admin, (string) Str::uuid7(), [
            'authorization_id' => $authority, 'expected_revision' => (int) $setting->revision,
        ]);
        $referrer = $this->rankUser();
        DB::table('referral_point_settings')->where('id', 1)->update([
            'is_enabled' => true, 'referrer_point_amount' => 100, 'referred_user_point_amount' => 50,
        ]);
        $sessions = app(\App\Domain\Identity\Services\V2SessionManager::class);
        $sms = app(\App\Domain\Identity\Services\V2SmsVerificationService::class);
        $candidates = [];
        foreach (['referred', 'ordinary'] as $index => $kind) {
            $email = 'synthetic-sms-'.$kind.'@example.test';
            $candidate = User::query()->create([
                'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
                'first_registration_qualified_at' => now(), 'state' => 'active',
                'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'),
            ]);
            if ($kind === 'referred') {
                app(\App\Domain\Referral\Services\V2ReferralRewardService::class)->record($candidate, $referrer->referral_code);
            }
            $session = $sessions->issue(\App\Domain\Identity\Enums\V2Realm::User, $candidate->id);
            $request = \Illuminate\Http\Request::create('/api/v2/me/sms-verification', 'POST');
            $request->cookies->set('__Host-oripa_user_session', $session['token']);
            $sent = $sms->send($candidate, $request, '0901234567'.$index);
            $challenge = \App\Models\V2\SmsVerificationChallenge::query()->where('public_id', $sent['challenge_id'])->sole();
            $message = \App\Models\V2\OutboxMessage::query()->where('topic', 'identity.sms-verification')->latest('id')->firstOrFail();
            $delivery = json_decode(Crypt::decryptString($message->payload['message_ciphertext']), true, flags: JSON_THROW_ON_ERROR);
            $challenge->forceFill([
                'delivery_state' => 'accepted', 'provider_request_id' => 'synthetic-sms-'.$index,
                'delivery_attempted_at' => now()->startOfSecond(), 'delivery_accepted_at' => now()->startOfSecond(),
            ])->save();
            $candidates[$kind] = compact('candidate', 'session', 'challenge', 'delivery');
        }
        $verify = function (array $candidate): \Illuminate\Testing\TestResponse {
            Auth::forgetGuards();
            $csrf = str_repeat('e', 64);

            return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_user_session', $candidate['session']['token'])
                ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
                ->withHeaders(['Origin' => 'https://example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf])
                ->postJson('/api/v2/me/sms-verification/verify', [
                    'challenge_id' => $candidate['challenge']->public_id, 'code' => $candidate['delivery']['verification_code'],
                ]);
        };
        $default = DB::getDefaultConnection();
        config(['database.connections.identity_fence' => config('database.connections.'.$default)]);
        $writer = DB::connection('identity_fence');
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        $verify($candidates['referred'])->assertStatus(500);
        self::assertNull($candidates['referred']['challenge']->fresh()->used_at);
        self::assertNull($candidates['referred']['candidate']->fresh()->first_sms_verified_at);
        self::assertSame(0, DB::table('user_phone_numbers')->where('user_id', $candidates['referred']['candidate']->id)->count());
        self::assertSame('pending', DB::table('user_referrals')->where('referred_user_id', $candidates['referred']['candidate']->id)->value('status'));
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'referral')->count());
        self::assertNull(DB::table('user_sessions')->where('session_id_hash', hash('sha256', $candidates['referred']['session']['token']))->value('revoked_at'));
        $verify($candidates['ordinary'])->assertOk()->assertJsonPath('verified', true);
        self::assertNotNull($candidates['ordinary']['challenge']->fresh()->used_at);
        $writer->rollBack();
        $verify($candidates['referred'])->assertOk()->assertJsonPath('verified', true);
        self::assertNotNull($candidates['referred']['challenge']->fresh()->used_at);
        self::assertSame('rewarded', DB::table('user_referrals')->where('referred_user_id', $candidates['referred']['candidate']->id)->value('status'));
        self::assertSame(2, DB::table('point_operations')->where('source_type', 'referral')->count());
        self::assertSame(50, (int) DB::table('wallets')->where('user_id', $candidates['referred']['candidate']->id)->value('free_balance'));
        self::assertSame(100, (int) DB::table('wallets')->where('user_id', $referrer->id)->value('free_balance'));
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        $verify($candidates['referred'])->assertUnauthorized();
        self::assertSame(2, DB::table('point_operations')->where('source_type', 'referral')->count());
        require_once __DIR__.'/LineLoginVerticalSliceTest.php';
        $callbackUrl = 'https://storefront.example.test/api/v2/auth/external/line/callback';
        config([
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.external_identity.line.client_id' => 'line-channel.test',
            'v2_identity.external_identity.line.client_secret' => 'synthetic-line-secret',
            'v2_identity.external_identity.line.redirect_uri' => $callbackUrl,
            'v2_identity.external_identity.line.email_scope_enabled' => true,
        ]);
        DB::table('line_messaging_settings')->where('id', 1)->update(['reward_enabled' => true, 'reward_point_amount' => 75]);
        $provider = new FakeLineOidcTransport();
        $this->app->instance(\App\Domain\Identity\Contracts\V2LineOidcTransport::class, $provider);
        $subject = 'synthetic-nested-line';
        $pending = new \App\Models\V2\LinePendingFollow();
        $pending->forceFill([
            'public_id' => (string) Str::uuid7(), 'subject_hash' => app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject),
            'status' => 'pending', 'followed_at' => now()->subMinute(),
        ])->save();
        $identities = app(\App\Domain\Identity\Services\V2ExternalIdentityService::class);
        $start = function () use ($identities, $provider, $subject): array {
            $started = $identities->startForProvider('line', 'login', '/', '192.0.2.70', (string) Str::uuid7(), null,
                \Illuminate\Http\Request::create('/api/v2/auth/external/line/start', 'POST'));
            parse_str(parse_url($started['authorization_url'], PHP_URL_QUERY), $query);
            $provider->expectChallenge($query['code_challenge']);
            $provider->claims($query['nonce'], $subject, 'synthetic-nested-line@example.test');

            return ['state' => $query['state'], 'binding' => $started['binding_token']];
        };
        $callback = function (array $started) use ($identities, $callbackUrl): array {
            $request = \Illuminate\Http\Request::create($callbackUrl, 'GET');
            $request->cookies->set('__Host-oripa_oidc_transaction', $started['binding']);

            return $identities->callbackForProvider('line', $started['state'], 'synthetic-line-code', $started['binding'],
                $callbackUrl, '192.0.2.71', $request);
        };
        $started = $start();
        $beforeUsers = DB::table('users')->count();
        $writer->beginTransaction();
        $writer->select('SELECT pg_advisory_xact_lock(740022, 1)');
        try {
            $callback($started);
            self::fail('Nested LINE reward must reject admission before consuming its durable pending follow.');
        } catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $exception) {
            self::assertSame('EXTERNAL_IDENTITY_CONFLICT', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        self::assertSame($beforeUsers, DB::table('users')->count());
        self::assertSame('pending', $pending->fresh()->status);
        self::assertSame(0, DB::table('line_friendships')->count());
        self::assertSame('failed', DB::table('external_identity_transactions')->latest('id')->value('status'));
        $writer->rollBack();
        $result = $callback($start());
        self::assertSame('claimed', $pending->fresh()->status);
        self::assertSame($result['user']->id, $pending->fresh()->claimed_by_user_id);
        self::assertSame(75, (int) DB::table('wallets')->where('user_id', $result['user']->id)->value('free_balance'));
        $callback($start());
        self::assertSame(1, DB::table('point_operations')->where('source_type', 'line_friend')->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        DB::purge('identity_fence');
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Http::assertNothingSent();
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
