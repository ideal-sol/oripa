<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankPrelaunchActivation;
use App\Domain\MemberRank\Services\V2MemberRankPrelaunchInspection;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2PaymentCommercialQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankClassifiedActivationTest extends TestCase
{
    use MemberRankAccountingFixture, \Tests\V2\Fixtures\UnivapayFixtures;

    public function test_historical_upgrade_activation_and_future_fincode_modes_preserve_financial_authority(): void
    {
        self::assertTrue(app()->environment('testing'));
        Http::preventStrayRequests();
        $this->configureRankFixture();
        $this->rankClock(DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant);
        $pay1 = '2026_10_26_000099_add_v2_payment_common_foundation.php';
        $paths = array_values(array_filter(glob(database_path('migrations-v2/*.php')),
            static fn (string $path): bool => basename($path) < $pay1));
        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);
        try {
            $admin = $this->rankAdmin();
            $user = $this->rankUser();
            $protected = [$this->rankUser(true), $this->rankUser(true), $this->rankUser(true)];
            $plan = DB::table('point_purchase_plans')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'code' => 'historical', 'version_no' => 1,
                'name' => 'Synthetic historical plan', 'audience_code' => 'all_users',
                'amount' => 1000, 'paid_point_amount' => 1000, 'free_point_amount' => 0,
                'currency' => 'JPY', 'status' => 'published', 'published_at' => now(),
            ]);
            $origins = [];
            for ($index = 0; $index < 29; $index++) {
                $origins[] = DB::table('payments')->insertGetId([
                    'public_id' => (string) Str::uuid7(), 'user_id' => $user->id,
                    'point_purchase_plan_id' => $plan, 'provider_code' => 'fincode',
                    'provider_payment_id' => 'historical-'.$index, 'payment_method' => 'credit_card',
                    'status' => $index < 13 ? 'created' : ($index < 16 ? 'failed' : 'requires_action'),
                    'amount' => 1000, 'paid_point_amount' => 1000, 'free_point_amount' => 0,
                    'currency' => 'JPY', 'plan_name_snapshot' => 'Synthetic historical plan',
                    'plan_code_snapshot' => 'historical', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $beforeClassification = DB::table('payments')->orderBy('id')->get()->toJson();
            Artisan::call('migrate', ['--path' => 'database/migrations-v2/'.$pay1, '--force' => true]);
            self::assertSame($beforeClassification, DB::table('payments')->orderBy('id')->get()->toJson());
            self::assertSame(29, DB::table('payment_commercial_classifications')
                ->where('classification', 'test')->where('reason', 'pay1_existing_fincode')->count());
            // Current payment/mail services require closure support. Install this
            // independent schema in the synthetic fixture while deliberately
            // leaving 000100 pending: the later financial-history/backfill
            // assertions must still exercise that migration on historical rows.
            // Ordinary ordered 000100 -> 000101 upgrades have separate coverage.
            $beforeClosureSupport = $this->history();
            Artisan::call('migrate', ['--path' => 'database/migrations-v2/2026_10_28_000101_add_v2_account_closure.php', '--force' => true]);
            self::assertSame($beforeClosureSupport, $this->history());
            self::assertSame(0, DB::table('account_closures')->count());
            self::assertFalse(DB::table('migrations')->where('migration', '2026_10_28_000100_bind_v2_prelaunch_payment_classifications')->exists());
            $payments = app(V2PaymentService::class);
            foreach (array_slice($origins, 0, 13) as $id) {
                $event = $payments->recordVerifiedProviderEvent('fincode', 'settled-'.$id,
                    'payment.succeeded', '{}', [], $id, providerOccurredAt: now());
                $payments->confirmSucceeded($event->id);
            }
            foreach (array_slice($origins, 16) as $id) {
                DB::table('fincode_payment_attempts')->insert([
                    'public_id' => (string) Str::uuid7(), 'payment_id' => $id,
                    'provider_idempotency_key' => (string) Str::uuid7(),
                    'status' => 'requires_action', 'attempt_count' => 1,
                ]);
            }
            for ($index = 0; $index < 67; $index++) {
                $payments->recordVerifiedProviderEvent('fincode', 'pending-'.$index,
                    $index % 2 === 0 ? 'payment.succeeded' : 'payment.status_changed', '{}', [],
                    $origins[$index % 29], providerOccurredAt: now());
            }
            $refund = $payments->reserveFullRefund($origins[0], (string) Str::uuid7());
            $payments->resolveRefund($refund->id, 'succeeded');
            foreach ([$origins[1], $origins[2]] as $id) {
                $payments->recordManualChargeback($id, $admin->adminId, 'Synthetic historical chargeback');
            }
            $history = $this->history();
            Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]);
            self::assertSame($history, $this->history(), 'Forward migration must not rewrite financial history.');
            $inspection = app(V2MemberRankPrelaunchInspection::class)->inspect();
            self::assertSame([], $inspection['data_holds']);
            self::assertSame(3, $inspection['allowed_zero_rank_adjustments']);
            self::assertSame(3, $inspection['counts']['protected_applicants']);
            self::assertSame(13, $inspection['counts']['paid_grants']);
            self::assertSame(13, DB::table('payments')->where('status', 'requires_action')->count());
            self::assertSame(67, DB::table('payment_provider_events')->whereNotIn('id',
                DB::table('payment_provider_event_attempts')->select('payment_provider_event_id'))->count());

            // Probe the same inspection under closed admission; every counterexample is rolled back.
            $this->assertHold(function () use ($payments, $user, $plan): void {
                $payments->createPayment($user->id, $plan, 'unknown-provider', 'unknown', 'unknown');
            }, 'UNAPPROVED_ORIGINS');
            $this->assertHold(function () use ($payments, $user, $plan): void {
                $this->configureUnivapay('live');
                $payments->createPayment($user->id, $plan, 'univapay', 'commercial', 'commercial');
            }, 'PENDING_PAYMENTS');
            $this->assertHold(function () use ($protected): void {
                $this->rankPayment($protected[0], 1000, commercial: false);
            }, 'PROTECTED_PAYMENTS');
            $this->assertHold(function () use ($payments, $origins): void {
                $payments->recordVerifiedProviderEvent('univapay', 'wrong-provider', 'payment.succeeded', '{}', [],
                    $origins[16], providerOccurredAt: now());
            }, 'PENDING_PROVIDER_EVENTS');
            $this->assertHold(function () use ($payments, $origins): void {
                $payments->recordVerifiedProviderEvent('fincode', 'unknown-kind', 'unknown.event', '{}', [],
                    $origins[16], providerOccurredAt: now());
            }, 'PENDING_PROVIDER_EVENTS');
            $this->assertHold(function () use ($origins): void {
                DB::table('fincode_payment_attempts')->where('payment_id', $origins[16])->update(['status' => 'uncertain']);
            }, 'PENDING_FINCODE_ATTEMPTS');
            $this->assertHold(function () use ($payments, $origins): void {
                $payments->reserveFullRefund($origins[3], (string) Str::uuid7());
            }, 'PENDING_ADJUSTMENTS');
            $this->assertHold(function () use ($user, $payments, $admin): void {
                $this->enableRankFixture();
                $ranked = $this->settleRankPayment($user, 10000);
                $payments->recordManualChargeback($ranked->id, $admin->adminId, 'Synthetic rank-effect rejection');
                self::assertFalse((bool) DB::selectOne('SELECT v2_prelaunch_zero_adjustment(id) AS allowed FROM member_rank_payment_adjustments WHERE payment_id = ?', [$ranked->id])->allowed);
            }, 'EXISTING_RANK_BUSINESS_STATE');
            self::assertSame($history, $this->history());
            $setting = DB::table('member_rank_settings')->sole();
            $authorization = (string) Str::uuid7();
            DB::table('member_rank_prelaunch_authorizations')->insert([
                'public_id' => $authorization, 'site_code' => 'oripoke-prelaunch',
                'setting_public_id' => $setting->public_id, 'expected_revision' => $setting->revision,
                'authority_sha256' => 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248',
                'origin_digest' => DB::selectOne('SELECT v2_member_rank_prelaunch_manifest() AS digest')->digest,
            ]);
            $input = ['authorization_id' => $authorization, 'expected_revision' => (int) $setting->revision];
            $key = (string) Str::uuid7();
            $activation = app(V2MemberRankPrelaunchActivation::class);
            $activation->activate($admin, $key, $input);
            self::assertSame($history, $this->history());
            self::assertTrue((bool) DB::table('member_rank_settings')->value('enabled'));
            self::assertSame(1, (int) DB::table('financial_admission_generations')->value('generation'));
            self::assertSame(1, DB::table('member_rank_prelaunch_runs')->count());
            self::assertSame(29, DB::table('member_rank_prelaunch_excluded_origins')->count());
            self::assertSame(0, DB::table('member_rank_point_entries')->count());
            self::assertSame(0, DB::table('member_rank_purchase_bonuses')->count());
            $audit = DB::table('audit_logs')->count();
            $activation->activate($admin, $key, $input);
            self::assertSame($audit, DB::table('audit_logs')->count());
            foreach ([$origins[0], $origins[16]] as $id) {
                $late = $payments->recordVerifiedProviderEvent('fincode', 'late-'.$id,
                    'payment.succeeded', '{}', [], $id, providerOccurredAt: now());
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    try {
                        $payments->confirmSucceeded($late->id);
                        self::assertSame($origins[0], $id, 'An excluded pending origin cannot grant points.');
                    } catch (V2PaymentException $exception) {
                        self::assertSame($origins[16], $id);
                        self::assertSame('PAYMENT_PRELAUNCH_ORIGIN_EXCLUDED', $exception->getMessage());
                    }
                }
            }
            self::assertSame($history, $this->history());
            self::assertSame(0, DB::table('member_rank_point_entries')->count());

            $future = $this->rankUser();
            $first = $this->settleRankPayment($future, 10000);
            $second = $this->settleRankPayment($future, 2000);
            self::assertSame(12000, (int) DB::table('member_rank_month_totals')->where('user_id', $future->id)->value('points'));
            self::assertSame(500, (int) DB::table('member_rank_achievements')->where('user_id', $future->id)->sum('bonus_amount'));
            self::assertSame(10, (int) DB::table('member_rank_purchase_bonuses')->where('payment_id', $second->id)->value('bonus_amount'));
            $commercial = DB::table('payments');
            V2PaymentCommercialQuery::apply($commercial, 'payments.id');
            self::assertSame(2, $commercial->count());
            $futureTest = $this->settleRankPayment($future, 20000, commercial: false);
            self::assertSame(12000, (int) DB::table('member_rank_month_totals')->where('user_id', $future->id)->value('points'));
            self::assertSame(0, DB::table('member_rank_purchase_bonuses')->where('payment_id', $futureTest->id)->count());
            $futureRefund = $payments->reserveFullRefund($second->id, (string) Str::uuid7());
            $payments->resolveRefund($futureRefund->id, 'succeeded');
            $payments->resolveRefund($futureRefund->id, 'succeeded');
            self::assertDatabaseHas('member_rank_payment_adjustments', ['payment_id' => $second->id,
                'effect' => 'current_month', 'applied_points' => 2000, 'purchase_bonus_reversed' => 10]);
            $payments->recordManualChargeback($first->id, $admin->adminId, 'Synthetic future commercial chargeback');
            self::assertDatabaseHas('member_rank_payment_adjustments', ['payment_id' => $first->id,
                'effect' => 'current_month', 'applied_points' => 10000]);
            self::assertSame(0, (int) DB::table('member_rank_month_totals')->where('user_id', $future->id)->value('points'));
            $unknown = $payments->createPayment($future->id, $plan, 'unknown-provider', 'future-unknown', 'future-unknown');
            $unknownEvent = $payments->recordVerifiedProviderEvent('unknown-provider', 'future-unknown-event',
                'payment.succeeded', '{}', [], $unknown->id, providerOccurredAt: now());
            $beforeUnknown = $this->history();
            try {
                $payments->confirmSucceeded($unknownEvent->id);
                self::fail('Unclassified post-activation settlement must roll back.');
            } catch (\LogicException $exception) {
                self::assertSame('Rank payment requires an explicit commercial classification.', $exception->getMessage());
            }
            self::assertSame($beforeUnknown, $this->history());
            self::assertFalse((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS eligible', [$unknown->id])->eligible);
            self::assertSame(0, app(V2MemberRankPrelaunchInspection::class)->inspect()['counts']['financial_discrepancies']);
            Http::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > 0) DB::rollBack();
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        }
    }

    private function assertHold(callable $seed, string $hold): void
    {
        DB::beginTransaction();
        try {
            DB::select('SELECT pg_advisory_xact_lock(740022, 1)');
            $seed();
            self::assertContains($hold, app(V2MemberRankPrelaunchInspection::class)->inspectClosedAdmission()['data_holds']);
        } finally {
            DB::rollBack();
        }
    }

    private function history(): array
    {
        $snapshot = [];
        foreach (['payments', 'payment_point_grants', 'payment_adjustments', 'payment_adjustment_point_impacts',
            'point_operations', 'point_lots', 'point_ledger_entries', 'wallets', 'member_rank_payment_adjustments'] as $table) {
            $snapshot[$table] = json_encode(DB::select('SELECT row_to_json(record)::text AS record FROM '.$table.' record ORDER BY row_to_json(record)::text'), JSON_THROW_ON_ERROR);
        }
        return $snapshot;
    }
}
