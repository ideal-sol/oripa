<?php

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Services\V2VerifiedExternalIdentity;
use SensitiveParameter;

interface V2ExternalIdentityProvider
{
    public function code(): string;

    public function issuer(): string;

    public function redirectUri(): string;

    public function authorizationUrl(
        #[SensitiveParameter] string $state,
        #[SensitiveParameter] string $nonce,
        #[SensitiveParameter] string $codeChallenge
    ): string;

    public function verifyAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        #[SensitiveParameter] string $expectedNonceHash
    ): V2VerifiedExternalIdentity;
}
