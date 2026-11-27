<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaymentCancellationService
{
    public static function terminal(string $status): bool
    {
        return in_array($status, ['succeeded', 'failed', 'canceled', 'expired'], true);
    }

    public static function unavailable(): V2FincodeException
    {
        return new V2FincodeException('PAYMENT_NOT_CANCELLABLE', 409, 'This payment cannot be canceled.');
    }

    public function cancel(User $user, string $id, string $key): array
    {
        $payment = Str::isUuid($id) ? DB::table('payments')->where('user_id', $user->id)->where('public_id', $id)->first() : null;
        if (! $payment) throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');

        // Bind the key even for terminal or unsupported payments. Provider reconciliation can be retried
        // after this transaction commits, without holding the idempotency lock during external I/O.
        try {
            DB::transaction(function () use ($user, $payment, $key) {
                $keys = app(V2PointIdempotencyService::class);
                $claim = $keys->claim('payment.cancel', 'user', $user->public_id, $key, ['payment_id' => $payment->public_id]);
                if (! $claim->replay) $keys->complete($claim->record, 'payment', $payment->public_id);
            });
        } catch (V2PointException $error) {
            throw new V2FincodeException($error->getMessage(), 409, 'Payment request conflict.');
        }

        if (! self::terminal($payment->status)) {
            if ($payment->provider_code === 'stripe') {
                return app(V2StripeReconciliationService::class)->operation($user, $id, 'notice_cancel', null);
            }
            if ($payment->provider_code === 'paidy') {
                app(V2PaidyPaymentService::class)->cancelFromNotice($payment);
            } elseif (! self::terminal(DB::table('payments')->where('id', $payment->id)->value('status'))) {
                throw self::unavailable();
            }
        }
        return app(V2FincodePaymentService::class)->show($user, $id);
    }
}
