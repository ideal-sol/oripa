<?php

namespace App\Domain\Notification\DTO;

class SmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?int $userId = null,
        public readonly string $purpose = 'registration',
        public readonly array $metadata = [],
    ) {
    }
}
