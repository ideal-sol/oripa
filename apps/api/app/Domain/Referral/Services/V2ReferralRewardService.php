<?php

namespace App\Domain\Referral\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use App\Models\V2\UserReferral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2ReferralRewardService
{
    public function __construct(
        private readonly V2ReferralPointSettingService $settings,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function record(User $referredUser, string $referralCode): UserReferral
    {
        return DB::transaction(function () use ($referredUser, $referralCode): UserReferral {
            $normalized = trim($referralCode);
            $referrer = User::query()->where('referral_code', $normalized)->lockForUpdate()->firstOrFail();
            if ((int) $referrer->getKey() === (int) $referredUser->getKey()) {
                throw new \InvalidArgumentException('A User cannot refer the same User.');
            }
            $existing = UserReferral::query()
                ->where('referred_user_id', $referredUser->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing instanceof UserReferral) {
                if ((int) $existing->referrer_user_id !== (int) $referrer->getKey()) {
                    throw new \InvalidArgumentException('The referred User already has a Referrer.');
                }

                return $existing;
            }
            $setting = $this->settings->setting(true);
            $referral = new UserReferral();
            $referral->forceFill([
                'public_id' => (string) Str::uuid7(),
                'referrer_user_id' => $referrer->getKey(),
                'referred_user_id' => $referredUser->getKey(),
                'referral_code' => $normalized,
                'status' => 'pending',
                'reward_enabled' => (bool) $setting->is_enabled,
                'referrer_point_amount' => (int) $setting->referrer_point_amount,
                'referred_user_point_amount' => (int) $setting->referred_user_point_amount,
                'reward_expiration_days' => (int) $setting->reward_expiration_days,
            ])->save();

            return $referral->refresh();
        }, 3);
    }

    public function rewardForReferredUser(User $referredUser): ?UserReferral
    {
        return DB::transaction(function () use ($referredUser): ?UserReferral {
            $referral = UserReferral::query()
                ->where('referred_user_id', $referredUser->getKey())
                ->lockForUpdate()
                ->first();
            if (! $referral instanceof UserReferral || $referral->status !== 'pending') {
                return $referral;
            }
            $now = now()->startOfSecond();
            if (
                ! $referral->reward_enabled
                || ((int) $referral->referrer_point_amount === 0
                    && (int) $referral->referred_user_point_amount === 0)
            ) {
                $referral->forceFill([
                    'status' => 'canceled',
                    'canceled_at' => $now,
                ])->save();

                return $referral->refresh();
            }
            $grants = [
                [
                    'beneficiary' => 'referrer',
                    'user_id' => (int) $referral->referrer_user_id,
                    'amount' => (int) $referral->referrer_point_amount,
                    'column' => 'referrer_point_operation_id',
                ],
                [
                    'beneficiary' => 'referred',
                    'user_id' => (int) $referral->referred_user_id,
                    'amount' => (int) $referral->referred_user_point_amount,
                    'column' => 'referred_user_point_operation_id',
                ],
            ];
            usort($grants, static fn (array $left, array $right): int => $left['user_id'] <=> $right['user_id']);
            $operations = [];
            foreach ($grants as $grant) {
                if ($grant['amount'] === 0) {
                    continue;
                }
                $operation = $this->points->grantReferralReward(
                    $grant['user_id'],
                    (int) $referral->getKey(),
                    $referral->public_id,
                    $grant['beneficiary'],
                    $grant['amount'],
                    $now
                );
                $operations[$grant['column']] = $operation->getKey();
            }
            $referral->forceFill([
                ...$operations,
                'status' => 'rewarded',
                'rewarded_at' => $now,
            ])->save();
            $this->audit->record('referral.reward.completed', [
                'actor_type' => 'system',
                'auth_realm' => 'system',
                'target_type' => 'user_referral',
                'target_public_id' => $referral->public_id,
                'metadata' => [
                    'referrer_point_amount' => (int) $referral->referrer_point_amount,
                    'referred_user_point_amount' => (int) $referral->referred_user_point_amount,
                ],
                'outcome' => 'success',
            ]);

            return $referral->refresh();
        }, 3);
    }
}
