<?php

namespace App\Domain\Shipping\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Domain\Shipping\Exceptions\UserPrizeOperationException;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Support\Facades\DB;

class UserPrizeExchangeService
{
    public function __construct(private readonly PointLotService $pointLotService)
    {
    }

    public function exchange(User $user, UserPrize $userPrize): UserPrize
    {
        return DB::transaction(function () use ($user, $userPrize): UserPrize {
            /** @var UserPrize|null $lockedPrize */
            $lockedPrize = UserPrize::query()
                ->with('prize')
                ->whereKey($userPrize->id)
                ->lockForUpdate()
                ->first();

            // 景品をロックし、配送申請とポイント交換の二重処理を防ぐ。
            if (! $lockedPrize || $lockedPrize->user_id !== $user->id) {
                throw new UserPrizeOperationException('Prize was not found.');
            }

            if ($lockedPrize->status !== UserPrizeStatus::Stored) {
                throw new UserPrizeOperationException('Only stored prizes can be exchanged.');
            }

            if ($lockedPrize->storage_expire_at->isPast()) {
                throw new UserPrizeOperationException('Expired prizes cannot be exchanged.');
            }

            $exchangePoint = (int) ($lockedPrize->prize?->exchange_point ?? 0);
            if ($exchangePoint <= 0) {
                throw new UserPrizeOperationException('This prize cannot be exchanged for points.');
            }

            // 交換で得るポイントは景品由来の無償ポイントとして期限付きにする。
            $lockedPrize->forceFill([
                'status' => UserPrizeStatus::Converted,
                'converted_point' => $exchangePoint,
            ])->save();

            $this->pointLotService->grantFree(
                user: $user,
                amount: $exchangePoint,
                expireAt: now()->addDays((int) config('oripa.free_point_expiration_days', 180)),
                sourceType: PointLotSourceType::Exchange,
                sourceId: $lockedPrize->id,
                ledgerType: PointLedgerType::Exchange,
                relatedType: 'user_prize',
                relatedId: $lockedPrize->id,
                description: 'Prize exchanged for free points.',
            );

            return $lockedPrize->refresh()->load('gacha', 'prize.rank');
        });
    }
}
