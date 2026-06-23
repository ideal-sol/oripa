<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
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

            $totalCost = $lockedGacha->price * $drawCount;
            $this->pointConsumptionService->assertSpendable($user, $totalCost);

            $drawRequest = DrawRequest::query()->create([
                'user_id' => $user->id,
                'gacha_id' => $lockedGacha->id,
                'draw_count' => $drawCount,
                'idempotency_key' => $idempotencyKey,
                'status' => DrawRequestStatus::Processing,
                'consumed_point_total' => $totalCost,
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
                $range = $this->rangeBuilder->build($stage);
                // 抽選乱数はフロントではなくバックエンドのCSPRNGで生成する。
                $randomValue = random_int(0, 999_999);
                $entry = $range->pick($randomValue);

                $drawResult = null;

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

            return $drawRequest->refresh()->load('results');
        });
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
            'selected_rank_image_url' => $presentation['image_url'],
            'selected_draw_video_url' => $presentation['video_url'],
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
