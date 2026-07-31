<?php

namespace App\Domain\QaDraw\ValueObjects;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;

final readonly class V2AdminQaDrawCommand
{
    public function __construct(
        public V2AdminAuthorizationContext $actor,
        public string $planPublicId,
        public int $planRevision,
        public string $assignmentPublicId,
        public int $assignmentRevision
    ) {
    }
}
