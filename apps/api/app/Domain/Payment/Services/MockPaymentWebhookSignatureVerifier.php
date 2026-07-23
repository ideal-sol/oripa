<?php

namespace App\Domain\Payment\Services;

class MockPaymentWebhookSignatureVerifier
{
    public function verify(string $payload, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, (string) config('oripa.payment.mock_webhook_secret'));

        return hash_equals($expected, $signature);
    }
}
