<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class V2GoogleOidcHttpTransport implements V2GoogleOidcTransport
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const JWKS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';
    private const JWKS_CACHE_KEY = 'v2:identity:google:jwks:v1';

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        $clientId = $this->configuration('client_id');
        $clientSecret = $this->configuration('client_secret');
        $this->assertRedirectUri($redirectUri);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'authorization_code',
                    'code' => $authorizationCode,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (Throwable) {
            throw new V2OidcProtocolException('provider_transport_failed');
        }

        $idToken = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            throw new V2OidcProtocolException('authorization_code_rejected');
        }

        return $idToken;
    }

    public function jwks(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        try {
            return Cache::remember(
                self::JWKS_CACHE_KEY,
                now()->addMinutes(30),
                function (): array {
                    $response = Http::acceptJson()
                        ->timeout(8)
                        ->get(self::JWKS_ENDPOINT);
                    $keys = $response->successful() ? $response->json() : null;
                    if (
                        ! is_array($keys)
                        || ! isset($keys['keys'])
                        || ! is_array($keys['keys'])
                    ) {
                        throw new V2OidcProtocolException('jwks_unavailable');
                    }

                    return $keys;
                }
            );
        } catch (V2OidcProtocolException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new V2OidcProtocolException('jwks_unavailable');
        }
    }

    private function configuration(string $key): string
    {
        $value = config('v2_identity.external_identity.google.'.$key);
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
