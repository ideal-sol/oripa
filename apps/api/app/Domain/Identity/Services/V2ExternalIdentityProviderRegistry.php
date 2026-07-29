<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2ExternalIdentityProvider;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;

final class V2ExternalIdentityProviderRegistry
{
    /** @var array<string, V2ExternalIdentityProvider> */
    private array $providers;

    public function __construct(
        V2GoogleExternalIdentityProvider $google,
        V2LineExternalIdentityProvider $line
    ) {
        $this->providers = [
            $google->code() => $google,
            $line->code() => $line,
        ];
    }

    public function get(string $provider): V2ExternalIdentityProvider
    {
        return $this->providers[$provider]
            ?? throw new V2OidcProtocolException('provider_not_allowed');
    }
}
