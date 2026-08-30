<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2EmailChangeService;
use App\Domain\Identity\Services\V2PasswordChangeService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Models\V2\OutboxMessage;
use App\Models\V2\PasswordResetToken;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserEmailChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZIdentityRecoveryConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for recovery concurrency verification.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
    }

    public function test_concurrent_password_reset_confirms_once(): void
    {
        $runId = bin2hex(random_bytes(6));
        $user = $this->user("concurrent-reset-{$runId}@example.test");
        app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        app(V2PasswordRecoveryService::class)->request(
            $user->email_display,
            '/',
            '192.0.2.130'
        );
        $token = $this->delivery('identity.password-reset')['reset_token'];

        $statuses = $this->concurrent(static function (int $_worker) use ($user, $token): bool {
            try {
                app(V2PasswordRecoveryService::class)->confirm(
                    $user->public_id,
                    $token,
                    'concurrent new password'
                );

                return true;
            } catch (V2AuthenticationException) {
                return false;
            }
        }, 'password-reset');

        sort($statuses);
        self::assertSame([false, true], $statuses);
        self::assertNotNull(PasswordResetToken::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->used_at);
        self::assertSame(0, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.password-changed')
            ->where('aggregate_public_id', $user->public_id)
            ->count());
    }

    public function test_concurrent_email_change_completion_consumes_once(): void
    {
        $runId = bin2hex(random_bytes(6));
        $user = $this->user("concurrent-email-{$runId}@example.test");
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $this->request($session['token'], '/api/v2/me/email-change-requests'),
            "concurrent-email-new-{$runId}@example.test",
            '/'
        );
        $token = $this->delivery('identity.email-change-verification')['verification_token'];

        $statuses = $this->concurrent(static function (int $_worker) use ($started, $token): bool {
            try {
                app(V2EmailChangeService::class)->complete(
                    $started['request_id'],
                    $token,
                    Request::create(
                        '/api/v2/me/email-change-requests/'.$started['request_id'].'/complete',
                        'POST'
                    )
                );

                return true;
            } catch (V2AuthenticationException) {
                return false;
            }
        }, 'email-change-once');

        sort($statuses);
        self::assertSame([false, true], $statuses);
        self::assertNotNull(UserEmailChangeRequest::query()
            ->where('public_id', $started['request_id'])
            ->sole()
            ->used_at);
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.email-change-completed')
            ->where('aggregate_public_id', $user->public_id)
            ->count());
        self::assertSame(1, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_concurrent_email_change_duplicate_claim_has_one_winner(): void
    {
        $runId = bin2hex(random_bytes(6));
        $first = $this->user("duplicate-email-first-{$runId}@example.test");
        $second = $this->user("duplicate-email-second-{$runId}@example.test");
        $target = "duplicate-email-target-{$runId}@example.test";
        $firstSession = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $first->getKey()
        );
        $secondSession = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $second->getKey()
        );
        $firstStarted = app(V2EmailChangeService::class)->start(
            $first,
            $this->request($firstSession['token'], '/api/v2/me/email-change-requests'),
            $target,
            '/'
        );
        $firstToken = $this->delivery(
            'identity.email-change-verification'
        )['verification_token'];
        $secondStarted = app(V2EmailChangeService::class)->start(
            $second,
            $this->request($secondSession['token'], '/api/v2/me/email-change-requests'),
            $target,
            '/'
        );
        $secondToken = $this->delivery(
            'identity.email-change-verification'
        )['verification_token'];
        $attempts = [
            [$firstStarted['request_id'], $firstToken],
            [$secondStarted['request_id'], $secondToken],
        ];

        $statuses = $this->concurrent(static function (int $worker) use ($attempts): bool {
            $attempt = $attempts[$worker];
            try {
                app(V2EmailChangeService::class)->complete(
                    $attempt[0],
                    $attempt[1],
                    Request::create(
                        '/api/v2/me/email-change-requests/'.$attempt[0].'/complete',
                        'POST'
                    )
                );

                return true;
            } catch (V2AuthenticationException) {
                return false;
            }
        }, 'email-change-duplicate');

        sort($statuses);
        self::assertSame([false, true], $statuses);
        self::assertSame(1, User::query()
            ->where('email_normalized', $target)
            ->count());
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.email-change-completed')
            ->whereIn('aggregate_public_id', [$first->public_id, $second->public_id])
            ->count());
    }

    public function test_concurrent_password_change_accepts_old_password_once(): void
    {
        $runId = bin2hex(random_bytes(6));
        $user = $this->user("concurrent-password-{$runId}@example.test");
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );

        $statuses = $this->concurrent(static function (int $_worker) use ($user, $session): bool {
            $request = Request::create('/api/v2/me/password', 'PUT');
            $request->cookies->set('__Host-oripa_user_session', $session['token']);
            try {
                app(V2PasswordChangeService::class)->change(
                    $user,
                    $request,
                    'valid old password',
                    'concurrent new password'
                );

                return true;
            } catch (V2AuthenticationException) {
                return false;
            }
        }, 'password-change');

        sort($statuses);
        self::assertSame([false, true], $statuses);
        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'concurrent new password',
            $user->refresh()->password_hash
        ));
        self::assertSame(1, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.password-changed')
            ->where('aggregate_public_id', $user->public_id)
            ->count());
    }

    public function test_concurrent_sms_verification_confirms_once(): void
    {
        $runId = bin2hex(random_bytes(6));
        $phone = '+8190'.random_int(10_000_000, 99_999_999);
        $user = $this->user("concurrent-sms-{$runId}@example.test");
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $request = $this->request($session['token']);
        app(V2SmsVerificationService::class)->send(
            $user,
            $request,
            $phone,
            '192.0.2.140'
        );
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->sole();
        $code = $this->delivery('identity.sms-verification')['verification_code'];

        $statuses = $this->concurrent(
            function (int $_worker) use ($user, $session, $challenge, $code): bool {
                try {
                    app(V2SmsVerificationService::class)->verify(
                        $user,
                        $this->request($session['token']),
                        $challenge->public_id,
                        $code
                    );

                    return true;
                } catch (V2AuthenticationException) {
                    return false;
                }
            },
            'sms-verify'
        );

        sort($statuses);
        self::assertSame([false, true], $statuses);
        self::assertNotNull($challenge->refresh()->used_at);
        self::assertSame(1, DB::table('user_phone_numbers')
            ->where('user_id', $user->getKey())
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->count());
        self::assertSame(1, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.phone-verified')
            ->where('aggregate_public_id', $user->public_id)
            ->count());
    }

    /**
     * @param callable(int): bool $operation
     * @return list<bool>
     */
    private function concurrent(callable $operation, string $scenario): array
    {
        $directory = sys_get_temp_dir().'/mig057-'.getmypid().'-'.$scenario;
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Recovery concurrency worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $succeeded = false;
                try {
                    $succeeded = $operation($worker);
                } catch (\Throwable) {
                    $succeeded = false;
                }
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode(['succeeded' => $succeeded], JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit(0);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        $statuses = [];
        foreach ([0, 1] as $worker) {
            $result = json_decode(
                file_get_contents("{$directory}/{$worker}.json"),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $statuses[] = $result['succeeded'];
            unlink("{$directory}/{$worker}.json");
        }
        rmdir($directory);

        return $statuses;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid old password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function request(
        string $token,
        string $path = '/api/v2/me/sms-verification'
    ): Request
    {
        $request = Request::create($path, 'POST');
        $request->cookies->set('__Host-oripa_user_session', $token);

        return $request;
    }

    /** @return array<string, mixed> */
    private function delivery(string $topic): array
    {
        $message = OutboxMessage::query()->where('topic', $topic)->latest('id')->firstOrFail();

        return json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
