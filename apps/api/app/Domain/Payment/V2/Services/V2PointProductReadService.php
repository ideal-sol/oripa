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

    /** @return array<string, mixed> */
    public function listing(?User $user): array
    {
        $asOf = CarbonImmutable::now('UTC')->startOfSecond();
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
                'plan.code',
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
        $campaigns = DB::table('point_purchase_plan_limited_bonus_campaigns')
            ->whereIn('point_purchase_plan_id', $rows->pluck('id'))
            ->where('is_enabled', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('point_purchase_plan_id');

        $bonus = app(\App\Domain\MemberRank\Services\V2MemberRankPurchaseBonusService::class)->estimateContext($user, $asOf);
        $tags = $user === null ? [] : DB::table('user_tag_assignments')
            ->where('user_id', $user->id)->whereIn('user_tag_id', $rows->pluck('target_user_tag_id')->filter()->unique())
            ->pluck('user_tag_id')->flip()->all();
        $firstPlans = $rows->where('audience_code', V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE)
            ->filter(fn ($plan) => $this->saleState($plan, $asOf) === 'available'
                && ($plan->target_user_tag_id === null || isset($tags[$plan->target_user_tag_id])));
        $qualifiedAt = $user === null ? null : $this->eligibility->qualifiedAt($user);
        $inWindow = $user !== null && $firstPlans->isNotEmpty() && $this->eligibility->withinQualifiedWindow($user, $asOf, $qualifiedAt);
        $blocking = $inWindow ? $this->eligibility->blockingRows($user->id, $firstPlans->pluck('code')->all()) : [];
        $reasons = [];
        foreach ($blocking as $payment) {
            if ((int) $payment->position === 1) {
                $reasons[$this->eligibility->scopeKey($payment->code)] = $this->eligibility->blockReason($payment);
            }
        }

        $products = [];
        $blockedIds = [];
        foreach ($rows as $plan) {
            $sale = $this->saleState($plan, $asOf);
            $scope = $this->eligibility->scopeKey($plan->code);
            $reason = match (true) {
                $sale !== 'available' => $sale === 'coming_soon' ? 'sale_not_started' : 'sale_ended',
                $user === null => 'authentication_required',
                $plan->target_user_tag_id !== null && ! isset($tags[$plan->target_user_tag_id]) => 'audience_not_eligible',
                $plan->audience_code !== V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE => null,
                ! $inWindow => 'first_purchase_required',
                default => $reasons[$scope] ?? null,
            };
            $products[$plan->public_id] = $this->present($plan, $user, $asOf,
                $campaigns->get($plan->id, collect())->all(), $bonus, $sale, $reason);
            if ($reason === 'first_purchase_pending') $blockedIds[$scope][] = $plan->public_id;
        }

        $offerPayments = [];
        $truncated = false;
        $byScope = [];
        foreach ($blocking as $payment) {
            $scope = $this->eligibility->scopeKey($payment->code);
            if (! isset($blockedIds[$scope])) continue;
            $notice = $this->notice($payment, $blockedIds[$scope]);
            if (count($byScope[$scope] ?? []) < 3) $byScope[$scope][] = $notice;
            if (count($offerPayments) < 10) $offerPayments[] = $notice;
            else $truncated = true;
        }
        foreach ($blockedIds as $scope => $ids) {
            foreach ($ids as $id) $products[$id]['blocking_payments'] = $byScope[$scope];
        }

        $expiresAt = $qualifiedAt?->addHours(24);
        $hasEligibleOffer = collect($products)->contains(
            fn (array $product): bool => $product['audience']['code']
                === V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE
                && $product['eligible']
        );
        $state = match (true) {
            $user === null => 'unauthenticated',
            $expiresAt === null => 'unavailable',
            $asOf->greaterThanOrEqualTo($expiresAt) => 'expired',
            $hasEligibleOffer => 'active',
            default => 'unavailable',
        };

        return [
            'data' => array_values($products),
            'first_user_offer' => [
                'state' => $state,
                'blocking_payments' => $offerPayments,
                'blocking_payments_truncated' => $truncated,
                'expires_at' => $expiresAt?->toIso8601ZuluString(),
                'as_of' => $asOf->toIso8601ZuluString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(
        object $plan,
        ?User $user,
        CarbonImmutable $now,
        array $campaigns,
        ?array $bonus,
        string $saleState,
        ?string $reason
    ): array
    {
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
            'limited_bonus' => $this->limitedBonus($campaigns, $now),
            'member_rank_purchase_bonus' => $bonus === null ? null : [
                ...$bonus,
                'bonus_points' => \App\Domain\MemberRank\Services\V2MemberRankPurchaseBonusService::calculate(
                    (int) $plan->amount, $bonus['rate_basis_points']
                ),
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
            'blocking_payments' => [],
        ];
    }

    /** @param list<object> $campaigns */
    private function limitedBonus(array $campaigns, CarbonImmutable $asOf): array
    {
        $active = null;
        $upcoming = null;
        foreach ($campaigns as $campaign) {
            $start = CarbonImmutable::parse($campaign->starts_at)->utc();
            $end = CarbonImmutable::parse($campaign->ends_at)->utc();
            if (! $asOf->lessThan($start) && $asOf->lessThan($end)) {
                $active = $campaign;
                break;
            }
            if ($asOf->lessThan($start) && $upcoming === null) {
                $upcoming = $campaign;
            }
        }
        $selected = $active ?? $upcoming;
        $state = $active !== null ? 'active' : ($upcoming !== null ? 'upcoming' : 'inactive');
        $amount = $selected === null ? 0 : (int) $selected->bonus_point_amount;

        return [
            'amount' => $amount,
            'starts_at' => $selected === null ? null : $this->utc($selected->starts_at),
            'ends_at' => $selected === null ? null : $this->utc($selected->ends_at),
            'state' => $state,
            'as_of' => $asOf->toIso8601ZuluString(),
            'presentation' => [
                'is_visible' => $selected !== null,
                'label' => '期間限定ボーナスコイン',
                'amount_text' => $selected === null
                    ? null
                    : '+'.number_format($amount).'コイン',
            ],
        ];
    }

    private function utc(mixed $value): string
    {
        return CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
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

    private function notice(object $p, array $blockedIds): array
    {
        $pending = $p->provider_code === 'stripe' && $p->cancel_requested_at !== null;
        $resume = $p->provider_code === 'stripe' && $p->payment_method === 'credit_card' && ! $pending
            && in_array($p->status, ['created', 'requires_action'], true);
        $paidy = $p->provider_code === 'paidy' && $p->status === 'requires_action'
            && $p->provider_payment_id === null && ! $p->authorization_record && ! $p->lookup_record;
        $reason = match (true) {
            $resume || $paidy => null,
            $pending => 'cancellation_pending',
            in_array($p->provider_code, ['stripe', 'paidy'], true) => 'in_progress_at_provider',
            default => 'provider_not_supported',
        };
        $deadline = match (true) {
            $p->status !== 'requires_action' || $pending => null,
            $p->provider_code === 'stripe' => $p->session_expires_at,
            $p->provider_code === 'paidy' => $paidy && $p->expires_at !== null ? V2PaidyDeadline::authorization($p) : null,
            default => $p->expires_at,
        };
        return [
            'id' => $p->public_id,
            'point_product_name' => $p->plan_name_snapshot,
            'method' => $p->payment_method,
            'method_label' => V2PaymentMethodPolicy::methodLabel($p->payment_method),
            'status' => $p->status,
            'started_at' => $this->utc($p->created_at),
            'blocks_point_product_ids' => $blockedIds,
            'cancellable' => $reason === null,
            'cancel_unavailable_reason' => $reason,
            'cancel_restores_offer' => ! $p->issued,
            'resumable' => $resume,
            'cancellation_pending' => $pending,
            'auto_release_at' => $deadline === null ? null : $this->utc($deadline),
        ];
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
