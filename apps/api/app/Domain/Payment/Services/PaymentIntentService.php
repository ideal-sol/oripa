<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;

class PaymentIntentService
{
    public function create(
        User $user,
        int $amount,
        int $paidPointAmount,
        int $freePointAmount = 0,
        string $provider = 'mock',
        string $currency = 'JPY',
        array $metadata = [],
    ): Payment {
        $providerPaymentId = $provider.'_'.Str::uuid()->toString();

        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentStatus::Pending,
            'amount' => $amount,
            'paid_point_amount' => $paidPointAmount,
            'free_point_amount' => $freePointAmount,
            'currency' => $currency,
            'metadata' => [
                ...$metadata,
                'checkout' => [
                    'mode' => $provider,
                    'payment_id' => $providerPaymentId,
                ],
            ],
        ]);
    }
}
