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
        $targetTagId = $plan->target_user_tag_id ?? null;
        if (($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE || $targetTagId !== null)
            && DB::transactionLevel() < 1) {
            throw new V2PaymentException('POINT_PURCHASE_ELIGIBILITY_TRANSACTION_REQUIRED');
        }
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE || $targetTagId !== null) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
        }
        if ($targetTagId !== null && ! $this->hasTag($user->id, (int) $targetTagId)) {
            throw new V2PaymentException('POINT_PURCHASE_USER_TAG_REQUIRED');
        }
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE) {
            if ($this->hasSuccessfulPurchase($user->id, $currentPaymentId)) {
                throw new V2PaymentException('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED');
            }
        }
    }

    /** @return array{eligible: bool, reason: ?string} */
    public function evaluate(User $user, object $plan): array
    {
        if (! in_array($plan->audience_code, [
            self::AUDIENCE_ALL,
            self::AUDIENCE_FIRST_PURCHASE,
        ], true)) {
            return ['eligible' => false, 'reason' => 'audience_not_eligible'];
        }
        $targetTagId = $plan->target_user_tag_id ?? null;
        if ($targetTagId !== null && ! $this->hasTag($user->id, (int) $targetTagId)) {
            return ['eligible' => false, 'reason' => 'audience_not_eligible'];
        }
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE
            && $this->hasSuccessfulPurchase($user->id)) {
            return ['eligible' => false, 'reason' => 'first_purchase_required'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function eligible(User $user, object $plan): bool
    {
        return $this->evaluate($user, $plan)['eligible'];
    }

    private function hasTag(int $userId, int $tagId): bool
    {
        return DB::table('user_tag_assignments')
            ->where('user_id', $userId)
            ->where('user_tag_id', $tagId)
            ->exists();
    }

    private function hasSuccessfulPurchase(int $userId, ?int $excludedPaymentId = null): bool
    {
        return DB::table('payments')
            ->where('user_id', $userId)
            ->where('status', 'succeeded')
            ->when(
                $excludedPaymentId !== null,
                fn ($query) => $query->where('id', '!=', $excludedPaymentId)
            )
            ->exists();
    }
}
