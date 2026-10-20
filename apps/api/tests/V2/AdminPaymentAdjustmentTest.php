<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class AdminPaymentAdjustmentTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        DB::beginTransaction();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_refund_prepare_complete_replay_and_audit_use_actual_admin_without_provider_io(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000, 100);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $before = $this->counts();
        $key = (string) Str::uuid7();
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => '全額返金の準備'], $key);
        self::assertSame('points_reserved', $prepared['data']['status']);
        self::assertSame($context->adminPublicId, $prepared['data']['requested_by_admin_id']);
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $payment->user_id)->value('paid_reserved_balance'));
        self::assertSame($before['payment_provider_events'], DB::table('payment_provider_events')->count());
        self::assertSame($before['outbox_messages'], DB::table('outbox_messages')->count());
        self::assertDatabaseHas('idempotency_records', ['scope' => 'admin.payment.refund', 'actor_public_id' => $context->adminPublicId]);
        $completeKey = (string) Str::uuid7();
        $input = ['reason' => '決済会社で返金確認済み', 'provider_refund_confirmed' => true, 'provider_adjustment_id' => 'refund-fixture'];
        $completed = $service->write($context, $payment->public_id, 'complete', $input, $completeKey, $prepared['data']['id']);
        self::assertSame('succeeded', $completed['data']['status']);
        self::assertSame(1000, $completed['data']['impact']['reversed_paid_from_paid']);
        self::assertSame(100, $completed['data']['impact']['reversed_free_from_free']);
        self::assertSame('admin', $completed['data']['history'][2]['source']);
        self::assertSame($context->adminPublicId, $completed['data']['history'][2]['admin_id']);
        self::assertDatabaseHas('wallets', ['user_id' => $payment->user_id, 'paid_balance' => 0, 'free_balance' => 0, 'paid_reserved_balance' => 0, 'free_reserved_balance' => 0]);
        $counts = $this->counts();
        self::assertEquals($completed['data'], $service->write($context, $payment->public_id, 'complete', $input, $completeKey, $prepared['data']['id'])['data']);
        self::assertEquals($prepared['data'], $service->write($context, $payment->public_id, 'refund', ['reason' => '全額返金の準備'], $key)['data']);
        self::assertSame($counts, $this->counts());
        self::assertSame($before['mail_deliveries'], $counts['mail_deliveries']);
        $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'refund', ['reason' => '変更'], $key), 'IDEMPOTENCY_KEY_REUSED');
        $this->expectConflict(fn () => $service->write($this->rankAdmin(), $payment->public_id, 'chargeback', ['reason' => '競合'], (string) Str::uuid7()));
    }

    public function test_cancel_releases_reservations_and_can_prepare_again_without_sales_adjustment(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 500, 50);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => '準備'], (string) Str::uuid7());
        $cancel = $service->write($context, $payment->public_id, 'cancel', ['reason' => '取りやめ'], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame('canceled', $cancel['data']['status']);
        self::assertNull($cancel['data']['succeeded_at']);
        self::assertNotNull($cancel['data']['canceled_at']);
        self::assertSame(0, DB::table('payment_adjustment_point_impacts')->where('payment_adjustment_id', DB::table('payment_adjustments')->where('public_id', $prepared['data']['id'])->value('id'))->count());
        self::assertDatabaseHas('wallets', ['user_id' => $payment->user_id, 'paid_balance' => 500, 'free_balance' => 50, 'paid_reserved_balance' => 0]);
        self::assertSame('points_reserved', $service->write($context, $payment->public_id, 'refund', ['reason' => '再準備'], (string) Str::uuid7())['data']['status']);
    }

    public function test_preview_is_read_only_and_execution_recalculates_after_spend_with_shortfall(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000, 100);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $before = $this->counts();
        $preview = $service->preview($context, $payment->public_id, ['reason' => 'CB通知']);
        self::assertSame($before, $this->counts());
        self::assertSame(1000, $preview['data']['impact']['reversed_paid_from_paid']);
        app(V2PointService::class)->consume($payment->user_id, 1050, 'm9-preview-spend:'.Str::uuid7());
        $result = $service->write($context, $payment->public_id, 'chargeback', ['reason' => 'CB確定', 'provider_case_id' => 'synthetic-case'], (string) Str::uuid7());
        self::assertSame(1000, $result['data']['impact']['shortfall_paid_amount']);
        self::assertSame(50, $result['data']['impact']['shortfall_free_amount']);
        self::assertSame(50, $result['data']['impact']['reversed_free_from_free']);
        self::assertDatabaseHas('payment_adjustments', ['public_id' => $result['data']['id'], 'source_provider_event_id' => null, 'provider_case_id' => 'synthetic-case']);
        $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'chargeback', ['reason' => '二重'], (string) Str::uuid7()));
        $beforeReversal = DB::table('point_ledger_entries')->count();
        $reversal = $service->write($context, $payment->public_id, 'reversal', ['reason' => '異議認容'], (string) Str::uuid7(), $result['data']['id']);
        self::assertSame('manual_review', $reversal['data']['status']);
        self::assertSame($result['data']['id'], $reversal['data']['parent_id']);
        self::assertSame($beforeReversal, DB::table('point_ledger_entries')->count());
        $this->expectConflict(fn () => $service->write($this->rankAdmin(), $payment->public_id, 'reversal', ['reason' => '二重取消'], (string) Str::uuid7(), $result['data']['id']));
    }

    public function test_refund_rejects_one_used_point_expiry_and_incomplete_payment(): void
    {
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        foreach (['spent', 'expired', 'pending'] as $condition) {
            $this->rankClock('2026-10-20T00:00:00Z');
            $context = $this->rankAdmin();
            $user = $this->rankUser();
            [$payment, $event] = $this->rankPayment($user, 100, 10);
            if ($condition !== 'pending') app(V2PaymentService::class)->confirmSucceeded($event->id);
            if ($condition === 'spent') app(V2PointService::class)->consume($user->id, 1, 'm9-used:'.Str::uuid7());
            if ($condition === 'expired') {
                $this->rankClock('2027-11-01T00:00:00Z');
                $context = $this->rankAdmin();
            }
            $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'refund', ['reason' => '返金'], (string) Str::uuid7()));
            self::assertFalse($service->detail($context, $payment->public_id)['data']['refund_eligibility']['allowed']);
        }
    }

    public function test_reserved_lots_are_not_consumed_by_chargeback_for_another_payment(): void
    {
        $user = $this->rankUser();
        $reservedPayment = $this->settleRankPayment($user, 500, 50);
        $target = $this->settleRankPayment($user, 1000, 100);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $service->write($context, $reservedPayment->public_id, 'refund', ['reason' => '予約'], (string) Str::uuid7());
        app(V2PointService::class)->consume($user->id, 1100, 'm9-reservation-spend:'.Str::uuid7());
        $preview = $service->preview($context, $target->public_id, ['reason' => 'CB']);
        $result = $service->write($context, $target->public_id, 'chargeback', ['reason' => 'CB'], (string) Str::uuid7());
        self::assertSame($preview['data']['impact'], $result['data']['impact']);
        self::assertSame(1000, $result['data']['impact']['shortfall_paid_amount']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 500, 'paid_reserved_balance' => 500, 'free_balance' => 50, 'free_reserved_balance' => 50]);
    }

    public function test_rank_guard_blocks_completion_after_activation_but_allows_cancel(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => '準備'], (string) Str::uuid7());
        $this->enableRankFixture();
        $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'complete', ['reason' => '確認', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']), 'PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        self::assertSame('canceled', $service->write($context, $payment->public_id, 'cancel', ['reason' => '取消'], (string) Str::uuid7(), $prepared['data']['id'])['data']['status']);
        $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'chargeback', ['reason' => 'CB'], (string) Str::uuid7()), 'PAYMENT_RANK_ADJUSTMENT_NOT_READY');
    }

    public function test_purchase_bonus_lot_is_displayed_and_held_without_rank_recovery(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 3);
        $payment = $this->settleRankPayment($user, 10000);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        self::assertGreaterThan(0, DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->value('bonus_amount'));
        $detail = $service->detail($context, $payment->public_id)['data'];
        self::assertTrue($detail['rank_adjustment_pending']);
        self::assertCount(1, array_filter($detail['lots'], fn ($lot) => $lot['member_rank_purchase_bonus']));
        self::assertStringContainsString('M8連動未対応', $detail['refund_eligibility']['reason']);
        foreach (['refund', 'chargeback'] as $operation) {
            $this->expectConflict(fn () => $service->write($context, $payment->public_id, $operation, ['reason' => '対象外'], (string) Str::uuid7()), 'PAYMENT_RANK_ADJUSTMENT_NOT_READY');
        }
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
    }

    public function test_zero_purchase_bonus_has_no_extra_lot_but_independent_rank_accounting_still_blocks(): void
    {
        $this->enableRankFixture();
        $payment = $this->settleRankPayment($this->rankUser(), 1000);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'bonus_amount' => 0, 'point_operation_id' => null]);
        $service = app(V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $detail = $service->detail($context, $payment->public_id)['data'];
        self::assertCount(1, $detail['lots']);
        self::assertFalse($detail['lots'][0]['member_rank_purchase_bonus']);
        self::assertTrue(DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->exists());
        $this->expectConflict(fn () => $service->write($context, $payment->public_id, 'refund', ['reason' => 'ランク減算は別途必要'], (string) Str::uuid7()), 'PAYMENT_RANK_ADJUSTMENT_NOT_READY');
    }

    public function test_reserved_points_cannot_be_spent(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000, 100);
        app(V2AdminPaymentAdjustmentService::class)->write($this->rankAdmin(), $payment->public_id, 'refund', ['reason' => '予約'], (string) Str::uuid7());
        $before = $this->counts();
        try {
            app(V2PointService::class)->consume($payment->user_id, 1, 'm9-reserved:'.Str::uuid7());
            self::fail('Reserved points must not be spendable');
        } catch (\App\Domain\Point\Exceptions\V2PointException $exception) {
            self::assertSame('INSUFFICIENT_POINT_BALANCE', $exception->getMessage());
        }
        self::assertSame($before, $this->counts());
    }

    public function test_product_free_and_limited_bonus_are_both_reversed(): void
    {
        foreach (['refund', 'chargeback'] as $operation) {
            $user = $this->rankUser();
            [$initial] = $this->rankPayment($user, 1000, 100);
            DB::table('point_purchase_plan_limited_bonus_campaigns')->insert([
                'public_id' => (string) Str::uuid7(), 'point_purchase_plan_id' => $initial->point_purchase_plan_id,
                'is_enabled' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
                'bonus_point_amount' => 50, 'created_at' => now(), 'updated_at' => now(),
            ]);
            [$payment, $event] = $this->rankPayment($user, 1000, 100, null, 'credit_card', $initial->point_purchase_plan_id);
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            $context = $this->rankAdmin();
            $service = app(V2AdminPaymentAdjustmentService::class);
            $result = $service->write($context, $payment->public_id, $operation, ['reason' => '全付与対象'], (string) Str::uuid7());
            if ($operation === 'refund') {
                $result = $service->write($context, $payment->public_id, 'complete', ['reason' => '実返金確認', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $result['data']['id']);
            }
            self::assertSame(150, $result['data']['impact']['required_free_amount']);
            self::assertSame(150, $result['data']['impact']['reversed_free_from_free']);
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0]);
        }
    }

    public function test_api_authorization_csrf_idor_validation_and_all_payment_methods(): void
    {
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $token = $this->adminSession($role);
            foreach (['credit_card', 'paypay', 'konbini', 'virtual_account'] as $method) {
                [$payment, $event] = $this->rankPayment($this->rankUser(), 100, 10, null, $method);
                app(V2PaymentService::class)->confirmSucceeded($event->id);
                $base = '/admin/api/v2/payments/'.$payment->public_id;
                $this->asAdmin($token)->getJson($base)->assertOk()->assertJsonPath('data.payment.method', $method);
                $this->csrf($token)->postJson($base.'/refunds', ['reason' => '準備'])->assertOk()->assertJsonPath('data.source', 'admin');
            }
        }
        $payment = $this->settleRankPayment($this->rankUser(), 100);
        $base = '/admin/api/v2/payments/'.$payment->public_id;
        $token = $this->adminSession(V2AdminRole::Operator);
        $this->csrf($token)->postJson($base.'/refunds', ['reason' => '不可'])->assertForbidden();
        $token = $this->adminSession(V2AdminRole::Admin);
        foreach ([[], ['reason' => ' '], ['reason' => str_repeat('あ', 501)], ['reason' => '理由', 'amount' => 1]] as $input) {
            $this->csrf($token)->postJson($base.'/refunds', $input)->assertUnprocessable();
        }
        $this->csrf($token)->postJson($base.'/refunds/'.Str::uuid7().'/complete', ['reason' => '確認', 'provider_refund_confirmed' => true])->assertNotFound();
        $this->csrf($token)->postJson($base.'/refunds/'.Str::uuid7().'/complete', ['reason' => '確認', 'provider_refund_confirmed' => false])->assertUnprocessable();
        $this->csrf($token)->withHeader('Idempotency-Key', '')->postJson($base.'/refunds', ['reason' => '理由'])->assertUnprocessable();
        $this->asAdmin($token)->withHeader('X-XSRF-TOKEN', 'invalid')->postJson($base.'/refunds', ['reason' => '理由'])->assertForbidden();
        DB::table('admin_sessions')->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($token))->update(['mfa_verified_at' => null]);
        $this->asAdmin($token)->getJson($base)->assertUnauthorized();
    }

    public function test_ignored_cancellation_is_narrow_and_provider_agnostic_reads_use_actual_provider(): void
    {
        $context = $this->rankAdmin();
        $user = $this->rankUser();
        $plan = DB::table('point_purchase_plans')->insertGetId(['public_id' => (string) Str::uuid7(), 'code' => 'other-'.Str::uuid7(), 'version_no' => 1, 'name' => 'Synthetic', 'amount' => 100, 'paid_point_amount' => 100, 'free_point_amount' => 0, 'currency' => 'JPY', 'status' => 'published']);
        $core = app(V2PaymentService::class);
        $payment = $core->createPayment($user->id, $plan, 'fixture_other', 'other-'.Str::uuid7(), (string) Str::uuid7());
        $event = $core->recordVerifiedProviderEvent('fixture_other', (string) Str::uuid7(), 'payment.succeeded', '{}', [], $payment->id, null, now());
        $core->confirmSucceeded($event->id);
        $service = app(V2AdminPaymentAdjustmentService::class);
        self::assertSame('fixture_other', $service->detail($context, $payment->public_id)['data']['payment']['provider']);
        $core->recordVerifiedProviderEvent('fixture_other', (string) Str::uuid7(), 'payment.status_changed', '{}', [], $payment->id);
        self::assertFalse($service->detail($context, $payment->public_id)['data']['payment']['adjustments']['review_required']);
        $cancel = $core->recordVerifiedProviderEvent('fixture_other', (string) Str::uuid7(), 'payment.canceled', '{}', [], $payment->id);
        self::assertSame('ignored', $cancel->processing_status);
        self::assertTrue($service->detail($context, $payment->public_id)['data']['payment']['adjustments']['review_required']);
        self::assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
        self::assertSame('points_reserved', $service->write($context, $payment->public_id, 'refund', ['reason' => '確認済み'], (string) Str::uuid7())['data']['status']);
    }

    private function expectConflict(callable $callback, ?string $code = null): void
    {
        try {
            $callback();
            self::fail('Expected payment adjustment conflict');
        } catch (V2AdminPaymentReadException $exception) {
            self::assertSame(409, $exception->status);
            if ($code !== null) self::assertSame($code, $exception->errorCode);
        }
    }

    public function test_audit_failure_rolls_back_every_financial_and_idempotency_write(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000, 100);
        $context = $this->rankAdmin();
        $before = $this->counts();
        DB::statement("CREATE FUNCTION pg_temp.m9_fail_audit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic rollback''; END;'");
        DB::statement('CREATE TRIGGER m9_fail_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION pg_temp.m9_fail_audit()');
        try {
            app(V2AdminPaymentAdjustmentService::class)->write($context, $payment->public_id, 'chargeback', ['reason' => 'rollback'], (string) Str::uuid7());
            self::fail('Expected synthetic audit failure');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('synthetic rollback', $exception->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertDatabaseHas('wallets', ['user_id' => $payment->user_id, 'paid_balance' => 1000, 'free_balance' => 100]);
    }

    public function test_long_lived_reservations_survive_expiry_and_release_only_on_explicit_cancel(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 1000, 100);
        $context = $this->rankAdmin();
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => '長期予約'], (string) Str::uuid7());
        $this->rankClock('2027-11-01T00:00:00Z');
        self::assertDatabaseHas('wallets', ['user_id' => $payment->user_id, 'paid_reserved_balance' => 1000, 'free_reserved_balance' => 100]);
        $context = $this->rankAdmin();
        self::assertSame('canceled', $service->write($context, $payment->public_id, 'cancel', ['reason' => '明示取消'], (string) Str::uuid7(), $prepared['data']['id'])['data']['status']);
    }

    private function counts(): array
    {
        $tables = ['payment_adjustments', 'payment_adjustment_status_histories', 'payment_adjustment_point_impacts', 'point_ledger_entries', 'point_operations', 'idempotency_records', 'audit_logs', 'outbox_messages', 'payment_provider_events', 'mail_deliveries'];

        return array_combine($tables, array_map(fn ($table): int => DB::table($table)->count(), $tables));
    }

    private function adminSession(V2AdminRole $role): string
    {
        $context = $this->rankAdmin();
        DB::table('admins')->where('id', $context->adminId)->update(['role' => $role->value]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)->update(['session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token)]);

        return $token;
    }

    private function asAdmin(string $token): static
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function csrf(string $token): static
    {
        $csrf = str_repeat('c', 64);

        return $this->asAdmin($token)->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7()]);
    }
}
