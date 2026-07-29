<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Services\V2ExternalIdentityService;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\ExternalIdentityTransaction;
use App\Models\V2\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\TestCase;

final class ZExternalIdentityConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for OIDC concurrency verification.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.external_identity.google.client_id' => 'google-client.test',
            'v2_identity.external_identity.google.client_secret' => 'synthetic-client-secret',
            'v2_identity.external_identity.google.redirect_uri' =>
                'https://storefront.example.test/api/v2/auth/external/google/callback',
            'v2_identity.external_identity.line.client_id' => 'line-channel.test',
            'v2_identity.external_identity.line.client_secret' => 'synthetic-line-secret',
            'v2_identity.external_identity.line.redirect_uri' =>
                'https://storefront.example.test/api/v2/auth/external/line/callback',
            'v2_identity.external_identity.line.email_scope_enabled' => true,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
    }

    public function test_same_oidc_transaction_concurrently_creates_one_user_and_identity(): void
    {
        $runId = bin2hex(random_bytes(6));
        $transport = new ConcurrentGoogleOidcTransport();
        $this->app->instance(V2GoogleOidcTransport::class, $transport);
        $service = app(V2ExternalIdentityService::class);
        $start = $service->start(
            'login',
            '/',
            '192.0.2.50',
            (string) Str::uuid7(),
            null,
            Request::create('/api/v2/auth/external/google/start', 'POST')
        );
        parse_str(parse_url($start['authorization_url'], PHP_URL_QUERY), $query);
        $transport->issue(
            $query['nonce'],
            'concurrent-'.$runId,
            "concurrent-{$runId}@example.test"
        );
        $statuses = $this->concurrent(function () use ($query, $service, $start): string {
            $request = Request::create(
                'https://storefront.example.test/api/v2/auth/external/google/callback',
                'GET'
            );
            $request->cookies->set(
                '__Host-oripa_oidc_transaction',
                $start['binding_token']
            );
            try {
                $service->callback(
                    $query['state'],
                    'concurrent-code',
                    $start['binding_token'],
                    'https://storefront.example.test/api/v2/auth/external/google/callback',
                    '192.0.2.51',
                    $request
                );

                return 'succeeded';
            } catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $exception) {
                return $exception->errorCode;
            } catch (\Throwable $exception) {
                return $exception::class;
            }
        });

        sort($statuses);
        self::assertSame(['EXTERNAL_IDENTITY_AUTHENTICATION_FAILED', 'succeeded'], $statuses);
        self::assertSame(1, User::query()
            ->where('email_normalized', "concurrent-{$runId}@example.test")
            ->count());
        self::assertSame(1, ExternalIdentityAccount::query()
            ->where('subject_hash', app(
                \App\Domain\Identity\Services\V2IdentityCorrelation::class
            )->hash(
                'google|https://accounts.google.com|concurrent-'.$runId
            ))
            ->count());
        self::assertSame('completed', ExternalIdentityTransaction::query()
            ->where('state_hash', hash('sha256', $query['state']))
            ->sole()
            ->status);
    }

    public function test_same_line_transaction_concurrently_creates_one_user_and_identity(): void
    {
        $runId = bin2hex(random_bytes(6));
        $transport = new ConcurrentLineOidcTransport();
        $this->app->instance(V2LineOidcTransport::class, $transport);
        $service = app(V2ExternalIdentityService::class);
        $callbackUrl = 'https://storefront.example.test/api/v2/auth/external/line/callback';
        $start = $service->startForProvider(
            'line',
            'login',
            '/',
            '192.0.2.52',
            (string) Str::uuid7(),
            null,
            Request::create('/api/v2/auth/external/line/start', 'POST')
        );
        parse_str(parse_url($start['authorization_url'], PHP_URL_QUERY), $query);
        $transport->issue(
            $query['nonce'],
            'line-concurrent-'.$runId,
            "line-concurrent-{$runId}@example.test"
        );
        $statuses = $this->concurrent(
            function () use ($query, $service, $start, $callbackUrl): string {
                $request = Request::create($callbackUrl, 'GET');
                $request->cookies->set(
                    '__Host-oripa_oidc_transaction',
                    $start['binding_token']
                );
                try {
                    $service->callbackForProvider(
                        'line',
                        $query['state'],
                        'line-concurrent-code',
                        $start['binding_token'],
                        $callbackUrl,
                        '192.0.2.53',
                        $request
                    );

                    return 'succeeded';
                } catch (
                    \App\Domain\Identity\Exceptions\V2AuthenticationException $exception
                ) {
                    return $exception->errorCode;
                } catch (\Throwable $exception) {
                    return $exception::class;
                }
            }
        );

        sort($statuses);
        self::assertSame(
            ['EXTERNAL_IDENTITY_AUTHENTICATION_FAILED', 'succeeded'],
            $statuses
        );
        self::assertSame(1, User::query()
            ->where('email_normalized', "line-concurrent-{$runId}@example.test")
            ->count());
        self::assertSame(1, ExternalIdentityAccount::query()
            ->where('subject_hash', app(
                \App\Domain\Identity\Services\V2IdentityCorrelation::class
            )->hash(
                'line|https://access.line.me|line-concurrent-'.$runId
            ))
            ->count());
    }

    /** @return list<string> */
    private function concurrent(callable $operation): array
    {
        $directory = sys_get_temp_dir().'/mig058a-'.getmypid();
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('OIDC concurrency worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $result = $operation();
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode(['result' => $result], JSON_THROW_ON_ERROR),
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
            $statuses[] = $result['result'];
            unlink("{$directory}/{$worker}.json");
        }
        rmdir($directory);

        return $statuses;
    }
}

final class ConcurrentLineOidcTransport implements V2LineOidcTransport
{
    /** @var array<string, mixed> */
    private array $claims = [];

    public function issue(string $nonce, string $subject, string $email): void
    {
        $now = now()->getTimestamp();
        $this->claims = [
            'iss' => 'https://access.line.me',
            'sub' => $subject,
            'aud' => 'line-channel.test',
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
            'email' => $email,
        ];
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        return 'synthetic-line-id-token';
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): array
    {
        return $this->claims;
    }
}

final class ConcurrentGoogleOidcTransport implements V2GoogleOidcTransport
{
    private string $privateKey;
    /** @var array<string, mixed> */
    private array $jwk;
    private string $idToken = '';

    public function __construct()
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new \RuntimeException('Synthetic RSA key generation failed.');
        }
        $this->privateKey = $privateKey;
        $details = openssl_pkey_get_details($key);
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'concurrent-key',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ];
    }

    public function issue(string $nonce, string $subject, string $email): void
    {
        $now = now()->getTimestamp();
        $this->idToken = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client.test',
            'azp' => 'google-client.test',
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'nonce' => $nonce,
            'iat' => $now,
            'exp' => $now + 300,
        ], $this->privateKey, 'RS256', 'concurrent-key');
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        return $this->idToken;
    }

    public function jwks(bool $refresh = false): array
    {
        return ['keys' => [$this->jwk]];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
