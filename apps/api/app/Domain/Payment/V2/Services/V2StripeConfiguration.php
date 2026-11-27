<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;

final class V2StripeConfiguration
{
    public const API_VERSION = '2026-11-18.endive';

    public function assertConfigured(): void
    {
        if (! $this->available()) {
            throw new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.');
        }
    }

    public function available(): bool
    {
        $mode = config('v2_stripe.mode');
        $minutes = filter_var(config('v2_stripe.checkout_expires_minutes'), FILTER_VALIDATE_INT);
        $limit = filter_var(config('v2_stripe.start_limit_per_hour'), FILTER_VALIDATE_INT);

        return in_array($mode, ['test', 'live'], true)
            && $minutes >= 35 && $minutes <= 1440 && $limit >= 1
            && preg_match('/\A(?:rk|sk)_'.$mode.'_[A-Za-z0-9]+\z/', (string) config('v2_stripe.secret_key')) === 1
            && preg_match('/\Apk_'.$mode.'_[A-Za-z0-9]+\z/', (string) config('v2_stripe.publishable_key')) === 1
            && preg_match('/\Awhsec_[A-Za-z0-9]+\z/', (string) config('v2_stripe.webhook_secret')) === 1;
    }
}
