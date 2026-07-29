<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2ExternalIdentityProvider;
use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use SensitiveParameter;
use Throwable;

final class V2LineExternalIdentityProvider implements V2ExternalIdentityProvider
{
    private const ISSUER = 'https://access.line.me';
    private const AUTHORIZATION_ENDPOINT = 'https://access.line.me/oauth2/v2.1/authorize';

    public function __construct(
        private readonly V2LineOidcTransport $transport,
        private readonly V2SecureToken $tokens,
        private readonly V2EmailNormalizer $emails
    ) {
    }

    public function code(): string
    {
        return 'line';
    }

    public function issuer(): string
    {
        return self::ISSUER;
    }

    public function redirectUri(): string
    {
        return $this->validatedRedirectUri();
    }

    public function authorizationUrl(
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $nonce,
        #[SensitiveParameter] string $codeChallenge
    ): string {
        $scopes = ['openid', 'profile'];
        if ((bool) config('v2_identity.external_identity.line.email_scope_enabled', false)) {
            $scopes[] = 'email';
        }

        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->configuration('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', $scopes),
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        #[SensitiveParameter] string $expectedNonceHash
    ): V2VerifiedExternalIdentity {
        $idToken = $this->transport->exchangeAuthorizationCode(
            $authorizationCode,
            $codeVerifier,
            $this->redirectUri()
        );
        $claims = $this->transport->verifyIdToken($idToken);
        $now = now()->getTimestamp();
        $skew = (int) config('v2_identity.external_identity.clock_skew_seconds', 60);
        $clientId = $this->configuration('client_id');
        if (
            ($claims['iss'] ?? null) !== self::ISSUER
            || ($claims['aud'] ?? null) !== $clientId
            || ! is_int($claims['exp'] ?? null)
            || ! is_int($claims['iat'] ?? null)
            || $claims['exp'] <= $now - $skew
            || $claims['iat'] > $now + $skew
        ) {
            throw new V2OidcProtocolException('invalid_identity_claims');
        }
        $nonce = $claims['nonce'] ?? null;
        if (
            ! is_string($nonce)
            || ! hash_equals($expectedNonceHash, $this->tokens->hash($nonce))
        ) {
            throw new V2OidcProtocolException('nonce_rejected');
        }
        $subject = $claims['sub'] ?? null;
        if (
            ! is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new V2OidcProtocolException('invalid_identity_claims');
        }

        $email = $claims['email'] ?? null;
        if ($email === null) {
            return new V2VerifiedExternalIdentity(self::ISSUER, $subject, null, null);
        }
        if (
            ! (bool) config('v2_identity.external_identity.line.email_scope_enabled', false)
            || ! is_string($email)
            || $email === ''
        ) {
            throw new V2OidcProtocolException('invalid_identity_claims');
        }
        try {
            $normalized = $this->emails->normalize($email);
        } catch (Throwable) {
            throw new V2OidcProtocolException('invalid_identity_claims');
        }

        return new V2VerifiedExternalIdentity(self::ISSUER, $subject, $email, $normalized);
    }

    private function configuration(string $key): string
    {
        $value = config('v2_identity.external_identity.line.'.$key);
        if (! is_string($value) || $value === '') {
            throw new V2OidcProtocolException('provider_configuration_unavailable');
        }

        return $value;
    }

    private function validatedRedirectUri(): string
    {
        $redirect = $this->configuration('redirect_uri');
        if (
            parse_url($redirect, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirect, PHP_URL_HOST) === null
            || parse_url($redirect, PHP_URL_USER) !== null
            || parse_url($redirect, PHP_URL_PASS) !== null
        ) {
            throw new V2OidcProtocolException('provider_configuration_unavailable');
        }

        return $redirect;
    }
}
