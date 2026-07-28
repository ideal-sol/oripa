<?php

namespace App\Domain\Identity\Contracts;

use SensitiveParameter;

interface V2GoogleOidcTransport
{
    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string;

    /**
     * @return array<string, mixed>
     */
    public function jwks(bool $refresh = false): array;
}
