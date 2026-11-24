<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class PaidyCheckoutAbandonmentTest extends TestCase
{
    use PaidyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePaidy();
    }

    protected function tearDown(): void
    {
        $this->quiescePaidyFixtureJobs();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::resetPaidyTestDatabase();
        parent::tearDownAfterClass();
    }

    #[DataProvider('checkoutResults')]
    public function test_first_purchase_eligibility_returns_immediately(string $result, string $status): void
    {
        config([
            'v2_payment.first_purchase_limit_scope' => 'any_product',
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        ]);
        $user = $this->paidyUser();
        $user->forceFill(['first_sms_verified_at' => now()->subMinute()])->save();
        $plans = [$this->paidyPlan(), $this->paidyPlan()];
        DB::table('point_purchase_plans')->whereIn('id', array_column($plans, 'id'))
            ->update(['audience_code' => 'first_purchase_users']);
        $headers = $this->browser($user);
        $payment = $this->postJson('/api/v2/payments', [
            'point_product_id' => $plans[0]->public_id, 'payment_method' => 'paidy',
        ], $headers)->assertCreated()->assertJsonPath('status', 'requires_action')->json();
        $url = $this->path($payment['id']);
        $headers['Idempotency-Key'] = 'checkout-abandonment';
        $this->assertOffers($plans, false);
        self::assertSame(['paidy.payment'], DB::table('outbox_messages')->where('aggregate_public_id', $payment['id'])
            ->where('topic', 'payment.paidy')->pluck('event_type')->all());

        $this->postJson($url, ['result' => $result], $headers)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', $status);
        $this->assertOffers($plans, true);
        $this->postJson($url, ['result' => $result], $headers)->assertOk()->assertJsonPath('status', $status);
        $this->postJson($url, ['result' => $result], [...$headers, 'Idempotency-Key' => 'another-abandonment'])
            ->assertOk()->assertJsonPath('status', $status);
        $this->postJson($url, ['result' => $result === 'closed' ? 'rejected' : 'closed'], $headers)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        self::assertSame(['user'], DB::table('payment_status_histories')->where('payment_id', $row->id)
            ->where('to_status', $status)->pluck('transition_source')->all());
        self::assertSame(1, DB::table('payment_progress_events')->where('payment_id', $row->id)
            ->where('type', 'paidy_checkout_result')->where('source', 'server')->count());
        Http::assertNothingSent();
    }

    public static function checkoutResults(): array
    {
        return [['closed', 'canceled'], ['rejected', 'failed']];
    }

    public function test_replay_returns_latest_payment_after_earlier_authorization_prevented_abandonment(): void
    {
        [$user, $payment] = $this->paidyPayment();
        $body = $this->paidyObject($payment, 'CLOSED');
        // A mismatched lookup retains requires_action and leaves the provider ID unset.
        $mismatch = [...$body, 'order' => ['order_ref' => (string) Str::uuid()]];
        Http::fakeSequence()->push($mismatch)->push($body);
        $this->authorizePaidy($user, $payment, $body['id']);
        $headers = $this->browser($user);
        $this->postJson($this->path($payment->public_id), ['result' => 'closed'], $headers)
            ->assertOk()->assertJsonPath('status', 'requires_action');
        $this->authorizePaidy($user, $payment, $body['id']);
        $this->postJson($this->path($payment->public_id), ['result' => 'closed'], $headers)
            ->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson($this->path($payment->public_id), ['result' => 'closed'], [...$headers, 'Idempotency-Key' => 'fresh-key'])
            ->assertOk()->assertJsonPath('status', 'succeeded');
        Http::assertSentCount(2);
    }

    public function test_linked_payment_and_each_durable_authorization_signal_prevent_abandonment(): void
    {
        [$user, $linked] = $this->paidyPayment();
        [$lookupUser, $queued] = $this->paidyPayment();
        [$recordUser, $recorded] = $this->paidyPayment();
        DB::table('payments')->where('id', $linked->id)->update(['provider_payment_id' => 'pay_already_linked']);
        DB::transaction(function () use ($queued, $recorded, $recordUser): void {
            app(V2PaidyReconciliationService::class)->enqueueLookup('pay_already_queued', $queued->public_id);
            $idempotency = app(V2PointIdempotencyService::class);
            $claim = $idempotency->claim('payment.paidy.authorize', 'user', $recordUser->public_id, 'record-only', ['payment_id' => $recorded->public_id]);
            $idempotency->complete($claim->record, 'payment', $recorded->public_id);
        });
        foreach ([[$user, $linked], [$lookupUser, $queued], [$recordUser, $recorded]] as [$owner, $row]) {
            $this->postJson($this->path($row->public_id), ['result' => 'rejected'], $this->browser($owner))
                ->assertOk()->assertJsonPath('status', 'requires_action');
        }
        Http::assertNothingSent();
    }

    #[DataProvider('lateAuthorizations')]
    public function test_late_approval_is_reconciled_after_periodic_lookup_finishes(string $scenario, bool $webhook): void
    {
        [$user, $payment] = $this->paidyPayment();
        $this->postJson($this->path($payment->public_id), ['result' => 'closed'], $this->browser($user))
            ->assertOk()->assertJsonPath('status', 'canceled');
        config(['v2_paidy.worker_enabled' => true]);
        $worker = app(V2PaidyReconciliationService::class);
        $this->travel(2)->minutes();
        self::assertSame(1, $worker->work());
        $this->travel(2)->minutes();
        self::assertSame(0, $worker->work());
        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $payment->public_id, 'event_type' => 'paidy.payment', 'status' => 'delivered', 'attempts' => 1,
        ]);
        Http::assertNothingSent();

        $body = $this->paidyObject($payment, $scenario === 'captured' ? 'CLOSED' : 'AUTHORIZED');
        if ($scenario === 'wrong_order') {
            $body['order']['order_ref'] = (string) Str::uuid();
        }
        Http::fake(function (Request $request) use ($body, $scenario) {
            if ($request->method() === 'POST') {
                self::assertSame('safe', $scenario);
                self::assertStringEndsWith('/close', $request->url());
                return Http::response([...$body, 'status' => 'CLOSED']);
            }
            return Http::response($body);
        });
        if ($webhook) {
            app(V2PaidyWebhookService::class)->receive(['payment_id' => $body['id'], 'status' => 'authorize_success']);
            self::assertSame(1, $worker->work());
        } else {
            self::assertSame('canceled', $this->authorizePaidy($user, $payment, $body['id'])['status']);
        }
        $lookup = DB::table('outbox_messages')->where('event_type', 'paidy.lookup')
            ->where('payload->paidy_payment_id', $body['id'])->first();
        self::assertNotNull($lookup);
        self::assertSame(1, $lookup->attempts);
        self::assertSame($scenario === 'wrong_order' ? 'pending' : 'delivered', $lookup->status);
        if ($scenario === 'safe') {
            $this->assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $payment->public_id, 'event_type' => 'paidy.cancel.claimed']);
        } else {
            $this->assertDatabaseHas('payment_progress_events', [
                'payment_id' => $payment->id, 'detail->reason' => 'reconciliation_failed',
                'detail->match' => $scenario === 'captured' ? 'expired' : 'member_mismatch',
            ]);
        }
        Http::assertSentCount($scenario === 'safe' ? 2 : 1);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'canceled']);
        $this->assertDatabaseMissing('payment_point_grants', ['payment_id' => $payment->id]);
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->sum('paid_balance'));
    }

    public static function lateAuthorizations(): array
    {
        return [['safe', false], ['safe', true], ['wrong_order', false], ['captured', true]];
    }

    public function test_authentication_payment_ownership_provider_body_and_rate_limit(): void
    {
        [$owner, $payment] = $this->paidyPayment();
        $other = $this->paidyUser();
        $nonPaidy = app(V2PaymentService::class)->createPayment($owner->id, $this->paidyPlan()->id,
            'fincode', null, 'other-provider', 'credit_card');
        $input = ['result' => 'closed'];
        $url = $this->path($payment->public_id);
        $this->postJson($url, $input, $this->browser())->assertUnauthorized();
        $this->postJson($url, $input, $this->browser($other))->assertNotFound()->assertJsonPath('code', 'PAYMENT_NOT_FOUND');
        $headers = $this->browser($owner);
        $this->postJson($url, $input, array_diff_key($headers, ['X-XSRF-TOKEN' => true]))->assertForbidden();
        $this->postJson($this->path((string) Str::uuid()), $input, $headers)->assertNotFound()->assertJsonPath('code', 'PAYMENT_NOT_FOUND');
        $this->postJson($this->path($nonPaidy->public_id), $input, $headers)->assertConflict()->assertJsonPath('code', 'PAYMENT_PROVIDER_MISMATCH');
        foreach ([[], ['result' => 'authorized'], ['result' => ['closed']], [...$input, 'extra' => true]] as $invalid) {
            $this->postJson($url, $invalid, $headers)->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_REQUEST_INVALID');
        }
        DB::table('user_phone_numbers')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson($url, $input, $headers)->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        config(['v2_identity.rate_limits.user_mutation' => [1, 60]]);
        $this->postJson($url, $input, $headers)->assertTooManyRequests()->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_action']);
        Http::assertNothingSent();
    }

    private function browser(?User $user = null): array
    {
        if ($user !== null) {
            Auth::guard('v2_user')->setUser($user);
        }
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', str_repeat('a', 64));

        return ['Origin' => 'https://example.test', 'X-XSRF-TOKEN' => str_repeat('a', 64), 'Idempotency-Key' => 'synthetic-key'];
    }

    private function path(string $paymentId): string
    {
        return '/api/v2/payments/'.$paymentId.'/paidy-checkout-abandonments';
    }

    private function assertOffers(array $plans, bool $eligible): void
    {
        $listing = $this->getJson('/api/v2/point-products')->assertOk()
            ->assertJsonPath('first_user_offer.state', $eligible ? 'active' : 'unavailable')->json('data');
        foreach ($plans as $plan) {
            $item = collect($listing)->firstWhere('id', $plan->public_id);
            self::assertNotNull($item);
            self::assertSame($eligible, $item['eligible']);
        }
    }
}
