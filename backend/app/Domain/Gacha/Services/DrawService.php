<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\DTO\QaDrawSelectedItem;
use App\Domain\Gacha\DTO\QaDrawSelection;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Domain\Gacha\Exceptions\DrawException;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointConsumptionService;
use App\Domain\Point\Services\PointLotService;
use App\Domain\Probability\Services\ProbabilityRangeBuilder;
use App\Domain\Probability\Services\StageResolver;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\QaDrawExecution;
use App\Models\User;
use App\Models\UserPrize;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DrawService
{
    public function __construct(
        private readonly StageResolver $stageResolver,
        private readonly ProbabilityRangeBuilder $rangeBuilder,
        private readonly PointConsumptionService $pointConsumptionService,
        private readonly PointLotService $pointLotService,
        private readonly QaDrawResolver $qaDrawResolver,
    ) {
    }

    public function draw(User $user, Gacha $gacha, int $drawCount, string $idempotencyKey): DrawRequest
    {
        if ($drawCount < 1) {
            throw new DrawException('Draw count must be greater than or equal to one.');
        }

        if ($idempotencyKey === '') {
            throw new DrawException('Idempotency key is required.');
        }

        // 抽選はポイント消費、通し番号、在庫、結果作成を必ず同じDBトランザクションで確定する。
        return DB::transaction(function () use ($user, $gacha, $drawCount, $idempotencyKey): DrawRequest {
            $existing = DrawRequest::query()
                ->where('user_id', $user->id)
                ->where('gacha_id', $gacha->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            // 同じリクエストの二重送信は既存結果を返し、処理中の重複実行は止める。
            if ($existing) {
                if ($existing->status === DrawRequestStatus::Completed) {
                    return $existing->load('results');
                }

                throw new DrawException('Draw request with the same idempotency key is already processing.');
            }

            $lockedGacha = Gacha::query()
                ->whereKey($gacha->id)
                ->lockForUpdate()
                ->firstOrFail();

            // sold_count をロックした状態で採番することで、ガチャごとの通し番号に重複と欠番を出さない。
            $this->assertDrawable($lockedGacha, $drawCount);
            $this->assertWithinDailyDrawLimit($user, $lockedGacha, $drawCount);
            $qaSelection = $this->qaDrawResolver->resolve($user, $lockedGacha, $drawCount);
            $lockedQaPrizes = $qaSelection->active ? $this->lockAndValidateQaPrizes($lockedGacha, $qaSelection) : collect();

            $totalCost = $lockedGacha->price * $drawCount;
            $this->pointConsumptionService->assertSpendable($user, $totalCost);

            $drawRequest = DrawRequest::query()->create([
                'user_id' => $user->id,
                'gacha_id' => $lockedGacha->id,
                'draw_count' => $drawCount,
                'idempotency_key' => $idempotencyKey,
                'status' => DrawRequestStatus::Processing,
                'consumed_point_total' => $totalCost,
                'is_qa_draw' => $qaSelection->active,
                'qa_test_user_mode_id' => $qaSelection->modeId(),
                'qa_draw_plan_id' => $qaSelection->planId(),
            ]);

            $this->pointConsumptionService->consume(
                user: $user,
                amount: $totalCost,
                relatedType: 'draw_request',
                relatedId: $drawRequest->id,
                description: "Gacha draw {$lockedGacha->id}",
            );

            $soldCountBefore = (int) $lockedGacha->sold_count;

            for ($drawIndex = 1; $drawIndex <= $drawCount; $drawIndex++) {
                $sequence = $soldCountBefore + $drawIndex;
                $stage = $this->stageResolver->resolve((int) $lockedGacha->current_probability_version_id, $sequence);
                // 抽選乱数はフロントではなくバックエンドのCSPRNGで生成する。
                $randomValue = random_int(0, 999_999);

                $drawResult = null;

                if ($qaSelection->active) {
                    /** @var QaDrawSelectedItem $qaItem */
                    $qaItem = $qaSelection->items[$drawIndex - 1];
                    /** @var GachaPrize $prize */
                    $prize = $lockedQaPrizes->get($qaItem->prize->id);

                    $this->assertPrizeAvailable($prize, $lockedGacha);

                    $prize->forceFill([
                        'won_count' => (int) $prize->won_count + 1,
                    ])->save();

                    $drawResult = $this->storePrizeResult(
                        drawRequest: $drawRequest,
                        user: $user,
                        gacha: $lockedGacha,
                        prize: $prize,
                        sequence: $sequence,
                        randomValue: $randomValue,
                        stageId: $stage->id,
                        selectedRankImageUrl: $qaItem->fixedRankImageUrl(),
                        selectedDrawVideoUrl: $qaItem->fixedDrawVideoUrl(),
                        isQaDraw: true,
                        qaDrawPlanItemId: $qaItem->planItem->id,
                    );

                    $this->createUserPrize($user, $lockedGacha, $prize, $drawResult);
                    $this->incrementQaPlanItem($qaItem);
                } else {
                    $range = $this->rangeBuilder->build($stage);
                    $entry = $range->pick($randomValue);

                    if ($entry->isPrize()) {
                        $prize = GachaPrize::query()
                            ->whereKey($entry->prizeId)
                            ->lockForUpdate()
                            ->firstOrFail();

                        // 当選直前に景品在庫をロックし、同じ景品が上限を超えて当たらないようにする。
                        $this->assertPrizeAvailable($prize, $lockedGacha);

                        $prize->forceFill([
                            'won_count' => (int) $prize->won_count + 1,
                        ])->save();

                        $drawResult = $this->storePrizeResult(
                            drawRequest: $drawRequest,
                            user: $user,
                            gacha: $lockedGacha,
                            prize: $prize,
                            sequence: $sequence,
                            randomValue: $randomValue,
                            stageId: $stage->id,
                        );

                        $this->createUserPrize($user, $lockedGacha, $prize, $drawResult);
                    } else {
                        $drawResult = $this->storePointBackResult(
                            drawRequest: $drawRequest,
                            user: $user,
                            gacha: $lockedGacha,
                            sequence: $sequence,
                            randomValue: $randomValue,
                            stageId: $stage->id,
                        );

                        $this->grantMinimumGuarantee($user, $lockedGacha, $drawResult);
                    }
                }

                $lockedGacha->forceFill([
                    'sold_count' => (int) $lockedGacha->sold_count + 1,
                ])->save();
            }

            // 最後の口まで販売された時点で完売へ切り替え、以後の抽選を止める。
            if ((int) $lockedGacha->sold_count >= (int) $lockedGacha->total_count) {
                $lockedGacha->forceFill([
                    'status' => GachaStatus::SoldOut,
                ])->save();
            }

            $drawRequest->forceFill([
                'status' => DrawRequestStatus::Completed,
            ])->save();

            if ($qaSelection->active) {
                $this->completeQaPlanIfConsumed($qaSelection);
                $this->createQaExecution($qaSelection, $drawRequest, $user, $lockedGacha, $drawCount);
            }

            return $drawRequest->refresh()->load('results');
        });
    }

    private function lockAndValidateQaPrizes(Gacha $gacha, QaDrawSelection $selection)
    {
        $neededByPrize = collect($selection->items)
            ->groupBy(fn (QaDrawSelectedItem $item): int => (int) $item->prize->id)
            ->map(fn ($items): int => $items->count());

        $prizes = GachaPrize::query()
            ->whereIn('id', $neededByPrize->keys()->sort()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($neededByPrize as $prizeId => $needed) {
            /** @var GachaPrize|null $prize */
            $prize = $prizes->get($prizeId);

            if (! $prize) {
                throw new DrawException('QA draw plan prize was not found.');
            }

            $this->assertPrizeAvailable($prize, $gacha);

            if ((int) $prize->max_win_count - (int) $prize->won_count < $needed) {
                throw new DrawException('QA draw plan prize inventory is insufficient.');
            }
        }

        return $prizes;
    }

    private function assertDrawable(Gacha $gacha, int $drawCount): void
    {
        if ($gacha->status !== GachaStatus::Active) {
            throw new DrawException('Gacha is not active.');
        }

        if (! $gacha->current_probability_version_id) {
            throw new DrawException('Gacha has no published probability version.');
        }

        if ((int) $gacha->sold_count + $drawCount > (int) $gacha->total_count) {
            throw new DrawException('Gacha does not have enough remaining draw count.');
        }
    }

    private function assertPrizeAvailable(GachaPrize $prize, Gacha $gacha): void
    {
        if ((int) $prize->gacha_id !== (int) $gacha->id) {
            throw new DrawException('Prize does not belong to the gacha.');
        }

        if (! $prize->is_active || (int) $prize->won_count >= (int) $prize->max_win_count) {
            throw new DrawException('Prize is not available.');
        }
    }

    private function assertWithinDailyDrawLimit(User $user, Gacha $gacha, int $drawCount): void
    {
        if ($gacha->daily_draw_limit === null) {
            return;
        }

        $timezone = config('app.timezone', 'Asia/Tokyo');
        $startOfDay = CarbonImmutable::now($timezone)->startOfDay();
        $endOfDay = CarbonImmutable::now($timezone)->endOfDay();
        $drawnToday = (int) DrawRequest::query()
            ->where('user_id', $user->id)
            ->where('gacha_id', $gacha->id)
            ->where('status', DrawRequestStatus::Completed)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->sum('draw_count');

        if ($drawnToday + $drawCount <= (int) $gacha->daily_draw_limit) {
            return;
        }

        $remaining = max(0, (int) $gacha->daily_draw_limit - $drawnToday);

        throw new DrawException("このガチャの本日抽選可能回数を超えています。本日残り{$remaining}回です。");
    }

    private function storePrizeResult(
        DrawRequest $drawRequest,
        User $user,
        Gacha $gacha,
        GachaPrize $prize,
        int $sequence,
        int $randomValue,
        int $stageId,
        ?string $selectedRankImageUrl = null,
        ?string $selectedDrawVideoUrl = null,
        bool $isQaDraw = false,
        ?int $qaDrawPlanItemId = null,
    ): DrawResult {
        $presentation = $this->selectRankPresentation($prize);

        return DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $sequence,
            'rank_id' => $prize->rank_id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => $gacha->price,
            'granted_point' => 0,
            'random_value' => $randomValue,
            'probability_version_id' => $gacha->current_probability_version_id,
            'probability_version_stage_id' => $stageId,
            'selected_rank_image_url' => $selectedRankImageUrl ?? $presentation['image_url'],
            'selected_draw_video_url' => $selectedDrawVideoUrl ?? $presentation['video_url'],
            'is_qa_draw' => $isQaDraw,
            'qa_draw_plan_item_id' => $qaDrawPlanItemId,
        ]);
    }

    private function incrementQaPlanItem(QaDrawSelectedItem $qaItem): void
    {
        $qaItem->planItem->forceFill([
            'consumed_count' => (int) $qaItem->planItem->consumed_count + 1,
        ])->save();
    }

    private function completeQaPlanIfConsumed(QaDrawSelection $selection): void
    {
        $plan = $selection->plan?->refresh()->load('items');

        if (! $plan || $plan->items->isEmpty()) {
            return;
        }

        if ($plan->items->every(fn ($item): bool => (int) $item->consumed_count >= (int) $item->quantity)) {
            $plan->forceFill([
                'status' => QaDrawPlanStatus::Completed,
            ])->save();
        }
    }

    private function createQaExecution(
        QaDrawSelection $selection,
        DrawRequest $drawRequest,
        User $user,
        Gacha $gacha,
        int $drawCount,
    ): void {
        QaDrawExecution::query()->create([
            'qa_test_user_mode_id' => $selection->modeId(),
            'qa_draw_plan_id' => $selection->planId(),
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => $drawCount,
            'reason' => $selection->plan?->reason ?? $selection->mode?->reason,
            'metadata' => [
                'mode_reason' => $selection->mode?->reason,
                'plan_reason' => $selection->plan?->reason,
                'items' => collect($selection->items)
                    ->map(fn (QaDrawSelectedItem $item): array => [
                        'qa_draw_plan_item_id' => $item->planItem->id,
                        'gacha_prize_id' => $item->prize->id,
                        'rank_image_asset_id' => $item->rankImageAsset?->id,
                        'draw_video_asset_id' => $item->drawVideoAsset?->id,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function selectRankPresentation(GachaPrize $prize): array
    {
        /** @var GachaRank $rank */
        $rank = GachaRank::query()
            ->with([
                'rankImageAsset',
                'drawVideoAsset',
                'rankImageAssets' => fn ($query) => $query->where('is_active', true),
                'drawVideoAssets' => fn ($query) => $query->where('is_active', true),
            ])
            ->whereKey($prize->rank_id)
            ->firstOrFail();

        return [
            'image_url' => $this->randomAssetUrl($rank->rankImageAssets) ?? $rank->effectiveImageUrl(),
            'video_url' => $this->randomAssetUrl($rank->drawVideoAssets) ?? $rank->effectiveDrawVideoUrl(),
        ];
    }

    private function randomAssetUrl($assets): ?string
    {
        $urls = $assets
            ->pluck('url')
            ->filter(fn (?string $url): bool => $url !== null && $url !== '')
            ->values();

        if ($urls->isEmpty()) {
            return null;
        }

        return $urls->get(random_int(0, $urls->count() - 1));
    }

    private function storePointBackResult(
        DrawRequest $drawRequest,
        User $user,
        Gacha $gacha,
        int $sequence,
        int $randomValue,
        int $stageId,
    ): DrawResult {
        return DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $sequence,
            'rank_id' => null,
            'prize_id' => null,
            'result_type' => DrawResultType::PointBack,
            'consumed_point' => $gacha->price,
            'granted_point' => $gacha->minimum_guarantee_value,
            'random_value' => $randomValue,
            'probability_version_id' => $gacha->current_probability_version_id,
            'probability_version_stage_id' => $stageId,
        ]);
    }

    private function createUserPrize(User $user, Gacha $gacha, GachaPrize $prize, DrawResult $drawResult): void
    {
        UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => UserPrizeStatus::Stored,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addDays(60),
        ]);
    }

    private function grantMinimumGuarantee(User $user, Gacha $gacha, DrawResult $drawResult): void
    {
        if ($gacha->minimum_guarantee_type !== MinimumGuaranteeType::Point) {
            throw new DrawException('Only point minimum guarantee is implemented.');
        }

        if ((int) $gacha->minimum_guarantee_value <= 0) {
            return;
        }

        $this->pointLotService->grantFree(
            user: $user,
            amount: (int) $gacha->minimum_guarantee_value,
            expireAt: now()->addDays((int) config('oripa.free_point_expiration_days', 180)),
            sourceType: PointLotSourceType::MinimumGuarantee,
            sourceId: $drawResult->id,
            ledgerType: PointLedgerType::Grant,
            relatedType: 'draw_result',
            relatedId: $drawResult->id,
            description: "Minimum guarantee for draw result {$drawResult->id}",
        );
    }
}
