<?php

namespace Tests\Support;

use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use SensitiveParameter;

final class FakeLineOidcTransport implements V2LineOidcTransport
{
    public ?\Closure $duringVerification = null;
    public bool $friendFlag = false;
    public bool $friendshipFails = false;
    public int $friendshipCalls = 0;
    public int $exchangeCalls = 0;
    public int $verifyCalls = 0;

    public function friendshipStatus(): bool
    {
        $this->friendshipCalls++;
        if ($this->friendshipFails) throw new V2OidcProtocolException('friendship_transport_failed');
        return $this->friendFlag;
    }

    public function discardAccessToken(): void {}

    /** @var array<string, mixed> */
    private array $claims = [];
    private ?string $expectedChallenge = null;
    private ?string $failure = null;

    /**
     * @param array<string, mixed> $overrides
     */
    public function claims(
        string $nonce,
        string $subject,
        ?string $email = null,
        array $overrides = []
    ): void {
        $now = now()->getTimestamp();
        $claims = [
            'iss' => 'https://access.line.me',
            'sub' => $subject,
            'aud' => 'line-channel.test',
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
        ];
        if ($email !== null) {
            $claims['email'] = $email;
        }
        $this->claims = [...$claims, ...$overrides];
    }

    public function expectChallenge(string $challenge): void
    {
        $this->expectedChallenge = $challenge;
    }

    public function fail(string $failure): void
    {
        $this->failure = $failure;
    }

    public function reset(): void
    {
        $this->failure = null;
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        $this->exchangeCalls++;
        if (
            $this->failure === 'timeout'
            || $this->failure === 'rate-limit'
            || $this->failure === 'server-error'
        ) {
            throw new V2OidcProtocolException(match ($this->failure) {
                'rate-limit' => 'provider_rate_limited',
                'server-error' => 'provider_unavailable',
                default => 'provider_transport_failed',
            });
        }
        if (
            $authorizationCode === ''
            || $redirectUri !==
                'https://storefront.example.test/api/v2/auth/external/line/callback'
            || $this->expectedChallenge === null
            || ! hash_equals(
                $this->expectedChallenge,
                $this->base64Url(hash('sha256', $codeVerifier, true))
            )
        ) {
            throw new V2OidcProtocolException('authorization_code_rejected');
        }

        return 'synthetic-line-id-token';
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): array
    {
        $this->verifyCalls++;
        if (
            $this->failure === 'verify-rejected'
            || $idToken !== 'synthetic-line-id-token'
            || $this->claims === []
        ) {
            throw new V2OidcProtocolException('line_verify_rejected');
        }

        if ($this->duringVerification !== null) ($this->duringVerification)();
        return $this->claims;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
