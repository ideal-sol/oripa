<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Models\V2\User;
use Illuminate\Support\Str;

final class V2UnivapayConfiguration
{
    public function assertConfigured(): void
    {
        if (config('v2_univapay.api_base_url') !== 'https://api.univapay.com'
            || ! is_string(config('v2_univapay.store_id')) || ! Str::isUuid(config('v2_univapay.store_id'))
            || ! in_array(config('v2_univapay.mode'), ['live', 'test'], true)) {
            throw $this->unavailable();
        }
        foreach (['api_secret', 'webhook_path_token', 'webhook_auth_token'] as $key) {
            $value = config('v2_univapay.'.$key);
            if (! is_string($value) || strlen($value) < ($key === 'api_secret' ? 1 : 32)
                || strlen($value) > 4096 || preg_match('/\s/', $value)) {
                throw $this->unavailable();
            }
        }
        foreach (['form_app_token', 'api_app_token'] as $key) {
            $token = config('v2_univapay.'.$key);
            if (! is_string($token) || strlen($token) > 8192
                || ! preg_match('/\A[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+\z/', $token, $match)) {
                throw $this->unavailable();
            }
            $claims = json_decode(base64_decode(strtr($match[1], '-_', '+/'), true) ?: '', true);
            // Local consistency only; the provider verifies JWT authenticity. Never infer live from an unknown mode.
            if (! is_array($claims) || (isset($claims['mode']) && $claims['mode'] !== config('v2_univapay.mode'))
                || (isset($claims['exp']) && (! is_numeric($claims['exp']) || $claims['exp'] <= now()->timestamp))) {
                throw $this->unavailable();
            }
        }
        $ips = config('v2_univapay.notify_allowed_ips');
        if (! is_string($ips)) throw $this->unavailable();
        foreach ($ips === '' ? [] : explode(',', $ips) as $ip) {
            if (! filter_var(trim($ip), FILTER_VALIDATE_IP)) throw $this->unavailable();
        }
    }

    public function available(): bool
    {
        try { $this->assertConfigured(); return true; }
        catch (V2FincodeException) { return false; }
    }

    public function inlineForm(int $amount, User $user): ?array
    {
        if (! $this->available() || $user->email_verified_at === null) return null;

        return [
            'provider' => 'univapay', 'script_url' => 'https://widget.univapay.com/client/checkout.js',
            'options' => [
                'appId' => config('v2_univapay.form_app_token'), 'checkout' => 'payment',
                'amount' => $amount, 'currency' => 'jpy', 'tokenType' => 'one_time',
                'capture' => true, 'inline' => true, 'locale' => 'ja',
                'email' => $user->email_display, 'showCvv' => true,
            ],
        ];
    }

    private function unavailable(): V2FincodeException
    {
        return new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.');
    }
}
