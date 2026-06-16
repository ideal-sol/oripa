<?php

namespace App\Domain\Shipping\Services;

use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Domain\Shipping\Exceptions\UserPrizeOperationException;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Support\Facades\DB;

class ShippingRequestService
{
    /**
     * @param list<int> $userPrizeIds
     * @param array<string, string|null> $address
     */
    public function create(User $user, array $userPrizeIds, array $address): ShippingRequest
    {
        $userPrizeIds = array_values(array_unique($userPrizeIds));

        if ($userPrizeIds === []) {
            throw new UserPrizeOperationException('At least one prize is required.');
        }

        return DB::transaction(function () use ($user, $userPrizeIds, $address): ShippingRequest {
            $prizes = UserPrize::query()
                ->whereIn('id', $userPrizeIds)
                ->lockForUpdate()
                ->get();

            if ($prizes->count() !== count($userPrizeIds) || $prizes->contains(fn (UserPrize $prize): bool => $prize->user_id !== $user->id)) {
                throw new UserPrizeOperationException('One or more prizes were not found.');
            }

            if ($prizes->contains(fn (UserPrize $prize): bool => $prize->status !== UserPrizeStatus::Stored)) {
                throw new UserPrizeOperationException('Only stored prizes can be requested for shipping.');
            }

            if ($prizes->contains(fn (UserPrize $prize): bool => $prize->storage_expire_at->isPast())) {
                throw new UserPrizeOperationException('Expired prizes cannot be requested for shipping.');
            }

            $shippingRequest = ShippingRequest::query()->create([
                'user_id' => $user->id,
                'status' => ShippingRequestStatus::Requested,
                'recipient_name' => $address['recipient_name'],
                'postal_code' => $address['postal_code'],
                'prefecture' => $address['prefecture'],
                'city' => $address['city'],
                'address_line1' => $address['address_line1'],
                'address_line2' => $address['address_line2'] ?? null,
                'phone_number' => $address['phone_number'],
                'requested_at' => now(),
            ]);

            foreach ($prizes as $prize) {
                ShippingItem::query()->create([
                    'shipping_request_id' => $shippingRequest->id,
                    'user_prize_id' => $prize->id,
                ]);

                $prize->forceFill([
                    'status' => UserPrizeStatus::ShippingRequested,
                ])->save();
            }

            return $shippingRequest->refresh()->load('items.userPrize.gacha', 'items.userPrize.prize.rank');
        });
    }
}
