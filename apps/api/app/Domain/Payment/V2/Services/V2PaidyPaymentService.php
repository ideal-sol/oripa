<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaidyPaymentService
{
    public function start(User $user, string $productId, string $key): array
    {
        app(V2PaidyConfiguration::class)->validate();
        app(V2SmsVerificationService::class)->assertVerified($user);
        $plan = DB::table('point_purchase_plans')->where('public_id', $productId)->first();
        if ($plan === null) throw new V2FincodeException('POINT_PRODUCT_NOT_FOUND', 404, 'The point product was not found.');
        try {
            $payment = DB::transaction(function () use ($user, $plan, $key): object {
                $payment = app(V2PaymentService::class)->createPayment((int) $user->id, (int) $plan->id, 'paidy', null, $key, 'paidy', true);
                if ($payment->status === 'created') {
                    $payment = app(V2PaymentService::class)->applyProviderStartResult((int) $payment->id, 'checkout', 'requires_action', CarbonImmutable::parse($payment->created_at)->addMinutes(60));
                }
                app(V2PaidyReconciliationService::class)->enqueuePayment($payment);
                return $payment;
            }, 3);
        } catch (V2PointException|V2PaymentException $exception) {
            throw self::translate($exception);
        }
        return app(V2FincodePaymentService::class)->show($user, $payment->public_id);
    }

    public function action(object $payment): ?array
    {
        if ($payment->provider_payment_id !== null || $payment->status !== 'requires_action'
            || CarbonImmutable::parse($payment->expires_at)->isPast()) return null;
        try { app(V2PaidyConfiguration::class)->validate(); }
        catch (V2FincodeException) { return null; }
        $classification = DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification');
        if ($classification !== (config('v2_paidy.mode') === 'test' ? 'test' : 'commercial')) return null;
        $user = User::query()->findOrFail($payment->user_id);
        if ($user->email_verified_at === null) throw new V2FincodeException('EMAIL_VERIFICATION_REQUIRED', 403, 'Verified email is required.');
        $status = app(V2SmsVerificationService::class)->status($user);
        $phone = $status['phone'];
        if (is_string($phone) && str_starts_with($phone, '+81')) $phone = '0'.substr($phone, 3);
        if (! is_string($phone) || ! preg_match('/\A0[789]0[0-9]{8}\z/', $phone)) {
            throw new V2FincodeException('SMS_VERIFICATION_REQUIRED', 403, 'A verified Japanese mobile number is required.');
        }
        $buyer = ['email' => $user->email_display, 'phone' => $phone];
        $name = trim(preg_replace('/[\s\x{3000}]+/u', ' ', (string) $user->full_name));
        if ($name !== '' && str_contains($name, ' ')) $buyer['name1'] = $name;
        return [
            'type' => 'paidy_checkout', 'script_url' => 'https://apps.paidy.com/', 'public_key' => config('v2_paidy.public_key'),
            'payload' => [
                'amount' => (int) $payment->amount, 'currency' => 'JPY', 'store_name' => config('v2_paidy.store_name'), 'description' => 'コイン購入',
                'buyer' => $buyer, 'buyer_data' => app(V2PaidyBuyerData::class)->forUser($user),
                'order' => ['order_ref' => $payment->public_id, 'shipping' => 0, 'tax' => 0,
                    'items' => [['id' => $payment->point_product_public_id, 'title' => $payment->plan_name_snapshot, 'quantity' => 1, 'unit_price' => (int) $payment->amount]]],
            ],
        ];
    }

    public function authorize(User $user, string $paymentId, array $input, string $key): array
    {
        $payment = $this->owned($user, $paymentId);
        app(V2SmsVerificationService::class)->assertVerified($user);
        if (array_keys($input) !== ['paidy_payment_id'] || ! V2PaidyClient::validId($input['paidy_payment_id'])) {
            throw new V2FincodeException('PAYMENT_REQUEST_INVALID', 422, 'The Paidy authorization request is invalid.');
        }
        try {
            DB::transaction(function () use ($user, $payment, $input, $key): void {
                $idempotency = app(V2PointIdempotencyService::class);
                $claim = $idempotency->claim('payment.paidy.authorize', 'user', $user->public_id, $key, ['payment_id' => $payment->public_id, ...$input]);
                if (! $claim->replay) {
                    // The durable lookup is completed before acknowledging the browser.
                    app(V2PaidyReconciliationService::class)->enqueueLookup($input['paidy_payment_id'], $payment->public_id);
                    $idempotency->complete($claim->record, 'payment', $payment->public_id);
                }
            }, 3);
        } catch (V2PointException $exception) { throw self::translate($exception); }
        // Uses exactly the same durable lease as the worker; HTTP is outside transactions.
        app(V2PaidyReconciliationService::class)->runLookup($input['paidy_payment_id'], $payment->public_id);
        return app(V2FincodePaymentService::class)->show($user, $payment->public_id);
    }

    private function owned(User $user, string $id): object
    {
        $payment = Str::isUuid($id) ? DB::table('payments')->where('public_id', $id)->where('user_id', $user->id)->first() : null;
        if ($payment === null) throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        if ($payment->provider_code !== 'paidy') throw new V2FincodeException('PAYMENT_PROVIDER_MISMATCH', 409, 'The payment provider does not match.');
        return $payment;
    }

    private static function translate(\Throwable $exception): V2FincodeException
    {
        $code = match ($exception->getMessage()) {
            'IDEMPOTENCY_KEY_REUSED' => 'IDEMPOTENCY_KEY_REUSED',
            'IDEMPOTENCY_REQUEST_IN_PROGRESS' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
            'POINT_PRODUCT_NOT_PURCHASABLE', 'POINT_PRODUCT_UNAVAILABLE' => 'POINT_PRODUCT_NOT_PURCHASABLE',
            'POINT_PURCHASE_FIRST_PURCHASE_REQUIRED' => 'POINT_PURCHASE_FIRST_PURCHASE_REQUIRED',
            'POINT_PURCHASE_USER_TAG_REQUIRED' => 'POINT_PURCHASE_USER_TAG_REQUIRED',
            default => 'PAYMENT_REQUEST_INVALID',
        };
        return new V2FincodeException($code, str_starts_with($code, 'IDEMPOTENCY') ? 409 : 422, 'The payment request could not be accepted.');
    }
}
