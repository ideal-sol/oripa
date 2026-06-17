<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Http\Requests\Admin\UpdateShippingRequestRequest;
use App\Http\Resources\ShippingRequestResource;
use App\Models\ShippingRequest;
use App\Models\ShippingRequestHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminShippingRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ShippingRequest::query()
            ->with(['user', 'items.userPrize.gacha', 'items.userPrize.prize.rank.rankImageAsset', 'items.userPrize.prize.rank.drawVideoAsset'])
            ->withCount('items')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        return ShippingRequestResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function show(ShippingRequest $shippingRequest): ShippingRequestResource
    {
        return new ShippingRequestResource($shippingRequest->load([
            'user',
            'items.userPrize.gacha',
            'items.userPrize.prize.rank',
            'histories.adminUser',
        ]));
    }

    public function update(
        UpdateShippingRequestRequest $request,
        ShippingRequest $shippingRequest,
        AuditLogService $auditLogService,
    ): ShippingRequestResource {
        $validated = $request->validated();

        $shippingRequest = DB::transaction(function () use ($request, $shippingRequest, $validated, $auditLogService): ShippingRequest {
            /** @var ShippingRequest $lockedRequest */
            $lockedRequest = ShippingRequest::query()
                ->with('items.userPrize')
                ->whereKey($shippingRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'status' => $lockedRequest->status?->value ?? $lockedRequest->status,
                'tracking_number' => $lockedRequest->tracking_number,
                'shipped_at' => $lockedRequest->shipped_at?->toIso8601String(),
            ];

            $nextStatus = ShippingRequestStatus::from($validated['status']);

            $this->assertAllowedTransition($lockedRequest->status, $nextStatus);
            $trackingNumber = $validated['tracking_number'] ?? $lockedRequest->tracking_number;

            if (in_array($nextStatus, [
                ShippingRequestStatus::Shipped,
                ShippingRequestStatus::Delivered,
                ShippingRequestStatus::Returned,
            ], true) && ! $trackingNumber) {
                throw ValidationException::withMessages(['tracking_number' => ['Tracking number is required for this status.']]);
            }

            $lockedRequest->forceFill([
                'status' => $nextStatus,
                'tracking_number' => $trackingNumber,
                'shipped_at' => $this->resolveShippedAt($nextStatus, $validated, $lockedRequest),
            ])->save();

            if (in_array($nextStatus, [
                ShippingRequestStatus::Shipped,
                ShippingRequestStatus::Delivered,
            ], true)) {
                foreach ($lockedRequest->items as $item) {
                    $item->userPrize?->forceFill(['status' => UserPrizeStatus::Shipped])->save();
                }
            }

            if ($nextStatus === ShippingRequestStatus::Canceled) {
                foreach ($lockedRequest->items as $item) {
                    $item->userPrize?->forceFill(['status' => UserPrizeStatus::Stored])->save();
                }
            }

            ShippingRequestHistory::query()->create([
                'shipping_request_id' => $lockedRequest->id,
                'admin_user_id' => $request->user()?->id,
                'from_status' => $before['status'],
                'to_status' => $nextStatus,
                'tracking_number' => $lockedRequest->tracking_number,
                'note' => $validated['note'] ?? null,
            ]);

            $auditLogService->record(
                action: 'admin.shipping_request.updated',
                adminUser: $request->user(),
                auditable: $lockedRequest,
                request: $request,
                metadata: [
                    'before' => $before,
                    'after' => [
                        'status' => $lockedRequest->status?->value ?? $lockedRequest->status,
                        'tracking_number' => $lockedRequest->tracking_number,
                        'shipped_at' => $lockedRequest->shipped_at?->toIso8601String(),
                    ],
                ],
            );

            return $lockedRequest;
        });

        return new ShippingRequestResource($shippingRequest->refresh()->load([
            'user',
            'items.userPrize.gacha',
            'items.userPrize.prize.rank',
            'histories.adminUser',
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
                'status' => ["Cannot change shipping request status from {$currentStatus->value} to {$nextStatus->value}."],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function resolveShippedAt(ShippingRequestStatus $nextStatus, array $validated, ShippingRequest $shippingRequest): mixed
    {
        if (array_key_exists('shipped_at', $validated)) {
            return $validated['shipped_at'];
        }

        if ($nextStatus === ShippingRequestStatus::Shipped) {
            return $shippingRequest->shipped_at ?? now();
        }

        return $shippingRequest->shipped_at;
    }
}
