<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaymentMethodPolicy
{
    public const LABELS = [
        'credit_card' => 'クレジットカード', 'paypay' => 'PayPay',
        'konbini' => 'コンビニ決済', 'virtual_account' => '銀行振込',
        'netridecash' => '電子マネー（NetRideCash）', 'paidy' => 'あと払い（ペイディ）',
    ];

    public static function methodLabel(?string $method): string
    {
        return self::LABELS[$method ?? ''] ?? ($method ?? '不明');
    }

    public static function providerLabel(string $provider): string
    {
        return ['univapay' => 'UnivaPay', 'techcoin' => 'テックコイン', 'paidy' => 'ペイディ', 'fincode' => 'fincode'][$provider] ?? $provider;
    }

    public function enabled(): array
    {
        $setting = config('v2_payment.methods_enabled');
        if (! is_string($setting)) {
            return [];
        }
        $methods = array_map('trim', explode(',', $setting));
        if (count(array_unique($methods)) !== count($methods) || array_diff($methods, array_keys(self::LABELS))) {
            return [];
        }

        return $methods;
    }

    public function provider(string $method): ?string
    {
        return match ($method) {
            'credit_card' => in_array(config('v2_payment.credit_card_provider'), ['fincode', 'univapay'], true)
                ? config('v2_payment.credit_card_provider') : null,
            'netridecash' => 'techcoin', 'paidy' => 'paidy',
            'paypay', 'konbini', 'virtual_account' => 'fincode',
            default => null,
        };
    }

    public function savedCardsEnabled(): bool
    {
        return config('v2_payment.saved_cards_enabled') === true;
    }

    public function assertSavedCardsEnabled(): void
    {
        if (! $this->savedCardsEnabled()) {
            throw new V2FincodeException('PAYMENT_SAVED_CARDS_DISABLED', 409, 'Saved card operations are disabled.');
        }
    }

    public function methods(?string $productId): array
    {
        $amount = $productId === null ? null : $this->amount($productId);
        $result = [];
        foreach ($this->enabled() as $method) {
            $provider = $this->provider($method);
            if ($provider === 'univapay' && $amount !== null && $amount > 100000) {
                continue;
            }
            $reason = $provider === 'techcoin' && $amount !== null && $amount > 999999
                ? 'amount_too_large' : ($this->available($provider) ? null : 'provider_unavailable');
            $result[] = [
                'method' => $method, 'label' => self::methodLabel($method),
                'available' => $reason === null, 'unavailable_reason' => $reason,
                'saved_cards_enabled' => $method === 'credit_card' && $provider === 'fincode' && $this->savedCardsEnabled(),
                'flow' => match (true) {
                    $provider === 'univapay' => 'inline_card_form',
                    $method === 'paidy' => 'paidy_checkout',
                    $method === 'credit_card' => 'card_component',
                    default => 'redirect',
                },
            ];
        }

        return ['data' => $result];
    }

    public function assertNewPayment(string $method, string $productId, ?array $card, bool $cardPresent): void
    {
        if (! in_array($method, $this->enabled(), true)) {
            throw new V2FincodeException('PAYMENT_METHOD_UNAVAILABLE', 422, 'The payment method is unavailable.');
        }
        $provider = $this->provider($method);
        if ($provider !== 'fincode' && $cardPresent) {
            throw new V2FincodeException('PAYMENT_REQUEST_INVALID', 422, 'Card input is not accepted for this payment method.');
        }
        if (($card['source'] ?? null) === 'saved' || ($card['save'] ?? false) === true) {
            $this->assertSavedCardsEnabled();
        }
        $amount = $this->amount($productId);
        if (($provider === 'univapay' && $amount > 100000) || ($provider === 'techcoin' && $amount > 999999)) {
            throw new V2FincodeException('PAYMENT_AMOUNT_TOO_LARGE', 422, 'The payment amount exceeds the provider limit.');
        }
        if (! $this->available($provider)) {
            throw new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.');
        }
    }

    private function available(?string $provider): bool
    {
        // PAY2–PAY4 supply real adapters. Configuration alone cannot enable an unimplemented provider.
        if ($provider !== 'fincode') {
            return false;
        }
        try {
            app(V2FincodePublicConfiguration::class)->bootstrap();

            return true;
        } catch (V2FincodeException) {
            return false;
        }
    }

    private function amount(string $productId): int
    {
        if (! Str::isUuid($productId)) {
            throw new V2FincodeException('PAYMENT_REQUEST_INVALID', 422, 'The point product identifier is invalid.');
        }
        $plan = DB::table('point_purchase_plans')->where('public_id', $productId)->where('status', 'published')->first();
        if ($plan === null) {
            throw new V2FincodeException('POINT_PRODUCT_NOT_FOUND', 404, 'The point product was not found.');
        }

        return (int) $plan->amount;
    }
}
