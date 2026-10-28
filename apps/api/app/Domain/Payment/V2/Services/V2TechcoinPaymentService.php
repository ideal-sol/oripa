<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2TechcoinPaymentService
{
    public function start(User $user, string $productId, string $key): array
    {
        app(V2TechcoinConfiguration::class)->assertAvailable();
        app(V2PaymentMethodPolicy::class)->assertNewPayment('netridecash', $productId, null, false);
        $this->verifiedEmail($user);
        try {
            $payment = app(V2PointTransactionRunner::class)->run(function () use ($user, $productId, $key): object {
                $plan = DB::table('point_purchase_plans')->where('public_id', $productId)->firstOrFail();
                $payments = app(V2PaymentService::class);
                $payment = $payments->createPayment((int) $user->id, (int) $plan->id, 'techcoin', null, $key,
                    'netridecash', requireSmsVerification: true);
                if ((int) $payment->amount > 999999) {
                    throw new V2FincodeException('PAYMENT_AMOUNT_TOO_LARGE', 422, 'The payment amount exceeds the provider limit.');
                }
                DB::table('payment_commercial_classifications')->insertOrIgnore([
                    'payment_id' => $payment->id,
                    'classification' => config('v2_techcoin.mode') === 'live' ? 'commercial' : 'test',
                    'reason' => config('v2_techcoin.mode') === 'live' ? 'provider_live_mode' : 'provider_test_mode',
                ]);
                if ($payment->status === 'created') {
                    $payment = $payments->applyProviderStartResult((int) $payment->id, 'FORM_READY', 'requires_action',
                        CarbonImmutable::parse($payment->created_at)->addHours(24));
                }
                return $payment;
            });
        } catch (V2PointException $exception) {
            $code = in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)
                ? $exception->getMessage() : 'PAYMENT_START_REJECTED';
            throw new V2FincodeException($code, 409, 'The payment could not be started.');
        } catch (V2PaymentException $exception) {
            throw new V2FincodeException($exception->getMessage() === 'PURCHASE_PLAN_NOT_AVAILABLE'
                ? 'POINT_PRODUCT_NOT_AVAILABLE' : 'PAYMENT_START_REJECTED', 409, 'The payment could not be started.');
        }
        return app(V2FincodePaymentService::class)->show($user, $payment->public_id);
    }

    public function action(object $payment): ?array
    {
        if ($payment->provider_code !== 'techcoin' || $payment->status !== 'requires_action'
            || $payment->expires_at === null || CarbonImmutable::parse($payment->expires_at)->lessThanOrEqualTo(now())
            || ! app(V2TechcoinConfiguration::class)->available()) return null;
        $user = User::query()->findOrFail($payment->user_id);
        if ($user->email_verified_at === null) return null;
        $fields = [
            'clientip' => config('v2_techcoin.client_ip'), 'payment_code' => '10055',
            'cookie' => V2TechcoinIdentity::cookie($payment->public_id), 'price' => (string) $payment->amount,
            'sendid' => V2TechcoinIdentity::sendid($user->public_id), 'email' => $this->verifiedEmail($user),
        ];
        return ['type' => 'form_post', 'url' => V2TechcoinConfiguration::ENDPOINT,
            'fields' => array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($fields), array_values($fields))];
    }

    private function verifiedEmail(User $user): string
    {
        if ($user->email_verified_at === null || ! is_string($user->email_normalized)
            || filter_var($user->email_normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new V2FincodeException('EMAIL_VERIFICATION_REQUIRED', 403, 'A verified email is required.');
        }
        return $user->email_normalized;
    }
}
