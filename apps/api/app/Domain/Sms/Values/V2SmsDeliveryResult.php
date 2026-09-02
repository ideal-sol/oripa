<?php

namespace App\Domain\Sms\Values;

use InvalidArgumentException;

final readonly class V2SmsDeliveryResult
{
    private function __construct(
        public string $state,
        public ?string $providerRequestId,
        public ?string $errorCategory
    ) {
        if (! in_array($state, ['accepted', 'failed', 'unknown'], true)) {
            throw new InvalidArgumentException('SMS delivery result state is invalid.');
        }
        if (($state === 'accepted') !== ($providerRequestId !== null)) {
            throw new InvalidArgumentException('SMS provider request ID is invalid.');
        }
        if (($state === 'accepted') === ($errorCategory !== null)) {
            throw new InvalidArgumentException('SMS delivery error category is invalid.');
        }
    }

    public static function accepted(string $providerRequestId): self
    {
        return new self('accepted', $providerRequestId, null);
    }

    public static function failed(string $errorCategory): self
    {
        return new self('failed', null, $errorCategory);
    }

    public static function unknown(string $errorCategory): self
    {
        return new self('unknown', null, $errorCategory);
    }
}
