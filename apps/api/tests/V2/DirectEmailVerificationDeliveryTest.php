<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2MailEmailVerificationNotifier;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class DirectEmailVerificationDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'v2_identity.transactions.store' => 'array',
            'v2_identity.email_verification.redirect_allowlist' => ['/', '/account'],
            'v2_identity.origins.user' => 'https://storefront.example.test',
        ]);
        Cache::store('array')->clear();
        $this->app->instance(V2SecurityEventSink::class, new SilentSecurityEventSink());
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_registration_uses_direct_mail_and_preserves_verification_semantics(): void
    {
        $capture = $this->spyOnRawMail();
        $service = app(V2UserAuthenticationService::class);
        $verificationOutboxCount = $this->verificationOutboxCount();

        self::assertInstanceOf(
            V2MailEmailVerificationNotifier::class,
            app(V2EmailVerificationNotifier::class)
        );

        $user = $service->register(
            'direct-register@example.test',
            'valid direct mail password',
            '/account',
            '192.0.2.140'
        );

        $this->assertRawMailWasSent($capture, 1);
        self::assertSame('direct-register@example.test', $capture->messages[0]['to']);
        self::assertSame('メールアドレス確認', $capture->messages[0]['subject']);
        self::assertMatchesRegularExpression(
            '#https://storefront\.example\.test/api/v2/auth/email/verify/'.preg_quote($user->public_id, '#').'/[a-f0-9]{64}\\?redirect=%2Faccount#',
            $capture->messages[0]['body']
        );
        self::assertStringNotContainsString(
            "URL:\n/api/v2/auth/email/verify/",
            $capture->messages[0]['body']
        );
        self::assertStringContainsString('This link expires in 60 minutes.', $capture->messages[0]['body']);
        self::assertSame(V2UserState::PendingVerification, $user->state);
        self::assertDatabaseHas('user_email_verifications', [
            'user_id' => $user->getKey(),
            'redirect_path' => '/account',
        ]);
        self::assertSame($verificationOutboxCount, $this->verificationOutboxCount());
    }

    public function test_resend_uses_direct_mail_revokes_the_previous_token_and_verifies(): void
    {
        $capture = $this->spyOnRawMail();
        $service = app(V2UserAuthenticationService::class);
        $verificationOutboxCount = $this->verificationOutboxCount();
        $user = $service->register(
            'direct-resend@example.test',
            'valid direct mail password',
            '/',
            '192.0.2.141'
        );
        $this->assertRawMailWasSent($capture, 1);
        $oldToken = $this->tokenFromBody($capture->messages[0]['body'], $user->public_id);

        $service->resend($user->public_id, '/account');

        $this->assertRawMailWasSent($capture, 2);
        self::assertSame('direct-resend@example.test', $capture->messages[1]['to']);
        $newToken = $this->tokenFromBody($capture->messages[1]['body'], $user->public_id);
        self::assertNotSame($oldToken, $newToken);
        self::assertNotNull(DB::table('user_email_verifications')
            ->where('token_hash', hash('sha256', $oldToken))
            ->value('revoked_at'));
        self::assertSame($verificationOutboxCount, $this->verificationOutboxCount());

        $verified = $service->verify($user->public_id, $newToken);

        self::assertSame(V2UserState::Active, $verified['user']->state);
    }

    public function test_browser_verification_redirects_to_the_stored_path_with_session_and_csrf(): void
    {
        $capture = $this->spyOnRawMail();
        $user = app(V2UserAuthenticationService::class)->register(
            'browser-verification@example.test',
            'valid browser verification password',
            '/',
            '192.0.2.143'
        );
        $url = $this->verificationUrlFromBody($capture->messages[0]['body']);

        $response = $this
            ->withServerVariables(['HTTPS' => 'on'])
            ->get($this->requestTarget($url));

        $response
            ->assertStatus(303)
            ->assertRedirect('/');
        self::assertStringContainsString(
            'Accept',
            (string) $response->headers->get('Vary')
        );
        self::assertSame(V2UserState::Active, $user->refresh()->state);
        $cookies = collect($response->headers->getCookies());
        $session = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        );
        $csrf = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        );
        self::assertNotNull($session);
        self::assertNotNull($csrf);
        self::assertTrue($session->isSecure());
        self::assertTrue($session->isHttpOnly());
        self::assertTrue($csrf->isSecure());
        self::assertFalse($csrf->isHttpOnly());
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $session->getValue()),
        ]);
    }

    public function test_json_verification_preserves_the_existing_response_and_cookies(): void
    {
        $capture = $this->spyOnRawMail();
        $user = app(V2UserAuthenticationService::class)->register(
            'json-verification@example.test',
            'valid json verification password',
            '/account',
            '192.0.2.144'
        );
        $url = $this->verificationUrlFromBody($capture->messages[0]['body']);

        $response = $this
            ->withServerVariables(['HTTPS' => 'on'])
            ->getJson($this->requestTarget($url));

        $response
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $user->public_id)
            ->assertJsonPath('user.state', V2UserState::Active->value)
            ->assertJsonPath('user.email_verified', true)
            ->assertJsonPath('redirect_path', '/account');
        self::assertStringContainsString(
            'Accept',
            (string) $response->headers->get('Vary')
        );
        $cookies = collect($response->headers->getCookies());
        self::assertNotNull($cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        ));
        self::assertNotNull($cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        ));
    }

    public function test_external_and_unallowlisted_verification_redirects_remain_rejected(): void
    {
        $service = app(V2UserAuthenticationService::class);
        foreach ([
            'https://evil.example/',
            '//evil.example/',
            '/not-allowlisted',
        ] as $index => $redirectPath) {
            try {
                $service->register(
                    'redirect-rejection-'.$index.'@example.test',
                    'valid redirect rejection password',
                    $redirectPath,
                    '192.0.2.'.(150 + $index)
                );
                self::fail('An unsafe verification redirect must be rejected.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_REDIRECT', $exception->errorCode);
            }
        }
    }

    public function test_verification_query_cannot_override_the_stored_redirect(): void
    {
        $capture = $this->spyOnRawMail();
        app(V2UserAuthenticationService::class)->register(
            'redirect-tampering@example.test',
            'valid redirect tampering password',
            '/',
            '192.0.2.145'
        );
        $url = $this->verificationUrlFromBody($capture->messages[0]['body']);
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this
            ->withServerVariables(['HTTPS' => 'on'])
            ->get($path.'?redirect='.rawurlencode('//evil.example/'))
            ->assertStatus(303)
            ->assertRedirect('/');
    }

    public function test_invalid_verification_token_remains_rejected(): void
    {
        $capture = $this->spyOnRawMail();
        $user = app(V2UserAuthenticationService::class)->register(
            'invalid-token@example.test',
            'valid invalid token password',
            '/',
            '192.0.2.146'
        );
        $url = $this->verificationUrlFromBody($capture->messages[0]['body']);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $invalidPath = preg_replace(
            '#/[a-f0-9]{64}$#',
            '/'.str_repeat('0', 64),
            $path
        );
        self::assertIsString($invalidPath);

        $this
            ->withServerVariables(['HTTPS' => 'on'])
            ->getJson($invalidPath)
            ->assertStatus(410)
            ->assertJsonPath('code', 'INVALID_VERIFICATION_LINK');

        self::assertSame(V2UserState::PendingVerification, $user->refresh()->state);
    }

    public function test_mail_transport_failure_rolls_back_registration_without_false_success(): void
    {
        $verificationOutboxCount = $this->verificationOutboxCount();
        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new RuntimeException('mail transport unavailable'));

        try {
            app(V2UserAuthenticationService::class)->register(
                'direct-failure@example.test',
                'valid direct mail password',
                '/',
                '192.0.2.142'
            );
            self::fail('Mail transport failure must abort registration.');
        } catch (RuntimeException $exception) {
            self::assertSame('mail transport unavailable', $exception->getMessage());
        }

        self::assertDatabaseMissing('users', [
            'email_normalized' => 'direct-failure@example.test',
        ]);
        self::assertSame($verificationOutboxCount, $this->verificationOutboxCount());
    }

    private function spyOnRawMail(): RawMailCapture
    {
        $capture = new RawMailCapture();
        Mail::spy();
        Mail::shouldReceive('raw')
            ->zeroOrMoreTimes()
            ->withArgs(function (string $body, callable $callback) use ($capture): bool {
                $message = new Message(new Email());
                $callback($message);
                $symfonyMessage = $message->getSymfonyMessage();
                $capture->messages[] = [
                    'body' => $body,
                    'subject' => (string) $symfonyMessage->getSubject(),
                    'to' => $symfonyMessage->getTo()[0]->getAddress(),
                ];

                return true;
            });

        return $capture;
    }

    private function assertRawMailWasSent(RawMailCapture $capture, int $expected): void
    {
        Mail::shouldHaveReceived('raw')->times($expected);
        self::assertCount($expected, $capture->messages);
    }

    private function tokenFromBody(string $body, string $publicId): string
    {
        self::assertMatchesRegularExpression(
            '#/api/v2/auth/email/verify/'.preg_quote($publicId, '#').'/([a-f0-9]{64})\\?#',
            $body
        );
        preg_match(
            '#/api/v2/auth/email/verify/'.preg_quote($publicId, '#').'/([a-f0-9]{64})\\?#',
            $body,
            $matches
        );

        return $matches[1];
    }

    private function verificationUrlFromBody(string $body): string
    {
        self::assertMatchesRegularExpression(
            '#https://storefront\.example\.test/api/v2/auth/email/verify/[0-9a-f-]{36}/[a-f0-9]{64}\\?redirect=[^\\s]+#',
            $body
        );
        preg_match(
            '#https://storefront\.example\.test/api/v2/auth/email/verify/[0-9a-f-]{36}/[a-f0-9]{64}\\?redirect=[^\\s]+#',
            $body,
            $matches
        );

        return $matches[0];
    }

    private function requestTarget(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH)
            .'?'.(string) parse_url($url, PHP_URL_QUERY);
    }

    private function verificationOutboxCount(): int
    {
        return DB::table('outbox_messages')
            ->where('topic', 'identity.email-verification')
            ->count();
    }
}

final class SilentSecurityEventSink implements V2SecurityEventSink
{
    public function record(string $event, array $context): void
    {
    }
}

final class RawMailCapture
{
    /** @var list<array{body: string, subject: string, to: string}> */
    public array $messages = [];
}
