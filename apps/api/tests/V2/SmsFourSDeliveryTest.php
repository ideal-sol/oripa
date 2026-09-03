<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Domain\Notification\Services\LogSmsSender;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Sms\Contracts\V2SmsProvider;
use App\Domain\Sms\Services\V2FourSSmsProvider;
use App\Domain\Sms\Services\V2SmsDeliveryWorker;
use App\Domain\Sms\Values\V2SmsDeliveryResult;
use App\Models\V2\OutboxMessage;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SmsFourSDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.name' => 'オリパ',
            'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_sms.fours.endpoint' => 'https://4sm.jp/api/sms_send',
            'v2_sms.fours.cp_userid' => 'fixture-user-placeholder',
            'v2_sms.fours.cp_password' => 'fixture-password-placeholder',
            'v2_sms.fours.user_agent' => 'OripaV2-SMS-Test/2',
            'v2_sms.fours.timeout_seconds' => 10,
        ]);
        Http::preventStrayRequests();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_fours_adapter_posts_exact_safe_form_and_parses_success(): void
    {
        Http::fake([
            'https://4sm.jp/api/sms_send' => Http::response([
                'result' => 'SUCCESS',
                'request_id' => 'request-safe-001',
                'request_date' => '2026-09-02 10:00:00',
            ]),
        ]);

        $result = app(V2FourSSmsProvider::class)->deliver('+819012345678', '123456', 60);

        self::assertSame('accepted', $result->state);
        self::assertSame('request-safe-001', $result->providerRequestId);
        Http::assertSent(function (HttpRequest $request): bool {
            self::assertSame('POST', $request->method());
            self::assertSame('https://4sm.jp/api/sms_send', $request->url());
            self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
            self::assertSame('OripaV2-SMS-Test/2', $request->header('User-Agent')[0]);
            self::assertSame([
                'carrier_id' => '99',
                'message' => "オリパの認証コードは「123456」です。\n\n有効期限は60分です。\n\nこのコードを第三者に教えないでください。",
                'address' => '09012345678',
                'send_date' => '',
                'urlshorterflg' => '0',
                'cp_userid' => 'fixture-user-placeholder',
                'cp_password' => 'fixture-password-placeholder',
            ], $request->data());

            return true;
        });
    }

    public function test_configured_ttl_drives_challenge_and_sms_message_together(): void
    {
        config(['v2_identity.sms_verification.ttl_minutes' => '30']);
        Http::fake([
            'https://4sm.jp/api/sms_send' => Http::response([
                'result' => 'SUCCESS',
                'request_id' => 'request-ttl-030',
                'request_date' => '2026-09-03 10:00:00',
            ]),
        ]);
        [$user, $request] = $this->userRequest('worker-ttl@example.test');
        app(V2SmsVerificationService::class)->send($user, $request, '08022223333');
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $outbox = OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        $verificationCode = $this->delivery($outbox)['verification_code'];

        self::assertSame(1800, (int) $challenge->created_at->diffInSeconds($challenge->expires_at));
        self::assertSame(1, app(V2SmsDeliveryWorker::class)->run('sms-worker-ttl', 10));
        Http::assertSent(function (HttpRequest $request) use ($verificationCode): bool {
            self::assertSame(
                "オリパの認証コードは「{$verificationCode}」です。\n\n有効期限は30分です。\n\nこのコードを第三者に教えないでください。",
                $request['message']
            );

            return true;
        });
    }

    public function test_fours_adapter_normalizes_error_timeout_5xx_and_malformed_responses(): void
    {
        Log::spy();
        $provider = app(V2FourSSmsProvider::class);
        Http::fakeSequence('https://4sm.jp/api/sms_send')
            ->push([
                'result' => 'ERROR',
                'error_code' => 'fixture-code',
                'error_message' => 'raw provider detail must disappear',
            ])
            ->push('not-json')
            ->push([
                'result' => 'SUCCESS',
                'request_date' => '2026-09-02 10:00:00',
            ])
            ->push(['result' => 'SUCCESS'], 503)
            ->pushFailedConnection('timeout');
        $cases = [
            ['failed', 'provider_rejected'],
            ['unknown', 'provider_malformed_response'],
            ['unknown', 'provider_malformed_response'],
            ['unknown', 'provider_unavailable'],
        ];
        foreach ($cases as [$state, $category]) {
            $result = $provider->deliver('+818012345678', '654321', 60);
            self::assertSame($state, $result->state);
            self::assertSame($category, $result->errorCategory);
            $serialized = json_encode($result, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('raw provider detail', $serialized);
            self::assertStringNotContainsString('fixture-password-placeholder', $serialized);
            self::assertStringNotContainsString('654321', $serialized);
            self::assertStringNotContainsString('+818012345678', $serialized);
        }

        $timeout = $provider->deliver('+817012345678', '111222', 60);
        self::assertSame('unknown', $timeout->state);
        self::assertSame('provider_timeout', $timeout->errorCategory);

        config(['v2_sms.fours.endpoint' => 'http://4sm.jp/api/sms_send']);
        $configuration = $provider->deliver('+817012345678', '111222', 60);
        self::assertSame('failed', $configuration->state);
        self::assertSame('provider_configuration_unavailable', $configuration->errorCategory);
        Http::assertSentCount(5);
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_worker_transitions_pending_to_accepted_and_never_uses_legacy_sender(): void
    {
        self::assertInstanceOf(V2FourSSmsProvider::class, app(V2SmsProvider::class));
        self::assertNotInstanceOf(LogSmsSender::class, app(V2SmsProvider::class));
        $provider = new QueuedSmsProvider([
            V2SmsDeliveryResult::accepted('worker-request-001'),
        ]);
        $this->app->instance(V2SmsProvider::class, $provider);
        [$user, $request] = $this->userRequest('worker-accepted@example.test');
        app(V2SmsVerificationService::class)->send($user, $request, '09012345678');
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $outbox = OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        $serializedPayload = json_encode($outbox->payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('09012345678', $serializedPayload);
        self::assertStringNotContainsString(
            $this->delivery($outbox)['verification_code'],
            $serializedPayload
        );

        self::assertSame(1, app(V2SmsDeliveryWorker::class)->run('sms-worker-a', 10));
        self::assertSame('accepted', $challenge->refresh()->delivery_state);
        self::assertSame('delivered', $outbox->refresh()->status);
        self::assertSame(1, $provider->calls);
        self::assertSame(0, app(V2SmsDeliveryWorker::class)->run('sms-worker-b', 10));
        self::assertSame(1, $provider->calls);
    }

    public function test_worker_failure_is_terminal_and_verify_returns_safe_unavailable(): void
    {
        $provider = new QueuedSmsProvider([
            V2SmsDeliveryResult::failed('provider_rejected'),
        ]);
        $this->app->instance(V2SmsProvider::class, $provider);
        [$user, $request] = $this->userRequest('worker-failed@example.test');
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '08012345678');
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $code = $this->delivery(
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole()
        )['verification_code'];

        app(V2SmsDeliveryWorker::class)->run('sms-worker-failed', 10);
        self::assertSame('failed', $challenge->refresh()->delivery_state);
        self::assertNotNull($challenge->revoked_at);
        try {
            $service->verify($user, $request, $challenge->public_id, $code);
            self::fail('A failed delivery must not verify.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('SMS_DELIVERY_UNAVAILABLE', $exception->errorCode);
            self::assertStringNotContainsString('provider', $exception->getMessage());
        }
    }

    public function test_unavailable_verified_phone_creates_no_delivery_or_provider_call(): void
    {
        $provider = new QueuedSmsProvider([
            V2SmsDeliveryResult::accepted('worker-request-holder'),
        ]);
        $this->app->instance(V2SmsProvider::class, $provider);
        [$holder, $holderRequest] = $this->userRequest('worker-holder@example.test');
        $service = app(V2SmsVerificationService::class);
        $service->send($holder, $holderRequest, '09022223333');
        $holderChallenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $holderMessage = OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        self::assertSame(1, app(V2SmsDeliveryWorker::class)->run('sms-worker-holder', 10));
        $service->verify(
            $holder,
            $holderRequest,
            $holderChallenge->public_id,
            $this->delivery($holderMessage)['verification_code']
        );

        [$claimant, $claimantRequest] = $this->userRequest('worker-claimant@example.test');
        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($claimant, $claimantRequest, '09022223333');
            self::fail('An unavailable verified phone must be rejected before delivery.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }

        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );
        self::assertSame(0, app(V2SmsDeliveryWorker::class)->run('sms-worker-unavailable', 10));
        self::assertSame(1, $provider->calls);
    }

    public function test_worker_rejects_outbox_and_challenge_ownership_mismatch(): void
    {
        $provider = new QueuedSmsProvider([
            V2SmsDeliveryResult::accepted('must-not-be-used'),
        ]);
        $this->app->instance(V2SmsProvider::class, $provider);
        [$user, $request] = $this->userRequest('worker-mismatch@example.test');
        app(V2SmsVerificationService::class)->send($user, $request, '08087654321');
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $message = OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        $message->forceFill([
            'aggregate_public_id' => (string) \Illuminate\Support\Str::uuid7(),
        ])->save();

        self::assertSame(1, app(V2SmsDeliveryWorker::class)->run('sms-worker-mismatch', 10));
        self::assertSame(0, $provider->calls);
        self::assertSame('failed', $challenge->refresh()->delivery_state);
        self::assertSame('sms_delivery_payload_invalid', $challenge->delivery_error_category);
        self::assertNotNull($challenge->revoked_at);
        self::assertSame('failed', $message->refresh()->status);
    }

    public function test_reclaimed_sending_message_becomes_unknown_without_blind_provider_retry(): void
    {
        $provider = new QueuedSmsProvider([]);
        $this->app->instance(V2SmsProvider::class, $provider);
        [$user, $request] = $this->userRequest('worker-ambiguous@example.test');
        app(V2SmsVerificationService::class)->send($user, $request, '07012345678');
        $message = app(V2OutboxService::class)
            ->claim('crashed-sms-worker', 1, 30, ['identity.sms-verification'])
            ->sole();
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $challenge->forceFill([
            'delivery_state' => 'sending',
            'delivery_attempted_at' => now()->startOfSecond(),
        ])->save();
        DB::table('outbox_messages')->where('id', $message->id)->update([
            'lease_expires_at' => now()->subSecond(),
        ]);

        self::assertSame(1, app(V2SmsDeliveryWorker::class)->run('recovery-sms-worker', 10));
        self::assertSame(0, $provider->calls);
        self::assertSame('unknown', $challenge->refresh()->delivery_state);
        self::assertSame('provider_ambiguous_outcome', $challenge->delivery_error_category);
        self::assertSame('failed', $message->refresh()->status);
    }

    public function test_phone_change_provider_failure_preserves_old_verified_phone(): void
    {
        $acceptedProvider = new QueuedSmsProvider([
            V2SmsDeliveryResult::accepted('worker-request-initial'),
        ]);
        $this->app->instance(V2SmsProvider::class, $acceptedProvider);
        [$user, $request] = $this->userRequest('worker-change-failure@example.test');
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '09077778888');
        $initialMessage = OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        app(V2SmsDeliveryWorker::class)->run('sms-worker-initial', 10);
        $initial = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $verified = $service->verify(
            $user,
            $request,
            $initial->public_id,
            $this->delivery($initialMessage)['verification_code']
        );
        $changeRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $changeRequest->cookies->set('__Host-oripa_user_session', $verified['session']['token']);
        $service->send($user, $changeRequest, '08077778888');
        $failedProvider = new QueuedSmsProvider([
            V2SmsDeliveryResult::failed('provider_rejected'),
        ]);
        $this->app->instance(V2SmsProvider::class, $failedProvider);
        app(V2SmsDeliveryWorker::class)->run('sms-worker-change', 10);

        $phone = UserPhoneNumber::query()->where('user_id', $user->getKey())->sole();
        self::assertSame('+819077778888', Crypt::decryptString($phone->phone_ciphertext));
        self::assertNotNull($phone->verified_at);
        self::assertNull($phone->revoked_at);
    }

    /** @return array{User, Request} */
    private function userRequest(string $email): array
    {
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => strtolower($email),
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid old password'),
            'state' => 'active',
        ]);
        $session = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        $request = Request::create('/api/v2/me/sms-verification', 'POST');
        $request->cookies->set('__Host-oripa_user_session', $session['token']);

        return [$user, $request];
    }

    /** @return array<string, mixed> */
    private function delivery(OutboxMessage $message): array
    {
        return json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}

final class QueuedSmsProvider implements V2SmsProvider
{
    public int $calls = 0;

    /** @param list<V2SmsDeliveryResult> $results */
    public function __construct(private array $results)
    {
    }

    public function deliver(
        #[\SensitiveParameter] string $canonicalPhone,
        #[\SensitiveParameter] string $verificationCode,
        int $ttlMinutes
    ): V2SmsDeliveryResult {
        $this->calls++;
        $result = array_shift($this->results);
        if (! $result instanceof V2SmsDeliveryResult) {
            throw new \RuntimeException('Unexpected SMS Provider invocation.');
        }

        return $result;
    }
}
