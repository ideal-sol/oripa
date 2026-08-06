<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;

final class V2PointPurchaseEligibilityService
{
    public const AUDIENCE_ALL = 'all_users';
    public const AUDIENCE_FIRST_PURCHASE = 'first_purchase_users';

    public function assertEligible(User $user, object $plan, ?int $currentPaymentId = null): void
    {
        if (! in_array($plan->audience_code, [
            self::AUDIENCE_ALL,
            self::AUDIENCE_FIRST_PURCHASE,
        ], true)) {
            throw new V2PaymentException('POINT_PURCHASE_AUDIENCE_INVALID');
        }
        if ($plan->audience_code === self::AUDIENCE_ALL) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new V2PaymentException('POINT_PURCHASE_ELIGIBILITY_TRANSACTION_REQUIRED');
        }

        DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
        $successful = DB::table('payments')
            ->where('user_id', $user->id)
            ->where('status', 'succeeded')
            ->when(
                $currentPaymentId !== null,
                fn ($query) => $query->where('id', '!=', $currentPaymentId)
            )
            ->exists();
        if ($successful) {
            throw new V2PaymentException('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED');
        }
    }

    public function eligible(User $user, object $plan): bool
    {
        if ($plan->audience_code === self::AUDIENCE_ALL) {
            return true;
        }
        if ($plan->audience_code !== self::AUDIENCE_FIRST_PURCHASE) {
            return false;
        }

        return ! DB::table('payments')
            ->where('user_id', $user->id)
            ->where('status', 'succeeded')
            ->exists();
    }
}
