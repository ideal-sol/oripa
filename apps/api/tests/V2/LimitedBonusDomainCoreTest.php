<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Services\V2LimitedBonusCampaignService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LimitedBonusDomainCoreTest extends TestCase
{
    private bool $usesOuterTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('l', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        CarbonImmutable::setTestNow('2026-10-01T12:00:00Z');
        $this->usesOuterTransaction = $this->name()
            !== 'test_concurrent_overlapping_campaign_mutations_are_serialized';
        if ($this->usesOuterTransaction) {
            DB::beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->usesOuterTransaction && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_campaign_validation_overlap_adjacent_and_off_state(): void
    {
        $plan = $this->plan();
        $service = app(V2LimitedBonusCampaignService::class);
        $first = $service->create(
            $plan->id,
            false,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            50
        );
        self::assertFalse((bool) $first->is_enabled);
        self::assertSame(50, (int) $first->bonus_point_amount);
        $adjacent = $service->create(
            $plan->id,
            true,
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            new CarbonImmutable('2026-10-04T00:00:00Z'),
            75
        );
        self::assertTrue((bool) $adjacent->is_enabled);

        foreach ([
            [new CarbonImmutable('2026-10-02T12:00:00Z'), new CarbonImmutable('2026-10-03T12:00:00Z'), 10],
            [new CarbonImmutable('2026-10-05T00:00:00Z'), new CarbonImmutable('2026-10-05T00:00:00Z'), 10],
            [new CarbonImmutable('2026-10-05T00:00:00Z'), new CarbonImmutable('2026-10-06T00:00:00Z'), 0],
        ] as [$start, $end, $amount]) {
            try {
                $service->create($plan->id, true, $start, $end, $amount);
                self::fail('Invalid or overlapping limited bonus campaigns must fail.');
            } catch (V2PaymentException $exception) {
                self::assertContains($exception->getMessage(), [
                    'LIMITED_BONUS_CAMPAIGN_INVALID',
                    'LIMITED_BONUS_CAMPAIGN_OVERLAP',
                ]);
            }
        }
    }

    public function test_concurrent_overlapping_campaign_mutations_are_serialized(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for concurrency verification.');
        }
        $plan = $this->plan();
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            try {
                app(App\Domain\Payment\V2\Services\V2LimitedBonusCampaignService::class)
                    ->create(
                        (int) $argv[1],
                        true,
                        new Carbon\CarbonImmutable($argv[2]),
                        new Carbon\CarbonImmutable($argv[3]),
                        100
                    );
                exit(0);
            } catch (App\Domain\Payment\V2\Exceptions\V2PaymentException $exception) {
                exit($exception->getMessage() === 'LIMITED_BONUS_CAMPAIGN_OVERLAP' ? 2 : 3);
            }
            PHP;
        try {
            $results = $this->parallelProcesses($script, [
                [(string) $plan->id, '2026-10-10T00:00:00Z', '2026-10-12T00:00:00Z'],
                [(string) $plan->id, '2026-10-11T00:00:00Z', '2026-10-13T00:00:00Z'],
            ]);
            sort($results);
            self::assertSame([0, 2], $results);
            self::assertSame(1, DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('point_purchase_plan_id', $plan->id)->count());
        } finally {
            DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('point_purchase_plan_id', $plan->id)->delete();
            DB::table('point_purchase_plans')->where('id', $plan->id)->delete();
        }
    }

    public function test_payment_snapshots_campaign_and_grants_regular_plus_limited_once(): void
    {
        $plan = $this->plan(1000, 20);
        $campaigns = app(V2LimitedBonusCampaignService::class);
        $campaign = $campaigns->create(
            $plan->id,
            true,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            100
        );
        $payment = $this->payment($this->user('snapshot'), $plan, 'snapshot');
        $snapshot = DB::table('payment_limited_bonus_snapshots')
            ->where('payment_id', $payment->id)->firstOrFail();
        self::assertTrue((bool) $snapshot->is_enabled);
        self::assertSame(100, (int) $snapshot->bonus_point_amount);

        $campaigns->update(
            $campaign->id,
            false,
            new CarbonImmutable('2026-10-04T00:00:00Z'),
            new CarbonImmutable('2026-10-05T00:00:00Z'),
            999
        );
        CarbonImmutable::setTestNow('2026-10-20T00:00:00Z');
        $occurredAt = new CarbonImmutable('2026-10-02T12:00:00Z');
        $event = $this->successEvent($payment, 'snapshot-success', $occurredAt);
        $service = app(V2PaymentService::class);
        $grant = $service->confirmSucceeded($event->id);
        self::assertSame($grant->id, $service->confirmSucceeded($event->id)->id);

        $finalPayment = DB::table('payments')->where('id', $payment->id)->firstOrFail();
        self::assertSame(100, (int) $finalPayment->limited_bonus_point_amount);
        self::assertTrue($occurredAt->equalTo(
            CarbonImmutable::parse($finalPayment->succeeded_at)
        ));
        self::assertNotSame(
            CarbonImmutable::parse($event->received_at)->toIso8601String(),
            CarbonImmutable::parse($finalPayment->succeeded_at)->toIso8601String()
        );
        self::assertSame(1, DB::table('payment_point_grants')
            ->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('point_operations')
            ->where('business_key', 'payment.grant:'.$payment->id)->count());
        self::assertDatabaseHas('wallets', [
            'user_id' => $payment->user_id,
            'paid_balance' => 1000,
            'free_balance' => 120,
        ]);
        $freeLot = DB::table('point_lots')
            ->where('grant_operation_id', $grant->point_operation_id)
            ->where('point_type', 'free')->firstOrFail();
        self::assertSame(120, (int) $freeLot->granted_amount);
        self::assertSame(
            CarbonImmutable::parse($grant->granted_at)->addDays(180)->toIso8601String(),
            CarbonImmutable::parse($freeLot->expire_at)->toIso8601String()
        );
        self::assertSame(100, (int) DB::table('payment_limited_bonus_snapshots')
            ->where('payment_id', $payment->id)->value('bonus_point_amount'));
    }

    public function test_success_boundaries_use_provider_time_and_disabled_snapshot_is_off(): void
    {
        $plan = $this->plan(1000, 0);
        app(V2LimitedBonusCampaignService::class)->create(
            $plan->id,
            true,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            80
        );
        $atStart = $this->payment($this->user('at-start'), $plan, 'at-start');
        $atEnd = $this->payment($this->user('at-end'), $plan, 'at-end');
        CarbonImmutable::setTestNow('2026-11-01T00:00:00Z');
        app(V2PaymentService::class)->confirmSucceeded(
            $this->successEvent(
                $atStart,
                'at-start',
                new CarbonImmutable('2026-10-02T00:00:00Z')
            )->id
        );
        app(V2PaymentService::class)->confirmSucceeded(
            $this->successEvent(
                $atEnd,
                'at-end',
                new CarbonImmutable('2026-10-03T00:00:00Z')
            )->id
        );
        self::assertSame(80, (int) DB::table('payments')
            ->where('id', $atStart->id)->value('limited_bonus_point_amount'));
        self::assertSame(0, (int) DB::table('payments')
            ->where('id', $atEnd->id)->value('limited_bonus_point_amount'));

        $offPlan = $this->plan(1000, 0);
        app(V2LimitedBonusCampaignService::class)->create(
            $offPlan->id,
            false,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            90
        );
        $offPayment = $this->payment($this->user('off'), $offPlan, 'off');
        app(V2PaymentService::class)->confirmSucceeded(
            $this->successEvent(
                $offPayment,
                'off',
                new CarbonImmutable('2026-10-02T12:00:00Z')
            )->id
        );
        self::assertSame(0, (int) DB::table('payments')
            ->where('id', $offPayment->id)->value('limited_bonus_point_amount'));
    }

    public function test_missing_canonical_success_time_fails_closed(): void
    {
        $payment = $this->payment($this->user('missing-time'), $this->plan(), 'missing-time');
        try {
            app(V2PaymentService::class)->recordVerifiedProviderEvent(
                'fixture', 'missing-time-ingress', 'payment.succeeded', '{}', [], $payment->id
            );
            self::fail('Verified success ingress must require provider_occurred_at.');
        } catch (V2PaymentException $exception) {
            self::assertSame('PROVIDER_OCCURRED_AT_REQUIRED', $exception->getMessage());
        }

        $eventId = DB::table('payment_provider_events')->insertGetId([
            'provider_code' => 'fixture',
            'external_event_id' => 'missing-time-direct-'.Str::uuid7(),
            'event_type' => 'payment.succeeded',
            'payment_id' => $payment->id,
            'signature_verified_at' => now(),
            'provider_occurred_at' => null,
            'received_at' => now(),
            'payload_hash' => hash('sha256', '{}'),
            'headers_redacted' => '{}',
            'processing_status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->expectException(V2PaymentException::class);
        $this->expectExceptionMessage('PROVIDER_OCCURRED_AT_REQUIRED');
        app(V2PaymentService::class)->confirmSucceeded($eventId);
    }

    public function test_legacy_payment_without_snapshot_never_receives_limited_bonus(): void
    {
        $plan = $this->plan(1000, 10);
        app(V2LimitedBonusCampaignService::class)->create(
            $plan->id,
            true,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            500
        );
        $user = $this->user('legacy');
        $paymentId = DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'point_purchase_plan_id' => $plan->id,
            'provider_code' => 'fixture',
            'provider_payment_id' => 'legacy-'.Str::uuid7(),
            'status' => 'created',
            'amount' => 1000,
            'currency' => 'JPY',
            'paid_point_amount' => 1000,
            'free_point_amount' => 10,
            'plan_name_snapshot' => $plan->name,
            'plan_code_snapshot' => $plan->code,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = DB::table('payments')->where('id', $paymentId)->firstOrFail();
        app(V2PaymentService::class)->confirmSucceeded(
            $this->successEvent(
                $payment,
                'legacy',
                new CarbonImmutable('2026-10-02T12:00:00Z')
            )->id
        );
        self::assertSame(0, (int) DB::table('payments')
            ->where('id', $paymentId)->value('limited_bonus_point_amount'));
        self::assertSame(10, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
    }

    public function test_refund_chargeback_totals_include_granted_bonus_and_reversal_is_manual(): void
    {
        $plan = $this->plan(1000, 100);
        app(V2LimitedBonusCampaignService::class)->create(
            $plan->id,
            true,
            new CarbonImmutable('2026-10-02T00:00:00Z'),
            new CarbonImmutable('2026-10-03T00:00:00Z'),
            50
        );
        $service = app(V2PaymentService::class);
        $refundPayment = $this->payment($this->user('refund'), $plan, 'refund');
        $service->confirmSucceeded($this->successEvent(
            $refundPayment,
            'refund-success',
            new CarbonImmutable('2026-10-02T12:00:00Z')
        )->id);
        $refund = $service->reserveFullRefund($refundPayment->id, 'refund-limited-bonus');
        $service->resolveRefund($refund->id, 'succeeded');
        self::assertSame(150, (int) DB::table('payment_adjustment_point_impacts')
            ->where('payment_adjustment_id', $refund->id)->value('required_free_amount'));

        $chargebackPayment = $this->payment($this->user('chargeback'), $plan, 'chargeback');
        $service->confirmSucceeded($this->successEvent(
            $chargebackPayment,
            'chargeback-success',
            new CarbonImmutable('2026-10-02T12:00:00Z')
        )->id);
        $chargebackEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-limited-'.Str::uuid7(),
            'payment.chargeback',
            '{}',
            [],
            $chargebackPayment->id
        );
        $chargeback = $service->processChargeback($chargebackEvent->id);
        $impact = DB::table('payment_adjustment_point_impacts')
            ->where('payment_adjustment_id', $chargeback->id)->firstOrFail();
        self::assertSame(150, (int) $impact->required_free_amount);
        self::assertSame(150, (int) $impact->reversed_free_from_free);
        self::assertSame(0, (int) $impact->shortfall_free_amount);

        $reversalEvent = $service->recordVerifiedProviderEvent(
            'fixture',
            'chargeback-limited-reversal-'.Str::uuid7(),
            'payment.chargeback_reversed',
            '{}',
            [],
            $chargebackPayment->id,
            $chargeback->id
        );
        $ledgerBefore = DB::table('point_ledger_entries')->count();
        $reversal = $service->recordChargebackReversal($reversalEvent->id, $chargeback->id);
        self::assertSame('manual_review', $reversal->status);
        self::assertSame($ledgerBefore, DB::table('point_ledger_entries')->count());
    }

    private function successEvent(
        object $payment,
        string $suffix,
        CarbonImmutable $providerOccurredAt
    ): object {
        return app(V2PaymentService::class)->recordVerifiedProviderEvent(
            'fixture',
            'limited-'.$suffix.'-'.$payment->public_id,
            'payment.succeeded',
            '{"safe":true}',
            [],
            $payment->id,
            null,
            $providerOccurredAt
        );
    }

    private function payment(User $user, object $plan, string $suffix): object
    {
        return app(V2PaymentService::class)->createPayment(
            $user->id,
            $plan->id,
            'fixture',
            'limited-'.$suffix.'-'.Str::uuid7(),
            'limited-create-'.$suffix.'-'.Str::uuid7()
        );
    }

    private function plan(int $paid = 1000, int $free = 0): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'limited-plan-'.Str::uuid7(),
            'version_no' => 1,
            'name' => 'Limited Bonus Plan',
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

    private function user(string $suffix): User
    {
        $email = 'limited-'.$suffix.'-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic limited bonus buyer',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
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

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = proc_close($process);
        }

        return $results;
    }
}
