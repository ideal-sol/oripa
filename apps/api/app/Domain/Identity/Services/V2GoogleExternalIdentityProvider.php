<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2ExternalIdentityProvider;
use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use SensitiveParameter;

final class V2GoogleExternalIdentityProvider implements V2ExternalIdentityProvider
{
    private const ISSUER = 'https://accounts.google.com';
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function __construct(
        private readonly V2GoogleOidcTransport $transport,
        private readonly V2GoogleIdTokenVerifier $idTokens
    ) {
    }

    public function code(): string
    {
        return 'google';
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
        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->configuration('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
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

        return $this->idTokens->verify($idToken, $expectedNonceHash);
    }

    private function configuration(string $key): string
    {
        $value = config('v2_identity.external_identity.google.'.$key);
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
