<?php

namespace App\Domain\Payment\V2\Services;

use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2PointProductReadService
{
    public function __construct(
        private readonly V2PointPurchaseEligibilityService $eligibility
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listing(?User $user): array
    {
        $latest = DB::table('point_purchase_plans')
            ->selectRaw('code, MAX(version_no) AS version_no')
            ->groupBy('code');
        $rows = DB::table('point_purchase_plans as plan')
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.code', '=', 'plan.code')
                ->on('latest.version_no', '=', 'plan.version_no'))
            ->where('plan.status', 'published')
            ->whereIn('plan.audience_code', [
                V2PointPurchaseEligibilityService::AUDIENCE_ALL,
                V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE,
            ])
            ->orderBy('plan.sort_order')
            ->orderBy('plan.id')
            ->get([
                'plan.id',
                'plan.public_id',
                'plan.name',
                'plan.amount',
                'plan.currency',
                'plan.paid_point_amount',
                'plan.free_point_amount',
                'plan.audience_code',
                'plan.target_user_tag_id',
                'plan.available_from',
                'plan.available_until',
            ]);
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        return $rows->map(
            fn (object $plan): array => $this->present($plan, $user, $now)
        )->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(object $plan, ?User $user, CarbonImmutable $now): array
    {
        $saleState = $this->saleState($plan, $now);
        $reason = $this->reason($plan, $user, $saleState);

        return [
            'id' => $plan->public_id,
            'title' => $plan->name,
            'price' => [
                'amount' => (int) $plan->amount,
                'currency' => $plan->currency,
            ],
            'grant' => [
                'paid_points' => (int) $plan->paid_point_amount,
                'bonus_points' => (int) $plan->free_point_amount,
                'total_points' => (int) $plan->paid_point_amount
                    + (int) $plan->free_point_amount,
            ],
            'audience' => [
                'code' => $plan->audience_code,
                'label' => $plan->audience_code
                    === V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE
                        ? '初回ユーザー'
                        : 'すべてのユーザー',
            ],
            'sale_state' => $saleState,
            'is_available' => $saleState === 'available',
            'user_state' => $user === null ? 'unauthenticated' : 'authenticated',
            'eligible' => $reason === null,
            'ineligible_reason' => $reason,
            'cta' => $this->cta($reason),
        ];
    }

    private function saleState(object $plan, CarbonImmutable $now): string
    {
        if ($plan->available_from !== null
            && $now->lessThan(CarbonImmutable::parse($plan->available_from)->utc())) {
            return 'coming_soon';
        }
        if ($plan->available_until !== null
            && ! $now->lessThan(CarbonImmutable::parse($plan->available_until)->utc())) {
            return 'ended';
        }

        return 'available';
    }

    private function reason(object $plan, ?User $user, string $saleState): ?string
    {
        if ($saleState !== 'available') {
            return $saleState === 'coming_soon' ? 'sale_not_started' : 'sale_ended';
        }
        if ($user === null) {
            return 'authentication_required';
        }

        return $this->eligibility->evaluate($user, $plan)['reason'];
    }

    /** @return array{state: string, action: string, reason: ?string} */
    private function cta(?string $reason): array
    {
        if ($reason === 'authentication_required') {
            return ['state' => 'enabled', 'action' => 'login', 'reason' => $reason];
        }

        return [
            'state' => $reason === null ? 'enabled' : 'disabled',
            'action' => 'purchase',
            'reason' => $reason,
        ];
    }
}
