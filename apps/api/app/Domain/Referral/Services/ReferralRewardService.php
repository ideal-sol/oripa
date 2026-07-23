<?php

namespace App\Domain\Referral\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Models\User;
use App\Models\UserReferral;

class ReferralRewardService
{
    public function __construct(private readonly PointLotService $pointLotService)
    {
    }

    public function rewardForReferredUser(User $referredUser): ?UserReferral
    {
        $referral = UserReferral::query()
            ->where('referred_user_id', $referredUser->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if (! $referral) {
            return null;
        }

        if ((int) $referral->reward_point_amount <= 0) {
            $referral->forceFill([
                'status' => 'canceled',
                'canceled_at' => now(),
            ])->save();

            return $referral->refresh();
        }

        $expireDays = $referral->reward_expiration_days ?? (int) config('oripa.free_point_expiration_days', 180);

        $this->pointLotService->grantFree(
            user: $referral->referrer,
            amount: (int) $referral->reward_point_amount,
            expireAt: now()->addDays((int) $expireDays),
            sourceType: PointLotSourceType::Referral,
            sourceId: $referral->id,
            ledgerType: PointLedgerType::Grant,
            relatedType: 'user_referral',
            relatedId: $referral->id,
            description: 'Referral reward granted after SMS verification.',
        );

        $referral->forceFill([
            'status' => 'rewarded',
            'rewarded_at' => now(),
        ])->save();

        return $referral->refresh();
    }
}
