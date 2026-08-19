<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
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
            '#/api/v2/auth/email/verify/'.preg_quote($user->public_id, '#').'/[a-f0-9]{64}\\?redirect=%2Faccount#',
            $capture->messages[0]['body']
        );
        self::assertStringContainsString('This link expires in 60 minutes.', $capture->messages[0]['body']);
        self::assertSame(V2UserState::PendingVerification, $user->state);
        self::assertDatabaseHas('user_email_verifications', [
            'user_id' => $user->getKey(),
            'redirect_path' => '/account',
        ]);
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.email-verification',
        ]);
    }

    public function test_resend_uses_direct_mail_revokes_the_previous_token_and_verifies(): void
    {
        $capture = $this->spyOnRawMail();
        $service = app(V2UserAuthenticationService::class);
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
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.email-verification',
        ]);

        $verified = $service->verify($user->public_id, $newToken);

        self::assertSame(V2UserState::Active, $verified['user']->state);
    }

    public function test_mail_transport_failure_rolls_back_registration_without_false_success(): void
    {
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
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.email-verification',
        ]);
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
