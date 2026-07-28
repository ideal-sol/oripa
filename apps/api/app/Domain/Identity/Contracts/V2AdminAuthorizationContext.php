<?php

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Enums\V2AdminRole;

final readonly class V2AdminAuthorizationContext
{
    public function __construct(
        public int $adminId,
        public string $adminPublicId,
        public V2AdminRole $role,
        public string $sessionIdHash,
        public string $sessionCorrelationHash,
        public string $requestId
    ) {
    }
}
