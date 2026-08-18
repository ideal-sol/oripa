<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2LimitedBonusCampaignService
{
    public function create(
        int $pointPurchasePlanId,
        bool $isEnabled,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        int $bonusPointAmount
    ): object {
        [$start, $end] = $this->validate($startsAt, $endsAt, $bonusPointAmount);

        return DB::transaction(function () use (
            $pointPurchasePlanId,
            $isEnabled,
            $start,
            $end,
            $bonusPointAmount
        ): object {
            $this->lockPlan($pointPurchasePlanId);
            $this->assertNoOverlap($pointPurchasePlanId, $start, $end);
            $id = DB::table('point_purchase_plan_limited_bonus_campaigns')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'point_purchase_plan_id' => $pointPurchasePlanId,
                'is_enabled' => $isEnabled,
                'starts_at' => $start->utc()->toIso8601String(),
                'ends_at' => $end->utc()->toIso8601String(),
                'bonus_point_amount' => $bonusPointAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('id', $id)->firstOrFail();
        }, 3);
    }

    public function update(
        int $campaignId,
        bool $isEnabled,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        int $bonusPointAmount
    ): object {
        [$start, $end] = $this->validate($startsAt, $endsAt, $bonusPointAmount);
        $campaignView = DB::table('point_purchase_plan_limited_bonus_campaigns')
            ->where('id', $campaignId)->firstOrFail();

        return DB::transaction(function () use (
            $campaignId,
            $campaignView,
            $isEnabled,
            $start,
            $end,
            $bonusPointAmount
        ): object {
            $this->lockPlan((int) $campaignView->point_purchase_plan_id);
            $campaign = DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('id', $campaignId)->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap(
                (int) $campaign->point_purchase_plan_id,
                $start,
                $end,
                (int) $campaign->id
            );
            DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('id', $campaign->id)->update([
                    'is_enabled' => $isEnabled,
                    'starts_at' => $start->utc()->toIso8601String(),
                    'ends_at' => $end->utc()->toIso8601String(),
                    'bonus_point_amount' => $bonusPointAmount,
                    'updated_at' => now(),
                ]);

            return DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('id', $campaign->id)->firstOrFail();
        }, 3);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function validate(
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        int $bonusPointAmount
    ): array {
        $start = CarbonImmutable::parse($startsAt->format('Y-m-d H:i:s.uP'))
            ->utc()->startOfSecond();
        $end = CarbonImmutable::parse($endsAt->format('Y-m-d H:i:s.uP'))
            ->utc()->startOfSecond();
        if (! $start->lessThan($end) || $bonusPointAmount <= 0) {
            throw new V2PaymentException('LIMITED_BONUS_CAMPAIGN_INVALID');
        }

        return [$start, $end];
    }

    private function lockPlan(int $pointPurchasePlanId): void
    {
        DB::table('point_purchase_plans')->where('id', $pointPurchasePlanId)
            ->lockForUpdate()->firstOrFail();
    }

    private function assertNoOverlap(
        int $pointPurchasePlanId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $exceptCampaignId = null
    ): void {
        $query = DB::table('point_purchase_plan_limited_bonus_campaigns')
            ->where('point_purchase_plan_id', $pointPurchasePlanId)
            ->where('starts_at', '<', $endsAt->utc()->toIso8601String())
            ->where('ends_at', '>', $startsAt->utc()->toIso8601String());
        if ($exceptCampaignId !== null) {
            $query->where('id', '<>', $exceptCampaignId);
        }
        if ($query->orderBy('id')->lockForUpdate()->first() !== null) {
            throw new V2PaymentException('LIMITED_BONUS_CAMPAIGN_OVERLAP');
        }
    }
}
