<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class V2LineOidcHttpTransport implements V2LineOidcTransport
{
    private const TOKEN_ENDPOINT = 'https://api.line.me/oauth2/v2.1/token';
    private const VERIFY_ENDPOINT = 'https://api.line.me/oauth2/v2.1/verify';

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        $this->assertRedirectUri($redirectUri);
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'authorization_code',
                    'code' => $authorizationCode,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $this->configuration('client_id'),
                    'client_secret' => $this->configuration('client_secret'),
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (Throwable) {
            throw new V2OidcProtocolException('provider_transport_failed');
        }
        $this->assertProviderResponse($response, 'authorization_code_rejected');
        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new V2OidcProtocolException('authorization_code_rejected');
        }

        return $idToken;
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post(self::VERIFY_ENDPOINT, [
                    'id_token' => $idToken,
                    'client_id' => $this->configuration('client_id'),
                ]);
        } catch (Throwable) {
            throw new V2OidcProtocolException('provider_transport_failed');
        }
        $this->assertProviderResponse($response, 'line_verify_rejected');
        $claims = $response->json();
        if (! is_array($claims)) {
            throw new V2OidcProtocolException('line_verify_rejected');
        }

        return $claims;
    }

    private function assertProviderResponse(Response $response, string $rejection): void
    {
        if ($response->status() === 429) {
            throw new V2OidcProtocolException('provider_rate_limited');
        }
        if ($response->serverError()) {
            throw new V2OidcProtocolException('provider_unavailable');
        }
        if (! $response->successful()) {
            throw new V2OidcProtocolException($rejection);
        }
    }

    private function configuration(string $key): string
    {
        $value = config('v2_identity.external_identity.line.'.$key);
        if (! is_string($value) || $value === '') {
            throw new V2OidcProtocolException('provider_configuration_unavailable');
        }

        return $value;
    }

    private function assertRedirectUri(string $redirectUri): void
    {
        $configured = $this->configuration('redirect_uri');
        if (
            ! hash_equals($configured, $redirectUri)
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https'
            || parse_url($redirectUri, PHP_URL_HOST) === null
            || parse_url($redirectUri, PHP_URL_USER) !== null
            || parse_url($redirectUri, PHP_URL_PASS) !== null
        ) {
            throw new V2OidcProtocolException('redirect_uri_rejected');
        }
    }
}
