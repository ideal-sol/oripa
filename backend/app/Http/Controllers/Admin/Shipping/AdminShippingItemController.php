<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Http\Requests\Admin\UpdateShippingItemRequest;
use App\Http\Resources\ShippingItemResource;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\ShippingRequestHistory;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminShippingItemController extends Controller
{
    public function update(
        UpdateShippingItemRequest $request,
        ShippingItem $shippingItem,
        AuditLogService $auditLogService,
    ): ShippingItemResource {
        $validated = $request->validated();

        $shippingItem = DB::transaction(function () use ($request, $shippingItem, $validated, $auditLogService): ShippingItem {
            /** @var ShippingItem $lockedItem */
            $lockedItem = ShippingItem::query()
                ->with(['shippingRequest.items', 'userPrize'])
                ->whereKey($shippingItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ShippingRequest $lockedRequest */
            $lockedRequest = ShippingRequest::query()
                ->whereKey($lockedItem->shipping_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $lockedItem->status instanceof ShippingRequestStatus
                ? $lockedItem->status
                : ShippingRequestStatus::from($lockedItem->status ?? $lockedRequest->status->value);
            $nextStatus = ShippingRequestStatus::from($validated['status']);
            $trackingNumber = $validated['tracking_number'] ?? $lockedItem->tracking_number;

            $this->assertAllowedTransition($currentStatus, $nextStatus);

            if (in_array($nextStatus, [
                ShippingRequestStatus::Shipped,
                ShippingRequestStatus::Delivered,
                ShippingRequestStatus::Returned,
            ], true) && ! $trackingNumber) {
                throw ValidationException::withMessages(['tracking_number' => ['Tracking number is required for this status.']]);
            }

            $before = [
                'status' => $currentStatus->value,
                'tracking_number' => $lockedItem->tracking_number,
                'shipped_at' => $lockedItem->shipped_at?->toIso8601String(),
            ];

            $lockedItem->forceFill([
                'status' => $nextStatus,
                'tracking_number' => $trackingNumber,
                'shipped_at' => $this->resolveShippedAt($nextStatus, $validated, $lockedItem),
            ])->save();

            $this->syncUserPrizeStatus($lockedItem, $nextStatus);
            $this->syncShippingRequestStatus($lockedRequest);

            ShippingRequestHistory::query()->create([
                'shipping_request_id' => $lockedRequest->id,
                'admin_user_id' => $request->user()?->id,
                'from_status' => $before['status'],
                'to_status' => $nextStatus,
                'tracking_number' => $lockedItem->tracking_number,
                'note' => $this->historyNote($lockedItem, $validated['note'] ?? null),
            ]);

            $auditLogService->record(
                action: 'admin.shipping_item.updated',
                adminUser: $request->user(),
                auditable: $lockedItem,
                request: $request,
                metadata: [
                    'shipping_request_id' => $lockedRequest->id,
                    'user_prize_id' => $lockedItem->user_prize_id,
                    'before' => $before,
                    'after' => [
                        'status' => $lockedItem->status?->value ?? $lockedItem->status,
                        'tracking_number' => $lockedItem->tracking_number,
                        'shipped_at' => $lockedItem->shipped_at?->toIso8601String(),
                    ],
                ],
            );

            return $lockedItem;
        });

        return new ShippingItemResource($shippingItem->refresh()->load([
            'shippingRequest',
            'userPrize.gacha',
            'userPrize.prize.rank',
        ]));
    }

    private function assertAllowedTransition(ShippingRequestStatus $currentStatus, ShippingRequestStatus $nextStatus): void
    {
        if ($currentStatus === $nextStatus) {
            return;
        }

        $allowedTransitions = [
            ShippingRequestStatus::Requested->value => [
                ShippingRequestStatus::Packing,
                ShippingRequestStatus::Canceled,
            ],
            ShippingRequestStatus::Packing->value => [
                ShippingRequestStatus::Shipped,
                ShippingRequestStatus::Canceled,
            ],
            ShippingRequestStatus::Shipped->value => [
                ShippingRequestStatus::Delivered,
                ShippingRequestStatus::Returned,
            ],
            ShippingRequestStatus::Delivered->value => [],
            ShippingRequestStatus::Returned->value => [],
            ShippingRequestStatus::Canceled->value => [],
        ];

        if (! in_array($nextStatus, $allowedTransitions[$currentStatus->value] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot change shipping item status from {$currentStatus->value} to {$nextStatus->value}."],
            ]);
        }
    }

    private function resolveShippedAt(ShippingRequestStatus $nextStatus, array $validated, ShippingItem $shippingItem): mixed
    {
        if (array_key_exists('shipped_at', $validated)) {
            return $validated['shipped_at'];
        }

        if ($nextStatus === ShippingRequestStatus::Shipped) {
            return $shippingItem->shipped_at ?? now();
        }

        return $shippingItem->shipped_at;
    }

    private function syncUserPrizeStatus(ShippingItem $shippingItem, ShippingRequestStatus $nextStatus): void
    {
        $status = match ($nextStatus) {
            ShippingRequestStatus::Shipped,
            ShippingRequestStatus::Delivered => UserPrizeStatus::Shipped,
            ShippingRequestStatus::Canceled => UserPrizeStatus::Stored,
            default => UserPrizeStatus::ShippingRequested,
        };

        $shippingItem->userPrize?->forceFill(['status' => $status])->save();
    }

    private function syncShippingRequestStatus(ShippingRequest $shippingRequest): void
    {
        $items = $shippingRequest->items()->get(['status', 'tracking_number', 'shipped_at']);
        $statuses = $items
            ->map(fn (ShippingItem $item): string => $item->status?->value ?? $item->status)
            ->all();

        $nextStatus = match (true) {
            $statuses !== [] && count(array_unique($statuses)) === 1 => $statuses[0],
            in_array(ShippingRequestStatus::Packing->value, $statuses, true) => ShippingRequestStatus::Packing->value,
            in_array(ShippingRequestStatus::Requested->value, $statuses, true) => ShippingRequestStatus::Requested->value,
            in_array(ShippingRequestStatus::Shipped->value, $statuses, true) => ShippingRequestStatus::Shipped->value,
            in_array(ShippingRequestStatus::Delivered->value, $statuses, true) => ShippingRequestStatus::Delivered->value,
            default => $shippingRequest->status?->value ?? $shippingRequest->status,
        };

        $shippingRequest->forceFill([
            'status' => $nextStatus,
            'tracking_number' => $items->count() === 1 ? $items->first()?->tracking_number : null,
            'shipped_at' => $items->whereNotNull('shipped_at')->max('shipped_at'),
        ])->save();
    }

    private function historyNote(ShippingItem $shippingItem, ?string $note): string
    {
        $prefix = "景品ID #{$shippingItem->user_prize_id}";

        return $note ? "{$prefix}: {$note}" : $prefix;
    }
}
