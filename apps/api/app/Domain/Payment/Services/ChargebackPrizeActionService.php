<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use App\Models\ShippingItem;
use App\Models\UserPrize;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargebackPrizeActionService
{
    public function apply(PaymentReversal $reversal): array
    {
        return DB::transaction(function () use ($reversal): array {
            $reversal = PaymentReversal::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();

            if ($reversal->prizeActions()->exists()) {
                return $this->summarizeExisting($reversal);
            }

            $summary = [
                'held_count' => 0,
                'return_requested_count' => 0,
                'no_action_count' => 0,
            ];

            $prizes = UserPrize::query()
                ->where('user_id', $reversal->user_id)
                ->with('paymentReversalPrizeActions')
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($prizes as $prize) {
                $shippingItem = ShippingItem::query()
                    ->where('user_prize_id', $prize->id)
                    ->lockForUpdate()
                    ->first();

                $actionType = $this->determineActionType($prize, $shippingItem);
                $previousPrizeStatus = $prize->status?->value ?? (string) $prize->status;
                $previousItemStatus = $shippingItem?->status?->value ?? ($shippingItem?->status ? (string) $shippingItem->status : null);

                if ($actionType === PaymentReversalPrizeActionType::Hold) {
                    $prize->forceFill([
                        'status' => UserPrizeStatus::Held,
                    ])->save();

                    if ($shippingItem && in_array($shippingItem->status, [ShippingRequestStatus::Requested, ShippingRequestStatus::Packing], true)) {
                        $shippingItem->forceFill([
                            'status' => ShippingRequestStatus::Hold,
                        ])->save();
                    }

                    $summary['held_count']++;
                } elseif ($actionType === PaymentReversalPrizeActionType::ReturnRequested) {
                    if ($shippingItem && in_array($shippingItem->status, [ShippingRequestStatus::Shipped, ShippingRequestStatus::Delivered], true)) {
                        $shippingItem->forceFill([
                            'status' => ShippingRequestStatus::ReturnRequested,
                        ])->save();
                    }

                    $summary['return_requested_count']++;
                } else {
                    $summary['no_action_count']++;
                }

                PaymentReversalPrizeAction::query()->create([
                    'payment_reversal_id' => $reversal->id,
                    'user_prize_id' => $prize->id,
                    'shipping_item_id' => $shippingItem?->id,
                    'action_type' => $actionType,
                    'previous_user_prize_status' => $previousPrizeStatus,
                    'previous_shipping_item_status' => $previousItemStatus,
                    'status' => $actionType === PaymentReversalPrizeActionType::NoAction
                        ? PaymentReversalPrizeActionStatus::Completed
                        : PaymentReversalPrizeActionStatus::Pending,
                    'note' => null,
                ]);
            }

            return $summary;
        });
    }

    public function releaseHolds(PaymentReversal $reversal, ?string $note = null): array
    {
        return DB::transaction(function () use ($reversal, $note): array {
            $reversal = PaymentReversal::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();
            $released = 0;

            $actions = PaymentReversalPrizeAction::query()
                ->where('payment_reversal_id', $reversal->id)
                ->where('action_type', PaymentReversalPrizeActionType::Hold->value)
                ->where('status', PaymentReversalPrizeActionStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            foreach ($actions as $action) {
                $prize = UserPrize::query()->whereKey($action->user_prize_id)->lockForUpdate()->first();
                $shippingItem = $action->shipping_item_id
                    ? ShippingItem::query()->whereKey($action->shipping_item_id)->lockForUpdate()->first()
                    : null;

                if ($prize && $prize->status === UserPrizeStatus::Held && $action->previous_user_prize_status) {
                    $prize->forceFill([
                        'status' => $action->previous_user_prize_status,
                    ])->save();
                }

                if ($shippingItem && $shippingItem->status === ShippingRequestStatus::Hold && $action->previous_shipping_item_status) {
                    $shippingItem->forceFill([
                        'status' => $action->previous_shipping_item_status,
                    ])->save();
                }

                $action->forceFill([
                    'status' => PaymentReversalPrizeActionStatus::Released,
                    'note' => $note ?? $action->note,
                ])->save();

                $released++;
            }

            return [
                'released_count' => $released,
            ];
        });
    }

    public function markReturned(PaymentReversalPrizeAction $action, ?string $note = null): PaymentReversalPrizeAction
    {
        return DB::transaction(function () use ($action, $note): PaymentReversalPrizeAction {
            $action = PaymentReversalPrizeAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($action->action_type !== PaymentReversalPrizeActionType::ReturnRequested) {
                throw new RuntimeException('Only return requested prize actions can be marked returned.');
            }

            $shippingItem = $action->shipping_item_id
                ? ShippingItem::query()->whereKey($action->shipping_item_id)->lockForUpdate()->first()
                : null;

            if ($shippingItem) {
                $shippingItem->forceFill([
                    'status' => ShippingRequestStatus::Returned,
                ])->save();
            }

            $action->forceFill([
                'status' => PaymentReversalPrizeActionStatus::Completed,
                'note' => $note ?? $action->note,
            ])->save();

            return $action->refresh()->load('paymentReversal.payment', 'userPrize', 'shippingItem');
        });
    }

    private function determineActionType(UserPrize $prize, ?ShippingItem $shippingItem): PaymentReversalPrizeActionType
    {
        if ($prize->status === UserPrizeStatus::Stored) {
            return PaymentReversalPrizeActionType::Hold;
        }

        if ($shippingItem) {
            return match ($shippingItem->status) {
                ShippingRequestStatus::Requested,
                ShippingRequestStatus::Packing => PaymentReversalPrizeActionType::Hold,
                ShippingRequestStatus::Shipped,
                ShippingRequestStatus::Delivered => PaymentReversalPrizeActionType::ReturnRequested,
                default => PaymentReversalPrizeActionType::NoAction,
            };
        }

        if ($prize->status === UserPrizeStatus::ShippingRequested) {
            return PaymentReversalPrizeActionType::Hold;
        }

        if ($prize->status === UserPrizeStatus::Shipped) {
            return PaymentReversalPrizeActionType::ReturnRequested;
        }

        return PaymentReversalPrizeActionType::NoAction;
    }

    private function summarizeExisting(PaymentReversal $reversal): array
    {
        return [
            'held_count' => $reversal->prizeActions()->where('action_type', PaymentReversalPrizeActionType::Hold->value)->count(),
            'return_requested_count' => $reversal->prizeActions()->where('action_type', PaymentReversalPrizeActionType::ReturnRequested->value)->count(),
            'no_action_count' => $reversal->prizeActions()->where('action_type', PaymentReversalPrizeActionType::NoAction->value)->count(),
        ];
    }
}
