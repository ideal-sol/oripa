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
                || $this->hasUsedOrPendingFirstPurchase($user->id, $plan->code))) {
            throw new V2PaymentException('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED');
        }
    }

    public function assertSettlementEligible(User $user, object $plan): void
    {
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE
            && app(\App\Domain\Identity\Services\V2PhoneBenefitEligibility::class)->reused((int) $user->id)) {
            throw new V2PaymentException('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED');
        }
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
        if ($plan->audience_code === self::AUDIENCE_FIRST_PURCHASE) {
            if (! $this->isWithinFirstUserWindow($user, $asOf)) return ['eligible' => false, 'reason' => 'first_purchase_required'];
            $block = $this->rankedBlocks($user->id, [$plan->code])->where('position', 1)->first();
            if ($block) return ['eligible' => false, 'reason' => self::blockReason($block)];
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

    private function hasUsedOrPendingFirstPurchase(int $userId, string $planCode): bool
    {
        return $this->blockingQuery($userId, [$planCode])->exists();
    }

    public function scopeKey(string $code): string
    {
        return config('v2_payment.first_purchase_limit_scope') === 'per_product' ? $code : '*';
    }

    private function blockingQuery(int $userId, array $codes): Builder
    {
        $classified = DB::table('payments as payment')
            ->join('point_purchase_plans as plan', 'plan.id', '=', 'payment.point_purchase_plan_id')
            ->where('payment.user_id', $userId)
            ->when(config('v2_payment.first_purchase_limit_scope') === 'per_product',
                fn (Builder $query) => $query->whereIn('plan.code', $codes),
                fn (Builder $query) => $query->where('plan.audience_code', self::AUDIENCE_FIRST_PURCHASE))
            ->select('payment.id', 'payment.public_id', 'payment.status', 'payment.created_at', 'payment.payment_method',
                'payment.provider_code', 'payment.provider_payment_id', 'payment.expires_at', 'payment.plan_name_snapshot', 'plan.code')
            ->selectRaw("EXISTS (SELECT 1 FROM payment_status_histories h
                WHERE h.payment_id = payment.id AND h.to_status = 'succeeded') AS succeeded_before")
            ->selectRaw("payment.payment_method IN ('konbini', 'virtual_account') AND
                (EXISTS (SELECT 1 FROM fincode_payment_attempts a WHERE a.payment_id = payment.id
                    AND a.provider_session_id IS NOT NULL AND a.redirect_url_ciphertext IS NOT NULL)
                OR EXISTS (SELECT 1 FROM payment_status_histories h WHERE h.payment_id = payment.id
                    AND h.to_status IN ('requires_action', 'processing')
                    AND h.transition_source IN ('provider_api', 'provider_event'))) AS issued");

        return DB::query()->fromSub($classified, 'p')->where(function (Builder $q) {
            $q->whereNotIn('p.status', ['failed', 'canceled', 'expired'])
                ->orWhere('p.succeeded_before', true)->orWhere('p.issued', true);
        });
    }

    public static function blockReason(object $payment): string
    {
        return $payment->permanent ? 'first_purchase_required' : 'first_purchase_pending';
    }

    /** Rank a permanent block first so an old success or issued terminal payment survives the display limit. */
    private function rankedBlocks(int $userId, array $codes): Builder
    {
        $partition = config('v2_payment.first_purchase_limit_scope') === 'per_product' ? 'PARTITION BY p.code' : '';
        $permanent = "p.succeeded_before OR p.status IN ('succeeded', 'failed', 'canceled', 'expired')";
        $ranked = $this->blockingQuery($userId, $codes)->select('p.*')
            ->selectRaw("($permanent) AS permanent, row_number() OVER ($partition ORDER BY
                ($permanent) DESC, p.created_at DESC, p.id DESC) AS position");
        return DB::query()->fromSub($ranked, 'p');
    }

    public function blockingRows(int $userId, array $codes): array
    {
        if ($codes === []) return [];
        return $this->rankedBlocks($userId, $codes)->where('p.position', '<=', 11)
            ->leftJoin('stripe_checkout_sessions as stripe', 'stripe.payment_id', '=', 'p.id')
            ->select('p.*', 'stripe.cancel_requested_at', 'stripe.session_expires_at')
            ->selectSub(V2PaidyPaymentService::authorizationRecords()->selectRaw('1')
                ->whereColumn('resource_public_id', 'p.public_id')->limit(1), 'authorization_record')
            ->selectSub(V2PaidyPaymentService::lookupRecords()->selectRaw('1')
                ->whereColumn('aggregate_public_id', 'p.public_id')->limit(1), 'lookup_record')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')->get()->all();
    }

    private function isWithinFirstUserWindow(User $user, CarbonImmutable $asOf): bool
    {
        return $this->withinQualifiedWindow($user, $asOf, $this->qualifiedAt($user));
    }

    public function withinQualifiedWindow(User $user, CarbonImmutable $asOf, ?CarbonImmutable $qualifiedAt): bool
    {
        return $qualifiedAt !== null && $qualifiedAt->lessThanOrEqualTo($asOf)
            && $asOf->lessThan($qualifiedAt->addHours(24))
            && ! app(\App\Domain\Identity\Services\V2PhoneBenefitEligibility::class)->reused((int) $user->id);
    }

    public function qualifiedAt(User $user): ?CarbonImmutable
    {
        return app(\App\Domain\Identity\Services\V2RegistrationQualification::class)->origin($user, 'first_sms_verified_at');
    }
}
