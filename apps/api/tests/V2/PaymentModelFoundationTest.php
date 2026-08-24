<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2CurrentUserPointReadService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
        $grantedAt = CarbonImmutable::parse($first->granted_at)->startOfSecond();
        $lots = DB::table('point_lots')
            ->where('user_id', $payment->user_id)
            ->orderBy('id')
            ->get();
        self::assertCount(2, $lots);
        foreach ($lots as $lot) {
            self::assertSame(
                $grantedAt->addDays(180)->toIso8601String(),
                CarbonImmutable::parse($lot->expire_at)->toIso8601String()
            );
            self::assertFalse((bool) $lot->legacy_no_expiry);
        }
        self::assertDatabaseHas('audit_logs', ['action_code' => 'payment.succeeded']);
        self::assertDatabaseHas('outbox_messages', ['event_type' => 'payment.succeeded']);
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'coin.purchase.completed:'.$payment->public_id)->count());
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

        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('PROVIDER_EVENT_ID_REUSED');
        $service->recordVerifiedProviderEvent(
            'fixture',
            'duplicate-event',
            'payment.failed',
            '{"safe":false}'
        );
    }

    public function test_payment_lifecycle_rejects_terminal_state_rollback(): void
    {
        $service = app(V2PaymentService::class);
        $user = $this->user('lifecycle');
        $payment = $this->payment($user, $this->plan(1000, 100), 'lifecycle');
        $processing = $service->recordVerifiedProviderEvent(
            'fixture',
            'lifecycle-processing',
            'payment.processing',
            '{}',
            [],
            $payment->id
        );
        self::assertSame(
            'processing',
            $service->applyVerifiedStatus($processing->id, 'processing')->status
        );
        $failed = $service->recordVerifiedProviderEvent(
            'fixture',
            'lifecycle-failed',
            'payment.failed',
            '{}',
            [],
            $payment->id
        );
        self::assertSame('failed', $service->applyVerifiedStatus($failed->id, 'failed')->status);
        self::assertSame(
            ['created', 'processing', 'failed'],
            DB::table('payment_status_histories')->where('payment_id', $payment->id)
                ->orderBy('id')->pluck('to_status')->all()
        );

        $lateSuccess = $service->recordVerifiedProviderEvent(
            'fixture',
            'lifecycle-late-success',
            'payment.succeeded',
            '{}',
            [],
            $payment->id,
            null,
            now()
        );
        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('PAYMENT_TERMINAL_STATE');
        $service->confirmSucceeded($lateSuccess->id);
    }

    public function test_payment_create_is_idempotent_and_rejects_key_reuse(): void
    {
        $service = app(V2PaymentService::class);
        $user = $this->user('payment-idempotency');
        $plan = $this->plan(1000, 100);
        $first = $service->createPayment(
            $user->id,
            $plan->id,
            'fixture',
            'idempotent-payment',
            'idempotent-create'
        );
        $replay = $service->createPayment(
            $user->id,
            $plan->id,
            'fixture',
            'idempotent-payment',
            'idempotent-create'
        );
        self::assertSame($first->id, $replay->id);

        $this->expectException(V2PointException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_KEY_REUSED');
        $service->createPayment(
            $user->id,
            $this->plan(2000, 0)->id,
            'fixture',
            'different-payment',
            'idempotent-create'
        );
    }

    public function test_payment_success_rolls_back_all_point_and_event_side_effects(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('success-rollback');
        DB::table('point_operations')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $payment->user_id,
            'operation_type' => 'fixture',
            'business_key' => 'payment.grant:'.$payment->id,
            'source_type' => 'test_fixture',
            'actor_type' => 'system',
            'is_qa' => false,
            'occurred_at' => now(),
            'business_date' => now('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
        ]);
        try {
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            self::fail('Duplicate point operation must fail the transaction.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(
            'created',
            DB::table('payments')->where('id', $payment->id)->value('status')
        );
        self::assertSame(0, DB::table('payment_point_grants')
            ->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('point_operations')
            ->where('business_key', 'payment.grant:'.$payment->id)->count());
        self::assertSame(0, DB::table('audit_logs')
            ->where('action_code', 'payment.succeeded')
            ->where('target_public_id', $payment->public_id)->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', 'payment.succeeded')
            ->where('aggregate_public_id', $payment->public_id)->count());
    }

    public function test_same_payment_concurrent_success_grants_points_once(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for concurrency verification.');
        }
        [$payment, $event] = $this->paymentWithVerifiedEvent('concurrent-success');
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config([
                'v2_audit.active_hmac_key_version' => 'v1',
                'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            ]);
            app(App\Domain\Payment\V2\Services\V2PaymentService::class)
                ->confirmSucceeded((int) $argv[1]);
            PHP;
        self::assertSame(
            [0, 0],
            $this->parallelProcesses($script, [
                [(string) $event->id],
                [(string) $event->id],
            ])
        );
        self::assertSame(1, DB::table('payment_point_grants')
            ->where('payment_id', $payment->id)->count());
        self::assertSame(1100, (int) DB::table('wallets')
            ->where('user_id', $payment->user_id)
            ->selectRaw('paid_balance + free_balance AS balance')
            ->value('balance'));
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
        self::assertDatabaseHas('audit_logs', ['action_code' => 'payment.refund_succeeded']);
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'payment.refund.succeeded',
        ]);
        self::assertSame(
            $resolved->id,
            $service->resolveRefund($adjustment->id, 'succeeded')->id
        );
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
        self::assertDatabaseHas('audit_logs', ['action_code' => 'payment.refund_failed']);
    }

    public function test_expired_payment_lots_cannot_be_newly_reserved_at_boundary(): void
    {
        Carbon::setTestNow('2026-08-01 00:00:00+00');
        CarbonImmutable::setTestNow('2026-08-01 00:00:00+00');
        try {
            [$payment, $event] = $this->paymentWithVerifiedEvent('expired-reservation');
            $service = app(V2PaymentService::class);
            $service->confirmSucceeded($event->id);
            $expiry = CarbonImmutable::parse(DB::table('point_lots')
                ->where('user_id', $payment->user_id)->min('expire_at'));
            Carbon::setTestNow($expiry);
            CarbonImmutable::setTestNow($expiry);

            try {
                $service->reserveFullRefund($payment->id, 'expired-reservation-key');
                self::fail('A lot is unavailable when operation_at equals expire_at.');
            } catch (V2PaymentException $exception) {
                self::assertSame('REFUND_POINTS_NOT_FULLY_UNUSED', $exception->getMessage());
            }
            $lotIds = DB::table('point_lots')
                ->where('user_id', $payment->user_id)
                ->pluck('id');
            self::assertSame(0, DB::table('point_lot_reservations')
                ->whereIn('point_lot_id', $lotIds)->count());
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_reservation_release_keeps_original_expiry_and_does_not_restore_availability(): void
    {
        Carbon::setTestNow('2026-08-01 00:00:00+00');
        CarbonImmutable::setTestNow('2026-08-01 00:00:00+00');
        try {
            [$payment, $event] = $this->paymentWithVerifiedEvent('reservation-expiry');
            $service = app(V2PaymentService::class);
            $service->confirmSucceeded($event->id);
            $originalExpiries = DB::table('point_lots')
                ->where('user_id', $payment->user_id)->orderBy('id')->pluck('expire_at', 'id');
            $adjustment = $service->reserveFullRefund($payment->id, 'reservation-expiry-key');
            $afterExpiry = CarbonImmutable::parse($originalExpiries->first())->addSecond();
            Carbon::setTestNow($afterExpiry);
            CarbonImmutable::setTestNow($afterExpiry);

            app(V2PointService::class)->expire($afterExpiry);
            self::assertSame(
                [1000, 100],
                DB::table('point_lots')->where('user_id', $payment->user_id)
                    ->orderBy('id')->pluck('remaining_amount')->map(fn ($value): int => (int) $value)->all()
            );
            $service->resolveRefund($adjustment->id, 'failed');
            self::assertSame(
                $originalExpiries->all(),
                DB::table('point_lots')->where('user_id', $payment->user_id)
                    ->orderBy('id')->pluck('expire_at', 'id')->all()
            );
            self::assertSame(
                [
                    'paid_points' => 0,
                    'free_points' => 0,
                    'total_points' => 0,
                    'as_of' => $afterExpiry->utc()->startOfSecond()->toIso8601ZuluString(),
                    'expiring_within_7_days' => [],
                ],
                app(V2CurrentUserPointReadService::class)
                    ->wallet(User::query()->findOrFail($payment->user_id))
            );
            self::assertSame(2, app(V2PointService::class)->expire($afterExpiry));
            self::assertDatabaseHas('wallets', [
                'user_id' => $payment->user_id,
                'paid_balance' => 0,
                'free_balance' => 0,
            ]);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_refund_reservation_blocks_point_consumption(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('refund-reservation-block');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        $service->reserveFullRefund($payment->id, 'refund-reservation-block-key');

        $this->expectException(V2PointException::class);
        app(V2PointService::class)->consume(
            $payment->user_id,
            1,
            'reserved-point-consumption'
        );
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

    public function test_provider_operation_replay_requires_the_same_request(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('provider-operation-replay');
        $service = app(V2PaymentService::class);
        $service->confirmSucceeded($event->id);
        $adjustment = $service->reserveFullRefund(
            $payment->id,
            'provider-operation-replay-refund'
        );
        $first = $service->beginProviderRefundOperation(
            $payment->id,
            $adjustment->id,
            'provider-operation-replay-key',
            ['amount' => 1000]
        );
        self::assertSame(
            $first->id,
            $service->beginProviderRefundOperation(
                $payment->id,
                $adjustment->id,
                'provider-operation-replay-key',
                ['amount' => 1000]
            )->id
        );

        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('PROVIDER_OPERATION_KEY_REUSED');
        $service->beginProviderRefundOperation(
            $payment->id,
            $adjustment->id,
            'provider-operation-replay-key',
            ['amount' => 999]
        );
    }

    public function test_adjustment_amount_and_immutable_histories_are_database_enforced(): void
    {
        [$payment, $event] = $this->paymentWithVerifiedEvent('adjustment-constraint');
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        $this->expectQueryFailure(fn () => DB::table('payment_adjustments')->insert([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $payment->id,
            'type' => 'refund',
            'status' => 'requested',
            'amount' => 1001,
            'currency' => 'JPY',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $historyId = DB::table('payment_status_histories')
            ->where('payment_id', $payment->id)->value('id');
        $this->expectQueryFailure(fn () => DB::table('payment_status_histories')
            ->where('id', $historyId)->update(['to_status' => 'failed']));
        $this->expectQueryFailure(fn () => DB::table('payment_provider_events')
            ->where('id', $event->id)->delete());
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

        self::assertSame(1000, (int) $impact->shortfall_paid_amount);
        self::assertSame(50, (int) $impact->shortfall_free_amount);
        self::assertSame(0, (int) $impact->reversed_paid_from_paid);
        self::assertSame(50, (int) $impact->reversed_free_from_free);
        self::assertSame(0, DB::table('point_ledger_entries')
            ->where('wallet_balance_after', '<', 0)->count());
        self::assertSame(1, DB::table('payment_adjustments')
            ->where('source_provider_event_id', $event->id)->count());
        self::assertSame(
            $adjustment->id,
            $service->processChargeback($event->id)->id
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'payment.chargeback_processed',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'payment.chargeback.processed',
        ]);
    }

    public function test_chargeback_uses_origin_lots_first_and_never_uses_paid_for_free_bonus(): void
    {
        $service = app(V2PaymentService::class);
        $user = $this->user('chargeback-order');
        $olderPayment = $this->payment(
            $user,
            $this->plan(300, 0),
            'chargeback-order-older'
        );
        $olderEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-order-older-success',
            'payment.succeeded',
            '{}',
            [],
            $olderPayment->id,
            null,
            now()
        );
        $service->confirmSucceeded($olderEvent->id);
        $targetPayment = $this->payment(
            $user,
            $this->plan(1000, 100),
            'chargeback-order-target'
        );
        $targetSuccess = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-order-target-success',
            'payment.succeeded',
            '{}',
            [],
            $targetPayment->id,
            null,
            now()
        );
        $service->confirmSucceeded($targetSuccess->id);
        $targetGrantOperation = DB::table('payment_point_grants')
            ->where('payment_id', $targetPayment->id)->value('point_operation_id');
        $targetFreeLot = DB::table('point_lots')
            ->where('grant_operation_id', $targetGrantOperation)
            ->where('point_type', 'free')->firstOrFail();
        DB::table('point_lots')->where('id', $targetFreeLot->id)
            ->update(['remaining_amount' => 0]);
        DB::table('wallets')->where('user_id', $user->id)
            ->update(['free_balance' => 0]);

        $chargebackEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-order-event',
            'payment.chargeback',
            '{}',
            [],
            $targetPayment->id
        );
        $adjustment = $service->processChargeback($chargebackEvent->id);
        $impact = DB::table('payment_adjustment_point_impacts')
            ->where('payment_adjustment_id', $adjustment->id)->firstOrFail();
        self::assertSame(1000, (int) $impact->reversed_paid_from_paid);
        self::assertSame(0, (int) $impact->reversed_free_from_free);
        self::assertSame(100, (int) $impact->shortfall_free_amount);
        self::assertSame(300, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('paid_balance'));
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
            $payment->id,
            null,
            now()
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

    /**
     * @param list<list<string>> $arguments
     * @return list<int>
     */
    private function parallelProcesses(string $script, array $arguments): array
    {
        $processes = [];
        foreach ($arguments as $args) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $script, ...$args],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                base_path()
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $statuses[] = proc_close($process);
        }
        DB::disconnect();
        DB::reconnect();

        return $statuses;
    }
}
