<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2PaymentMethodPolicy;
use App\Domain\Payment\V2\Services\V2PaymentProgressService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentCommonFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        // Match existing mail tests: defer afterCommit delivery until explicitly exercised.
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_unimplemented_providers_are_ordered_and_fail_closed_without_creating_payments(): void
    {
        config(['v2_payment.methods_enabled' => 'credit_card,netridecash,paidy', 'v2_payment.credit_card_provider' => 'univapay']);
        $policy = app(V2PaymentMethodPolicy::class);
        $plan = $this->plan(1000);
        $methods = $policy->methods($plan->public_id)['data'];
        self::assertSame(['credit_card','netridecash','paidy'], array_column($methods, 'method'));
        self::assertSame([false,false,false], array_column($methods, 'available'));
        self::assertSame(['provider_unavailable','provider_unavailable','provider_unavailable'], array_column($methods, 'unavailable_reason'));
        $count = DB::table('payments')->count();
        foreach (['credit_card','netridecash','paidy'] as $method) {
            $this->problem(fn () => $policy->assertNewPayment($method, $plan->public_id, null, false), 'PAYMENT_PROVIDER_UNAVAILABLE', 503);
        }
        foreach (['paypay','konbini','virtual_account'] as $method) {
            $this->problem(fn () => $policy->assertNewPayment($method, $plan->public_id, null, false), 'PAYMENT_METHOD_UNAVAILABLE', 422);
        }
        self::assertSame($count, DB::table('payments')->count());
    }

    public function test_univapay_boundaries_hide_only_over_limit_and_use_server_price(): void
    {
        config(['v2_payment.methods_enabled' => 'credit_card,netridecash,paidy', 'v2_payment.credit_card_provider' => 'univapay']);
        $policy = app(V2PaymentMethodPolicy::class);
        foreach ([99999, 100000, 100001] as $amount) {
            $plan = $this->plan($amount);
            self::assertSame($amount <= 100000, in_array('credit_card', array_column($policy->methods($plan->public_id)['data'], 'method'), true));
            $this->problem(fn () => $policy->assertNewPayment('credit_card', $plan->public_id, null, false),
                $amount <= 100000 ? 'PAYMENT_PROVIDER_UNAVAILABLE' : 'PAYMENT_AMOUNT_TOO_LARGE', $amount <= 100000 ? 503 : 422);
        }
        config(['v2_payment.credit_card_provider' => 'fincode']);
        self::assertSame('credit_card', $policy->methods($plan->public_id)['data'][0]['method']);
        $this->problem(fn () => $policy->assertNewPayment('credit_card', $plan->public_id, null, false), 'PAYMENT_PROVIDER_UNAVAILABLE', 503);
        $large = $this->plan(1000000);
        self::assertSame('amount_too_large', $policy->methods($large->public_id)['data'][1]['unavailable_reason']);
    }

    public function test_invalid_configuration_and_card_input_are_rejected(): void
    {
        $policy = app(V2PaymentMethodPolicy::class);
        self::assertSame(['credit_card','paypay','konbini','virtual_account'], $policy->enabled());
        foreach (['credit_card,unknown', 'credit_card,credit_card', ''] as $setting) {
            config(['v2_payment.methods_enabled' => $setting]);
            self::assertSame([], $policy->enabled());
        }
        config(['v2_payment.methods_enabled' => 'credit_card', 'v2_payment.credit_card_provider' => 'univapay']);
        $plan = $this->plan(1000);
        $this->problem(fn () => $policy->assertNewPayment('credit_card', $plan->public_id, null, true), 'PAYMENT_REQUEST_INVALID', 422);
        config(['v2_payment.credit_card_provider' => 'fincode', 'v2_payment.saved_cards_enabled' => false]);
        $this->problem(fn () => $policy->assertNewPayment('credit_card', $plan->public_id, ['source'=>'saved'], true), 'PAYMENT_SAVED_CARDS_DISABLED', 409);
    }

    public function test_client_events_are_owned_bounded_and_cannot_change_payment_or_store_pii(): void
    {
        [$user, $payment] = $this->payment();
        $service = app(V2PaymentProgressService::class);
        for ($i=0; $i<20; $i++) {
            $service->client($user, $payment->public_id, ['type'=>'redirect_started']);
        }
        self::assertSame(3, DB::table('payment_progress_events')->where('payment_id', $payment->id)->where('source', 'client')->count());
        self::assertSame('created', DB::table('payments')->where('id', $payment->id)->value('status'));
        $other = $this->user();
        $this->problem(fn () => $service->client($other, $payment->public_id, ['type'=>'redirect_started']), 'PAYMENT_NOT_FOUND', 404);
        foreach ([['type'=>'succeeded'], ['type'=>'returned','detail'=>['email'=>'synthetic@example.test']], ['type'=>'inline_result','detail'=>['result'=>'private-value']]] as $input) {
            $this->problem(fn () => $service->client($user, $payment->public_id, $input), 'PAYMENT_CLIENT_EVENT_INVALID', 422);
        }
        self::assertStringNotContainsString('@', json_encode($service->timeline((int) $payment->id)));
    }

    public function test_test_payment_classification_is_not_a_permanent_fincode_provider_exclusion(): void
    {
        config(['v2_fincode.base_url'=>'https://api.test.fincode.jp']);
        [, $test] = $this->payment('fincode');
        self::assertFalse((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS eligible', [$test->id])->eligible);
        config(['v2_fincode.base_url'=>'https://api.fincode.jp']);
        [, $live] = $this->payment('fincode');
        self::assertTrue((bool) DB::selectOne('SELECT v2_payment_commercial(?) AS eligible', [$live->id])->eligible);
        self::assertSame(1, DB::table('payments')->where('id', $test->id)->count());
    }

    public function test_success_receipt_is_frozen_and_new_provider_raw_body_is_not_retained(): void
    {
        [$user, $payment] = $this->payment('techcoin', 'netridecash');
        $service = app(V2PaymentService::class);
        $event = $service->recordVerifiedProviderEvent('techcoin', 'synthetic-event', 'payment.succeeded',
            '{"email":"synthetic@example.test","secret":"must-not-retain"}', ['x-private'=>'private-value'], (int) $payment->id, providerOccurredAt: now());
        $service->confirmSucceeded((int) $event->id);
        $before = DB::table('payment_receipt_snapshots')->where('payment_id', $payment->id)->value('snapshot');
        $service->confirmSucceeded((int) $event->id);
        self::assertSame($before, DB::table('payment_receipt_snapshots')->where('payment_id', $payment->id)->value('snapshot'));
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        $body = \Illuminate\Support\Facades\Crypt::decryptString($event->payload_ciphertext);
        self::assertStringNotContainsString('synthetic@example.test', $body);
        self::assertStringNotContainsString('must-not-retain', $body);
        self::assertSame('{}', $event->headers_redacted);
        self::assertSame('電子マネー（NetRideCash）', app(V2FincodePaymentService::class)->show($user, $payment->public_id)['method_label']);
        self::assertCount(1, app(V2FincodePaymentService::class)->history($user, 'succeeded', null, 20)['data']);
    }

    private function problem(callable $call, string $code, int $status): void
    {
        try { $call(); self::fail('Expected payment rejection.'); }
        catch (V2FincodeException $exception) { self::assertSame($code, $exception->errorCode); self::assertSame($status, $exception->status); }
    }

    public function test_retention_guards_allow_only_expired_purge_and_classifications_are_immutable(): void
    {
        [, $payment] = $this->payment('fincode');
        foreach ([
            fn () => DB::table('payment_progress_events')->where('payment_id', $payment->id)->update(['detail'=>'{}']),
            fn () => DB::table('payment_progress_events')->where('payment_id', $payment->id)->delete(),
            fn () => DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->update(['classification'=>'commercial']),
        ] as $mutation) {
            try { DB::transaction($mutation); self::fail('Immutable record must reject mutation.'); }
            catch (\Illuminate\Database\QueryException $e) { self::assertSame('P0001', $e->getCode()); }
        }
        DB::table('payment_progress_events')->insert([
            'payment_id'=>$payment->id, 'type'=>'returned', 'source'=>'client', 'detail'=>'{}',
            'recorded_at'=>now()->subDays(367), 'retain_until'=>now()->subDays(2),
        ]);
        self::assertSame(1, app(V2PaymentProgressService::class)->purge());
        self::assertSame(1, DB::table('payment_progress_events')->where('payment_id', $payment->id)->count());
    }

    public function test_payment_mail_failure_requires_review_and_never_retries_or_reverses_settlement(): void
    {
        [, $payment] = $this->payment('techcoin', 'netridecash');
        $service = app(V2PaymentService::class);
        $event = $service->recordVerifiedProviderEvent('techcoin', 'mail-failure', 'payment.succeeded', '{}', [], (int) $payment->id, providerOccurredAt: now());
        $service->confirmSucceeded((int) $event->id);
        $delivery = DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->sole();
        $resolver = new \ReflectionMethod(\App\Domain\Mail\Services\V2TemplateMailDeliveryService::class, 'paymentValues');
        [$recipient, $values] = $resolver->invoke(app(\App\Domain\Mail\Services\V2TemplateMailDeliveryService::class), $payment->public_id);
        self::assertNotFalse(filter_var($recipient, FILTER_VALIDATE_EMAIL));
        $template = DB::table('mail_templates')->where('template_key', 'coin_purchase_completed')->sole();
        self::assertStringContainsString('購入後のコイン残高：1,000', app(\App\Domain\Mail\Services\V2TemplateVariableRenderer::class)->html($template->body_html, $values));
        \Illuminate\Support\Facades\Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('synthetic ambiguous transport'));
        $mail = app(\App\Domain\Mail\Services\V2TemplateMailDeliveryService::class);
        $mail->deliver($delivery->public_id);
        $mail->deliver($delivery->public_id);
        $service->confirmSucceeded((int) $event->id);
        $this->assertDatabaseHas('mail_deliveries', ['id'=>$delivery->id, 'status'=>'failed', 'attempts'=>1, 'failure_code'=>'delivery_requires_review', 'sent_at'=>null]);
        $this->assertDatabaseHas('payments', ['id'=>$payment->id, 'status'=>'succeeded']);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
    }

    public function test_receipt_sent_once_uses_settlement_balance_after_a_later_purchase(): void
    {
        [$user, $payment] = $this->payment('techcoin', 'netridecash');
        $service = app(V2PaymentService::class);
        $event = $service->recordVerifiedProviderEvent('techcoin', 'receipt-first', 'payment.succeeded', '{}', [], (int) $payment->id, providerOccurredAt: now());
        $service->confirmSucceeded((int) $event->id);
        $plan = $this->plan(2000);
        $second = $service->createPayment((int) $user->id, (int) $plan->id, 'techcoin', 'second-purchase', 'second-order', 'netridecash');
        $secondEvent = $service->recordVerifiedProviderEvent('techcoin', 'receipt-second', 'payment.succeeded', '{}', [], (int) $second->id, providerOccurredAt: now());
        $service->confirmSucceeded((int) $secondEvent->id);
        self::assertSame(3000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        \Illuminate\Support\Facades\Mail::shouldReceive('html')->once()->withArgs(function (string $body, callable $callback): bool {
            self::assertStringContainsString('購入後のコイン残高：1,000', $body);
            self::assertStringContainsString('有償コインの有効期限：なし', $body);
            self::assertStringNotContainsString('商品ボーナス', $body);
            self::assertStringNotContainsString('還元', $body);
            return true;
        });
        $delivery = DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->sole();
        $mail = app(\App\Domain\Mail\Services\V2TemplateMailDeliveryService::class);
        $mail->deliver($delivery->public_id);
        $mail->deliver($delivery->public_id);
        $this->assertDatabaseHas('mail_deliveries', ['id'=>$delivery->id, 'status'=>'sent', 'attempts'=>1]);
    }

    public function test_disabled_cards_are_guarded_in_every_controller_operation_after_authentication(): void
    {
        config(['v2_payment.saved_cards_enabled'=>false]);
        $this->actingAs($this->user(), 'v2_user');
        $controller = app(\App\Http\Controllers\V2\V2PaymentController::class);
        foreach (['cards','cardUiBootstrap','reserveCard','startCardRegistration','showCardRegistration','reconcileCardRegistration','cancelCardRegistration','completeCard','deleteCard'] as $method) {
            $request = \Illuminate\Http\Request::create('/synthetic', 'POST', ['card_token'=>'synthetic', 'provider_card_id'=>'synthetic']);
            $response = $controller->$method($request, (string) Str::uuid7());
            self::assertSame(409, $response->status(), $method);
            self::assertSame('PAYMENT_SAVED_CARDS_DISABLED', $response->getData(true)['code']);
        }
    }

    private function user(): User
    {
        return User::query()->create(['public_id'=>(string) Str::uuid7(), 'email_display'=>'synthetic-'.Str::uuid().'@example.test',
            'email_normalized'=>'synthetic-'.Str::uuid().'@example.test', 'email_verified_at'=>now(),
            'password_hash'=>password_hash('SyntheticPassword123', PASSWORD_ARGON2ID), 'state'=>V2UserState::Active, 'display_name'=>'Synthetic']);
    }

    private function plan(int $amount): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId(['public_id'=>(string) Str::uuid7(), 'code'=>'pay1-'.Str::uuid(),
            'version_no'=>1, 'name'=>'Synthetic plan', 'amount'=>$amount, 'paid_point_amount'=>$amount,
            'free_point_amount'=>0, 'currency'=>'JPY', 'status'=>'published']);
        return DB::table('point_purchase_plans')->where('id', $id)->first();
    }

    private function payment(string $provider = 'synthetic', string $method = 'credit_card'): array
    {
        $user = $this->user();
        $plan = $this->plan(1000);
        return [$user, app(V2PaymentService::class)->createPayment((int) $user->id, (int) $plan->id, $provider, 'pay1-'.Str::uuid(), 'pay1-'.Str::uuid(), $method)];
    }
}
