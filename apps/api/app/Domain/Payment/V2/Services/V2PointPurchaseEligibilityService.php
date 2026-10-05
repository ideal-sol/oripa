<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2PointPurchaseEligibilityService
{
    public const AUDIENCE_ALL = 'all_users';
    public const AUDIENCE_FIRST_PURCHASE = 'first_purchase_users';

    public function assertEligible(User $user, object $plan, CarbonImmutable $asOf): void
    {
        $this->assertSettlementEligible($user, $plan);
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE
            && (! $this->isWithinFirstUserWindow($user, $asOf)
                || $this->hasUsedOrPendingProduct($user->id, $plan->code))) {
            throw new V2PaymentException('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED');
        }
    }

    public function assertSettlementEligible(User $user, object $plan): void
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
    }

    /** @return array{eligible: bool, reason: ?string} */
    public function evaluate(User $user, object $plan, CarbonImmutable $asOf): array
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
            && (! $this->isWithinFirstUserWindow($user, $asOf)
                || $this->hasUsedOrPendingProduct($user->id, $plan->code))) {
            return ['eligible' => false, 'reason' => 'first_purchase_required'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function eligible(User $user, object $plan, CarbonImmutable $asOf): bool
    {
        return $this->evaluate($user, $plan, $asOf)['eligible'];
    }

    private function hasTag(int $userId, int $tagId): bool
    {
        return DB::table('user_tag_assignments')
            ->where('user_id', $userId)
            ->where('user_tag_id', $tagId)
            ->exists();
    }

    private function hasUsedOrPendingProduct(int $userId, string $planCode): bool
    {
        return DB::table('payments as payment')
            ->join('point_purchase_plans as plan', 'plan.id', '=', 'payment.point_purchase_plan_id')
            ->where('payment.user_id', $userId)
            ->where('plan.code', $planCode)
            ->where(function (Builder $blocked): void {
                $blocked->whereNotIn('payment.status', ['failed', 'canceled', 'expired'])
                    ->orWhereExists(function (Builder $history): void {
                        $history->selectRaw('1')->from('payment_status_histories as history')
                            ->whereColumn('history.payment_id', 'payment.id')
                            ->where('history.to_status', 'succeeded');
                    })
                    ->orWhere(function (Builder $asynchronous): void {
                        $asynchronous->whereIn('payment.payment_method', ['konbini', 'virtual_account'])
                            ->where(function (Builder $started): void {
                                $started->whereExists(function (Builder $attempt): void {
                                    $attempt->selectRaw('1')->from('fincode_payment_attempts as attempt')
                                        ->whereColumn('attempt.payment_id', 'payment.id')
                                        ->whereNotNull('attempt.provider_session_id')
                                        ->whereNotNull('attempt.redirect_url_ciphertext');
                                })->orWhereExists(function (Builder $history): void {
                                    $history->selectRaw('1')->from('payment_status_histories as history')
                                        ->whereColumn('history.payment_id', 'payment.id')
                                        ->whereIn('history.to_status', ['requires_action', 'processing'])
                                        ->whereIn('history.transition_source', ['provider_api', 'provider_event']);
                                });
                            });
                    });
            })->exists();
    }

    private function isWithinFirstUserWindow(User $user, CarbonImmutable $asOf): bool
    {
        $qualifiedAt = $user->first_registration_qualified_at?->utc();

        return $qualifiedAt !== null
            && $qualifiedAt->lessThanOrEqualTo($asOf)
            && $asOf->lessThan($qualifiedAt->addHours(24));
    }
}
