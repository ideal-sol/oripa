<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;

final class V2FincodePublicConfiguration
{
    /** @return array{provider: string, public_api_key: string, is_live_mode: bool} */
    public function bootstrap(): array
    {
        if (config('v2_fincode.enabled') !== true) {
            throw new V2FincodeException(
                'FINCODE_ACTIVATION_DEFERRED',
                503,
                'The payment provider is not activated.'
            );
        }

        $baseUrl = config('v2_fincode.base_url');
        $publicKey = config('v2_fincode.public_api_key');
        $secretKey = config('v2_fincode.secret_api_key');
        $webhookSignature = config('v2_fincode.webhook_signature');
        $timeout = config('v2_fincode.timeout_seconds');
        $isLiveMode = match ($baseUrl) {
            'https://api.test.fincode.jp' => false,
            'https://api.fincode.jp' => true,
            default => null,
        };

        if (
            $isLiveMode === null
            || ! is_string($publicKey)
            || ! preg_match('/^p_(?:test|prod)_[^\s]{1,500}$/', $publicKey)
            || strlen($publicKey) > 512
            || ! is_string($secretKey)
            || ! preg_match('/^m_(?:test|prod)_[^\s]{1,500}$/', $secretKey)
            || strlen($secretKey) > 512
            || ! is_string($webhookSignature)
            || $webhookSignature === ''
            || strlen($webhookSignature) > 512
            || ! is_int($timeout)
            || $timeout < 1
            || $timeout > 30
        ) {
            throw $this->unavailable();
        }

        $publicIsLive = str_starts_with($publicKey, 'p_prod_');
        $secretIsLive = str_starts_with($secretKey, 'm_prod_');
        if (
            $publicIsLive !== $isLiveMode
            || $secretIsLive !== $isLiveMode
            || app()->environment('production') !== $isLiveMode
        ) {
            throw $this->unavailable();
        }

        return [
            'provider' => 'fincode',
            'public_api_key' => $publicKey,
            'is_live_mode' => $isLiveMode,
        ];
    }

    private function unavailable(): V2FincodeException
    {
        return new V2FincodeException(
            'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE',
            503,
            'The payment provider public configuration is unavailable.'
        );
    }
}
