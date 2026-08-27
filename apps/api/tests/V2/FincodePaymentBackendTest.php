<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Services\V2AdminPaymentReadService;
use App\Domain\Payment\V2\Services\V2FincodeCardService;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2FincodeReconciliationService;
use App\Domain\Payment\V2\Services\V2FincodeReturnUrl;
use App\Domain\Payment\V2\Services\V2FincodeWebhookService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Http\Controllers\V2\V2PaymentController;
use App\Models\V2\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FincodePaymentBackendTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_fincode.enabled' => true,
            'v2_fincode.base_url' => 'https://api.test.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_test_secret-not-production',
            'v2_fincode.public_api_key' => 'p_test_public-key',
            'v2_fincode.webhook_signature' => 'test-webhook-signature',
            'v2_fincode.platform_origin' => 'https://api.luxe-pack.biz',
            'v2_fincode.storefront_origin' => 'https://luxe-pack.biz',
            'v2_fincode.admin_origin' => 'https://admin.luxe-pack.biz',
        ]);
    }

    public function test_four_payment_methods_map_to_fincode_and_duplicate_start_is_idempotent(): void
    {
        $this->fakeFincode();
        $user = $this->user('mapping');
        $service = app(V2FincodePaymentService::class);
        $methods = ['credit_card', 'paypay', 'konbini', 'virtual_account'];
        $responses = [];
        foreach ($methods as $method) {
            $plan = $this->plan();
            $responses[$method] = $service->start(
                $user,
                $plan->public_id,
                $method,
                'start-'.$method,
                $method === 'credit_card' ? ['source' => 'new', 'save' => false] : null
            );
            self::assertSame($plan->public_id, $responses[$method]['point_product_id']);
        }
        self::assertSame('fincode_card_component', $responses['credit_card']['next_action']['type']);
        self::assertSame('p_test_public-key', $responses['credit_card']['next_action']['public_api_key']);
        self::assertFalse($responses['credit_card']['next_action']['is_live_mode']);
        self::assertSame('redirect', $responses['paypay']['next_action']['type']);
        self::assertSame('redirect', $responses['konbini']['next_action']['type']);
        self::assertSame('redirect', $responses['virtual_account']['next_action']['type']);
        self::assertSame(3, (int) config('v2_fincode.konbini_payment_term_days'));
        self::assertSame(3, (int) config('v2_fincode.virtual_account_payment_term_days'));

        $plan = $this->plan();
        $first = $service->start($user, $plan->public_id, 'paypay', 'same-start');
        $sent = Http::recorded()->count();
        $replay = $service->start($user, $plan->public_id, 'paypay', 'same-start');
        self::assertSame($first['id'], $replay['id']);
        self::assertSame($sent, Http::recorded()->count());
        self::assertSame(5, DB::table('fincode_payment_attempts')->count());
    }

    public function test_card_ui_bootstrap_is_authenticated_read_only_and_exposes_only_public_environment(): void
    {
        Http::fake();
        $user = $this->user('card-ui-bootstrap');
        $tables = [
            'payments',
            'fincode_payment_attempts',
            'fincode_card_registration_intents',
            'fincode_cards',
            'payment_point_grants',
            'point_operations',
            'point_ledger_entries',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()]
        )->all();

        Auth::guard('v2_user')->setUser($user);
        $response = $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
            ->assertOk()
            ->assertExactJson([
                'provider' => 'fincode',
                'public_api_key' => 'p_test_public-key',
                'is_live_mode' => false,
            ])
            ->assertHeader('Vary', 'Cookie');
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($before, collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()]
        )->all());
        self::assertCount(0, Http::recorded());

        $serialized = $response->getContent();
        self::assertIsString($serialized);
        self::assertStringNotContainsString('m_test_secret-not-production', $serialized);
        self::assertStringNotContainsString('test-webhook-signature', $serialized);
        foreach (['secret', 'token', 'credential', 'customer_id', 'card_id', 'user_id'] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $serialized);
        }

        Auth::forgetGuards();
        $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_card_ui_bootstrap_fails_closed_for_unavailable_or_inconsistent_configuration(): void
    {
        Http::fake();
        Auth::guard('v2_user')->setUser($this->user('card-ui-bootstrap-invalid'));
        $valid = [
            'v2_fincode.enabled' => true,
            'v2_fincode.base_url' => 'https://api.test.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_test_secret-not-production',
            'v2_fincode.public_api_key' => 'p_test_public-key',
            'v2_fincode.webhook_signature' => 'webhook-signature-fixture',
            'v2_fincode.timeout_seconds' => 10,
        ];
        $cases = [
            [['v2_fincode.enabled' => false], 'FINCODE_ACTIVATION_DEFERRED'],
            [['v2_fincode.public_api_key' => null], 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE'],
            [['v2_fincode.public_api_key' => 'p_prod_public-key'], 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE'],
            [['v2_fincode.secret_api_key' => null], 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE'],
            [['v2_fincode.webhook_signature' => null], 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE'],
            [['v2_fincode.base_url' => 'https://invalid.example.test'], 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE'],
        ];

        foreach ($cases as [$overrides, $code]) {
            config([...$valid, ...$overrides]);
            $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
                ->assertStatus(503)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', $code)
                ->assertJsonPath('retryable', false);
        }

        config([...$valid, 'v2_fincode.public_api_key' => 'p_prod_public-key']);
        try {
            app(V2FincodePaymentService::class)->start(
                $this->user('card-ui-start-invalid'),
                $this->plan()->public_id,
                'credit_card',
                'card-ui-start-invalid',
                ['source' => 'new', 'save' => false]
            );
            self::fail('startPayment must fail before mutation when the provider environment is inconsistent.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE', $exception->errorCode);
        }

        self::assertCount(0, Http::recorded());
        self::assertSame(0, DB::table('payments')->count());
        self::assertSame(0, DB::table('fincode_payment_attempts')->count());
        self::assertSame(0, DB::table('fincode_card_registration_intents')->count());
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_platform_generates_canonical_return_urls_for_all_payment_methods(): void
    {
        $this->fakeFincode();
        $user = $this->user('return-url');
        $plan = $this->plan();
        $service = app(V2FincodePaymentService::class);

        $card = $service->start(
            $user,
            $plan->public_id,
            'credit_card',
            'return-url-card',
            ['source' => 'new', 'save' => false]
        );
        self::assertSame(
            'https://api.luxe-pack.biz/api/v2/payment-returns/fincode/normal?pid='.$card['id'],
            $card['next_action']['return_url']
        );
        self::assertSame(
            'https://api.luxe-pack.biz/api/v2/payment-returns/fincode/failure?pid='.$card['id'],
            $card['next_action']['failure_url']
        );

        foreach (['paypay', 'konbini', 'virtual_account'] as $method) {
            $payment = $service->start($user, $plan->public_id, $method, 'return-url-'.$method);
            Http::assertSent(function (Request $request) use ($payment, $plan): bool {
                if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v1/sessions')) {
                    return false;
                }
                $data = $request->data();

                return ($data['success_url'] ?? null)
                        === 'https://api.luxe-pack.biz/api/v2/payment-returns/fincode/normal?pid='.$payment['id']
                    && ($data['cancel_url'] ?? null)
                        === 'https://api.luxe-pack.biz/api/v2/payment-returns/fincode/failure?pid='.$payment['id'];
            });
        }
    }

    public function test_fincode_origins_fail_closed_when_missing_invalid_or_admin_scoped(): void
    {
        foreach ([
            ['v2_fincode.platform_origin', null, 'provider'],
            ['v2_fincode.platform_origin', 'https://admin.luxe-pack.biz', 'provider'],
            ['v2_fincode.platform_origin', 'https://user@example.test', 'provider'],
            ['v2_fincode.platform_origin', 'https://api.example.test/path', 'provider'],
            ['v2_fincode.platform_origin', 'https://api.example.test?query=1', 'provider'],
            ['v2_fincode.storefront_origin', null, 'storefront'],
            ['v2_fincode.storefront_origin', 'https://admin.luxe-pack.biz', 'storefront'],
            ['v2_fincode.storefront_origin', 'https://storefront.example.test/#fragment', 'storefront'],
        ] as [$key, $value, $target]) {
            config([$key => $value]);
            try {
                $returns = app(V2FincodeReturnUrl::class);
                $target === 'provider'
                    ? $returns->providerNormal((string) Str::uuid7())
                    : $returns->storefrontPoints();
                self::fail('Invalid or Admin-scoped fincode origins must fail closed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('FINCODE_RETURN_URL_INVALID', $exception->errorCode);
            }
            config([
                'v2_fincode.platform_origin' => 'https://api.luxe-pack.biz',
                'v2_fincode.storefront_origin' => 'https://luxe-pack.biz',
            ]);
        }

        self::assertSame(0, DB::table('payments')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_fincode_post_returns_are_normalized_to_safe_storefront_gets_without_mutation(): void
    {
        $this->fakeFincode();
        $user = $this->user('return-handler');
        $plan = $this->plan();
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $plan->public_id,
            'paypay',
            'return-handler'
        );
        $before = DB::table('payments')->where('public_id', $payment['id'])->value('status');

        $normalReturn = $this->post('/api/v2/payment-returns/fincode/normal?pid='.$payment['id'], [
            'status' => 'CAPTURED',
            'access_id' => 'must-not-be-forwarded',
        ]);
        $normalReturn->assertStatus(303)
            ->assertHeader('Location', 'https://luxe-pack.biz/points/purchase/thanks?pid='.$payment['id'])
            ->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $normalReturn->headers->get('Cache-Control'));

        $this->post('/api/v2/payment-returns/fincode/failure?pid='.$payment['id'], [
            'redirect_url' => 'https://evil.example.test',
        ])->assertStatus(303)
            ->assertHeader(
                'Location',
                'https://luxe-pack.biz/points/purchase/'.$plan->public_id.'?pid='.$payment['id']
            );

        $this->post('/api/v2/payment-returns/fincode/normal?pid=malformed')
            ->assertStatus(303)
            ->assertHeader('Location', 'https://luxe-pack.biz/points');
        $this->post('/api/v2/payment-returns/fincode/normal?pid='.(string) Str::uuid7())
            ->assertStatus(303)
            ->assertHeader('Location', 'https://luxe-pack.biz/points');
        $this->post('/api/v2/payment-returns/fincode/failure')
            ->assertStatus(303)
            ->assertHeader('Location', 'https://luxe-pack.biz/points');
        $this->post('/api/v2/payment-returns/fincode/failure?pid='.(string) Str::uuid7())
            ->assertStatus(303)
            ->assertHeader('Location', 'https://luxe-pack.biz/points');

        self::assertSame($before, DB::table('payments')->where('public_id', $payment['id'])->value('status'));
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_payment_creation_rejects_storefront_return_overrides(): void
    {
        foreach ([
            'return_url',
            'success_url',
            'failure_url',
            'cancel_url',
            'pid',
            'payment_id',
            'product_id',
            'production_id',
        ] as $field) {
            $request = \Illuminate\Http\Request::create('/api/v2/payments', 'POST', [
                'point_product_id' => (string) Str::uuid7(),
                'payment_method' => 'paypay',
                $field => $field === 'pid' ? (string) Str::uuid7() : 'https://evil.example.test',
            ]);
            $response = app(V2PaymentController::class)->store($request);

            self::assertSame(422, $response->getStatusCode());
            self::assertSame('PAYMENT_RETURN_OVERRIDE_FORBIDDEN', $response->getData(true)['code']);
        }
        self::assertSame(0, DB::table('payments')->count());
    }

    public function test_get_payment_has_common_ownership_boundary_and_canonical_polling_states(): void
    {
        $this->fakeFincode();
        $owner = $this->user('payment-owner');
        $other = $this->user('payment-other');
        $plan = $this->plan();
        $payment = app(V2FincodePaymentService::class)->start(
            $owner,
            $plan->public_id,
            'paypay',
            'payment-polling'
        );
        $service = app(V2FincodePaymentService::class);

        foreach (['created', 'requires_action', 'processing', 'succeeded', 'failed', 'canceled', 'expired'] as $status) {
            DB::table('payments')->where('public_id', $payment['id'])->update(['status' => $status]);
            $read = $service->show($owner, $payment['id']);
            self::assertSame($status, $read['status']);
            self::assertSame($plan->public_id, $read['point_product_id']);
            self::assertSame([
                'paid_points' => 1000,
                'bonus_points' => 100,
                'limited_bonus_points' => 0,
                'total_points' => 1100,
            ], $read['grant']);
        }

        foreach ([(string) Str::uuid7(), 'malformed'] as $unknown) {
            try {
                $service->show($owner, $unknown);
                self::fail('Unknown payment must not be disclosed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('PAYMENT_NOT_FOUND', $exception->errorCode);
                self::assertSame(404, $exception->status);
            }
        }
        try {
            $service->show($other, $payment['id']);
            self::fail('Other-user payment must not be disclosed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('PAYMENT_NOT_FOUND', $exception->errorCode);
            self::assertSame(404, $exception->status);
        }
    }

    public function test_unpaid_resume_reuses_existing_redirect_without_new_payment_or_provider_session(): void
    {
        $this->fakeFincode();
        $owner = $this->user('resume-owner');
        $other = $this->user('resume-other');
        $plan = $this->plan();
        $service = app(V2FincodePaymentService::class);

        foreach (['konbini', 'virtual_account'] as $method) {
            $payment = $service->start($owner, $plan->public_id, $method, 'resume-'.$method);
            DB::table('payments')->where('public_id', $payment['id'])->update([
                'status' => 'processing',
                'provider_status' => 'AWAITING_CUSTOMER_PAYMENT',
                'expires_at' => now()->addDay(),
            ]);
            $expectedUrl = $payment['next_action']['url'];
            self::assertSame('processing', $service->show($owner, $payment['id'])['status']);
            self::assertNull($service->show($owner, $payment['id'])['next_action']);
            $paymentCount = DB::table('payments')->count();
            $providerCallCount = Http::recorded()->count();

            $resumed = $service->resume($owner, $payment['id']);

            self::assertSame($expectedUrl, $resumed['next_action']['url']);
            self::assertSame($paymentCount, DB::table('payments')->count());
            self::assertSame($providerCallCount, Http::recorded()->count());
            try {
                $service->resume($other, $payment['id']);
                self::fail('Other-user unpaid payment must not be resumable.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }
        }

        foreach (['credit_card', 'paypay'] as $method) {
            $payment = $service->start(
                $owner,
                $plan->public_id,
                $method,
                'resume-unsupported-'.$method,
                $method === 'credit_card' ? ['source' => 'new', 'save' => false] : null
            );
            try {
                $service->resume($owner, $payment['id']);
                self::fail('Card and PayPay must not use unpaid resume.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }
        }

        $terminal = $service->start($owner, $plan->public_id, 'virtual_account', 'resume-terminal');
        foreach (['succeeded', 'expired', 'failed', 'canceled'] as $status) {
            DB::table('payments')->where('public_id', $terminal['id'])->update([
                'status' => $status,
                'provider_status' => strtoupper($status),
                'expires_at' => now()->addDay(),
            ]);
            try {
                $service->resume($owner, $terminal['id']);
                self::fail('Terminal payment must not be resumable.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }
        }
    }

    public function test_browser_read_does_not_grant_and_duplicate_webhook_grants_coin_and_mail_once(): void
    {
        $this->fakeFincode('CAPTURED');
        $user = $this->user('webhook-once');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'paypay',
            'webhook-once'
        );
        app(V2FincodePaymentService::class)->show($user, $payment['id']);
        self::assertSame(0, DB::table('payment_point_grants')->count());

        $raw = json_encode([
            'event' => 'payments.paypay.complete',
            'order_id' => DB::table('payments')->where('public_id', $payment['id'])->value('provider_payment_id'),
            'pay_type' => 'Paypay',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        $webhooks = app(V2FincodeWebhookService::class);
        self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);
        self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);
        $paymentRow = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        self::assertSame('succeeded', $paymentRow->status);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $paymentRow->id)->count());
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'coin.purchase.completed:'.$paymentRow->public_id)->count());

        $this->fakeFincode('AWAITING_CUSTOMER_PAYMENT');
        $delayed = json_encode([
            'event' => 'payments.paypay.exec',
            'order_id' => $paymentRow->provider_payment_id,
            'pay_type' => 'Paypay',
            'status' => 'AWAITING_CUSTOMER_PAYMENT',
            'process_date' => '2026/08/24 11:59:00.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('ignored', $webhooks->process($delayed, 'test-webhook-signature')['status']);
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
    }

    public function test_payment_grant_breakdown_uses_success_snapshots_after_product_campaign_and_clock_changes(): void
    {
        $this->fakeFincode('CAPTURED', '10000');
        $user = $this->user('grant-history');
        $plan = $this->plan(10000, 10000, 1000);
        $campaignId = DB::table('point_purchase_plan_limited_bonus_campaigns')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'point_purchase_plan_id' => $plan->id,
            'is_enabled' => true,
            'starts_at' => '2026-08-24 00:00:00+00',
            'ends_at' => '2026-08-25 00:00:00+00',
            'bonus_point_amount' => 2000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $plan->public_id,
            'paypay',
            'grant-history'
        );
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $raw = json_encode([
            'event' => 'payments.paypay.complete',
            'order_id' => $row->provider_payment_id,
            'pay_type' => 'Paypay',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        app(V2FincodeWebhookService::class)->process($raw, 'test-webhook-signature');

        DB::table('point_purchase_plans')->where('id', $plan->id)->update([
            'name' => 'Changed Product',
        ]);
        DB::table('point_purchase_plan_limited_bonus_campaigns')->where('id', $campaignId)->update([
            'is_enabled' => false,
            'starts_at' => '2030-01-01 00:00:00+00',
            'ends_at' => '2030-01-02 00:00:00+00',
            'bonus_point_amount' => 9999,
            'updated_at' => now(),
        ]);
        Carbon::setTestNow('2031-01-01 00:00:00+00');
        try {
            $expected = [
                'paid_points' => 10000,
                'bonus_points' => 1000,
                'limited_bonus_points' => 2000,
                'total_points' => 13000,
            ];
            self::assertSame($expected, app(V2FincodePaymentService::class)
                ->show($user, $payment['id'])['grant']);
            self::assertSame($expected, app(V2FincodePaymentService::class)
                ->history($user, 'succeeded', null, 20)['data'][0]['grant']);
            self::assertSame(13000, (int) DB::table('point_lots')
                ->where('user_id', $user->id)->sum('granted_amount'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_all_four_canonical_successes_grant_coin_exactly_once(): void
    {
        $this->fakeFincode('CAPTURED');
        $plan = $this->plan();
        $service = app(V2FincodePaymentService::class);
        $webhooks = app(V2FincodeWebhookService::class);
        $methods = [
            'credit_card' => ['Card', 'payments.card.capture', 'order_id'],
            'paypay' => ['Paypay', 'payments.paypay.complete', 'order_id'],
            'konbini' => ['Konbini', 'payments.konbini.complete', 'order_id'],
            'virtual_account' => ['Virtualaccount', 'payments.virtualaccount.complete', 'id'],
        ];

        foreach ($methods as $method => [$payType, $event, $referenceField]) {
            $user = $this->user('success-'.$method);
            $payment = $service->start(
                $user,
                $plan->public_id,
                $method,
                'success-'.$method,
                $method === 'credit_card' ? ['source' => 'new', 'save' => false] : null
            );
            $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
            $raw = json_encode([
                'event' => $event,
                $referenceField => $row->provider_payment_id,
                'pay_type' => $payType,
                'status' => 'CAPTURED',
                'transaction_date' => '2026/08/24 12:00:00.000',
            ], JSON_THROW_ON_ERROR);
            self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);
            self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);
            self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        }

        self::assertSame(4, DB::table('payment_point_grants')->count());
        self::assertSame(4, DB::table('mail_deliveries')
            ->where('event_key', 'like', 'coin.purchase.completed:%')->count());
    }

    public function test_three_d_secure_non_success_statuses_never_grant_coin(): void
    {
        $expectations = [
            'FAILED' => 'failed',
            'CANCELED' => 'canceled',
            'EXPIRED' => 'expired',
            'AUTHENTICATED' => 'requires_action',
        ];
        foreach ($expectations as $providerStatus => $platformStatus) {
            $this->fakeFincode($providerStatus);
            $user = $this->user('three-d-secure-'.strtolower($providerStatus));
            $payment = app(V2FincodePaymentService::class)->start(
                $user,
                $this->plan()->public_id,
                'credit_card',
                'three-d-secure-'.strtolower($providerStatus),
                ['source' => 'new', 'save' => false]
            );
            $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
            $raw = json_encode([
                'event' => 'payments.card.secure2.result',
                'order_id' => $row->provider_payment_id,
                'pay_type' => 'Card',
                'status' => $providerStatus,
                'process_date' => '2026/08/24 12:00:00.000',
            ], JSON_THROW_ON_ERROR);
            app(V2FincodeWebhookService::class)->process($raw, 'test-webhook-signature');

            self::assertSame($platformStatus, DB::table('payments')->where('id', $row->id)->value('status'));
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        }
    }

    public function test_provider_rejection_fails_payment_without_coin(): void
    {
        Http::swap(new Factory());
        Http::fake(static fn (): mixed => Http::response(['errors' => [['code' => 'provider_rejected']]], 422));
        $user = $this->user('provider-rejection');
        try {
            app(V2FincodePaymentService::class)->start(
                $user,
                $this->plan()->public_id,
                'paypay',
                'provider-rejection'
            );
            self::fail('Provider rejection must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_PROVIDER_REJECTED', $exception->errorCode);
        }
        self::assertSame('failed', DB::table('payments')->where('user_id', $user->id)->value('status'));
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_due_virtual_account_reconciliation_uses_provider_expired_status(): void
    {
        $this->fakeFincode('EXPIRED');
        $user = $this->user('virtual-account-reconciliation');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'virtual_account',
            'virtual-account-reconciliation'
        );
        DB::table('payments')->where('public_id', $payment['id'])->update([
            'expires_at' => now()->subMinute(),
        ]);

        $result = app(V2FincodeReconciliationService::class)->reconcileDue(100);

        self::assertSame(['selected' => 1, 'processed' => 1, 'failed' => 0], $result);
        self::assertSame('expired', DB::table('payments')->where('public_id', $payment['id'])->value('status'));
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_storefront_histories_include_only_succeeded_and_valid_unpaid(): void
    {
        $user = $this->user('storefront-history');
        $plan = $this->plan();
        $fixtures = [
            ['paypay', 'CAPTURED', 'payments.paypay.complete', 'order_id'],
            ['konbini', 'AWAITING_CUSTOMER_PAYMENT', 'payments.konbini.exec', 'order_id'],
            ['virtual_account', 'EXPIRED', 'payments.virtualaccount.exec', 'id'],
            ['paypay', 'FAILED', 'payments.paypay.exec', 'order_id'],
            ['paypay', 'CANCELED', 'payments.paypay.cancel', 'order_id'],
        ];
        $created = [];
        foreach ($fixtures as $index => [$method, $status, $event, $referenceField]) {
            $this->fakeFincode($status);
            $payment = app(V2FincodePaymentService::class)->start(
                $user,
                $plan->public_id,
                $method,
                'history-'.$index
            );
            $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
            $payType = $method === 'virtual_account' ? 'Virtualaccount' : ucfirst($method);
            $raw = json_encode([
                'event' => $event,
                $referenceField => $row->provider_payment_id,
                'pay_type' => $payType,
                'status' => $status,
                'transaction_date' => '2026/08/24 12:00:00.000',
            ], JSON_THROW_ON_ERROR);
            app(V2FincodeWebhookService::class)->process($raw, 'test-webhook-signature');
            $created[$status] = $payment['id'];
        }

        $succeeded = app(V2FincodePaymentService::class)->history($user, 'succeeded', null, 20);
        $unpaid = app(V2FincodePaymentService::class)->history($user, 'unpaid', null, 20);
        self::assertSame([$created['CAPTURED']], array_column($succeeded['data'], 'id'));
        self::assertSame([$created['AWAITING_CUSTOMER_PAYMENT']], array_column($unpaid['data'], 'id'));
        self::assertSame([
            'paid_points' => 1000,
            'bonus_points' => 100,
            'limited_bonus_points' => 0,
            'total_points' => 1100,
        ], $succeeded['data'][0]['grant']);
        self::assertSame([
            'paid_points' => 1000,
            'bonus_points' => 100,
            'limited_bonus_points' => 0,
            'total_points' => 1100,
        ], $unpaid['data'][0]['grant']);
        self::assertSame($created['AWAITING_CUSTOMER_PAYMENT'], app(V2FincodePaymentService::class)
            ->resume($user, $created['AWAITING_CUSTOMER_PAYMENT'])['payment_id']);
    }

    public function test_webhook_authenticity_unknown_event_and_out_of_order_delivery_are_safe(): void
    {
        $this->fakeFincode('AWAITING_CUSTOMER_PAYMENT');
        $webhooks = app(V2FincodeWebhookService::class);
        try {
            $webhooks->process('{"event":"unknown"}', 'wrong');
            self::fail('Invalid authenticity must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_WEBHOOK_AUTHENTICITY_INVALID', $exception->errorCode);
        }
        self::assertSame(
            'ignored',
            $webhooks->process('{"event":"payments.future.event"}', 'test-webhook-signature')['status']
        );

        $user = $this->user('out-of-order');
        $started = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'virtual_account',
            'out-of-order'
        );
        $orderId = DB::table('payments')->where('public_id', $started['id'])->value('provider_payment_id');
        $pending = json_encode([
            'event' => 'payments.virtualaccount.exec',
            'id' => $orderId,
            'pay_type' => 'Virtualaccount',
            'status' => 'AWAITING_CUSTOMER_PAYMENT',
            'process_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $webhooks->process($pending, 'test-webhook-signature')['status']);
        $history = app(V2FincodePaymentService::class)->history($user, 'unpaid', null, 20);
        self::assertSame($started['id'], $history['data'][0]['id']);
        self::assertSame($started['id'], app(V2FincodePaymentService::class)
            ->resume($user, $started['id'])['payment_id']);
    }

    public function test_webhook_rejects_non_integer_canonical_amount(): void
    {
        $this->fakeFincode();
        $user = $this->user('amount-mismatch');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'paypay',
            'amount-mismatch'
        );
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $this->fakeFincode('CAPTURED', '1000.5');
        $raw = json_encode([
            'event' => 'payments.paypay.complete',
            'order_id' => $row->provider_payment_id,
            'pay_type' => 'Paypay',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        try {
            app(V2FincodeWebhookService::class)->process($raw, 'test-webhook-signature');
            self::fail('A non-integer canonical amount must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_PAYMENT_AMOUNT_MISMATCH', $exception->errorCode);
        }
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_konbini_allows_only_one_unpaid_but_virtual_account_allows_multiple(): void
    {
        $this->fakeFincode();
        $user = $this->user('unpaid-limit');
        $service = app(V2FincodePaymentService::class);
        $service->start($user, $this->plan()->public_id, 'konbini', 'konbini-first');
        try {
            $service->start($user, $this->plan()->public_id, 'konbini', 'konbini-second');
            self::fail('A second active Konbini payment must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('KONBINI_UNPAID_LIMIT_REACHED', $exception->errorCode);
        }
        $payment = DB::table('payments')
            ->where('user_id', $user->id)->where('payment_method', 'konbini')->firstOrFail();
        $event = app(V2PaymentService::class)->recordVerifiedProviderEvent(
            'fincode',
            hash('sha256', 'konbini-expired'),
            'payment.status_changed',
            '{}',
            [],
            $payment->id
        );
        app(V2PaymentService::class)->applyVerifiedStatus($event->id, 'expired', 'EXPIRED');
        $service->start($user, $this->plan()->public_id, 'konbini', 'konbini-after-expiry');
        $service->start($user, $this->plan()->public_id, 'virtual_account', 'va-one');
        $service->start($user, $this->plan()->public_id, 'virtual_account', 'va-two');
        self::assertSame(2, DB::table('payments')
            ->where('user_id', $user->id)->where('payment_method', 'virtual_account')->count());
    }

    public function test_card_registration_enforces_three_ownership_and_expiry(): void
    {
        $this->fakeFincode();
        $user = $this->user('cards');
        $other = $this->user('other-cards');
        $cards = app(V2FincodeCardService::class);
        $registered = [];
        foreach (range(1, 3) as $index) {
            $intent = $cards->reserveRegistration($user, 'card-intent-'.$index);
            $registered[] = $cards->completeRegistration($user, $intent['id'], 'card_'.$index);
        }
        self::assertSame(3, count($cards->cards($user)['data']));
        try {
            $cards->reserveRegistration($user, 'card-intent-four');
            self::fail('A fourth card must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_LIMIT_REACHED', $exception->errorCode);
        }
        try {
            $cards->ownedUsableCard($other, $registered[0]['id']);
            self::fail('Card ownership mismatch must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_NOT_FOUND', $exception->errorCode);
        }
        DB::table('fincode_cards')->where('public_id', $registered[0]['id'])->update([
            'expire_month' => 1,
            'expire_year' => 2020,
        ]);
        try {
            $cards->ownedUsableCard($user, $registered[0]['id']);
            self::fail('An expired card must not pay.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_EXPIRED', $exception->errorCode);
        }
        $cards->delete($user, $registered[1]['id']);
        self::assertSame(2, count($cards->cards($user)['data']));
    }

    public function test_saved_card_payment_requires_three_d_secure_parameters(): void
    {
        $this->fakeFincode();
        $user = $this->user('saved-card');
        $cards = app(V2FincodeCardService::class);
        $intent = $cards->reserveRegistration($user, 'saved-card-intent');
        $card = $cards->completeRegistration($user, $intent['id'], 'card_saved');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'saved-card-payment',
            ['source' => 'saved', 'card_id' => $card['id']]
        );
        self::assertSame('three_d_secure', $payment['next_action']['type']);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v1/payments')) {
                return false;
            }
            $data = $request->data();

            return ($data['tds_type'] ?? null) === '2'
                && ($data['tds2_type'] ?? null) === '2'
                && ! array_key_exists('card_no', $data)
                && ! array_key_exists('security_code', $data);
        });
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/v1/payments/')) {
                return false;
            }
            $data = $request->data();

            return ($data['customer_id'] ?? null) !== null
                && ($data['card_id'] ?? null) !== null
                && ! array_key_exists('card_no', $data)
                && ! array_key_exists('security_code', $data);
        });
    }

    public function test_timeout_is_uncertain_and_never_grants_coin(): void
    {
        Http::fake(fn () => Http::failedConnection());
        $user = $this->user('timeout');
        try {
            app(V2FincodePaymentService::class)->start(
                $user,
                $this->plan()->public_id,
                'paypay',
                'timeout-start'
            );
            self::fail('Timeout must be reported.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_TIMEOUT', $exception->errorCode);
        }
        self::assertDatabaseHas('fincode_payment_attempts', ['status' => 'uncertain']);
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_admin_all_user_and_user_detail_contracts_include_all_states_and_filters(): void
    {
        $this->fakeFincode();
        $first = $this->user('admin-first');
        $second = $this->user('admin-second');
        $service = app(V2FincodePaymentService::class);
        $firstPayment = $service->start($first, $this->plan()->public_id, 'paypay', 'admin-first-payment');
        $secondPayment = $service->start($second, $this->plan()->public_id, 'virtual_account', 'admin-second-payment');
        $payments = app(V2PaymentService::class);
        $firstPaymentId = DB::table('payments')->where('public_id', $firstPayment['id'])->value('id');
        $secondPaymentId = DB::table('payments')->where('public_id', $secondPayment['id'])->value('id');
        $payments->applyProviderStartResult($firstPaymentId, 'FAILED', 'failed');
        $event = $payments->recordVerifiedProviderEvent(
            'fincode',
            hash('sha256', 'admin-second-canceled'),
            'payment.status_changed',
            '{}',
            [],
            $secondPaymentId
        );
        $payments->applyVerifiedStatus($event->id, 'canceled', 'CANCELED');
        $context = new V2AdminAuthorizationContext(
            1,
            (string) Str::uuid7(),
            V2AdminRole::Owner,
            hash('sha256', 'session'),
            hash('sha256', 'correlation'),
            (string) Str::uuid7()
        );
        $admin = app(V2AdminPaymentReadService::class);
        $all = $admin->all($context, null, 20, null, null, null);
        self::assertCount(2, $all['data']);
        $detail = $admin->forUser($context, $first->public_id, null, 20, 'failed', 'paypay');
        self::assertCount(1, $detail['data']);
        self::assertSame($first->public_id, $detail['data'][0]['user']['id']);
        self::assertSame('failed', $detail['data'][0]['status']);

        $operator = new V2AdminAuthorizationContext(
            2,
            (string) Str::uuid7(),
            V2AdminRole::Operator,
            hash('sha256', 'operator-session'),
            hash('sha256', 'operator-correlation'),
            (string) Str::uuid7()
        );
        try {
            $admin->all($operator, null, 20, null, null, null);
            self::fail('Operator financial payment history access must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    private function fakeFincode(
        string $retrievedStatus = 'AWAITING_CUSTOMER_PAYMENT',
        string $retrievedAmount = '1000'
    ): void
    {
        Http::swap(new Factory());
        Http::fake(function (Request $request) use ($retrievedStatus, $retrievedAmount) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/customers')) {
                return Http::response(['id' => $request->data()['id']], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/cards/')) {
                $cardId = basename(parse_url($url, PHP_URL_PATH));

                return Http::response([
                    'id' => $cardId,
                    'brand' => 'VISA',
                    'customer_id' => basename(dirname(dirname(parse_url($url, PHP_URL_PATH)))),
                    'card_no' => '************4242',
                    'expire' => '3012',
                ], 200);
            }
            if ($request->method() === 'DELETE' && str_contains($url, '/cards/')) {
                return Http::response([], 204);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/payments')) {
                return Http::response([
                    'id' => $request->data()['id'],
                    'access_id' => 'a_'.Str::lower((string) Str::ulid()),
                    'status' => 'UNPROCESSED',
                ], 200);
            }
            if ($request->method() === 'PUT' && str_contains($url, '/v1/payments/')) {
                return Http::response([
                    'status' => 'AUTHENTICATED',
                    'acs_url' => 'https://acs.example.test/challenge',
                ], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/sessions')) {
                return Http::response([
                    'id' => 's_'.Str::lower((string) Str::ulid()),
                    'link_url' => 'https://pay.test.fincode.jp/session/'.Str::lower((string) Str::ulid()),
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/v1/payments/')) {
                $orderId = basename(parse_url($url, PHP_URL_PATH));
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response([
                    'id' => $orderId,
                    'pay_type' => $query['pay_type'] ?? null,
                    'status' => $retrievedStatus,
                    'amount' => $retrievedAmount,
                    'tax' => '0',
                    'transaction_date' => '2026/08/24 12:00:00.000',
                    'process_date' => '2026/08/24 12:00:00.000',
                ], 200);
            }

            return Http::response([], 500);
        });
    }

    private function plan(
        int $amount = 1000,
        int $paidPointAmount = 1000,
        int $freePointAmount = 100,
    ): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'fincode-'.Str::uuid(),
            'version_no' => 1,
            'name' => 'fincode Test Plan',
            'amount' => $amount,
            'paid_point_amount' => $paidPointAmount,
            'free_point_amount' => $freePointAmount,
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
}
