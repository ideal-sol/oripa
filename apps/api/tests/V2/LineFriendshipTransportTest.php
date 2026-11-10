<?php

namespace Tests\V2;

use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use App\Domain\Identity\Services\V2LineExternalIdentityProvider;
use App\Domain\Identity\Services\V2LineOidcHttpTransport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LineFriendshipTransportTest extends TestCase
{
    private const REDIRECT = 'https://storefront.example.test/api/v2/auth/external/line/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2_line.friendship_check_enabled' => true,
            'v2_identity.external_identity.line.client_id' => 'synthetic-client',
            'v2_identity.external_identity.line.client_secret' => 'synthetic-secret',
            'v2_identity.external_identity.line.redirect_uri' => self::REDIRECT]);
        Http::preventStrayRequests();
    }

    private function exchanged(): V2LineOidcHttpTransport
    {
        $transport = new V2LineOidcHttpTransport();
        self::assertSame('synthetic-id-token', $transport->exchangeAuthorizationCode('synthetic-code', 'synthetic-verifier', self::REDIRECT));
        return $transport;
    }

    public function test_boolean_status_and_ephemeral_token_disposal(): void
    {
        foreach ([true, false] as $flag) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::preventStrayRequests();
            Http::fake(['*/oauth2/v2.1/token' => Http::response(['id_token' => 'synthetic-id-token', 'access_token' => 'test-token']),
                '*/friendship/v1/status' => Http::response(['friendFlag' => $flag])]);
            $transport = $this->exchanged();
            self::assertSame($flag, $transport->friendshipStatus());
            Http::assertSent(fn ($request) => $request->url() === 'https://api.line.me/friendship/v1/status'
                && $request->hasHeader('Authorization', 'Bearer test-token'));
            try {
                $transport->friendshipStatus();
                self::fail('Access token was retained');
            } catch (V2OidcProtocolException $exception) {
                self::assertSame('friendship_token_unavailable', $exception->reasonCode);
            }
        }
    }

    public function test_provider_failures_and_invalid_boolean_are_sanitized(): void
    {
        foreach ([[400, []], [401, []], [429, []], [500, []], [200, []], [200, ['friendFlag' => 'true']], [302, []]] as [$status, $body]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::preventStrayRequests();
            Http::fake(['*/oauth2/v2.1/token' => Http::response(['id_token' => 'synthetic-id-token', 'access_token' => 'test-token']),
                '*/friendship/v1/status' => Http::response($body, $status)]);
            $transport = $this->exchanged();
            try {
                $transport->friendshipStatus();
                self::fail('Invalid provider response was accepted');
            } catch (V2OidcProtocolException $exception) {
                self::assertStringNotContainsString('test-token', $exception->getMessage());
            }
        }
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*/oauth2/v2.1/token' => Http::response(['id_token' => 'synthetic-id-token', 'access_token' => 'test-token']),
            '*/friendship/v1/status' => Http::failedConnection()]);
        try {
            $this->exchanged()->friendshipStatus();
            self::fail('Transport failure was accepted');
        } catch (V2OidcProtocolException $exception) {
            self::assertSame('friendship_transport_failed', $exception->reasonCode);
        }
    }

    public function test_bot_prompt_is_optional_and_allowlisted(): void
    {
        $provider = app(V2LineExternalIdentityProvider::class);
        foreach (['none', 'normal', 'aggressive'] as $prompt) {
            config(['v2_identity.external_identity.line.bot_prompt' => $prompt]);
            parse_str(parse_url($provider->authorizationUrl('synthetic-state', 'synthetic-nonce', 'synthetic-challenge'), PHP_URL_QUERY), $query);
            self::assertSame('openid profile', $query['scope']);
            self::assertSame($prompt === 'none' ? null : $prompt, $query['bot_prompt'] ?? null);
        }
        config(['v2_identity.external_identity.line.bot_prompt' => 'invalid']);
        $this->expectException(V2OidcProtocolException::class);
        $provider->authorizationUrl('synthetic-state', 'synthetic-nonce', 'synthetic-challenge');
    }
}
