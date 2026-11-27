<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2PaymentMethodPolicy;
use App\Domain\Payment\V2\Services\V2StripeClient;
use App\Domain\Payment\V2\Services\V2StripePaymentService;
use App\Domain\Payment\V2\Services\V2StripeReconciliationService;
use App\Domain\Payment\V2\Services\V2StripeWorker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;
use Tests\V2\Fixtures\UnivapayFixtures;
use Tests\V2\Fixtures\PaidyFixtures;

final class StripeCheckoutTest extends TestCase
{
    use DatabaseTransactions, UnivapayFixtures, PaidyFixtures;

    private array $session = [];
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureUnivapay();
        config(['v2_payment.credit_card_provider' => 'stripe','v2_stripe.mode' => 'test',
            'v2_stripe.secret_key' => 'rk_test_'.'synthetic', 'v2_stripe.publishable_key' => 'pk_test_'.'synthetic',
            'v2_stripe.webhook_secret' => 'whsec_'.'synthetic']);
    }

    private function created(array $params, string $key): array
    {
        $this->calls[] = [$params, $key];
        $mode = config('v2_stripe.mode');
        $id = 'cs_'.$mode.'_'.substr(hash('sha256', $key), 0, 20);
        return $this->session = ['id' => $id, 'status' => 'open', 'payment_status' => 'unpaid', 'mode' => 'payment',
            'livemode' => $mode === 'live', 'client_reference_id' => $params['client_reference_id'], 'metadata' => $params['metadata'],
            'currency' => 'jpy', 'amount_total' => $params['line_items'][0]['price_data']['unit_amount'],
            'client_secret' => $id.'_secret_synthetic', 'expires_at' => $params['expires_at']];
    }

    public function test_timeout_replays_fixed_parameters_and_cancellation_only_releases_after_verified_expiry(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(function ($params, $key) {
            $session = $this->created($params, $key);
            if (count($this->calls) === 1) throw ApiConnectionException::factory('Synthetic timeout');
            return $session;
        });
        $client->shouldReceive('expire')->twice()->andThrow(ApiConnectionException::factory('Synthetic timeout'));
        $client->shouldReceive('session')->twice()->andReturnUsing(function () {
            static $calls = 0;
            if (++$calls === 1) throw ApiConnectionException::factory('Synthetic timeout');
            return [...$this->session, 'status' => 'expired'];
        });
        $start = app(V2StripePaymentService::class);
        try {
            $start->start($user, $plan->public_id, 'stripe-create');
            self::fail('Expected uncertain start');
        } catch (V2FincodeException $failure) {
            self::assertSame(503, $failure->status);
        }
        $this->travel(60)->seconds();
        $payment = $start->start($user, $plan->public_id, 'stripe-create');
        self::assertSame($this->calls[0], $this->calls[1]);
        self::assertSame('stripe_checkout', $payment['next_action']['type']);
        self::assertTrue($payment['provider_sync']);
        $sync = app(V2StripeReconciliationService::class);
        $pending = $sync->operation($user, $payment['id'], 'cancel', 'same-key', 'leave');
        self::assertTrue($pending['cancellation_pending']);
        self::assertNull($pending['next_action']);
        $done = $sync->operation($user, $payment['id'], 'cancel', 'same-key', 'user');
        self::assertSame('canceled', $done['status']);
        self::assertFalse($done['provider_sync']);
        self::assertSame(1, DB::table('payment_progress_events')->where('type', 'cancel_requested')->where('detail->trigger', 'leave')->count());
    }

    public function test_late_response_after_ten_minutes_cannot_revive_payment_and_new_purchase_can_start(): void
    {
        $user = $this->univapayUser();
        $user->forceFill(['first_sms_verified_at' => now()->subSecond()])->save();
        $planId = (string) \Illuminate\Support\Str::uuid7();
        DB::table('point_purchase_plans')->insert([
            'public_id' => $planId, 'code' => 'first-'.$planId, 'version_no' => 1,
            'name' => 'First purchase', 'audience_code' => 'first_purchase_users',
            'amount' => 1000, 'paid_point_amount' => 1000, 'free_point_amount' => 100,
            'currency' => 'JPY', 'status' => 'published',
        ]);
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(function ($params, $key) {
            $result = $this->created($params, $key);
            if (count($this->calls) === 1) $this->travel(10)->minutes();
            return $result;
        });
        $start = app(V2StripePaymentService::class);
        $old = $start->start($user, $planId, 'old');
        $orphan = $this->session;
        self::assertSame('failed', $old['status']);
        self::assertNull($old['next_action']);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'stripe.expire')->count());
        $new = $start->start($user, $planId, 'new');
        self::assertSame('requires_action', $new['status']);
        self::assertNotSame($old['id'], $new['id']);
        $client->shouldReceive('session')->once()->with($orphan['id'])->andReturn($orphan);
        $client->shouldReceive('expire')->once()->with($orphan['id']);
        app(V2StripeWorker::class)->work(5);
        self::assertSame('delivered', DB::table('outbox_messages')->where('deduplication_key', 'stripe.expire:'.$orphan['id'])->value('status'));
    }

    public function test_amount_and_verified_email_are_checked_before_creating_payment(): void
    {
        $user = $this->univapayUser();
        $this->mock(V2StripeClient::class)->shouldNotReceive('create');
        foreach ([49, 50] as $amount) {
            $plan = $this->univapayPlan($amount);
            if ($amount === 50) $user->forceFill(['email_verified_at' => null])->save();
            self::assertSame($amount === 49 ? 'amount_too_small' : 'email_unverified',
                app(V2PaymentMethodPolicy::class)->methods($plan->public_id, $user)['data'][0]['unavailable_reason']);
            try {
                app(V2StripePaymentService::class)->start($user, $plan->public_id, 'new-'.$amount);
                self::fail('Expected unavailable purchase');
            } catch (V2FincodeException $error) {
                self::assertSame($amount === 49 ? 422 : 403, $error->status);
            }
        }
        self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
    }

    public function test_concurrent_replies_keep_the_adopted_session_and_rate_limited_replays_work(): void
    {
        config(['v2_stripe.start_limit_per_hour' => 1]);
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(function ($params, $key) {
            $result = $this->created($params, $key);
            if (count($this->calls) === 1) {
                $view = DB::table('payments')->where('public_id', $params['client_reference_id'])->sole();
                app(V2StripePaymentService::class)->ensureSession($view);
            }
            return $result;
        });
        $start = app(V2StripePaymentService::class);
        $payment = $start->start($user, $plan->public_id, 'same');
        self::assertSame($payment, $start->start($user, $plan->public_id, 'same'));
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'stripe.expire')->count());
        self::assertSame(1, DB::table('stripe_checkout_sessions')->count());
        try {
            $start->start($user, $plan->public_id, 'other');
            self::fail('Expected start limit');
        } catch (V2FincodeException $error) {
            self::assertSame(429, $error->status);
        }
    }

    public function test_verified_paid_wins_cancel_and_stale_lookup_does_not_restore_input(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($params, $key) => $this->created($params, $key));
        $payment = app(V2StripePaymentService::class)->start($user, $plan->public_id, 'paid');
        $paid = $this->paidSession();
        $client->shouldReceive('expire')->once()->andThrow(ApiConnectionException::factory('Synthetic'));
        $client->shouldReceive('session')->times(3)->andReturn($paid, $this->session, $paid);
        $sync = app(V2StripeReconciliationService::class);
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains(strtolower($query->sql), 'for update')
                && preg_match('/from "(users|payments|stripe_checkout_sessions|stripe_checkout_attempts|payment_provider_events)"/', $query->sql, $table)) {
                $locks[] = $table[1];
            }
        });
        $done = $sync->operation($user, $payment['id'], 'cancel', 'cancel', 'user');
        self::assertSame(['users', 'payments', 'stripe_checkout_sessions', 'stripe_checkout_attempts', 'payment_provider_events'],
            array_values(array_unique($locks)));
        self::assertSame('succeeded', $done['status']);
        self::assertTrue($sync->lookup($this->session['id']));
        self::assertTrue($sync->lookup($this->session['id']));
        self::assertNull(app(V2FincodePaymentService::class)->show($user, $payment['id'])['next_action']);
        self::assertSame('complete', DB::table('stripe_checkout_sessions')->value('session_status'));
        self::assertSame(1, DB::table('payment_point_grants')->count());
        self::assertSame('test', DB::table('payment_commercial_classifications')->value('classification'));
    }

    private function paidSession(): array
    {
        $intent = [
            'id' => 'pi_Synthetic', 'livemode' => false, 'currency' => 'jpy',
            'amount_received' => $this->session['amount_total'], 'status' => 'succeeded',
            'metadata' => $this->session['metadata'],
            'latest_charge' => ['id' => 'ch_Synthetic', 'created' => now()->timestamp,
                'payment_intent' => 'pi_Synthetic', 'payment_method_details' => ['type' => 'card']],
        ];
        return [...$this->session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => $intent];
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['test', 'live'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['live', 'test'])]
    public function test_session_recovery_lookup_and_action_require_the_original_mode(string $original, string $changed): void
    {
        $configure = static function (string $mode): void {
            config(['v2_stripe.mode' => $mode, 'v2_stripe.publishable_key' => 'pk_'.$mode.'_synthetic',
                'v2_stripe.secret_key' => 'rk_'.$mode.'_synthetic']);
        };
        $configure($original);
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(function ($params, $key) use ($configure, $changed) {
            if ($this->calls === []) $configure($changed);
            return $this->created($params, $key);
        });
        $checkout = app(V2StripePaymentService::class);
        // The first response agrees with the changed configuration. A retry in
        // that mode must stop before sending a second create request.
        for ($i = 0; $i < 2; ++$i) {
            try {
                $checkout->start($user, $plan->public_id, 'mode-change');
                self::fail('Expected rejection in the other commercial mode');
            } catch (V2FincodeException $error) {
                self::assertSame(503, $error->status);
            }
        }
        self::assertCount(1, $this->calls);
        self::assertSame(0, DB::table('stripe_checkout_sessions')->count());
        $configure($original);
        $payment = $checkout->start($user, $plan->public_id, 'mode-change');
        self::assertSame('stripe_checkout', $payment['next_action']['type']);
        $configure($changed);
        self::assertNull(app(V2FincodePaymentService::class)->show($user, $payment['id'])['next_action']);
        // A pre-fix row could already match the new key mode. The immutable
        // classification must still protect both the secret and settlement.
        DB::table('stripe_checkout_sessions')->where('session_id', $this->session['id'])
            ->update(['livemode' => $changed === 'live']);
        self::assertNull(app(V2FincodePaymentService::class)->show($user, $payment['id'])['next_action']);
        $paid = $this->paidSession();
        $paid['livemode'] = $paid['payment_intent']['livemode'] = $changed === 'live';
        $client->shouldReceive('session')->once()->andReturn($paid);
        self::assertTrue(app(V2StripeReconciliationService::class)->lookup($this->session['id']));
        self::assertSame('requires_action', DB::table('payments')->where('public_id', $payment['id'])->value('status'));
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_lookup_with_a_different_session_id_records_review_without_a_grant(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        $payment = app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'mismatch');
        $client->shouldReceive('session')->once()->andReturn([...$this->paidSession(), 'id' => 'cs_test_Other']);
        self::assertTrue(app(V2StripeReconciliationService::class)->lookup($this->session['id']));
        $row = DB::table('payments')->where('public_id', $payment['id'])->sole();
        self::assertSame('requires_action', $row->status);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        self::assertSame(1, DB::table('payment_progress_events')->where('payment_id', $row->id)->where('detail->match', 'source_mismatch')->count());
    }

    public function test_verified_late_payment_after_expiry_requires_review_once(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        $payment = app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'late');
        $paid = $this->paidSession();
        $client->shouldReceive('session')->times(4)->andReturn(
            [...$this->session, 'status' => 'expired'], $paid, $paid, $this->session
        );
        $sync = app(V2StripeReconciliationService::class);
        for ($request = 0; $request < 4; ++$request) self::assertTrue($sync->lookup($this->session['id']));
        self::assertSame('expired', DB::table('payments')->where('public_id', $payment['id'])->value('status'));
        self::assertSame('expired', DB::table('stripe_checkout_sessions')->value('session_status'));
        self::assertSame(1, DB::table('payment_progress_events')->where('type', 'paid_after_terminal')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_closed_member_late_payment_reuses_existing_review_without_credit(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'close');
        DB::transaction(function () use ($user) {
            $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked(
                $locked, 'self', 'normal', (string) \Illuminate\Support\Str::uuid7()
            );
        });
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'stripe.expire')->count());
        $client->shouldReceive('session')->twice()->andReturn($this->paidSession());
        $sync = app(V2StripeReconciliationService::class);
        self::assertTrue($sync->lookup($this->session['id']));
        self::assertTrue($sync->lookup($this->session['id']));
        self::assertSame(1, DB::table('account_closure_late_payments')->count());
        self::assertSame(0, DB::table('payment_progress_events')->where('type', 'paid_after_terminal')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_five_xx_advances_attempt_and_foreign_session_is_never_adopted_or_expired(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(function ($params, $key) {
            $result = $this->created($params, $key);
            if (count($this->calls) === 1) throw \Stripe\Exception\UnknownApiErrorException::factory('Synthetic', 500);
            $result['metadata']['oripa-payment-id'] = 'other';
            return $result;
        });
        foreach ([1, 2] as $number) {
            try {
                app(V2StripePaymentService::class)->start($user, $plan->public_id, 'retry');
                self::fail('Expected unresolved session');
            } catch (V2FincodeException $failure) {
                self::assertSame(503, $failure->status);
            }
        }
        self::assertNotSame($this->calls[0][1], $this->calls[1][1]);
        self::assertSame(['failed','pending'], DB::table('stripe_checkout_attempts')->orderBy('attempt_no')->pluck('state')->all());
        self::assertSame(0, DB::table('stripe_checkout_sessions')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'stripe.expire')->count());
    }

    public function test_retry_boundary_keeps_the_snapshot_until_four_minutes_then_rotates(): void
    {
        $this->freezeSecond();
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->times(3)->andReturnUsing(function ($params, $key) {
            $this->created($params, $key);
            if (count($this->calls) < 3) throw ApiConnectionException::factory('Synthetic timeout');
            return $this->session;
        });
        $service = app(V2StripePaymentService::class);
        foreach ([0, 240] as $seconds) {
            $this->travel($seconds)->seconds();
            try {
                $service->start($user, $plan->public_id, 'boundary');
                self::fail('Expected provider timeout');
            } catch (V2FincodeException $error) {
                self::assertSame(503, $error->status);
            }
        }
        self::assertSame($this->calls[0], $this->calls[1]);
        $this->travel(1)->seconds();
        self::assertSame('requires_action', $service->start($user, $plan->public_id, 'boundary')['status']);
        self::assertNotSame($this->calls[1][1], $this->calls[2][1]);
        self::assertSame(['abandoned', 'created'], DB::table('stripe_checkout_attempts')->orderBy('attempt_no')->pluck('state')->all());
    }

    public function test_late_provider_rejection_keeps_the_concurrent_cancellation(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(function ($params) use ($user) {
            $result = app(V2StripeReconciliationService::class)->operation($user, $params['client_reference_id'], 'cancel', 'leave-key', 'leave');
            self::assertSame('canceled', $result['status']);
            throw \Stripe\Exception\InvalidRequestException::factory('Synthetic request rejection', 400);
        });
        $payment = app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'race-cancel');
        self::assertSame('canceled', $payment['status']);
        self::assertNull($payment['next_action']);
    }

    public function test_webhook_validates_raw_signature_and_records_mode_mismatch_without_work(): void
    {
        $event = ['id' => 'evt_Signed', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => true,
            'data' => ['object' => ['id' => 'cs_live_Signed', 'object' => 'checkout.session']]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $at = time();
        $signature = 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$body, config('v2_stripe.webhook_secret'));
        foreach (['invalid' => 400, $signature => 200] as $header => $expected) {
            $response = $this->call('POST', '/webhooks/v2/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header], $body);
            $response->assertStatus($expected);
        }
        $this->call('POST', '/webhooks/v2/stripe', [], [], [], [], str_repeat('x', 524289))->assertStatus(400);
        self::assertSame(1, DB::table('payment_provider_events')->where('event_type', 'stripe.mode_mismatch.checkout.session.completed')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'stripe.lookup')->count());
    }

    public function test_successful_create_rolls_back_local_failure_and_recovers_the_same_session(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->twice()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        DB::unprepared("CREATE FUNCTION pg_temp.reject_session() RETURNS trigger LANGUAGE plpgsql AS
            'BEGIN RAISE EXCEPTION ''synthetic session store failure''; END';
            CREATE TRIGGER fixture_stripe_session_failure BEFORE INSERT ON stripe_checkout_sessions
            FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_session()");
        $service = app(V2StripePaymentService::class);
        try {
            $service->start($user, $plan->public_id, 'rollback');
            self::fail('Expected synthetic session storage failure');
        } catch (\Illuminate\Database\QueryException $failure) {
            self::assertSame('P0001', $failure->getCode());
        }
        self::assertSame('pending', DB::table('stripe_checkout_attempts')->value('state'));
        self::assertSame(0, DB::table('stripe_checkout_sessions')->count());
        DB::statement('DROP TRIGGER fixture_stripe_session_failure ON stripe_checkout_sessions');
        self::assertSame('requires_action', $service->start($user, $plan->public_id, 'rollback')['status']);
        self::assertSame($this->calls[0], $this->calls[1]);
    }

    private function purchase(string $product, string $method, string $key): \Illuminate\Http\JsonResponse
    {
        $request = \Illuminate\Http\Request::create('/api/v2/payments', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
        ], content: json_encode(['point_product_id' => $product, 'payment_method' => $method], JSON_THROW_ON_ERROR));
        return app(\App\Http\Controllers\V2\V2PaymentController::class)->store($request);
    }

    public function test_disabled_legacy_methods_still_reject_completed_purchase_keys(): void
    {
        $this->configurePaidy();
        $user = $this->univapayUser();
        $this->actingAs($user, 'v2_user');
        $plan = $this->univapayPlan();
        config(['v2_payment.methods_enabled' => '']);
        foreach (['fincode' => 'credit_card', 'paidy' => 'paidy', 'techcoin' => 'netridecash'] as $provider => $method) {
            app(\App\Domain\Payment\V2\Services\V2PaymentService::class)
                ->createPayment($user->id, $plan->id, $provider, null, $provider, $method);
            $response = $this->purchase($plan->public_id, $method, $provider);
            self::assertSame(422, $response->status());
            self::assertSame('PAYMENT_METHOD_UNAVAILABLE', $response->getData(true)['code']);
        }
        self::assertSame(3, DB::table('payments')->where('user_id', $user->id)->count());
    }

    public function test_provider_switch_replays_the_original_purchase_and_rejects_key_reuse(): void
    {
        $user = $this->univapayUser();
        $this->actingAs($user, 'v2_user');
        $plan = $this->univapayPlan(100001);
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        $purchase = $this->purchase(...);
        $original = $purchase($plan->public_id, 'credit_card', 'checkout-key');
        self::assertSame(201, $original->status());
        config(['v2_payment.credit_card_provider' => 'univapay']);
        $replay = $purchase($plan->public_id, 'credit_card', 'checkout-key');
        self::assertSame($original->getData(true)['id'], $replay->getData(true)['id']);
        self::assertSame('stripe_checkout', $replay->getData(true)['next_action']['type']);
        foreach ([[$this->univapayPlan()->public_id, 'credit_card'], [$plan->public_id, 'paidy']] as [$product, $method]) {
            $error = $purchase($product, $method, 'checkout-key');
            self::assertSame(409, $error->status());
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $error->getData(true)['code']);
        }
        $otherPlan = $this->univapayPlan();
        $other = $purchase($otherPlan->public_id, 'credit_card', 'univapay-key');
        self::assertSame('univapay_inline', $other->getData(true)['next_action']['type']);
        config(['v2_payment.credit_card_provider' => 'stripe', 'v2_stripe.secret_key' => null]);
        self::assertSame($other->getData(true)['id'], $purchase($otherPlan->public_id, 'credit_card', 'univapay-key')->getData(true)['id']);
    }

    public function test_fifth_failed_attempt_ends_the_payment_and_invalid_ttl_prevents_creation(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->times(5)->andThrow(\Stripe\Exception\UnknownApiErrorException::factory('Synthetic', 500));
        $service = app(V2StripePaymentService::class);
        config(['v2_stripe.checkout_expires_minutes' => 34]);
        try {
            $service->start($user, $plan->public_id, 'invalid');
            self::fail('Expected unavailable checkout');
        } catch (V2FincodeException $error) {
            self::assertSame(503, $error->status);
        }
        self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
        config(['v2_stripe.checkout_expires_minutes' => 35]);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $service->start($user, $plan->public_id, 'five');
                self::fail('Expected provider retry');
            } catch (V2FincodeException $error) {
                self::assertSame(503, $error->status);
            }
        }
        self::assertSame('failed', $service->start($user, $plan->public_id, 'five')['status']);
        self::assertSame(5, DB::table('stripe_checkout_attempts')->count());
    }

    public function test_resume_confirms_an_expired_session_and_preserves_the_slot_on_lookup_failure(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        $payment = app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'resume');
        $sync = app(V2StripeReconciliationService::class);
        self::assertSame('stripe_checkout', $sync->operation($user, $payment['id'], 'resume', 'resume-key')['next_action']['type']);
        $this->travel(36)->minutes();
        $client->shouldReceive('session')->once()->ordered()->andThrow(ApiConnectionException::factory('Synthetic timeout'));
        $pending = $sync->operation($user, $payment['id'], 'resume', 'resume-key');
        self::assertSame('requires_action', $pending['status']);
        self::assertNull($pending['next_action']);
        $client->shouldReceive('session')->once()->ordered()->andReturn([...$this->session, 'status' => 'expired']);
        self::assertSame('expired', $sync->operation($user, $payment['id'], 'resume', 'resume-key')['status']);
    }

    public function test_worker_reconciles_refunds_and_disputes_without_reversing_a_grant(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        $payment = app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'reversals');
        $client->shouldReceive('session')->once()->andReturn($this->paidSession());
        $charge = ['id' => 'ch_Synthetic', 'payment_intent' => 'pi_Synthetic', 'currency' => 'jpy', 'livemode' => false,
            'amount_refunded' => 500, 'refunds' => ['data' => [['id' => 're_Synthetic']]]];
        $dispute = ['id' => 'dp_Synthetic', 'charge' => 'ch_Synthetic', 'payment_intent' => 'pi_Synthetic',
            'currency' => 'jpy', 'livemode' => false, 'amount' => 500, 'reason' => 'duplicate', 'status' => 'needs_response'];
        $intent = [...$this->paidSession()['payment_intent'], 'amount' => $this->session['amount_total']];
        $client->shouldReceive('charge')->twice()->with('ch_Synthetic')->andReturn([...$charge, 'payment_intent' => $intent]);
        $reply = [...$dispute, 'payment_intent' => $intent];
        $closed = [...$reply, 'status' => 'won'];
        $client->shouldReceive('dispute')->times(4)->with('dp_Synthetic')->andReturn($reply, $closed, $reply, $closed);
        $worker = app(V2StripeWorker::class);
        foreach (['charge.refunded', 'charge.dispute.created', 'charge.dispute.closed'] as $index => $type) {
            $event = ['id' => 'evt_Reversal'.$index, 'type' => $type, 'livemode' => false,
                'data' => ['object' => $index === 0 ? $charge : $dispute]];
            $worker->receive($event);
            $worker->receive($event);
            $worker->work(10);
            self::assertSame('pending', DB::table('outbox_messages')
                ->where('deduplication_key', 'stripe.receipt:'.$event['id'])->value('status'));
        }
        self::assertSame(0, DB::table('payment_progress_events')->whereIn('type', ['dispute_opened','dispute_closed','provider_reversed'])->count());
        self::assertTrue(app(V2StripeReconciliationService::class)->lookup($this->session['id']));
        $this->travel(61)->seconds();
        $worker->work(10);
        self::assertSame(3, DB::table('outbox_messages')->where('deduplication_key', 'like', 'stripe.receipt:evt_Reversal%')
            ->where('attempts', 2)->where('status', 'delivered')->count());
        $row = DB::table('payments')->where('public_id', $payment['id'])->sole();
        self::assertSame('succeeded', $row->status);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $row->id)->count());
        $events = DB::table('payment_progress_events')->where('payment_id', $row->id)
            ->whereIn('type', ['provider_reversed', 'dispute_opened', 'dispute_closed'])->orderBy('id')->get();
        self::assertSame(['provider_reversed', 'dispute_opened', 'dispute_closed'], $events->pluck('type')->all());
        self::assertSame(['evt_Reversal0', 'evt_Reversal1', 'evt_Reversal2'],
            $events->map(fn ($event) => json_decode($event->detail, true)['event_id'])->all());
        self::assertSame(['re_Synthetic'], json_decode($events[0]->detail, true)['refund_ids']);
    }

    public function test_unmatched_charge_retains_original_receipt_and_adds_one_review(): void
    {
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('charge')->once()->with('ch_Unknown')->andReturn([
            'id' => 'ch_Unknown', 'payment_intent' => 'pi_Unknown', 'livemode' => false, 'currency' => 'jpy',
        ]);
        $event = ['id' => 'evt_Unknown', 'type' => 'charge.refunded', 'livemode' => false,
            'data' => ['object' => ['id' => 'ch_Unknown', 'payment_intent' => 'pi_Unknown']]];
        $worker = app(V2StripeWorker::class);
        $worker->receive($event);
        $worker->work(10);
        $worker->receive($event);
        $worker->work(10);
        self::assertSame('charge.refunded', DB::table('payment_provider_events')->where('external_event_id', 'receipt:evt_Unknown')->value('event_type'));
        self::assertSame('payment.review.stripe_reference_unmatched', DB::table('payment_provider_events')->where('external_event_id', 'review:evt_Unknown')->value('event_type'));
        self::assertSame('delivered', DB::table('outbox_messages')->where('deduplication_key', 'stripe.receipt:evt_Unknown')->value('status'));
    }

    public function test_reversal_wait_is_bounded_and_member_mismatch_goes_directly_to_review(): void
    {
        $user = $this->univapayUser();
        $client = $this->mock(V2StripeClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(fn ($p, $k) => $this->created($p, $k));
        app(V2StripePaymentService::class)->start($user, $this->univapayPlan()->public_id, 'review-window');
        $intent = [...$this->paidSession()['payment_intent'], 'amount' => $this->session['amount_total']];
        $charge = ['id' => 'ch_Wait', 'payment_intent' => $intent, 'currency' => 'jpy', 'livemode' => false];
        $client->shouldReceive('charge')->with('ch_Wait')->times(15)->andReturn($charge);
        $intent['metadata']['oripa-member'] = (string) \Illuminate\Support\Str::uuid7();
        $client->shouldReceive('charge')->with('ch_Mismatch')->once()
            ->andReturn([...$charge, 'id' => 'ch_Mismatch', 'payment_intent' => $intent]);
        $worker = app(V2StripeWorker::class);
        foreach (['Wait', 'Mismatch'] as $name) {
            $worker->receive(['id' => 'evt_'.$name, 'type' => 'charge.refunded', 'livemode' => false,
                'data' => ['object' => ['id' => 'ch_'.$name, 'payment_intent' => 'pi_Synthetic']]]);
        }
        $worker->work(10);
        $outbox = DB::table('outbox_messages')->where('deduplication_key', 'stripe.receipt:evt_Wait');
        self::assertSame('pending', $outbox->value('status'));
        self::assertSame('delivered', DB::table('outbox_messages')->where('deduplication_key', 'stripe.receipt:evt_Mismatch')->value('status'));
        for ($attempt = 2; $attempt <= 15; $attempt++) {
            $this->travel(61)->seconds();
            $worker->work(10);
        }
        self::assertSame('delivered', $outbox->value('status'));
        self::assertSame(2, DB::table('payment_provider_events')->whereIn('external_event_id', ['review:evt_Wait', 'review:evt_Mismatch'])->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_receipts_deduplicate_and_worker_releases_created_payment(): void
    {
        $user = $this->univapayUser();
        $plan = $this->univapayPlan();
        $this->mock(V2StripeClient::class)->shouldReceive('create')->once()->andThrow(ApiConnectionException::factory('Synthetic'));
        try {
            app(V2StripePaymentService::class)->start($user, $plan->public_id, 'timeout');
            self::fail('Expected timeout');
        } catch (V2FincodeException $error) {
            self::assertSame(503, $error->status);
        }
        $this->travel(10)->minutes();
        $worker = app(V2StripeWorker::class);
        $worker->work(1);
        self::assertSame('failed', DB::table('payments')->where('user_id', $user->id)->value('status'));
        $event = ['id' => 'evt_Synthetic', 'type' => 'checkout.session.expired', 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_test_Synthetic', 'email' => 'discard@example.test']]];
        $worker->receive($event);
        $worker->receive($event);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'stripe.lookup')->count());
        $row = DB::table('payment_provider_events')->where('external_event_id', 'receipt:evt_Synthetic')->sole();
        self::assertSame(['event_type' => 'checkout.session.expired', 'event_id' => 'evt_Synthetic', 'session_id' => 'cs_test_Synthetic'],
            json_decode(decrypt($row->payload_ciphertext, false), true));
    }
}
