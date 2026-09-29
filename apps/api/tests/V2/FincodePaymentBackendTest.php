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
use App\Domain\Payment\V2\Services\V2FincodeClient;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\V2TimestampFixture;
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

    public function test_production_test_endpoint_is_rejected_when_opt_in_is_unset(): void
    {
        self::assertFalse(config('v2_fincode.allow_test_in_production'));

        $this->assertProductionTestEndpointRejected(true);
    }

    public function test_production_test_endpoint_is_rejected_when_opt_in_is_false(): void
    {
        $this->assertProductionTestEndpointRejected(false);
    }

    public function test_production_test_endpoint_is_allowed_only_with_explicit_opt_in(): void
    {
        Http::fake([
            'https://api.test.fincode.jp/v1/customers' => Http::response(['id' => 'customer-test'], 200),
        ]);
        config(['v2_fincode.allow_test_in_production' => true]);
        app()->detectEnvironment(fn (): string => 'production');
        Auth::guard('v2_user')->setUser($this->user('production-test-opt-in'));
        $before = $this->paymentMutationCounts();

        $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
            ->assertOk()
            ->assertExactJson([
                'provider' => 'fincode',
                'public_api_key' => 'p_test_public-key',
                'is_live_mode' => false,
            ]);
        app(V2FincodeClient::class)->createCustomer('customer-test', 'production-test-opt-in');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.test.fincode.jp/v1/customers');
        self::assertSame($before, $this->paymentMutationCounts());
    }

    public function test_production_endpoint_remains_allowed_in_production(): void
    {
        Http::fake([
            'https://api.fincode.jp/v1/customers' => Http::response(['id' => 'customer-live'], 200),
        ]);
        config([
            'v2_fincode.allow_test_in_production' => false,
            'v2_fincode.base_url' => 'https://api.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_prod_secret-not-real',
            'v2_fincode.public_api_key' => 'p_prod_public-not-real',
        ]);
        app()->detectEnvironment(fn (): string => 'production');
        Auth::guard('v2_user')->setUser($this->user('production-live-endpoint'));
        $before = $this->paymentMutationCounts();

        $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
            ->assertOk()
            ->assertExactJson([
                'provider' => 'fincode',
                'public_api_key' => 'p_prod_public-not-real',
                'is_live_mode' => true,
            ]);
        app(V2FincodeClient::class)->createCustomer('customer-live', 'production-live-endpoint');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.fincode.jp/v1/customers');
        self::assertSame($before, $this->paymentMutationCounts());
    }

    public function test_opt_in_never_bypasses_environment_endpoint_or_key_mode_validation(): void
    {
        Http::fake();
        $user = $this->user('production-mode-mismatch');
        Auth::guard('v2_user')->setUser($user);
        $before = $this->paymentMutationCounts();
        $cases = [
            [
                'environment' => 'testing',
                'base_url' => 'https://api.fincode.jp',
                'public_key' => 'p_prod_public-not-real',
                'secret_key' => 'm_prod_secret-not-real',
            ],
            [
                'environment' => 'production',
                'base_url' => 'https://api.test.fincode.jp',
                'public_key' => 'p_prod_public-not-real',
                'secret_key' => 'm_test_secret-not-production',
            ],
            [
                'environment' => 'production',
                'base_url' => 'https://api.test.fincode.jp',
                'public_key' => 'p_test_public-key',
                'secret_key' => 'm_prod_secret-not-real',
            ],
            [
                'environment' => 'production',
                'base_url' => 'https://unknown.fincode.jp',
                'public_key' => 'p_test_public-key',
                'secret_key' => 'm_test_secret-not-production',
            ],
        ];

        foreach ($cases as $case) {
            app()->detectEnvironment(fn (): string => $case['environment']);
            config([
                'v2_fincode.allow_test_in_production' => true,
                'v2_fincode.base_url' => $case['base_url'],
                'v2_fincode.public_api_key' => $case['public_key'],
                'v2_fincode.secret_api_key' => $case['secret_key'],
            ]);

            $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
                ->assertStatus(503)
                ->assertJsonPath('code', 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE');
            try {
                app(V2FincodePaymentService::class)->start(
                    $user,
                    (string) Str::uuid7(),
                    'paypay',
                    'mode-mismatch-'.Str::uuid7()
                );
                self::fail('Environment, endpoint, and key modes must remain fail closed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE', $exception->errorCode);
            }
        }

        config([
            'v2_fincode.base_url' => 'https://unknown.fincode.jp',
            'v2_fincode.secret_api_key' => 'm_test_secret-not-production',
        ]);
        try {
            app(V2FincodeClient::class)->createCustomer('unknown-endpoint', 'unknown-endpoint');
            self::fail('Unknown endpoints must remain rejected when the opt-in is true.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_CONFIGURATION_UNAVAILABLE', $exception->errorCode);
        }

        self::assertCount(0, Http::recorded());
        self::assertSame($before, $this->paymentMutationCounts());
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
                        === 'https://api.luxe-pack.biz/api/v2/payment-returns/fincode/failure?pid='.$payment['id']
                    && ($data['transaction']['order_id'] ?? null)
                        === DB::table('payments')->where('public_id', $payment['id'])->value('provider_payment_id')
                    && ! array_key_exists('id', $data['transaction'] ?? []);
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
            $expectedUrl = $payment['next_action']['url'];
            $paymentCount = DB::table('payments')->count();
            $providerCallCount = Http::recorded()->count();

            $initial = $service->resume($owner, $payment['id']);

            self::assertSame($expectedUrl, $initial['next_action']['url']);
            self::assertSame($paymentCount, DB::table('payments')->count());
            self::assertSame($providerCallCount, Http::recorded()->count());
            try {
                $service->resume($other, $payment['id']);
                self::fail('Other-user initial unpaid payment must not be resumable.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }

            DB::table('payments')->where('public_id', $payment['id'])->update(V2TimestampFixture::attributes([
                'status' => 'processing',
                'provider_status' => 'AWAITING_CUSTOMER_PAYMENT',
                'expires_at' => now()->addDay(),
            ]));
            self::assertSame('processing', $service->show($owner, $payment['id'])['status']);
            self::assertNull($service->show($owner, $payment['id'])['next_action']);

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

        $missingRedirect = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'resume-missing-redirect'
        );
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $missingRedirect['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => null]);
        $providerCallCount = Http::recorded()->count();
        try {
            $service->resume($owner, $missingRedirect['id']);
            self::fail('Payment without a saved redirect must not be resumable.');
        } catch (V2FincodeException $exception) {
            self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
        }
        self::assertSame($providerCallCount, Http::recorded()->count());

        $undecryptableOwner = $this->user('resume-undecryptable-owner');
        $undecryptable = $service->start(
            $undecryptableOwner,
            $plan->public_id,
            'konbini',
            'resume-undecryptable-redirect'
        );
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $undecryptable['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => 'invalid-ciphertext']);
        $providerCallCount = Http::recorded()->count();
        try {
            $service->resume($undecryptableOwner, $undecryptable['id']);
            self::fail('Payment with an undecryptable redirect must not be resumable.');
        } catch (V2FincodeException $exception) {
            self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
        }
        self::assertSame($providerCallCount, Http::recorded()->count());

        $expired = $service->start($owner, $plan->public_id, 'virtual_account', 'resume-expired');
        DB::table('payments')->where('public_id', $expired['id'])->update(V2TimestampFixture::attributes(['expires_at' => now()->subSecond()]));
        $providerCallCount = Http::recorded()->count();
        try {
            $service->resume($owner, $expired['id']);
            self::fail('Expired unpaid payment must not be resumable.');
        } catch (V2FincodeException $exception) {
            self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
        }
        self::assertSame($providerCallCount, Http::recorded()->count());

        foreach (['credit_card', 'paypay'] as $method) {
            $payment = $service->start(
                $owner,
                $plan->public_id,
                $method,
                'resume-unsupported-'.$method,
                $method === 'credit_card' ? ['source' => 'new', 'save' => false] : null
            );
            $providerCallCount = Http::recorded()->count();
            try {
                $service->resume($owner, $payment['id']);
                self::fail('Card and PayPay must not use unpaid resume.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }
            self::assertSame($providerCallCount, Http::recorded()->count());
        }

        $terminal = $service->start($owner, $plan->public_id, 'virtual_account', 'resume-terminal');
        foreach (['succeeded', 'expired', 'failed', 'canceled'] as $status) {
            DB::table('payments')->where('public_id', $terminal['id'])->update(V2TimestampFixture::attributes([
                'status' => $status,
                'provider_status' => strtoupper($status),
                'expires_at' => now()->addDay(),
            ]));
            try {
                $service->resume($owner, $terminal['id']);
                self::fail('Terminal payment must not be resumable.');
            } catch (V2FincodeException $exception) {
                self::assertSame('UNPAID_PAYMENT_NOT_RESUMABLE', $exception->errorCode);
            }
        }
    }

    public function test_json_resume_http_contract_reuses_existing_redirect_without_provider_request(): void
    {
        $this->fakeFincode();
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $owner = $this->user('resume-http-owner');
        $payment = app(V2FincodePaymentService::class)->start(
            $owner,
            $this->plan()->public_id,
            'virtual_account',
            'resume-http-contract'
        );
        $expectedUrl = $payment['next_action']['url'];
        $paymentCount = DB::table('payments')->count();
        $providerCallCount = Http::recorded()->count();
        $csrf = str_repeat('a', 64);

        Auth::guard('v2_user')->setUser($owner);
        $this
            ->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->postJson("/api/v2/payments/{$payment['id']}/resume", [])
            ->assertOk()
            ->assertJsonPath('payment_id', $payment['id'])
            ->assertJsonPath('next_action.url', $expectedUrl);

        self::assertSame($paymentCount, DB::table('payments')->count());
        self::assertSame($providerCallCount, Http::recorded()->count());
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

    public function test_documented_card_3ds_failure_from_exact_requery_is_terminal_and_duplicate_safe(): void
    {
        $this->fakeFincode();
        $user = $this->user('three-d-secure-terminal');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-terminal',
            ['source' => 'new', 'save' => false]
        );
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A3');
        $raw = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $row->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
            'error_code' => 'EC0091310A2',
            'process_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        $webhooks = app(V2FincodeWebhookService::class);

        self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);
        self::assertSame('processed', $webhooks->process($raw, 'test-webhook-signature')['status']);

        $failed = DB::table('payments')->where('id', $row->id)->firstOrFail();
        self::assertSame('failed', $failed->status);
        self::assertSame('AUTHENTICATED', $failed->provider_status);
        self::assertNotNull($failed->provider_confirmed_at);
        $attempt = DB::table('fincode_payment_attempts')->where('payment_id', $row->id)->firstOrFail();
        self::assertSame('failed', $attempt->status);
        self::assertSame('EC0091310A3', $attempt->last_error_code);
        self::assertSame(1, DB::table('payment_provider_events')->where('payment_id', $row->id)->count());
        self::assertSame(1, DB::table('payment_provider_event_attempts')->count());
        self::assertSame(1, DB::table('payment_status_histories')
            ->where('payment_id', $row->id)->where('to_status', 'failed')->count());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
        Http::assertSent(function (Request $request) use ($row): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/'.$row->provider_payment_id)
                && ($query['pay_type'] ?? null) === 'Card';
        });
    }

    public function test_documented_card_3ds_failure_after_challenge_event_uses_requery_authority(): void
    {
        $this->fakeFincode();
        $user = $this->user('three-d-secure-challenge');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-challenge',
            ['source' => 'new', 'save' => false]
        );
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $webhooks = app(V2FincodeWebhookService::class);
        $this->fakeFincode('AUTHENTICATED');
        $challenge = json_encode([
            'event' => 'payments.card.secure2.authenticate',
            'order_id' => $row->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
            'error_code' => 'EC0091310A3',
            'process_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);

        self::assertSame('processed', $webhooks->process($challenge, 'test-webhook-signature')['status']);
        self::assertSame('requires_action', DB::table('payments')->where('id', $row->id)->value('status'));
        self::assertNull(DB::table('fincode_payment_attempts')
            ->where('payment_id', $row->id)->value('last_error_code'));

        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A3');
        $failure = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $row->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
            'process_date' => '2026/08/24 12:00:01.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $webhooks->process($failure, 'test-webhook-signature')['status']);

        self::assertSame('failed', DB::table('payments')->where('id', $row->id)->value('status'));
        self::assertSame('EC0091310A3', DB::table('fincode_payment_attempts')
            ->where('payment_id', $row->id)->value('last_error_code'));
        self::assertSame(2, DB::table('payment_provider_events')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_documented_card_3ds_failure_is_shared_by_direct_reconciliation(): void
    {
        $this->fakeFincode();
        $payment = app(V2FincodePaymentService::class)->start(
            $this->user('three-d-secure-reconciliation'),
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-reconciliation',
            ['source' => 'new', 'save' => false]
        );
        $row = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A3');

        $result = app(V2FincodeWebhookService::class)->reconcile($row);

        self::assertSame('processed', $result['status']);
        self::assertSame('failed', DB::table('payments')->where('id', $row->id)->value('status'));
        self::assertSame('AUTHENTICATED', DB::table('payments')
            ->where('id', $row->id)->value('provider_status'));
        self::assertSame('EC0091310A3', DB::table('fincode_payment_attempts')
            ->where('payment_id', $row->id)->value('last_error_code'));
        self::assertSame(1, DB::table('payment_provider_events')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_card_terminal_failure_and_success_resist_reverse_order_events(): void
    {
        $this->fakeFincode();
        $failedUser = $this->user('three-d-secure-failure-first');
        $failedPayment = app(V2FincodePaymentService::class)->start(
            $failedUser,
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-failure-first',
            ['source' => 'new', 'save' => false]
        );
        $failedRow = DB::table('payments')->where('public_id', $failedPayment['id'])->firstOrFail();
        $webhooks = app(V2FincodeWebhookService::class);
        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A3');
        $failure = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $failedRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
            'process_date' => '2026/08/24 12:00:00.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $webhooks->process($failure, 'test-webhook-signature')['status']);

        $this->fakeFincode('CAPTURED');
        $lateSuccess = json_encode([
            'event' => 'payments.card.capture',
            'order_id' => $failedRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:01.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('ignored', $webhooks->process($lateSuccess, 'test-webhook-signature')['status']);
        self::assertSame('failed', DB::table('payments')->where('id', $failedRow->id)->value('status'));
        self::assertSame('AUTHENTICATED', DB::table('payments')
            ->where('id', $failedRow->id)->value('provider_status'));
        self::assertSame(0, DB::table('payment_point_grants')
            ->where('payment_id', $failedRow->id)->count());

        $succeededUser = $this->user('three-d-secure-success-first');
        $succeededPayment = app(V2FincodePaymentService::class)->start(
            $succeededUser,
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-success-first',
            ['source' => 'new', 'save' => false]
        );
        $succeededRow = DB::table('payments')->where('public_id', $succeededPayment['id'])->firstOrFail();
        $this->fakeFincode('CAPTURED', '1000', 'EC0091310A3');
        $success = json_encode([
            'event' => 'payments.card.capture',
            'order_id' => $succeededRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'CAPTURED',
            'transaction_date' => '2026/08/24 12:00:02.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $webhooks->process($success, 'test-webhook-signature')['status']);

        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A3');
        $lateFailure = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $succeededRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
            'process_date' => '2026/08/24 12:00:03.000',
        ], JSON_THROW_ON_ERROR);
        self::assertSame('ignored', $webhooks->process($lateFailure, 'test-webhook-signature')['status']);
        self::assertSame('succeeded', DB::table('payments')->where('id', $succeededRow->id)->value('status'));
        self::assertSame('CAPTURED', DB::table('payments')
            ->where('id', $succeededRow->id)->value('provider_status'));
        self::assertSame('completed', DB::table('fincode_payment_attempts')
            ->where('payment_id', $succeededRow->id)->value('status'));
        self::assertNull(DB::table('fincode_payment_attempts')
            ->where('payment_id', $succeededRow->id)->value('last_error_code'));
        self::assertSame(1, DB::table('payment_point_grants')
            ->where('payment_id', $succeededRow->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'coin.purchase.completed:'.$succeededRow->public_id)->count());
    }

    public function test_unclassified_malformed_and_unavailable_card_requeries_fail_closed(): void
    {
        $this->fakeFincode();
        $service = app(V2FincodePaymentService::class);
        $webhooks = app(V2FincodeWebhookService::class);
        $unclassified = $service->start(
            $this->user('three-d-secure-unclassified'),
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-unclassified',
            ['source' => 'new', 'save' => false]
        );
        $unclassifiedRow = DB::table('payments')->where('public_id', $unclassified['id'])->firstOrFail();
        $this->fakeFincode('AUTHENTICATED', '1000', 'EC0091310A2');
        $raw = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $unclassifiedRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
        ], JSON_THROW_ON_ERROR);
        try {
            $webhooks->process($raw, 'test-webhook-signature');
            self::fail('An unclassified Card error must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_CARD_FAILURE_UNCLASSIFIED', $exception->errorCode);
            self::assertSame(503, $exception->status);
            self::assertTrue($exception->retryable);
        }
        self::assertSame('requires_action', DB::table('payments')
            ->where('id', $unclassifiedRow->id)->value('status'));
        self::assertSame('UNPROCESSED', DB::table('payments')
            ->where('id', $unclassifiedRow->id)->value('provider_status'));

        $malformed = $service->start(
            $this->user('three-d-secure-malformed'),
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-malformed',
            ['source' => 'new', 'save' => false]
        );
        $malformedRow = DB::table('payments')->where('public_id', $malformed['id'])->firstOrFail();
        Http::swap(new Factory());
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'id' => basename((string) parse_url($request->url(), PHP_URL_PATH)),
                'pay_type' => $query['pay_type'] ?? null,
                'status' => 'AUTHENTICATED',
                'error_code' => ['EC0091310A3'],
                'amount' => '1000',
                'tax' => '0',
            ], 200);
        });
        $malformedRaw = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $malformedRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
        ], JSON_THROW_ON_ERROR);
        try {
            $webhooks->process($malformedRaw, 'test-webhook-signature');
            self::fail('A malformed Card error must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_CANONICAL_RESPONSE_INVALID', $exception->errorCode);
            self::assertTrue($exception->retryable);
        }

        $this->fakeFincode();
        $unavailable = $service->start(
            $this->user('three-d-secure-unavailable'),
            $this->plan()->public_id,
            'credit_card',
            'three-d-secure-unavailable',
            ['source' => 'new', 'save' => false]
        );
        $unavailableRow = DB::table('payments')->where('public_id', $unavailable['id'])->firstOrFail();
        Http::swap(new Factory());
        Http::fake(fn () => Http::failedConnection());
        $unavailableRaw = json_encode([
            'event' => 'payments.card.secure2.result',
            'order_id' => $unavailableRow->provider_payment_id,
            'pay_type' => 'Card',
            'status' => 'AUTHENTICATED',
        ], JSON_THROW_ON_ERROR);
        try {
            $webhooks->process($unavailableRaw, 'test-webhook-signature');
            self::fail('An unavailable Provider re-query must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_TIMEOUT', $exception->errorCode);
            self::assertTrue($exception->retryable);
        }

        foreach ([$unclassifiedRow, $malformedRow, $unavailableRow] as $row) {
            self::assertSame('requires_action', DB::table('payments')->where('id', $row->id)->value('status'));
            self::assertSame('requires_action', DB::table('fincode_payment_attempts')
                ->where('payment_id', $row->id)->value('status'));
            self::assertNull(DB::table('fincode_payment_attempts')
                ->where('payment_id', $row->id)->value('last_error_code'));
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $row->id)->count());
        }
        self::assertSame(0, DB::table('payment_provider_events')->count());
        self::assertSame(0, DB::table('point_ledger_entries')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
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
        DB::table('payments')->where('public_id', $payment['id'])->update(V2TimestampFixture::attributes([
            'expires_at' => now()->subMinute(),
        ]));

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

    public function test_unpaid_history_uses_exact_resume_eligibility_and_fails_closed(): void
    {
        $this->fakeFincode();
        $owner = $this->user('unpaid-history-owner');
        $other = $this->user('unpaid-history-other');
        $plan = $this->plan();
        $service = app(V2FincodePaymentService::class);

        $requiresActionVa = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-requires-action-va'
        );
        $requiresActionKonbini = $service->start(
            $owner,
            $plan->public_id,
            'konbini',
            'unpaid-history-requires-action-konbini'
        );
        $processingVa = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-processing-va'
        );
        DB::table('payments')->where('public_id', $processingVa['id'])->update([
            'status' => 'processing',
            'provider_status' => 'AWAITING_CUSTOMER_PAYMENT',
        ]);

        $card = $service->start(
            $owner,
            $plan->public_id,
            'credit_card',
            'unpaid-history-card',
            ['source' => 'new', 'save' => false]
        );
        $paypay = $service->start($owner, $plan->public_id, 'paypay', 'unpaid-history-paypay');
        $paypayProcessing = $service->start(
            $owner,
            $plan->public_id,
            'paypay',
            'unpaid-history-paypay-processing'
        );
        DB::table('payments')->where('public_id', $paypayProcessing['id'])->update([
            'status' => 'processing',
            'provider_status' => 'AWAITING_CUSTOMER_PAYMENT',
        ]);

        $expired = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-expired'
        );
        DB::table('payments')->where('public_id', $expired['id'])->update(V2TimestampFixture::attributes(['expires_at' => now()->subSecond()]));

        $terminalIds = [];
        foreach (['succeeded', 'failed', 'canceled', 'expired'] as $status) {
            $terminal = $service->start(
                $owner,
                $plan->public_id,
                'virtual_account',
                'unpaid-history-terminal-'.$status
            );
            $terminalIds[] = $terminal['id'];
            DB::table('payments')->where('public_id', $terminal['id'])->update([
                'status' => $status,
                'provider_status' => strtoupper($status),
            ]);
        }

        $missingRedirect = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-missing-redirect'
        );
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $missingRedirect['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => null]);

        $undecryptable = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-undecryptable'
        );
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $undecryptable['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => 'invalid-ciphertext']);

        $invalidAuthority = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-invalid-authority'
        );
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $invalidAuthority['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => Crypt::encryptString('https://payment.example.test/session')]);

        $mismatchedPair = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-mismatched-pair'
        );
        DB::table('payments')->where('public_id', $mismatchedPair['id'])->update([
            'status' => 'requires_action',
            'provider_status' => 'AWAITING_CUSTOMER_PAYMENT',
        ]);

        $otherProvider = $service->start(
            $owner,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-other-provider'
        );
        DB::table('payments')->where('public_id', $otherProvider['id'])->update(['provider_code' => 'other']);

        $otherUserPayment = $service->start(
            $other,
            $plan->public_id,
            'virtual_account',
            'unpaid-history-other-user'
        );
        $providerCallCount = Http::recorded()->count();

        $history = $service->history($owner, 'unpaid', null, 100);

        self::assertSame([
            $processingVa['id'],
            $requiresActionKonbini['id'],
            $requiresActionVa['id'],
        ], array_column($history['data'], 'id'));
        self::assertFalse($history['pagination']['has_more']);
        self::assertNull($history['pagination']['next_cursor']);
        self::assertSame($providerCallCount, Http::recorded()->count());
        self::assertNotContains($card['id'], array_column($history['data'], 'id'));
        self::assertNotContains($paypay['id'], array_column($history['data'], 'id'));
        self::assertNotContains($paypayProcessing['id'], array_column($history['data'], 'id'));
        self::assertNotContains($expired['id'], array_column($history['data'], 'id'));
        self::assertSame([], array_intersect($terminalIds, array_column($history['data'], 'id')));
        self::assertNotContains($missingRedirect['id'], array_column($history['data'], 'id'));
        self::assertNotContains($undecryptable['id'], array_column($history['data'], 'id'));
        self::assertNotContains($invalidAuthority['id'], array_column($history['data'], 'id'));
        self::assertNotContains($mismatchedPair['id'], array_column($history['data'], 'id'));
        self::assertNotContains($otherProvider['id'], array_column($history['data'], 'id'));
        self::assertNotContains($otherUserPayment['id'], array_column($history['data'], 'id'));

        Auth::guard('v2_user')->setUser($owner);
        $this->getJson('/api/v2/me/payments?view=unpaid&limit=100')
            ->assertOk()
            ->assertJsonPath('data.0.id', $processingVa['id'])
            ->assertJsonPath('data.1.id', $requiresActionKonbini['id'])
            ->assertJsonPath('data.2.id', $requiresActionVa['id'])
            ->assertJsonCount(3, 'data');
        self::assertSame($providerCallCount, Http::recorded()->count());
    }

    public function test_unpaid_history_pagination_skips_invalid_redirects_without_duplicates(): void
    {
        $this->fakeFincode();
        $user = $this->user('unpaid-history-pagination');
        $plan = $this->plan();
        $service = app(V2FincodePaymentService::class);
        $eligible = [];

        $eligible[] = $service->start($user, $plan->public_id, 'virtual_account', 'unpaid-page-1')['id'];
        $missing = $service->start($user, $plan->public_id, 'virtual_account', 'unpaid-page-missing');
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $missing['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => null]);
        $eligible[] = $service->start($user, $plan->public_id, 'virtual_account', 'unpaid-page-2')['id'];
        $invalid = $service->start($user, $plan->public_id, 'virtual_account', 'unpaid-page-invalid');
        DB::table('fincode_payment_attempts')
            ->where('payment_id', DB::table('payments')->where('public_id', $invalid['id'])->value('id'))
            ->update(['redirect_url_ciphertext' => 'invalid-ciphertext']);
        $eligible[] = $service->start($user, $plan->public_id, 'virtual_account', 'unpaid-page-3')['id'];
        $providerCallCount = Http::recorded()->count();

        $first = $service->history($user, 'unpaid', null, 2);
        $second = $service->history($user, 'unpaid', $first['pagination']['next_cursor'], 2);

        self::assertSame(array_reverse($eligible), [
            ...array_column($first['data'], 'id'),
            ...array_column($second['data'], 'id'),
        ]);
        self::assertTrue($first['pagination']['has_more']);
        self::assertNotNull($first['pagination']['next_cursor']);
        self::assertFalse($second['pagination']['has_more']);
        self::assertNull($second['pagination']['next_cursor']);
        self::assertSame([], array_intersect(
            array_column($first['data'], 'id'),
            array_column($second['data'], 'id')
        ));
        self::assertSame($providerCallCount, Http::recorded()->count());
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
        $first = $service->start($user, $this->plan()->public_id, 'konbini', 'konbini-first');
        $providerCallCount = Http::recorded()->count();
        self::assertSame(
            [$first['id']],
            array_column($service->history($user, 'unpaid', null, 20)['data'], 'id')
        );
        self::assertSame($providerCallCount, Http::recorded()->count());
        try {
            $service->start($user, $this->plan()->public_id, 'konbini', 'konbini-second');
            self::fail('A second active Konbini payment must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('KONBINI_UNPAID_LIMIT_REACHED', $exception->errorCode);
            self::assertSame(409, $exception->status);
            self::assertFalse($exception->retryable);
        }
        self::assertSame($providerCallCount, Http::recorded()->count());
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

    public function test_canonical_card_registration_uses_fixed_three_d_secure_contract_and_is_exactly_once(): void
    {
        $this->fakeFincode();
        $user = $this->user('canonical-card');
        $cards = app(V2FincodeCardService::class);
        $cardToken = 'tok_browser_only_card_fixture';
        $started = $cards->startRegistration($user, $cardToken, 'canonical-card-start');

        self::assertSame('requires_action', $started['status']);
        self::assertSame('three_d_secure', $started['next_action']['type']);
        self::assertStringStartsWith('https://pay.test.fincode.jp/', $started['next_action']['url']);
        self::assertNull($started['saved_card_id']);
        self::assertSame(0, DB::table('fincode_cards')->count());
        $providerCalls = Http::recorded()->count();
        $replayed = $cards->startRegistration($user, 'tok_different_replay_value', 'canonical-card-start');
        self::assertSame($started, $replayed);
        self::assertSame($providerCalls, Http::recorded()->count());

        $intent = DB::table('fincode_card_registration_intents')
            ->where('public_id', $started['id'])
            ->firstOrFail();
        self::assertSame('three_d_secure_2', $intent->flow_type);
        self::assertSame('AWAITING_CUSTOMER_ACTION', $intent->provider_status);
        self::assertSame('AUTHENTICATING', $intent->provider_tds2_status);
        self::assertStringNotContainsString($cardToken, json_encode((array) $intent, JSON_THROW_ON_ERROR));
        $providerCustomerId = DB::table('fincode_customers')
            ->where('user_id', $user->id)
            ->value('provider_customer_id');
        Http::assertSent(function (Request $request) use (
            $started,
            $cardToken,
            $intent,
            $providerCustomerId
        ): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.test.fincode.jp/v1/customers/'
                    .rawurlencode((string) $providerCustomerId).'/payment_methods'
                && ($request->header('Accept')[0] ?? null) === 'application/json'
                && ($request->header('Content-Type')[0] ?? null) === 'application/json'
                && ($request->header('Authorization')[0] ?? null)
                    === 'Bearer '.config('v2_fincode.secret_api_key')
                && ($request->header('idempotent_key')[0] ?? null) === $intent->provider_idempotency_key
                && $request->data() === [
                    'pay_type' => 'Card',
                    'default_flag' => '1',
                    'return_url' => 'https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/normal?rid='.$started['id'],
                    'return_url_on_failure' => 'https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/failure?rid='.$started['id'],
                    'client_field_1' => $started['id'],
                    'card' => [
                        'token' => $cardToken,
                        'tds_type' => '2',
                        'tds2_type' => '2',
                    ],
                ];
        });

        $this->fakeFincode(registration: ['canonical_tds2_status' => 'AUTHENTICATING']);
        $browserReturn = $this->post(
            '/api/v2/payment-card-registration-returns/fincode/normal?rid='.$started['id'],
            ['provider_card_id' => 'browser_supplied_card_must_be_ignored']
        )
            ->assertStatus(303)
            ->assertRedirect('https://luxe-pack.biz/?card_registration_id='.$started['id'])
            ->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString(
            'no-store',
            (string) $browserReturn->headers->get('Cache-Control')
        );
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertNull(DB::table('fincode_card_registration_intents')
            ->where('public_id', $started['id'])->value('provider_card_id'));

        $this->fakeFincode();
        $webhook = $this->registrationWebhook($started['id']);
        self::assertSame(
            ['status' => 'processed'],
            app(V2FincodeWebhookService::class)->process($webhook, 'test-webhook-signature')
        );
        $completed = $cards->registration($user, $started['id']);
        self::assertSame('completed', $completed['status']);
        self::assertNotNull($completed['completed_at']);
        self::assertNotNull($completed['saved_card_id']);
        self::assertNull($completed['next_action']);
        self::assertSame(1, DB::table('fincode_cards')->count());

        $canonicalCalls = Http::recorded()->count();
        app(V2FincodeWebhookService::class)->process($webhook, 'test-webhook-signature');
        $cards->reconcileRegistration($user, $started['id']);
        $this->post('/api/v2/payment-card-registration-returns/fincode/failure?rid='.$started['id'])
            ->assertStatus(303);
        self::assertSame($canonicalCalls, Http::recorded()->count());
        self::assertSame(1, DB::table('fincode_cards')->count());
        self::assertSame(1, DB::table('fincode_cards')
            ->where('registration_assurance', 'three_d_secure_2')
            ->whereNotNull('registration_verified_at')
            ->count());
    }

    #[DataProvider('registrationTransactionReferences')]
    public function test_registration_webhook_transaction_reference_is_optional_nullable_metadata(
        mixed $transactionId,
        bool $missing,
        bool $valid
    ): void {
        $this->fakeFincode();
        $user = $this->user('transaction-metadata');
        $started = app(V2FincodeCardService::class)->startRegistration($user, 'tok_metadata', 'metadata');
        $payload = json_decode($this->registrationWebhook($started['id']), true, flags: JSON_THROW_ON_ERROR);
        $payload['transaction_id'] = $transactionId;
        if ($missing) {
            unset($payload['transaction_id']);
        }
        $response = $this->withHeader('Fincode-Signature', 'test-webhook-signature')
            ->postJson('/webhooks/v2/fincode', $payload);
        if (! $valid) {
            $response->assertStatus(422)->assertJsonPath('code', 'FINCODE_WEBHOOK_INVALID');
            self::assertSame(0, DB::table('fincode_cards')->count());
            self::assertNull(DB::table('fincode_card_registration_intents')->value('webhook_received_at'));

            return;
        }
        $response->assertOk();
        self::assertSame(1, DB::table('fincode_cards')->count());
        self::assertSame($missing ? null : $transactionId, DB::table('fincode_card_registration_intents')
            ->where('public_id', $started['id'])->value('provider_transaction_id'));
    }

    public static function registrationTransactionReferences(): array
    {
        return [
            'string' => ['txn_valid_123', false, true],
            'null' => [null, false, true],
            'missing' => [null, true, true],
            'empty' => ['', false, false],
            'integer' => [123, false, false],
            'boolean' => [false, false, false],
            'float' => [1.5, false, false],
            'array' => [['txn'], false, false],
            'object' => [(object) ['id' => 'txn'], false, false],
            'format' => ['txn invalid!', false, false],
            'length' => [str_repeat('x', 129), false, false],
        ];
    }

    public function test_registration_null_or_missing_redelivery_preserves_transaction_metadata(): void
    {
        $this->fakeFincode();
        $user = $this->user('metadata-retained');
        $started = app(V2FincodeCardService::class)->startRegistration($user, 'tok_retained', 'retained');
        $payload = json_decode($this->registrationWebhook($started['id']), true, flags: JSON_THROW_ON_ERROR);
        $transactionId = $payload['transaction_id'];
        $webhooks = app(V2FincodeWebhookService::class);
        $webhooks->process(json_encode($payload, JSON_THROW_ON_ERROR), 'test-webhook-signature');
        $payload['transaction_id'] = null;
        $webhooks->process(json_encode($payload, JSON_THROW_ON_ERROR), 'test-webhook-signature');
        unset($payload['transaction_id']);
        $webhooks->process(json_encode($payload, JSON_THROW_ON_ERROR), 'test-webhook-signature');
        self::assertSame($transactionId, DB::table('fincode_card_registration_intents')
            ->where('public_id', $started['id'])->value('provider_transaction_id'));
        $this->assertRegistrationCompletedOnce($started['id']);
    }

    #[DataProvider('registrationWebhookStatuses')]
    public function test_registration_webhook_status_is_only_a_reconciliation_trigger(mixed $status, bool $valid): void
    {
        $this->fakeFincode(registration: ['canonical_tds2_status' => 'AUTHENTICATING']);
        $user = $this->user('webhook-status');
        $started = app(V2FincodeCardService::class)->startRegistration($user, 'tok_status', 'status');
        $payload = json_decode($this->registrationWebhook($started['id'], ['status' => $status]), true);
        $response = $this->withHeader('Fincode-Signature', 'test-webhook-signature')
            ->postJson('/webhooks/v2/fincode', $payload);
        $response->assertStatus($valid ? 200 : 422);
        self::assertSame(0, DB::table('fincode_cards')->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/cards/'));
        if ($valid) {
            $this->fakeFincode();
            app(V2FincodeCardService::class)->reconcileRegistration($user, $started['id']);
            $this->assertRegistrationCompletedOnce($started['id']);
        }
    }

    public static function registrationWebhookStatuses(): array
    {
        return [
            'authenticated' => ['AUTHENTICATED', true],
            'check' => ['CHECK', true],
            'historical-authenticating' => ['AUTHENTICATING', true],
            'historical-challenge' => ['CHALLENGE', true],
            'unknown' => ['UNKNOWN', false],
            'null' => [null, false],
            'integer' => [1, false],
            'array' => [[], false],
        ];
    }

    #[DataProvider('registrationWebhookCorrelations')]
    public function test_registration_webhook_security_boundaries_are_preserved(
        array $overrides,
        string $signature,
        int $httpStatus
    ): void {
        $this->fakeFincode();
        $user = $this->user('webhook-correlation');
        $started = app(V2FincodeCardService::class)->startRegistration($user, 'tok_correlation', 'correlation');
        $payload = json_decode($this->registrationWebhook($started['id'], $overrides), true);
        $this->withHeader('Fincode-Signature', $signature)->postJson('/webhooks/v2/fincode', $payload)
            ->assertStatus($httpStatus);
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertNull(DB::table('fincode_card_registration_intents')->value('webhook_received_at'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public static function registrationWebhookCorrelations(): array
    {
        return [
            'signature' => [[], 'invalid-signature', 401],
            'unsupported-event' => [['event' => 'customers.unknown'], 'test-webhook-signature', 200],
            'access' => [['access_id' => 'a_foreign'], 'test-webhook-signature', 422],
            'customer' => [['customer_id' => 'c_foreign'], 'test-webhook-signature', 422],
            'client' => [['client_field_1' => '00000000-0000-4000-8000-000000000001'], 'test-webhook-signature', 422],
        ];
    }

    #[DataProvider('registrationRecoveryTriggers')]
    public function test_registration_recovers_without_webhook_authority_and_handles_late_notification(string $trigger): void
    {
        $this->fakeFincode();
        $user = $this->user('canonical-recovery');
        $cards = app(V2FincodeCardService::class);
        $started = $cards->startRegistration($user, 'tok_recovery', 'recovery');
        if ($trigger === 'return') {
            $this->post('/api/v2/payment-card-registration-returns/fincode/normal?rid='.$started['id'], [
                'provider_card_id' => 'untrusted_browser_reference',
            ])->assertStatus(303);
        } elseif ($trigger === 'worker') {
            self::assertSame(1, app(V2FincodeReconciliationService::class)->reconcileCardRegistrations(10)['processed']);
        } elseif (in_array($trigger, ['webhook-without-card', 'webhook-missing-card'], true)) {
            $payload = json_decode($this->registrationWebhook($started['id'], [
                'card_id' => null, 'transaction_id' => null, 'status' => 'CHECK',
            ]), true, flags: JSON_THROW_ON_ERROR);
            if ($trigger === 'webhook-missing-card') {
                unset($payload['card_id']);
            }
            app(V2FincodeWebhookService::class)->process(
                json_encode($payload, JSON_THROW_ON_ERROR),
                'test-webhook-signature'
            );
        } else {
            $cards->reconcileRegistration($user, $started['id']);
        }
        $intent = DB::table('fincode_card_registration_intents')->where('public_id', $started['id'])->firstOrFail();
        self::assertSame($intent->provider_payment_method_id, $intent->provider_card_id);
        if (! str_starts_with($trigger, 'webhook-')) {
            self::assertNull($intent->webhook_received_at);
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/cards/'.$intent->provider_card_id));
        $calls = Http::recorded()->count();
        $cards->reconcileRegistration($user, $started['id']);
        $this->post('/api/v2/payment-card-registration-returns/fincode/normal?rid='.$started['id'])->assertStatus(303);
        app(V2FincodeWebhookService::class)->process($this->registrationWebhook($started['id']), 'test-webhook-signature');
        self::assertSame($calls, Http::recorded()->count());
        $this->assertRegistrationCompletedOnce($started['id']);
        try {
            app(V2FincodeWebhookService::class)->process(
                $this->registrationWebhook($started['id'], ['card_id' => 'cs_'.str_repeat('z', 22)]),
                'test-webhook-signature'
            );
            self::fail('Conflicting late webhook must not overwrite the canonical card.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_OWNERSHIP_INVALID', $exception->errorCode);
        }
        self::assertSame($intent->provider_card_id, DB::table('fincode_card_registration_intents')
            ->where('id', $intent->id)->value('provider_card_id'));
        self::assertSame(2, $cards->cards($user)['limits']['registration_remaining']);
    }

    public static function registrationRecoveryTriggers(): array
    {
        return array_map(static fn (string $trigger): array => [$trigger], [
            'return', 'worker', 'reconcile', 'webhook-without-card', 'webhook-missing-card',
        ]);
    }

    #[DataProvider('registrationRecoveryFailures')]
    public function test_registration_recovery_fails_closed_and_network_errors_remain_retryable(
        array $providerOverrides,
        string $errorCode,
        string $state
    ): void {
        $this->fakeFincode(registration: $providerOverrides);
        $user = $this->user('recovery-failure');
        $cards = app(V2FincodeCardService::class);
        $started = $cards->startRegistration($user, 'tok_failure', 'failure');
        try {
            $cards->reconcileRegistration($user, $started['id']);
            self::assertSame('', $errorCode, 'Expected canonical validation to fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
        $intent = DB::table('fincode_card_registration_intents')->where('public_id', $started['id'])->firstOrFail();
        self::assertSame($state, $intent->status);
        self::assertNull($intent->provider_card_id);
        self::assertNull($intent->webhook_received_at);
        self::assertNull($intent->completed_at);
        self::assertSame(0, DB::table('fincode_cards')->count());
        if ($state === 'pending' || $state === 'requires_action') {
            $this->fakeFincode();
            $cards->reconcileRegistration($user, $started['id']);
            $this->assertRegistrationCompletedOnce($started['id']);
        } else {
            self::assertSame(3, $cards->cards($user)['limits']['registration_remaining']);
            $this->fakeFincode();
            $cards->reconcileRegistration($user, $started['id']);
            Http::assertNothingSent();
            self::assertSame(0, DB::table('fincode_cards')->count());
        }
    }

    public static function registrationRecoveryFailures(): array
    {
        return [
            'method-timeout' => [['canonical_timeout' => true], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'method-error' => [['canonical_http_status' => 503], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'card-timeout' => [['card_timeout' => true], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'card-error' => [['card_http_status' => 503], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'card-not-found' => [['card_http_status' => 404], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'method-id' => [['canonical_payment_method_id' => 'cs_'.str_repeat('z', 22)], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'method-format' => [['canonical_payment_method_id' => 'invalid/reference'], 'CARD_REGISTRATION_UNAVAILABLE', 'pending'],
            'customer' => [['canonical_customer_id' => 'c_foreign'], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'access' => [['canonical_access_id' => 'a_foreign'], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'pay-type' => [['canonical_pay_type' => 'Directdebit'], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'card-id' => [['card_id' => 'cs_'.str_repeat('z', 22)], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'card-customer' => [['card_customer_id' => 'c_foreign'], 'CARD_REGISTRATION_OWNERSHIP_INVALID', 'failed'],
            'tds-type' => [['canonical_tds_type' => '1'], 'CARD_REGISTRATION_FAILED', 'failed'],
            'tds2-type' => [['canonical_tds2_type' => '3'], 'CARD_REGISTRATION_FAILED', 'failed'],
            'method-failed' => [['canonical_status' => 'FAILED'], 'CARD_REGISTRATION_FAILED', 'failed'],
            'method-incomplete' => [['canonical_status' => 'AWAITING_CUSTOMER_ACTION'], '', 'requires_action'],
            '3ds-incomplete' => [['canonical_tds2_status' => 'CHALLENGE'], '', 'pending'],
        ];
    }

    public function test_conflicting_webhook_card_reference_never_becomes_verified(): void
    {
        $this->fakeFincode();
        $user = $this->user('conflicting-card');
        $started = app(V2FincodeCardService::class)->startRegistration($user, 'tok_conflict', 'conflict');
        $conflict = 'cs_'.str_repeat('z', 22);
        try {
            app(V2FincodeWebhookService::class)->process(
                $this->registrationWebhook($started['id'], ['card_id' => $conflict]),
                'test-webhook-signature'
            );
            self::fail('Conflicting webhook card must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_OWNERSHIP_INVALID', $exception->errorCode);
        }
        self::assertSame($conflict, DB::table('fincode_card_registration_intents')->value('provider_card_id'));
        self::assertSame('failed', DB::table('fincode_card_registration_intents')->value('status'));
        self::assertSame(0, DB::table('fincode_cards')->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/cards/'));
    }

    public function test_non_card_method_identifier_is_not_guessed_or_transformed_into_a_card_reference(): void
    {
        foreach (['pm_'.str_repeat('a', 22), 'cs_short'] as $reference) {
            $this->fakeFincode();
            $user = $this->user('ambiguous-reference');
            $cards = app(V2FincodeCardService::class);
            $started = $cards->startRegistration($user, 'tok_ambiguous', 'ambiguous-'.$reference);
            $intent = DB::table('fincode_card_registration_intents')->where('public_id', $started['id'])->firstOrFail();
            DB::table('fincode_card_registration_intents')->where('id', $intent->id)
                ->update(['provider_payment_method_id' => $reference]);
            $this->fakeFincode(registration: ['canonical_access_id' => $intent->provider_access_id]);
            try {
                $cards->reconcileRegistration($user, $started['id']);
                self::fail('An ambiguous Card identifier must not be transformed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('CARD_REGISTRATION_UNAVAILABLE', $exception->errorCode);
            }
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/cards/'));
            self::assertNull(DB::table('fincode_card_registration_intents')->where('id', $intent->id)->value('provider_card_id'));
            self::assertSame(0, DB::table('fincode_cards')->where('registration_intent_id', $intent->id)->count());
        }
    }

    private function assertRegistrationCompletedOnce(string $publicId): void
    {
        $intent = DB::table('fincode_card_registration_intents')->where('public_id', $publicId)->firstOrFail();
        self::assertSame('completed', $intent->status);
        self::assertSame(1, DB::table('fincode_cards')->where('registration_intent_id', $intent->id)->count());
        self::assertSame(1, DB::table('fincode_cards')->where('registration_intent_id', $intent->id)
            ->where('registration_assurance', 'three_d_secure_2')->whereNotNull('registration_verified_at')->count());
        self::assertSame(1, DB::table('audit_logs')->where('target_public_id', $publicId)
            ->where('action_code', 'payment.card_registration.completed')->count());
        self::assertSame(0, DB::table('payments')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
    }

    public function test_card_registration_marks_only_the_first_saved_card_as_provider_default(): void
    {
        $this->fakeFincode();
        $user = $this->user('card-default-contract');
        $this->completeCanonicalRegistration($user, 'card-default-first');

        $second = app(V2FincodeCardService::class)->startRegistration(
            $user,
            'tok_card_default_second',
            'card-default-second'
        );

        Http::assertSent(function (Request $request) use ($second): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/payment_methods')
                && ($request->data()['client_field_1'] ?? null) === $second['id']
                && ($request->data()['default_flag'] ?? null) === '0';
        });
        self::assertSame(1, DB::table('fincode_cards')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
    }

    public function test_card_registration_preserves_sanitized_structured_provider_rejection(): void
    {
        $providerErrorCode = 'EC013136002';
        $providerPrivateMarker = 'provider-private-detail-must-not-persist';
        $cardToken = 'tok_structured_rejection_must_not_persist';
        $this->fakeFincode(registration: [
            'create_http_status' => 400,
            'create_response' => [
                'errors' => [[
                    'error_code' => $providerErrorCode,
                    'error_message' => $providerPrivateMarker,
                ]],
            ],
        ]);
        $user = $this->user('registration-structured-rejection');

        try {
            app(V2FincodeCardService::class)->startRegistration(
                $user,
                $cardToken,
                'registration-structured-rejection'
            );
            self::fail('A Provider rejection must fail the card registration.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_FAILED', $exception->errorCode);
            self::assertSame(422, $exception->status);
            self::assertStringNotContainsString($providerErrorCode, $exception->getMessage());
            self::assertStringNotContainsString($providerPrivateMarker, $exception->getMessage());
        }

        $intent = DB::table('fincode_card_registration_intents')
            ->where('user_id', $user->id)
            ->firstOrFail();
        self::assertSame('failed', $intent->status);
        self::assertSame(
            'FINCODE_PROVIDER_REJECTED|HTTP_400|'.$providerErrorCode,
            $intent->last_error_code
        );
        self::assertNull($intent->provider_payment_method_id);
        self::assertNull($intent->provider_access_id);
        self::assertNull($intent->redirect_url_ciphertext);
        $storedEvidence = json_encode((array) $intent, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($providerPrivateMarker, $storedEvidence);
        self::assertStringNotContainsString($cardToken, $storedEvidence);
        self::assertSame(0, DB::table('fincode_cards')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_card_registration_rejection_with_malformed_or_empty_body_fails_closed(): void
    {
        $cases = [
            'malformed' => [
                'errors' => [[
                    'error_code' => "EC013136002\nunsafe",
                    'error_message' => 'malformed-provider-detail-must-not-persist',
                ]],
            ],
            'empty' => '',
        ];

        foreach ($cases as $name => $providerResponse) {
            $this->fakeFincode(registration: [
                'create_http_status' => 400,
                'create_response' => $providerResponse,
            ]);
            $user = $this->user('registration-'.$name.'-rejection');
            $cardToken = 'tok_'.$name.'_rejection_must_not_persist';

            try {
                app(V2FincodeCardService::class)->startRegistration(
                    $user,
                    $cardToken,
                    'registration-'.$name.'-rejection'
                );
                self::fail('Malformed Provider rejection evidence must fail closed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('CARD_REGISTRATION_FAILED', $exception->errorCode);
                self::assertSame(422, $exception->status);
            }

            $intent = DB::table('fincode_card_registration_intents')
                ->where('user_id', $user->id)
                ->firstOrFail();
            self::assertSame('failed', $intent->status);
            self::assertSame('FINCODE_PROVIDER_REJECTED|HTTP_400', $intent->last_error_code);
            $storedEvidence = json_encode((array) $intent, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($cardToken, $storedEvidence);
            self::assertStringNotContainsString('malformed-provider-detail-must-not-persist', $storedEvidence);
            self::assertSame(0, DB::table('fincode_cards')->where('user_id', $user->id)->count());
            self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
        }
        self::assertSame(0, DB::table('payment_point_grants')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_registration_terminal_states_release_capacity_without_business_mutation(): void
    {
        $this->fakeFincode();
        $user = $this->user('registration-capacity');
        $cards = app(V2FincodeCardService::class);
        $started = [];
        foreach (range(1, 3) as $index) {
            $started[] = $cards->startRegistration(
                $user,
                'tok_registration_capacity_'.$index,
                'registration-capacity-'.$index
            );
        }
        $capacity = $cards->cards($user)['limits'];
        self::assertSame(3, $capacity['remaining']);
        self::assertSame(0, $capacity['registration_remaining']);
        self::assertSame($started[0]['expires_at'], $capacity['next_capacity_at']);

        $canceled = $cards->cancelRegistration($user, $started[0]['id']);
        self::assertSame('canceled', $canceled['status']);
        self::assertSame(1, $cards->cards($user)['limits']['registration_remaining']);

        $this->fakeFincode(registration: ['canonical_status' => 'FAILED']);
        try {
            app(V2FincodeWebhookService::class)->process(
                $this->registrationWebhook($started[1]['id'], [
                    'customer_id' => null,
                    'card_id' => null,
                ]),
                'test-webhook-signature'
            );
            self::fail('A canonical Provider registration failure must fail closed.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_FAILED', $exception->errorCode);
        }
        self::assertSame('failed', DB::table('fincode_card_registration_intents')
            ->where('public_id', $started[1]['id'])->value('status'));
        self::assertSame(2, $cards->cards($user)['limits']['registration_remaining']);

        DB::table('fincode_card_registration_intents')
            ->where('public_id', $started[2]['id'])
            ->update(V2TimestampFixture::attributes(['expires_at' => now()->subSecond()]));
        self::assertSame(3, $cards->cards($user)['limits']['registration_remaining']);
        self::assertSame('requires_action', DB::table('fincode_card_registration_intents')
            ->where('public_id', $started[2]['id'])->value('status'));
        self::assertSame('expired', $cards->cancelRegistration($user, $started[2]['id'])['status']);
        $released = $cards->cards($user)['limits'];
        self::assertSame(3, $released['registration_remaining']);
        self::assertNull($released['next_capacity_at']);
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertSame(0, DB::table('payments')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
        self::assertSame(0, DB::table('mail_deliveries')->count());
    }

    public function test_registration_fails_closed_for_unsupported_unknown_and_unavailable_provider_states(): void
    {
        $cases = [
            'unsupported' => [
                'fake' => ['canonical_tds2_type' => '3'],
                'expected_error' => 'CARD_REGISTRATION_FAILED',
                'expected_status' => 'failed',
                'expected_capacity' => 3,
            ],
            'unknown' => [
                'fake' => ['canonical_status' => 'UNKNOWN'],
                'expected_error' => 'CARD_REGISTRATION_UNAVAILABLE',
                'expected_status' => 'pending',
                'expected_capacity' => 2,
            ],
            'unavailable' => [
                'fake' => ['canonical_http_status' => 503],
                'expected_error' => 'CARD_REGISTRATION_UNAVAILABLE',
                'expected_status' => 'pending',
                'expected_capacity' => 2,
            ],
        ];

        foreach ($cases as $name => $case) {
            $this->fakeFincode(registration: $case['fake']);
            $user = $this->user('registration-'.$name);
            $cards = app(V2FincodeCardService::class);
            $started = $cards->startRegistration(
                $user,
                'tok_registration_'.$name,
                'registration-'.$name
            );
            try {
                app(V2FincodeWebhookService::class)->process(
                    $this->registrationWebhook($started['id']),
                    'test-webhook-signature'
                );
                self::fail('A non-authoritative Provider outcome must not create a card.');
            } catch (V2FincodeException $exception) {
                self::assertSame($case['expected_error'], $exception->errorCode);
            }
            self::assertSame($case['expected_status'], DB::table('fincode_card_registration_intents')
                ->where('public_id', $started['id'])->value('status'));
            self::assertSame($case['expected_capacity'], $cards->cards($user)['limits']['registration_remaining']);
            self::assertSame(0, DB::table('fincode_cards')->where('user_id', $user->id)->count());
        }

        $this->fakeFincode(registration: ['canonical_tds2_status' => null]);
        $user = $this->user('registration-assurance-pending');
        $cards = app(V2FincodeCardService::class);
        $started = $cards->startRegistration(
            $user,
            'tok_registration_assurance_pending',
            'registration-assurance-pending'
        );
        self::assertSame(
            ['status' => 'processed'],
            app(V2FincodeWebhookService::class)->process(
                $this->registrationWebhook($started['id']),
                'test-webhook-signature'
            )
        );
        self::assertSame('pending', $cards->registration($user, $started['id'])['status']);
        self::assertSame(2, $cards->cards($user)['limits']['registration_remaining']);
        self::assertSame(0, DB::table('fincode_cards')->where('user_id', $user->id)->count());
    }

    public function test_registration_rejects_provider_payment_method_customer_and_card_ownership_mismatch(): void
    {
        $cases = [
            'payment-method' => ['canonical_payment_method_id' => 'pm_foreign'],
            'customer' => ['canonical_customer_id' => 'c_foreign'],
            'card' => ['card_customer_id' => 'c_foreign'],
        ];

        foreach ($cases as $name => $providerOverrides) {
            $this->fakeFincode(registration: $providerOverrides);
            $user = $this->user('registration-ownership-'.$name);
            $cards = app(V2FincodeCardService::class);
            $started = $cards->startRegistration(
                $user,
                'tok_ownership_'.$name,
                'registration-ownership-'.$name
            );
            try {
                app(V2FincodeWebhookService::class)->process(
                    $this->registrationWebhook($started['id']),
                    'test-webhook-signature'
                );
                self::fail('Provider ownership mismatch must fail closed.');
            } catch (V2FincodeException $exception) {
                self::assertSame('CARD_REGISTRATION_OWNERSHIP_INVALID', $exception->errorCode);
            }
            $intent = DB::table('fincode_card_registration_intents')
                ->where('public_id', $started['id'])->firstOrFail();
            self::assertSame('failed', $intent->status);
            self::assertSame('CARD_REGISTRATION_OWNERSHIP_INVALID', $intent->last_error_code);
            self::assertSame(3, $cards->cards($user)['limits']['registration_remaining']);
            self::assertSame(0, DB::table('fincode_cards')->where('user_id', $user->id)->count());
        }
    }

    public function test_legacy_non_three_d_secure_save_paths_fail_closed_but_new_card_payment_remains_available(): void
    {
        $this->fakeFincode();
        $user = $this->user('legacy-card');
        $cards = app(V2FincodeCardService::class);
        $legacy = $cards->reserveRegistration($user, 'legacy-registration');
        $providerCalls = Http::recorded()->count();
        try {
            $cards->completeRegistration($user, $legacy['id'], 'browser_card_id');
            self::fail('Legacy standalone completion must not save a card.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_3DS_REQUIRED', $exception->errorCode);
        }
        try {
            app(V2FincodePaymentService::class)->start(
                $user,
                $this->plan()->public_id,
                'credit_card',
                'legacy-save-payment',
                ['source' => 'new', 'save' => true]
            );
            self::fail('Legacy save=true payment must not save or start a payment.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_REGISTRATION_3DS_REQUIRED', $exception->errorCode);
        }
        self::assertSame($providerCalls, Http::recorded()->count());
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertSame(0, DB::table('payments')->count());

        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'new-card-payment-without-save',
            ['source' => 'new', 'save' => false]
        );
        self::assertSame('fincode_card_component', $payment['next_action']['type']);
        self::assertSame(1, DB::table('payments')->count());
    }

    public function test_existing_unverified_card_is_not_backfilled_or_usable_for_payment(): void
    {
        $this->fakeFincode();
        $user = $this->user('unverified-card');
        $cards = app(V2FincodeCardService::class);
        $legacy = $cards->reserveRegistration($user, 'unverified-card-customer');
        DB::table('fincode_card_registration_intents')
            ->where('public_id', $legacy['id'])
            ->update(['status' => 'canceled']);
        $customerId = DB::table('fincode_customers')->where('user_id', $user->id)->value('id');
        $cardId = (string) Str::uuid7();
        DB::table('fincode_cards')->insert(V2TimestampFixture::attributes([
            'public_id' => $cardId,
            'user_id' => $user->id,
            'fincode_customer_id' => $customerId,
            'provider_card_id' => 'legacy_unverified_card',
            'brand' => 'VISA',
            'last4' => '4242',
            'expire_month' => 12,
            'expire_year' => 2030,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $collection = $cards->cards($user);
        self::assertSame('unverified', $collection['data'][0]['verification_status']);
        self::assertFalse($collection['data'][0]['can_pay']);
        self::assertSame(2, $collection['limits']['remaining']);
        self::assertSame(3, $collection['limits']['registration_remaining']);
        $stored = DB::table('fincode_cards')->where('public_id', $cardId)->firstOrFail();
        self::assertNull($stored->registration_intent_id);
        self::assertNull($stored->provider_payment_method_id);
        self::assertNull($stored->registration_assurance);
        self::assertNull($stored->registration_verified_at);
        try {
            app(V2FincodePaymentService::class)->start(
                $user,
                $this->plan()->public_id,
                'credit_card',
                'unverified-card-payment',
                ['source' => 'saved', 'card_id' => $cardId]
            );
            self::fail('An unverified legacy card must not be usable for payment.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_NOT_FOUND', $exception->errorCode);
        }
        self::assertSame(0, DB::table('payments')->count());
    }

    public function test_card_registration_enforces_three_ownership_and_expiry(): void
    {
        $this->fakeFincode();
        $user = $this->user('cards');
        $other = $this->user('other-cards');
        $cards = app(V2FincodeCardService::class);
        $registered = [];
        foreach (range(1, 3) as $index) {
            $registered[] = $this->completeCanonicalRegistration(
                $user,
                'card-intent-'.$index
            );
        }
        $collection = $cards->cards($user);
        self::assertSame(3, count($collection['data']));
        self::assertSame(0, $collection['limits']['remaining']);
        self::assertSame(0, $collection['limits']['registration_remaining']);
        try {
            $cards->startRegistration($user, 'tok_fourth_card', 'card-intent-four');
            self::fail('A fourth card must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_LIMIT_REACHED', $exception->errorCode);
        }
        try {
            $cards->ownedUsableCard($other, $registered[0]['saved_card_id']);
            self::fail('Card ownership mismatch must fail.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_NOT_FOUND', $exception->errorCode);
        }
        DB::table('fincode_cards')->where('public_id', $registered[0]['saved_card_id'])->update([
            'expire_month' => 1,
            'expire_year' => 2020,
        ]);
        try {
            $cards->ownedUsableCard($user, $registered[0]['saved_card_id']);
            self::fail('An expired card must not pay.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_EXPIRED', $exception->errorCode);
        }
        $cards->delete($user, $registered[1]['saved_card_id']);
        self::assertSame(2, count($cards->cards($user)['data']));
    }

    public function test_saved_card_payment_requires_three_d_secure_parameters(): void
    {
        $this->fakeFincode();
        $user = $this->user('saved-card');
        $card = $this->completeCanonicalRegistration($user, 'saved-card-intent');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'saved-card-payment',
            ['source' => 'saved', 'card_id' => $card['saved_card_id']]
        );
        self::assertSame('three_d_secure', $payment['next_action']['type']);
        self::assertSame('https://pay.test.fincode.jp/redirect', $payment['next_action']['url']);
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
                && isset($data['return_url'], $data['return_url_on_failure'])
                && ! array_key_exists('tds2_ret_url', $data)
                && ! array_key_exists('card_no', $data)
                && ! array_key_exists('security_code', $data);
        });
    }

    public function test_bodyless_card_delete_http_soft_deletes_only_owned_card_and_is_idempotent(): void
    {
        $this->fakeFincode();
        $owner = $this->user('delete-http');
        $card = $this->completeCanonicalRegistration($owner, 'delete-http-card');
        $otherCard = $this->completeCanonicalRegistration($owner, 'delete-http-other');
        $otherBefore = DB::table('fincode_cards')->where('public_id', $otherCard['saved_card_id'])->first();
        Auth::guard('v2_user')->setUser($owner);

        $this->bodylessCardDelete($card['saved_card_id'])->assertNoContent();
        self::assertNotNull(DB::table('fincode_cards')->where('public_id', $card['saved_card_id'])->value('deleted_at'));
        self::assertEquals($otherBefore, DB::table('fincode_cards')->where('public_id', $otherCard['saved_card_id'])->first());
        $remaining = app(V2FincodeCardService::class)->cards($owner)['data'];
        self::assertCount(1, $remaining);
        self::assertSame($otherCard['saved_card_id'], $remaining[0]['id']);
        try {
            app(V2FincodeCardService::class)->ownedUsableCard($owner, $card['saved_card_id']);
            self::fail('Deleted card must not remain selectable.');
        } catch (V2FincodeException $exception) {
            self::assertSame('CARD_NOT_FOUND', $exception->errorCode);
        }
        $this->bodylessCardDelete($card['saved_card_id'])->assertNoContent();
        self::assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'));
    }

    public function test_bodyless_card_delete_http_keeps_authentication_ownership_and_missing_card_rejections(): void
    {
        $this->fakeFincode();
        $owner = $this->user('delete-owner');
        $card = $this->completeCanonicalRegistration($owner, 'delete-owner-card');
        $before = DB::table('fincode_cards')->get()->all();
        Auth::forgetGuards();
        $this->bodylessCardDelete($card['saved_card_id'])->assertStatus(401);
        Auth::guard('v2_user')->setUser($this->user('delete-other'));
        $this->bodylessCardDelete($card['saved_card_id'])
            ->assertStatus(404)->assertJsonPath('code', 'CARD_NOT_FOUND');
        Auth::guard('v2_user')->setUser($owner);
        $this->bodylessCardDelete((string) Str::uuid())
            ->assertStatus(404)->assertJsonPath('code', 'CARD_NOT_FOUND');
        self::assertCount(0, Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'));
        self::assertEquals($before, DB::table('fincode_cards')->get()->all());
    }

    public function test_bodyless_card_delete_http_security_rejection_does_not_call_provider_or_change_card(): void
    {
        $this->fakeFincode();
        $owner = $this->user('delete-security');
        $card = $this->completeCanonicalRegistration($owner, 'delete-security-card');
        Auth::guard('v2_user')->setUser($owner);
        $before = DB::table('fincode_cards')->get()->all();
        $providerCalls = Http::recorded()->count();
        foreach ([
            ['HTTP_ORIGIN' => 'https://attacker.example.test'],
            ['HTTP_ORIGIN' => ''],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            ['HTTP_X_XSRF_TOKEN' => ''],
            ['HTTP_X_XSRF_TOKEN' => str_repeat('b', 64)],
        ] as $headers) {
            $this->bodylessCardDelete($card['saved_card_id'], $headers)
                ->assertStatus(403)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        }
        self::assertSame($providerCalls, Http::recorded()->count());
        self::assertEquals($before, DB::table('fincode_cards')->get()->all());
    }

    public function test_bodyless_card_delete_http_provider_failure_preserves_local_card(): void
    {
        $this->fakeFincode();
        $owner = $this->user('delete-failure');
        $card = $this->completeCanonicalRegistration($owner, 'delete-failure-card');
        Auth::guard('v2_user')->setUser($owner);
        $before = DB::table('fincode_cards')->get()->all();
        Http::swap(new Factory());
        Http::fake(fn (Request $request) => Http::response([], 500));

        $this->bodylessCardDelete($card['saved_card_id'])
            ->assertStatus(503)->assertJsonPath('code', 'FINCODE_PROVIDER_UNAVAILABLE');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
        self::assertEquals($before, DB::table('fincode_cards')->get()->all());
    }

    private function bodylessCardDelete(string $cardId, array $headers = []): \Illuminate\Testing\TestResponse
    {
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $csrf = str_repeat('a', 64);
        $request = \Illuminate\Http\Request::create('/api/v2/me/payment-cards/'.$cardId, 'DELETE', [], [
            '__Host-oripa_user_xsrf' => $csrf,
        ], [], [
            'HTTPS' => 'on',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'https://storefront.example.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_XSRF_TOKEN' => $csrf,
            ...$headers,
        ], '');
        $request->headers->remove('Content-Type');
        $request->server->remove('CONTENT_TYPE');
        self::assertFalse($request->headers->has('Content-Type'));
        self::assertSame('', $request->getContent());
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $this->createTestResponse($response, $request);
    }

    #[DataProvider('savedCardRedirectResponses')]
    public function test_saved_card_automatic_three_d_secure_uses_only_valid_redirect_url(
        array $executionResponse,
        ?string $expectedUrl
    ): void {
        $this->fakeFincode(paymentExecution: $executionResponse);
        $user = $this->user('automatic-redirect');
        $card = $this->completeCanonicalRegistration($user, 'automatic-redirect-card');
        $payment = app(V2FincodePaymentService::class)->start(
            $user,
            $this->plan()->public_id,
            'credit_card',
            'automatic-redirect-payment',
            ['source' => 'saved', 'card_id' => $card['saved_card_id']]
        );
        $stored = DB::table('payments')->where('public_id', $payment['id'])->firstOrFail();
        $attempt = DB::table('fincode_payment_attempts')->where('payment_id', $stored->id)->firstOrFail();
        self::assertSame('requires_action', $payment['status']);
        self::assertSame('requires_action', $attempt->status);
        if ($expectedUrl === null) {
            self::assertNull($payment['next_action']);
            self::assertNull($attempt->redirect_url_ciphertext);
        } else {
            self::assertSame(['type' => 'three_d_secure', 'url' => $expectedUrl], $payment['next_action']);
            self::assertSame($expectedUrl, Crypt::decryptString($attempt->redirect_url_ciphertext));
        }
        self::assertNull($stored->succeeded_at);
        self::assertNull($stored->points_granted_at);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $stored->id)->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/secure2/'));
    }

    public static function savedCardRedirectResponses(): array
    {
        $redirectUrl = 'https://pay.test.fincode.jp/redirect';
        $acsUrl = 'https://acs.example.test/manual';

        return [
            'automatic URL' => [['redirect_url' => $redirectUrl], $redirectUrl],
            'automatic URL wins over manual URL' => [['redirect_url' => $redirectUrl, 'acs_url' => $acsUrl], $redirectUrl],
            'missing' => [[], null],
            'manual URL is not a fallback' => [['acs_url' => $acsUrl], null],
            'null' => [['redirect_url' => null, 'acs_url' => $acsUrl], null],
            'empty' => [['redirect_url' => '', 'acs_url' => $acsUrl], null],
            'malformed' => [['redirect_url' => 'not-a-url', 'acs_url' => $acsUrl], null],
            'relative' => [['redirect_url' => '/redirect'], null],
            'http' => [['redirect_url' => 'http://pay.test.fincode.jp/redirect', 'acs_url' => $acsUrl], null],
            'javascript' => [['redirect_url' => 'javascript:alert(1)'], null],
            'integer' => [['redirect_url' => 123], null],
            'boolean' => [['redirect_url' => true], null],
            'array' => [['redirect_url' => [$redirectUrl]], null],
            'object' => [['redirect_url' => (object) ['url' => $redirectUrl]], null],
        ];
    }

    public function test_unsaved_card_payment_keeps_component_three_d_secure_without_registration(): void
    {
        $this->fakeFincode();
        $payment = app(V2FincodePaymentService::class)->start(
            $this->user('unsaved-redirect-regression'),
            $this->plan()->public_id,
            'credit_card',
            'unsaved-redirect-payment',
            ['source' => 'new', 'save' => false]
        );
        self::assertSame('requires_action', $payment['status']);
        self::assertSame('fincode_card_component', $payment['next_action']['type']);
        self::assertSame('2', $payment['next_action']['tds_type']);
        self::assertSame(0, DB::table('fincode_card_registration_intents')->count());
        self::assertSame(0, DB::table('fincode_cards')->count());
        self::assertSame(0, DB::table('payment_point_grants')->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/customers')
            || str_contains($request->url(), '/secure2/'));
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

    /** @return array<string, mixed> */
    private function completeCanonicalRegistration(
        User $user,
        string $idempotencyKey
    ): array {
        $cards = app(V2FincodeCardService::class);
        $started = $cards->startRegistration($user, 'tok_'.$idempotencyKey, $idempotencyKey);
        $payload = $this->registrationWebhook($started['id']);
        app(V2FincodeWebhookService::class)->process($payload, 'test-webhook-signature');

        return $cards->registration($user, $started['id']);
    }

    /** @param array<string, mixed> $overrides */
    private function registrationWebhook(
        string $registrationPublicId,
        array $overrides = []
    ): string {
        $intent = DB::table('fincode_card_registration_intents as intent')
            ->join('fincode_customers as customer', 'customer.id', '=', 'intent.fincode_customer_id')
            ->where('intent.public_id', $registrationPublicId)
            ->select(['intent.*', 'customer.provider_customer_id'])
            ->firstOrFail();

        return json_encode([
            'event' => 'customers.payment_methods.updated',
            'pay_type' => 'Card',
            'customer_id' => $intent->provider_customer_id,
            'card_id' => $intent->provider_payment_method_id,
            'card_status' => 'ACTIVATED',
            'status' => 'AUTHENTICATED',
            'access_id' => $intent->provider_access_id,
            'transaction_id' => 'txn_'.substr(hash('sha256', $registrationPublicId), 0, 24),
            'client_field_1' => $intent->public_id,
            'error_code' => null,
            ...$overrides,
        ], JSON_THROW_ON_ERROR);
    }

    private function fakeFincode(
        string $retrievedStatus = 'AWAITING_CUSTOMER_PAYMENT',
        string $retrievedAmount = '1000',
        ?string $retrievedErrorCode = null,
        array $registration = [],
        ?array $paymentExecution = null
    ): void
    {
        $registration = [
            'create_http_status' => 200,
            'create_response' => [],
            'create_status' => 'AWAITING_CUSTOMER_ACTION',
            'create_tds_type' => '2',
            'create_tds2_type' => '2',
            'create_tds2_status' => 'AUTHENTICATING',
            'canonical_http_status' => 200,
            'canonical_status' => 'ACTIVATED',
            'canonical_tds_type' => '2',
            'canonical_tds2_type' => '2',
            'canonical_tds2_status' => 'AUTHENTICATED',
            'canonical_customer_id' => null,
            'canonical_payment_method_id' => null,
            'card_customer_id' => null,
            ...$registration,
        ];
        Http::swap(new Factory());
        Http::fake(function (Request $request) use (
            $retrievedStatus,
            $retrievedAmount,
            $retrievedErrorCode,
            $registration,
            $paymentExecution
        ) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/customers')) {
                return Http::response(['id' => $request->data()['id']], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/payment_methods')) {
                if ($registration['create_http_status'] !== 200) {
                    return Http::response(
                        $registration['create_response'],
                        $registration['create_http_status']
                    );
                }
                $customerId = basename(dirname((string) parse_url($url, PHP_URL_PATH)));
                $suffix = substr(hash('sha256', (string) $request->data()['client_field_1']), 0, 22);

                return Http::response([
                    'id' => 'cs_'.$suffix,
                    'pay_type' => 'Card',
                    'customer_id' => $customerId,
                    'status' => $registration['create_status'],
                    'redirect_url' => $registration['create_status'] === 'AWAITING_CUSTOMER_ACTION'
                        ? 'https://pay.test.fincode.jp/card-registration/'.$suffix
                        : null,
                    'card' => [
                        'tds_type' => $registration['create_tds_type'],
                        'tds2_type' => $registration['create_tds2_type'],
                        'tds2_status' => $registration['create_tds2_status'],
                        'access_id' => 'a_'.$suffix,
                    ],
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/payment_methods/')) {
                if ($registration['canonical_timeout'] ?? false) {
                    return Http::failedConnection();
                }
                if ($registration['canonical_http_status'] !== 200) {
                    return Http::response([], $registration['canonical_http_status']);
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                $paymentMethodId = basename($path);
                $customerId = basename(dirname(dirname($path)));
                $suffix = str_starts_with($paymentMethodId, 'cs_')
                    ? substr($paymentMethodId, 3)
                    : substr(hash('sha256', $paymentMethodId), 0, 22);

                return Http::response([
                    'id' => $registration['canonical_payment_method_id'] ?? $paymentMethodId,
                    'pay_type' => $registration['canonical_pay_type'] ?? 'Card',
                    'customer_id' => $registration['canonical_customer_id'] ?? $customerId,
                    'status' => $registration['canonical_status'],
                    'redirect_url' => $registration['canonical_status'] === 'AWAITING_CUSTOMER_ACTION'
                        ? 'https://pay.test.fincode.jp/card-registration/'.$suffix
                        : null,
                    'card' => [
                        'card_no' => '************4242',
                        'expire' => '3012',
                        'brand' => 'VISA',
                        'tds_type' => $registration['canonical_tds_type'],
                        'tds2_type' => $registration['canonical_tds2_type'],
                        'tds2_status' => $registration['canonical_tds2_status'],
                        'access_id' => $registration['canonical_access_id'] ?? 'a_'.$suffix,
                    ],
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/cards/')) {
                if ($registration['card_timeout'] ?? false) {
                    return Http::failedConnection();
                }
                if (($registration['card_http_status'] ?? 200) !== 200) {
                    return Http::response([], $registration['card_http_status']);
                }
                $cardId = basename(parse_url($url, PHP_URL_PATH));
                $customerId = basename(dirname(dirname(parse_url($url, PHP_URL_PATH))));

                return Http::response([
                    'id' => $registration['card_id'] ?? $cardId,
                    'brand' => 'VISA',
                    'customer_id' => $registration['card_customer_id'] ?? $customerId,
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
                    ...($paymentExecution ?? ['redirect_url' => 'https://pay.test.fincode.jp/redirect']),
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
                $response = [
                    'id' => $orderId,
                    'pay_type' => $query['pay_type'] ?? null,
                    'status' => $retrievedStatus,
                    'amount' => $retrievedAmount,
                    'tax' => '0',
                    'transaction_date' => '2026/08/24 12:00:00.000',
                    'process_date' => '2026/08/24 12:00:00.000',
                ];
                if ($retrievedErrorCode !== null) {
                    $response['error_code'] = $retrievedErrorCode;
                }

                return Http::response($response, 200);
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

    /** @return array<string, int> */
    private function paymentMutationCounts(): array
    {
        return collect([
            'payments',
            'fincode_payment_attempts',
            'payment_point_grants',
            'wallets',
            'point_lots',
            'point_operations',
            'point_ledger_entries',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function assertProductionTestEndpointRejected(bool $unset): void
    {
        Http::fake();
        if ($unset) {
            app('config')->offsetUnset('v2_fincode.allow_test_in_production');
            self::assertNull(config('v2_fincode.allow_test_in_production'));
        } else {
            config(['v2_fincode.allow_test_in_production' => false]);
            self::assertFalse(config('v2_fincode.allow_test_in_production'));
        }
        app()->detectEnvironment(fn (): string => 'production');
        $user = $this->user($unset ? 'production-test-unset' : 'production-test-false');
        Auth::guard('v2_user')->setUser($user);
        $before = $this->paymentMutationCounts();

        $this->getJson('/api/v2/me/payment-card-ui-bootstrap')
            ->assertStatus(503)
            ->assertJsonPath('code', 'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE');
        try {
            app(V2FincodePaymentService::class)->start(
                $user,
                (string) Str::uuid7(),
                'paypay',
                'production-test-payment-rejected'
            );
            self::fail('Payment start must fail before mutation without explicit opt-in.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE', $exception->errorCode);
            self::assertSame(503, $exception->status);
        }
        try {
            app(V2FincodeClient::class)->createCustomer('customer-test', 'production-test-rejected');
            self::fail('The fincode Test endpoint must fail closed in Production without explicit opt-in.');
        } catch (V2FincodeException $exception) {
            self::assertSame('FINCODE_PRODUCTION_ENDPOINT_REQUIRED', $exception->errorCode);
            self::assertSame(503, $exception->status);
        }

        self::assertCount(0, Http::recorded());
        self::assertSame($before, $this->paymentMutationCounts());
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
