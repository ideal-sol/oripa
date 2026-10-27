<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyResponseClassifier;
use App\Domain\Payment\V2\Services\V2PaymentMethodPolicy;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class PaidyPaymentTest extends TestCase
{
    use PaidyFixtures;

    protected function setUp(): void { parent::setUp(); $this->configurePaidy(); }
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); parent::tearDown(); }
    public static function tearDownAfterClass(): void { self::resetPaidyTestDatabase(); parent::tearDownAfterClass(); }

    public function test_checkout_uses_saved_amount_verified_identity_and_no_secret_or_address(): void
    {
        [$user, $payment, $result] = $this->paidyPayment();
        $action = $result['next_action']; $payload = $action['payload'];
        self::assertSame('paidy_checkout', $action['type']);
        self::assertSame(1000, $payload['amount']);
        self::assertSame($payload['amount'], array_sum(array_map(fn ($i) => $i['unit_price'] * $i['quantity'], $payload['order']['items'])) + $payload['order']['tax'] + $payload['order']['shipping']);
        self::assertSame($payment->public_id, $payload['order']['order_ref']);
        self::assertSame($user->email_display, $payload['buyer']['email']);
        self::assertMatchesRegularExpression('/^0[789]0[0-9]{8}$/', $payload['buyer']['phone']);
        self::assertArrayNotHasKey('name1', $payload['buyer']);
        self::assertArrayNotHasKey('shipping_address', $payload);
        self::assertStringNotContainsString('sk_', json_encode($result));
        self::assertSame(3600, strtotime($payment->expires_at) - strtotime($payment->created_at));
        self::assertSame('test', DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification'));
        self::assertFalse((bool) DB::selectOne('select v2_payment_commercial(?) as eligible', [$payment->id])->eligible);
        self::assertSame(0, $payload['buyer_data']['ltv']);
        self::assertSame(0, $payload['buyer_data']['order_count']);
        $user->forceFill(['full_name' => '　　'])->save();
        self::assertArrayNotHasKey('name1', app(V2FincodePaymentService::class)->show($user, $payment->public_id)['next_action']['payload']['buyer']);
        $user->forceFill(['full_name' => '合成　会員'])->save();
        self::assertSame('合成 会員', app(V2FincodePaymentService::class)->show($user, $payment->public_id)['next_action']['payload']['buyer']['name1']);
        Http::assertNothingSent();
    }

    public function test_start_replay_retains_one_payment_and_missing_or_invalid_configuration_fails_closed(): void
    {
        $user = $this->paidyUser(); $plan = $this->paidyPlan(); $key = (string) Str::uuid();
        $service = app(V2PaidyPaymentService::class);
        self::assertSame($service->start($user, $plan->public_id, $key)['id'], $service->start($user, $plan->public_id, $key)['id']);
        foreach (['mode', 'secret_key', 'public_key', 'store_name', 'webhook_path_token'] as $field) {
            $value = config('v2_paidy.'.$field); config(['v2_paidy.'.$field => null]);
            self::assertFalse(app(V2PaymentMethodPolicy::class)->methods(null)['data'][0]['available']);
            $this->problem(fn () => $service->start($user, $plan->public_id, (string) Str::uuid()), 'PAYMENT_PROVIDER_UNAVAILABLE', 503);
            config(['v2_paidy.'.$field => $value]);
        }
        self::assertSame(1, DB::table('payments')->where('user_id', $user->id)->count());
    }

    public function test_public_start_routes_paidy_with_csrf_server_price_and_idempotency(): void
    {
        $user = $this->paidyUser(); $plan = $this->paidyPlan(); Auth::guard('v2_user')->setUser($user);
        $headers = ['Origin' => 'https://example.test', 'X-XSRF-TOKEN' => str_repeat('a', 64), 'Idempotency-Key' => 'synthetic-paidy-start'];
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', str_repeat('a', 64));
        $input = ['point_product_id' => $plan->public_id, 'payment_method' => 'paidy'];
        $first = $this->postJson('/api/v2/payments', $input, $headers)->assertCreated()
            ->assertJsonPath('status', 'requires_action')->assertJsonPath('next_action.payload.amount', 1000)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v2/payments', $input, $headers)->assertCreated()->assertJsonPath('id', $first->json('id'));
        foreach ([['amount' => 1], ['order_ref' => 'untrusted'], ['buyer_data' => []], ['card' => null]] as $extra) {
            $this->postJson('/api/v2/payments', [...$input, ...$extra], $headers)->assertStatus(422);
        }
        self::assertSame(1, DB::table('payments')->where('user_id', $user->id)->count());
        Http::assertNothingSent();
    }

    public function test_authorization_captures_with_empty_object_and_grants_mail_once_on_201(): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        Http::fake(function (Request $request) use ($authorized, $closed) {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('2018-04-10', $request->header('Paidy-Version')[0]);
            if ($request->method() === 'POST') {
                self::assertStringEndsWith('/captures', $request->url()); self::assertSame('{}', $request->body());
                return Http::response($closed, 201);
            }
            return Http::response($authorized);
        });
        self::assertSame('1000', app(\App\Domain\Payment\V2\Services\V2PaidyClient::class)->request($authorized['id'])['body']['amount']);
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://api.paidy.com/*' => Http::response($closed)]);
        $this->authorizePaidy($user, $payment, $authorized['id']);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(1, DB::table('payment_receipt_snapshots')->where('payment_id', $payment->id)->count());
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

    public function test_forbidden_followed_by_verified_captured_result_grants_once(): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        Http::fakeSequence()->push($authorized)->push(['code' => 'service.forbidden'], 403)->push($closed);
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $authorized['id'])['status']);
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

    public function test_mismatched_amount_closes_only_verified_uncaptured_authorization(): void
    {
        [$user, $payment] = $this->paidyPayment(); $body = $this->paidyObject($payment); $body['amount'] = 2000;
        Http::fake(function (Request $request) use ($body) {
            if ($request->method() === 'POST') { self::assertStringEndsWith('/close', $request->url()); return Http::response([...$body, 'status' => 'CLOSED']); }
            return Http::response($body);
        });
        self::assertSame('failed', $this->authorizePaidy($user, $payment, $body['id'])['status']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }

    public function test_ownership_provider_request_and_idempotency_boundaries(): void
    {
        [$user, $payment] = $this->paidyPayment(); $service = app(V2PaidyPaymentService::class);
        $this->problem(fn () => $service->authorize($this->paidyUser(), $payment->public_id, ['paidy_payment_id' => 'pay_synthetic'], 'other'), 'PAYMENT_NOT_FOUND', 404);
        $plan = $this->paidyPlan(); $other = app(V2PaymentService::class)->createPayment($user->id, $plan->id, 'synthetic', null, 'other-'.Str::uuid(), 'credit_card');
        $this->problem(fn () => $service->authorize($user, $other->public_id, ['paidy_payment_id' => 'pay_synthetic'], 'other'), 'PAYMENT_PROVIDER_MISMATCH', 409);
        foreach ([[], ['paidy_payment_id' => '../path'], ['paidy_payment_id' => 'pay_synthetic', 'amount' => 1]] as $input) {
            $this->problem(fn () => $service->authorize($user, $payment->public_id, $input, 'invalid'), 'PAYMENT_REQUEST_INVALID', 422);
        }
        Http::fake(['https://api.paidy.com/*' => Http::response([], 500)]);
        $service->authorize($user, $payment->public_id, ['paidy_payment_id' => 'pay_synthetic'], 'same');
        $this->problem(fn () => $service->authorize($user, $payment->public_id, ['paidy_payment_id' => 'pay_different'], 'same'), 'IDEMPOTENCY_KEY_REUSED', 409);
    }

    public function test_hidden_method_does_not_interrupt_existing_reconciliation_or_history(): void
    {
        [$user, $payment] = $this->paidyPayment(); config(['v2_payment.methods_enabled' => 'credit_card']);
        $body = $this->paidyObject($payment, 'CLOSED'); Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $body['id'])['status']);
        self::assertSame('あと払い（ペイディ）', app(V2FincodePaymentService::class)->show($user, $payment->public_id)['method_label']);
        $this->problem(fn () => app(V2FincodePaymentService::class)->resume($user, $payment->public_id), 'UNPAID_PAYMENT_NOT_RESUMABLE', 409);
        self::assertCount(1, app(V2FincodePaymentService::class)->history($user, 'succeeded', null, 20)['data']);
    }

    public function test_http_auth_csrf_sms_and_no_store_are_enforced(): void
    {
        [$user, $payment] = $this->paidyPayment(); $url = '/api/v2/payments/'.$payment->public_id.'/paidy-authorizations';
        Auth::guard('v2_user')->setUser($user);
        $this->postJson($url, ['paidy_payment_id' => 'pay_synthetic'], ['Origin' => 'https://example.test', 'Idempotency-Key' => 'synthetic'])
            ->assertStatus(403);
        $this->getJson('/api/v2/payments/'.$payment->public_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        try { $this->authorizePaidy($user, $payment, 'pay_synthetic'); self::fail('SMS must be required.'); }
        catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $e) { self::assertSame('SMS_VERIFICATION_REQUIRED', $e->errorCode); }
        app('auth')->forgetGuards();
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', str_repeat('a', 64))
            ->postJson($url, ['paidy_payment_id' => 'pay_synthetic'], ['Origin' => 'https://example.test',
                'X-XSRF-TOKEN' => str_repeat('a', 64), 'Idempotency-Key' => 'synthetic'])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_exact_yen_normalization_rejects_rounding_and_overflow(): void
    {
        foreach ([1000, 1000.0, '1000', '1000.00'] as $value) self::assertSame(1000, V2PaidyResponseClassifier::yen($value));
        foreach ([1000.1, '1000.01', '1e3', '-1', true, null, '2147483648', INF] as $value) self::assertNull(V2PaidyResponseClassifier::yen($value));
        $decoded = \App\Domain\Payment\V2\Services\V2PaidyClient::decode('{"amount":1000.0000000000000001,"test":true,"id":"pay_123","captures":[{"amount":1000.00}]}');
        self::assertSame('1000.0000000000000001', $decoded['amount']);
        self::assertNull(V2PaidyResponseClassifier::yen($decoded['amount']));
        self::assertSame(1000, V2PaidyResponseClassifier::yen($decoded['captures'][0]['amount']));
        self::assertTrue($decoded['test']); self::assertSame('pay_123', $decoded['id']);
        $payment = (object) ['public_id' => (string) Str::uuid(), 'amount' => 1000, 'created_at' => '2026-01-01T00:00:00Z'];
        $body = $this->paidyObject($payment, 'CLOSED', 'pay_synthetic');
        $body['captures'][0]['created_at'] = '2026-02-31T00:00:00Z';
        self::assertSame('uncertain', app(V2PaidyResponseClassifier::class)->classify($body, $payment, 'pay_synthetic', true)['state']);
        $body['captures'][0]['created_at'] = now()->utc()->startOfSecond()->format('Y-m-d\TH:i:s\Z');
        $body['refunds'] = [['id' => 'ref_synthetic', 'amount' => '0.000000000000001']];
        self::assertSame('uncertain', app(V2PaidyResponseClassifier::class)->classify($body, $payment, 'pay_synthetic', true)['state']);
    }

    public function test_capture_grant_failure_rolls_back_money_and_recovers_without_second_capture(): void
    {
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        $failGrant = true; $captured = false; $captureCount = 0;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$failGrant): void {
            if ($failGrant && str_contains($query->sql, 'insert into "payment_point_grants"')) throw new \RuntimeException('Synthetic grant failure.');
        });
        Http::fake(function (Request $request) use ($authorized, $closed, &$captured, &$captureCount) {
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

    public function test_second_provider_id_for_same_payment_and_reuse_for_another_payment_never_grant_twice(): void
    {
        [$user, $payment] = $this->paidyPayment(); $first = $this->paidyObject($payment, 'CLOSED');
        Http::fake(['https://api.paidy.com/*' => Http::response($first)]);
        self::assertSame('succeeded', $this->authorizePaidy($user, $payment, $first['id'])['status']);
        $second = $this->paidyObject($payment, 'CLOSED', 'pay_second_payment_id');
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['https://api.paidy.com/*' => Http::response($second)]);
        $this->authorizePaidy($user, $payment, $second['id']);
        self::assertSame($first['id'], DB::table('payments')->where('id', $payment->id)->value('provider_payment_id'));
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        [$other, $otherPayment] = $this->paidyPayment();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['https://api.paidy.com/*' => Http::response($first)]);
        $this->authorizePaidy($other, $otherPayment, $first['id']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $otherPayment->id)->count());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    private function problem(callable $call, string $code, int $status): void
    {
        try { $call(); self::fail('Expected rejection.'); }
        catch (V2FincodeException $e) { self::assertSame($code, $e->errorCode); self::assertSame($status, $e->status); }
    }
}
