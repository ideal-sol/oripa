<?php

namespace App\Domain\Line\Services;

/** D5 must bind a server-side immutable asset verifier. Caller-provided flags are never proof. */
class V2LineDeliveryMedia
{
    public function assertVerified(#[\SensitiveParameter] array $message): void
    {
        throw new \RuntimeException('LINE media verification is not available.');
    }
}
