<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentModelFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_payment.purchase_bonus_expiry_days' => 365,
        ]);
    }

    public function test_plan_constraints_and_published_financial_values_are_immutable(): void
    {
        $plan = $this->plan(1000, 100);
        $this->expectQueryFailure(
            fn () => DB::table('point_purchase_plans')->where('id', $plan->id)
                ->update(['amount' => 900])
        );
        $this->expectQueryFailure(fn () => DB::table('point_purchase_plans')->insert([
            'public_id' => (string) Str::uuid7(),
            'code' => 'invalid-currency',
            'version_no' => 1,
            'name' => 'invalid',
            'amount' => 1000,
            'paid_point_amount' => 999,
            'free_point_amount' => 0,
            'currency' => 'USD',
            'status' => 'draft',
        ]));
    }

    public function test_verified_payment_success_grants_paid_and_free_once(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('grant-once');
        $service = app(V2PaymentService::class);
        $first = $service->confirmSucceeded($event->id);
        $replay = $service->confirmSucceeded($event->id);

        self::assertSame($first->id, $replay->id);
        self::assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'succeeded',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
        ]);
        self::assertSame(1, DB::table('payment_point_grants')
            ->where('payment_id', $payment->id)->count());
        self::assertDatabaseHas('wallets', [
            'user_id' => $payment->user_id,
            'paid_balance' => 1000,
            'free_balance' => 100,
        ]);
        self::assertDatabaseHas('point_lots', [
            'user_id' => $payment->user_id,
            'point_type' => 'paid',
            'remaining_amount' => 1000,
        ]);
        self::assertDatabaseHas('point_lots', [
            'user_id' => $payment->user_id,
            'point_type' => 'free',
            'remaining_amount' => 100,
        ]);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'payment.succeeded']);
        self::assertDatabaseHas('outbox_messages', ['event_type' => 'payment.succeeded']);
    }

    public function test_browser_or_unsigned_event_cannot_succeed_payment(): void
    {
        $eventId = DB::table('payment_provider_events')->insertGetId([
            'provider_code' => 'fixture',
            'external_event_id' => 'unsigned-event',
            'event_type' => 'payment.succeeded',
            'payment_id' => null,
            'signature_verified_at' => now(),
            'received_at' => now(),
            'payload_hash' => hash('sha256', '{}'),
            'headers_redacted' => '{}',
            'processing_status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('VERIFIED_SERVER_EVENT_REQUIRED');
        app(V2PaymentService::class)->confirmSucceeded($eventId);
    }

    public function test_provider_event_replay_is_deduplicated(): void
    {
        $service = app(V2PaymentService::class);
        $first = $service->recordVerifiedProviderEvent(
            'fixture',
            'duplicate-event',
            'payment.processing',
            '{"safe":true}',
            ['Content-Type' => 'application/json', 'Authorization' => 'removed']
        );
        $second = $service->recordVerifiedProviderEvent(
            'fixture',
            'duplicate-event',
            'payment.processing',
            '{"safe":true}'
        );
        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('payment_provider_events')
            ->where('external_event_id', 'duplicate-event')->count());
        self::assertStringNotContainsString(
            'Authorization',
            (string) $first->headers_redacted
        );
    }

    public function test_full_unused_refund_reserves_and_consumes_lots(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('refund-unused');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        $adjustment = $service->reserveFullRefund($payment->id, 'refund-key');

        self::assertSame('points_reserved', $adjustment->status);
        self::assertSame(2, DB::table('point_lot_reservations')
            ->where('payment_adjustment_id', $adjustment->id)
            ->where('status', 'active')->count());
        $resolved = $service->resolveRefund($adjustment->id, 'succeeded');
        self::assertSame('succeeded', $resolved->status);
        self::assertDatabaseHas('wallets', [
            'user_id' => $payment->user_id,
            'paid_balance' => 0,
            'free_balance' => 0,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
        ]);
    }

    public function test_refund_rejects_any_consumed_payment_point(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('refund-used');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        app(V2PointService::class)->consume($payment->user_id, 1, 'used-one-point');

        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('REFUND_POINTS_NOT_FULLY_UNUSED');
        $service->reserveFullRefund($payment->id, 'refund-used-key');
    }

    public function test_failed_refund_releases_and_uncertain_refund_keeps_reservation(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('refund-result');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        $uncertain = $service->reserveFullRefund($payment->id, 'uncertain-key');
        self::assertSame('processing', $service->resolveRefund($uncertain->id, 'uncertain')->status);
        self::assertSame(2, DB::table('point_lot_reservations')
            ->where('payment_adjustment_id', $uncertain->id)
            ->where('status', 'active')->count());
        $failed = $service->resolveRefund($uncertain->id, 'failed');
        self::assertSame('failed', $failed->status);
        self::assertSame(2, DB::table('point_lot_reservations')
            ->where('payment_adjustment_id', $uncertain->id)
            ->where('status', 'released')->count());
    }

    public function test_provider_operation_runs_outside_transaction_and_tracks_unknown_result(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('provider-operation');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        $adjustment = $service->reserveFullRefund($payment->id, 'provider-operation-refund');
        $operation = $service->beginProviderRefundOperation(
            $payment->id,
            $adjustment->id,
            'provider-idempotency-key',
            ['amount' => 1000, 'currency' => 'JPY']
        );
        self::assertSame('pending', $operation->status);
        $uncertain = $service->resolveProviderOperation($operation->id, 'uncertain');
        self::assertSame('uncertain', $uncertain->status);
        self::assertTrue($uncertain->outcome_uncertain);
        self::assertSame(2, DB::table('point_lot_reservations')
            ->where('payment_adjustment_id', $adjustment->id)
            ->where('status', 'active')->count());

        $this->expectException(V2PaymentException::class);
        DB::transaction(fn () => $service->beginProviderRefundOperation(
            $payment->id,
            $adjustment->id,
            'inside-transaction',
            []
        ));
    }

    public function test_chargeback_uses_paid_then_free_and_records_shortfall_without_negative_balance(): void
    {
        [$payment, $success] = $this->paymentWithVerifiedEvent('chargeback');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($success->id);
        app(V2PointService::class)->consume($payment->user_id, 1050, 'chargeback-consumed');
        $event = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-event',
            'payment.chargeback',
            '{"safe":true}',
            [],
            $payment->id
        );
        $adjustment = $service->processChargeback($event->id);
        $impact = DB::table('payment_adjustment_point_impacts')
            ->where('payment_adjustment_id', $adjustment->id)->firstOrFail();

        self::assertSame(950, (int) $impact->shortfall_paid_amount);
        self::assertSame(100, (int) $impact->shortfall_free_amount);
        self::assertSame(0, DB::table('point_ledger_entries')
            ->where('wallet_balance_after', '<', 0)->count());
        self::assertSame(1, DB::table('payment_adjustments')
            ->where('source_provider_event_id', $event->id)->count());
        self::assertSame(
            $adjustment->id,
            $service->processChargeback($event->id)->id
        );
    }

    public function test_chargeback_reversal_never_restores_points_automatically(): void
    {
        [$payment, $success] = $this->paymentWithVerifiedEvent('chargeback-reversal');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($success->id);
        $chargebackEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-for-reversal',
            'payment.chargeback',
            '{}',
            [],
            $payment->id
        );
        $chargeback = $service->processChargeback($chargebackEvent->id);
        $reversalEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-reversal',
            'payment.chargeback_reversed',
            '{}',
            [],
            $payment->id,
            $chargeback->id
        );
        $before = DB::table('point_ledger_entries')->count();
        $reversal = $service->recordChargebackReversal($reversalEvent->id, $chargeback->id);

        self::assertSame('manual_review', $reversal->status);
        self::assertSame($before, DB::table('point_ledger_entries')->count());
    }

    public function test_mock_payment_is_fail_closed_in_production(): void
    {
        config(['v2_payment.mock_driver' => true]);
        app()->detectEnvironment(fn (): string => 'production');
        $this->expectException(V2PaymentException::class);
        app(V2PaymentService::class)->assertRuntimeSafe();
    }

    private function paymentWithVerifiedEvent(string $suffix): array
    {
        $user = $this->user($suffix);
        $payment = $this->payment($user, $this->plan(1000, 100), $suffix);
        $event = app(V2PaymentService::class)->recordVerifiedProviderEvent(
            'fixture',
            'event-'.$suffix,
            'payment.succeeded',
            '{"safe":true}',
            [],
            $payment->id
        );

        return [$payment, $event];
    }

    private function payment(User $user, object $plan, string $suffix): object
    {
        return app(V2PaymentService::class)->createPayment(
            $user->id,
            $plan->id,
            'fixture',
            'payment-'.$suffix,
            'create-'.$suffix
        );
    }

    private function plan(int $paid, int $free): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'plan-'.Str::uuid(),
            'version_no' => 1,
            'name' => 'Test Plan',
            'amount' => $paid,
            'paid_point_amount' => $paid,
            'free_point_amount' => $free,
            'currency' => 'JPY',
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('point_purchase_plans')->where('id', $id)->firstOrFail();
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'email_display' => $name.'-'.Str::uuid().'@example.test',
            'email_normalized' => $name.'-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function expectQueryFailure(callable $callback): void
    {
        try {
            DB::transaction($callback);
            self::fail('PostgreSQL constraint must reject the mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
