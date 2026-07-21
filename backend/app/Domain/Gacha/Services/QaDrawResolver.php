<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Gacha\DTO\QaDrawSelectedItem;
use App\Domain\Gacha\DTO\QaDrawSelection;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Domain\Gacha\Exceptions\DrawException;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\QaDrawPlan;
use App\Models\QaDrawPlanItem;
use App\Models\QaTestUserMode;
use App\Models\RankAsset;
use App\Models\User;
use Illuminate\Support\Collection;

class QaDrawResolver
{
    public function resolve(User $user, Gacha $gacha, int $drawCount, bool $lockForUpdate = true): QaDrawSelection
    {
        if ($drawCount < 1) {
            throw new DrawException('Draw count must be greater than or equal to one.');
        }

        $mode = $this->activeMode($user, $lockForUpdate);

        if (! $mode) {
            return QaDrawSelection::inactive();
        }

        $plan = $this->activePlan($user, $gacha, $lockForUpdate);

        if (! $plan) {
            throw new DrawException('QA draw mode is active but no active QA draw plan exists for this gacha.');
        }

        $items = $this->planItems($plan, $lockForUpdate);
        $expandedItems = $this->expandRemainingItems($items);

        if (count($expandedItems) < $drawCount) {
            throw new DrawException('QA draw plan does not have enough remaining configured items.');
        }

        $selectedPlanItems = array_slice($expandedItems, 0, $drawCount);
        $selected = $this->hydrateAndValidateSelection($gacha, $selectedPlanItems);
        $this->assertInventoryAvailable($selected);

        return QaDrawSelection::active($mode, $plan, $selected);
    }

    private function activeMode(User $user, bool $lockForUpdate): ?QaTestUserMode
    {
        $query = QaTestUserMode::query()
            ->where('user_id', $user->id)
            ->where('is_enabled', true)
            ->whereNull('disabled_at')
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where('ends_at', '>', now());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activePlan(User $user, Gacha $gacha, bool $lockForUpdate): ?QaDrawPlan
    {
        $query = QaDrawPlan::query()
            ->where('user_id', $user->id)
            ->where('gacha_id', $gacha->id)
            ->where('status', QaDrawPlanStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function planItems(QaDrawPlan $plan, bool $lockForUpdate): Collection
    {
        $query = QaDrawPlanItem::query()
            ->where('qa_draw_plan_id', $plan->id)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @return list<QaDrawPlanItem>
     */
    private function expandRemainingItems(Collection $items): array
    {
        $expanded = [];

        foreach ($items as $item) {
            $remaining = max(0, (int) $item->quantity - (int) $item->consumed_count);

            for ($i = 0; $i < $remaining; $i++) {
                $expanded[] = $item;
            }
        }

        return $expanded;
    }

    /**
     * @param  list<QaDrawPlanItem>  $planItems
     * @return list<QaDrawSelectedItem>
     */
    private function hydrateAndValidateSelection(Gacha $gacha, array $planItems): array
    {
        $selected = [];

        foreach ($planItems as $planItem) {
            $prize = GachaPrize::query()
                ->whereKey($planItem->gacha_prize_id)
                ->first();

            if (! $prize || (int) $prize->gacha_id !== (int) $gacha->id) {
                throw new DrawException('QA draw plan prize does not belong to the target gacha.');
            }

            if (! $prize->is_active) {
                throw new DrawException('QA draw plan prize is not active.');
            }

            $rankImageAsset = $this->validateAsset($planItem->rank_image_asset_id, 'image');
            $drawVideoAsset = $this->validateAsset($planItem->draw_video_asset_id, 'video');

            $selected[] = new QaDrawSelectedItem(
                planItem: $planItem,
                prize: $prize,
                rankImageAsset: $rankImageAsset,
                drawVideoAsset: $drawVideoAsset,
            );
        }

        return $selected;
    }

    private function validateAsset(?int $assetId, string $expectedType): ?RankAsset
    {
        if ($assetId === null) {
            return null;
        }

        $asset = RankAsset::query()->find($assetId);

        if (! $asset || ! $asset->is_active || $asset->asset_type !== $expectedType) {
            throw new DrawException("QA draw plan asset must be an active {$expectedType} asset.");
        }

        return $asset;
    }

    /**
     * @param  list<QaDrawSelectedItem>  $selected
     */
    private function assertInventoryAvailable(array $selected): void
    {
        collect($selected)
            ->groupBy(fn (QaDrawSelectedItem $item): int => (int) $item->prize->id)
            ->each(function (Collection $items): void {
                /** @var QaDrawSelectedItem $first */
                $first = $items->first();
                $prize = $first->prize;
                $needed = $items->count();
                $remaining = (int) $prize->max_win_count - (int) $prize->won_count;

                if ($remaining < $needed) {
                    throw new DrawException('QA draw plan prize inventory is insufficient.');
                }
            });
    }
}
