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
    private ?string $accessToken = null;

    public function discardAccessToken(): void
    {
        $this->accessToken = null;
    }

    public function friendshipStatus(): bool
    {
        try {
            if ($this->accessToken === null) {
                throw new V2OidcProtocolException('friendship_token_unavailable');
            }
            $response = Http::acceptJson()->withToken($this->accessToken)
                ->timeout(3)->connectTimeout(3)->withoutRedirecting()
                ->get('https://api.line.me/friendship/v1/status');
            $this->assertProviderResponse($response, 'friendship_check_rejected');
            $flag = $response->json('friendFlag');
            if (! is_bool($flag)) {
                throw new V2OidcProtocolException('friendship_response_invalid');
            }
            return $flag;
        } catch (V2OidcProtocolException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new V2OidcProtocolException('friendship_transport_failed');
        } finally {
            $this->discardAccessToken();
        }
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        $this->discardAccessToken();
        $this->assertRedirectUri($redirectUri);
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->withoutRedirecting()
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

        $accessToken = $response->json('access_token');
        if ((bool) config('v2_line.friendship_check_enabled', false) && is_string($accessToken) && $accessToken !== '') {
            $this->accessToken = $accessToken;
        }
        return $idToken;
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(8)
                ->withoutRedirecting()
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
        $value = app(\App\Domain\Line\Services\V2LineConfiguration::class)->login($key);
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
