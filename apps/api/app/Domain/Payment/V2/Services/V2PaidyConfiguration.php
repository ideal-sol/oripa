<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;

final class V2PaidyConfiguration
{
    public function validate(): void
    {
        if (config('v2_paidy.api_base_url') !== 'https://api.paidy.com'
            || config('v2_paidy.api_version') !== '2018-04-10'
            || ! in_array(config('v2_paidy.mode'), ['test', 'live'], true)
            || ! $this->matches(config('v2_paidy.public_key'), '/\Apk_[A-Za-z0-9_-]{8,250}\z/')
            || ! $this->matches(config('v2_paidy.secret_key'), '/\Ask_[A-Za-z0-9_-]{8,250}\z/')
            || ! $this->matches(config('v2_paidy.webhook_path_token'), '/\A[A-Za-z0-9_-]{32,256}\z/')
            || ! is_string(config('v2_paidy.store_name')) || trim(config('v2_paidy.store_name')) === ''
            || mb_strlen(config('v2_paidy.store_name')) > 128) {
            throw new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.');
        }
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }
}
