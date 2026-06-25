<?php

namespace App\Domain\Notification\DTO;

class SmsSendResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly string $provider,
        public readonly ?string $messageId = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function sent(string $provider, ?string $messageId = null): self
    {
        return new self(true, $provider, $messageId);
    }

    public static function skipped(string $provider, string $reason): self
    {
        return new self(false, $provider, null, $reason);
    }
}
