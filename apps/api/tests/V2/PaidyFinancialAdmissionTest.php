<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;
use Tests\V2\Fixtures\PaidyFinancialAdmissionFixture;

final class PaidyFinancialAdmissionTest extends TestCase
{
    use PaidyFixtures, PaidyFinancialAdmissionFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePaidyFinancialAdmission();
        $this->configurePaidy();
    }

    protected function tearDown(): void
    {
        $this->quiescePaidyFixtureJobs();
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void { self::resetPaidyTestDatabase(); parent::tearDownAfterClass(); }

    public function test_generation_one_rejects_unadmitted_provider_id_write(): void
    {
        [, $payment] = $this->paidyPayment();
        try {
            DB::transaction(fn () => DB::table('payments')->where('id', $payment->id)->update(['provider_payment_id' => 'pay_synthetic']));
            self::fail('The real financial guard must remain enforced.');
        } catch (QueryException $exception) {
            self::assertSame('40001', $exception->errorInfo[0]);
            self::assertStringContainsString('financial writer requires outer admission', $exception->getMessage());
        }
        self::assertNull(DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
        Http::assertNothingSent();
    }

    #[DataProvider('entryPaths')]
    public function test_generation_one_capture_and_grant_once(string $path): void
    {
        [$user, $payment] = $this->paidyPayment();
        $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED'); $captures = 0;
        Http::fake(function (Request $request) use ($payment, $authorized, $closed, &$captures) {
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') {
                self::assertStringEndsWith('/captures', $request->url());
                self::assertSame($authorized['id'], DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
                self::assertTrue(DB::table('outbox_messages')->where('deduplication_key', 'paidy.operation:'.$payment->public_id)
                    ->where('event_type', 'paidy.capture.claimed')->where('status', 'delivered')->exists());
                $captures++;
            }
            return Http::response($captures ? $closed : $authorized, 200);
        });
        if ($path === 'browser') {
            self::assertFalse(config('v2_paidy.worker_enabled'));
            Auth::guard('v2_user')->setUser($user);
            $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
                ->withUnencryptedCookie('__Host-oripa_user_xsrf', str_repeat('a', 64));
            $this->postJson('/api/v2/payments/'.$payment->public_id.'/paidy-authorizations',
                ['paidy_payment_id' => $authorized['id']], ['Origin' => 'https://example.test',
                    'X-XSRF-TOKEN' => str_repeat('a', 64), 'Idempotency-Key' => (string) Str::uuid()])
                ->assertOk()->assertJsonPath('status', 'succeeded');
        } else {
            config(['v2_paidy.worker_enabled' => true]);
            if ($path === 'webhook') app(V2PaidyWebhookService::class)->receive(['payment_id' => $authorized['id'], 'status' => 'authorize_success']);
            else DB::transaction(fn () => app(V2PaidyReconciliationService::class)->enqueueLookup($authorized['id'], $payment->public_id));
            app(V2PaidyReconciliationService::class)->work(100);
        }
        $this->authorizePaidy($user, $payment, $authorized['id']);
        $this->authorizePaidy($user, $payment, $authorized['id']);
        $key = (string) Str::uuid();
        foreach ([1, 2] as $replay) {
            app(\App\Domain\Payment\V2\Services\V2PaidyPaymentService::class)->authorize(
                $user, $payment->public_id, ['paidy_payment_id' => $authorized['id']], $key);
        }
        self::assertSame($authorized['id'], DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
        self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        self::assertSame(1, $captures);
        $this->assertGrantCount($payment, 1);
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(1, DB::table('outbox_messages')->where('deduplication_key', 'paidy.operation:'.$payment->public_id)->count());
    }

    public static function entryPaths(): array { return [['browser'], ['webhook'], ['worker']]; }

    #[DataProvider('preCaptureFailures')]
    public function test_database_failure_before_capture_never_sends_money_request(string $statement): void
    {
        [$user, $payment] = $this->paidyPayment(); $body = $this->paidyObject($payment);
        $inject = true;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$inject, $statement): void {
            if ($inject && str_contains($query->sql, $statement)
                && ($statement !== 'insert into "outbox_messages"' || in_array('paidy.capture.claimed', $query->bindings, true))) {
                throw new \RuntimeException('Synthetic pre-capture database failure.');
            }
        });
        Http::fake(function (Request $request) use ($body) {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('GET', $request->method());
            return Http::response($body);
        });
        try {
            $this->authorizePaidy($user, $payment, $body['id']);
            self::assertSame('requires_action', DB::table('payments')->where('id', $payment->id)->value('status'));
            self::assertSame(0, DB::table('outbox_messages')->where('deduplication_key', 'paidy.operation:'.$payment->public_id)->count());
            $this->assertGrantCount($payment, 0);
            Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
            if (str_contains($statement, 'provider_payment_id')) {
                self::assertNull(DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
            }
        } finally { $inject = false; }
    }

    public static function preCaptureFailures(): array
    {
        return [['update "payments" set "provider_payment_id"'], ['insert into "outbox_messages"']];
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_or_expired_authorization_cannot_capture_or_grant(string $state): void
    {
        [$user, $payment] = $this->paidyPayment();
        if ($state === 'deadline') {
            \Carbon\Carbon::setTestNow(now()->addMinutes(91));
            \Carbon\CarbonImmutable::setTestNow(\Carbon\Carbon::now());
        } else {
            app(V2PointTransactionRunner::class)->run(fn () => DB::table('payments')->where('id', $payment->id)->update(['status' => $state]));
        }
        $body = $this->paidyObject($payment);
        Http::fake(function (Request $request) use ($body) {
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') {
                self::assertStringEndsWith('/close', $request->url());
                return Http::response([...$body, 'status' => 'CLOSED']);
            }
            return Http::response($body);
        });
        $this->authorizePaidy($user, $payment, $body['id']);
        $this->authorizePaidy($user, $payment, $body['id']);
        $this->assertGrantCount($payment, 0);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/captures'));
        self::assertSame(1, DB::table('outbox_messages')->where('deduplication_key', 'paidy.operation:'.$payment->public_id)
            ->where('event_type', 'paidy.cancel.claimed')->count());
    }

    public static function terminalStates(): array { return [['expired'], ['canceled'], ['failed'], ['deadline']]; }

    public function test_worker_expires_unassociated_payment_under_generation_one(): void
    {
        [, $payment] = $this->paidyPayment();
        \Carbon\Carbon::setTestNow(now()->addMinutes(91));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\Carbon::now());
        config(['v2_paidy.worker_enabled' => true]);
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame('expired', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertGrantCount($payment, 0);
        Http::assertNothingSent();
    }

    #[DataProvider('captureFailures')]
    public function test_capture_unknown_result_retrieves_and_never_sends_capture_twice(string $failure): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $calls = 0;
        Http::fake(function (Request $request) use ($authorized, $failure, &$calls) {
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') {
                $calls++;
                if ($failure === 'timeout') throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
                return Http::response($failure === 'invalid2xx' ? ['id' => $authorized['id']] : ['code' => 'service.forbidden'], $failure === 'invalid2xx' ? 201 : (int) $failure);
            }
            return Http::response($authorized);
        });
        self::assertSame('processing', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame('processing', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame(1, $calls);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $closed = $this->paidyObject($payment, 'CLOSED'); Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://api.paidy.com/*' => Http::response($closed)]);
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    public static function captureFailures(): array { return [['timeout'], ['500'], ['403'], ['invalid2xx']]; }


    public function test_capture_grant_failure_rolls_back_money_and_recovers_without_second_capture(): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        $failGrant = true; $captured = false; $captureCount = 0;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$failGrant): void {
            if ($failGrant && str_contains($query->sql, 'insert into "payment_point_grants"')) throw new \RuntimeException('Synthetic grant failure.');
        });
        Http::fake(function (Request $request) use ($authorized, $closed, &$captured, &$captureCount) {
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') { $captured = true; $captureCount++; }
            return Http::response($captured ? $closed : $authorized);
        });
        self::assertSame('processing', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->sum('paid_balance'));
        self::assertSame(0, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        self::assertTrue(DB::table('payment_progress_events')->where('payment_id', $payment->id)->whereRaw("detail->>'reason' = 'capture_grant_failed'")->exists());
        $failGrant = false;
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame(1, $captureCount);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
    }


    public function test_provider_authorization_expired_fails_without_grant(): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment);
        Http::fakeSequence()->push($authorized)->push(['code' => 'payment.authorization.expired'], 400);
        self::assertSame('failed', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    #[DataProvider('closedStates')]
    public function test_closed_and_rejected_states_never_grant_without_exact_capture(string $variant, string $expected): void
    {
        [$user, $payment] = $this->paidyPayment(); $body = $this->paidyObject($payment, $variant === 'rejected' ? 'REJECTED' : 'CLOSED');
        if ($variant === 'empty') $body['captures'] = [];
        if ($variant === 'refund') $body['refunds'] = [['id' => 'ref_synthetic', 'amount' => 1000]];
        if ($variant === 'mismatch') $body['captures'][0]['amount'] = 999;
        Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
        self::assertSame($expected, $this->authorizePaidy($user, $payment, $body['id'])['status']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        Http::assertSentCount(1);
    }

    public static function closedStates(): array { return [['empty', 'failed'], ['refund', 'requires_action'], ['mismatch', 'processing'], ['rejected', 'failed']]; }


    public function test_uncertain_capture_after_deadline_remains_processing_without_close_or_second_capture(): void
    {
        [$user, $payment] = $this->paidyPayment(); $body = $this->paidyObject($payment); $captureCount = 0;
        Http::fake(function (Request $request) use ($body, &$captureCount) {
            if ($request->method() === 'POST') { self::assertStringEndsWith('/captures', $request->url()); $captureCount++; throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout.'); }
            return Http::response($body);
        });
        self::assertSame('processing', $this->authorizePaidy($user, $payment, $body['id'])['status']);
        \Carbon\Carbon::setTestNow(now()->addMinutes(65));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\Carbon::now());
        try {
            self::assertSame('processing', $this->authorizePaidy($user, $payment, $body['id'])['status']);
            self::assertSame(1, $captureCount);
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertTrue(DB::table('payment_progress_events')->where('payment_id', $payment->id)->whereRaw("detail->>'match' = 'expired'")->exists());
        } finally { \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow(); }
    }


    public function test_late_capture_for_terminal_payment_is_review_only_and_no_close(): void
    {
        foreach (['expired', 'failed', 'canceled'] as $terminal) {
            [$user, $payment] = $this->paidyPayment();
            $event = app(V2PaymentService::class)->recordVerifiedProviderEvent('paidy', 'fixture:'.Str::uuid(), 'payment.'.$terminal, '{}', [], (int) $payment->id);
            app(V2PaymentService::class)->applyVerifiedStatus((int) $event->id, $terminal);
            $body = $this->paidyObject($payment, 'CLOSED'); Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
            self::assertSame($terminal, $this->authorizePaidy($user, $payment, $body['id'])['status']);
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertTrue(DB::table('payment_progress_events')->where('payment_id', $payment->id)->whereRaw("detail->>'match' = 'expired'")->exists());
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }


    private function assertGrantCount(object $payment, int $count): void
    {
        self::assertSame($count, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }
}
