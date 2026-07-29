<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use SensitiveParameter;
use Throwable;

final class V2GoogleIdTokenVerifier
{
    private const ISSUER = 'https://accounts.google.com';
    private const ALGORITHM = 'RS256';

    public function __construct(
        private readonly V2GoogleOidcTransport $transport,
        private readonly V2SecureToken $tokens,
        private readonly V2EmailNormalizer $emails
    ) {
    }

    public function verify(
        #[SensitiveParameter] string $idToken,
        #[SensitiveParameter] string $expectedNonceHash
    ): V2VerifiedExternalIdentity {
        $header = $this->header($idToken);
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new V2OidcProtocolException('unsupported_algorithm');
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new V2OidcProtocolException('missing_key_id');
        }

        $keys = $this->keysFor($kid, false);
        if (! isset($keys[$kid])) {
            $keys = $this->keysFor($kid, true);
        }
        if (! isset($keys[$kid])) {
            throw new V2OidcProtocolException('unknown_key_id');
        }

        $now = now()->getTimestamp();
        $skew = (int) config('v2_identity.external_identity.clock_skew_seconds', 60);
        $previousTimestamp = JWT::$timestamp;
        $previousLeeway = JWT::$leeway;
        JWT::$timestamp = $now;
        JWT::$leeway = $skew;
        try {
            $claims = (array) JWT::decode($idToken, $keys[$kid]);
        } catch (Throwable) {
            throw new V2OidcProtocolException('invalid_signature_or_claims');
        } finally {
            JWT::$timestamp = $previousTimestamp;
            JWT::$leeway = $previousLeeway;
        }

        $clientId = config('v2_identity.external_identity.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            throw new V2OidcProtocolException('provider_configuration_unavailable');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience)
            ? [$audience]
            : (is_array($audience) ? array_values($audience) : []);
        if (
            ($claims['iss'] ?? null) !== self::ISSUER
            || ! in_array($clientId, $audiences, true)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || ! isset($claims['exp'], $claims['iat'])
            || ! is_int($claims['exp'])
            || ! is_int($claims['iat'])
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
        $email = $claims['email'] ?? null;
        $verified = $claims['email_verified'] ?? null;
        if (
            ! is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || ! is_string($email)
            || $email === ''
            || ! in_array($verified, [true, 'true'], true)
        ) {
            throw new V2OidcProtocolException('verified_identity_required');
        }

        try {
            $normalized = $this->emails->normalize($email);
        } catch (Throwable) {
            throw new V2OidcProtocolException('verified_identity_required');
        }

        return new V2VerifiedExternalIdentity(
            self::ISSUER,
            $subject,
            $email,
            $normalized
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function header(#[SensitiveParameter] string $jwt): array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new V2OidcProtocolException('malformed_id_token');
        }
        try {
            $header = json_decode(
                JWT::urlsafeB64Decode($segments[0]),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            throw new V2OidcProtocolException('malformed_id_token');
        }
        if (! is_array($header)) {
            throw new V2OidcProtocolException('malformed_id_token');
        }

        return $header;
    }

    /**
     * @return array<string, \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource>
     */
    private function keysFor(string $kid, bool $refresh): array
    {
        $jwks = $this->transport->jwks($refresh);
        $filtered = [];
        foreach ($jwks['keys'] ?? [] as $key) {
            if (
                is_array($key)
                && ($key['kid'] ?? null) === $kid
                && ($key['kty'] ?? null) === 'RSA'
                && (! isset($key['alg']) || $key['alg'] === self::ALGORITHM)
                && (! isset($key['use']) || $key['use'] === 'sig')
            ) {
                $filtered[] = $key;
            }
        }

        try {
            return JWK::parseKeySet(['keys' => $filtered], self::ALGORITHM);
        } catch (Throwable) {
            throw new V2OidcProtocolException('invalid_jwks');
        }
    }
}
