<?php

namespace App\Http\Controllers\Admin\Point;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Domain\Point\Services\PointConsumptionService;
use App\Domain\Point\Services\PointLotService;
use App\Http\Requests\Admin\StorePointAdjustmentRequest;
use App\Http\Resources\PointAdjustmentResource;
use App\Models\PointAdjustment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPointAdjustmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PointAdjustment::query()
            ->with(['user', 'adminUser'])
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('adjustment_type')) {
            $query->where('adjustment_type', $request->string('adjustment_type')->toString());
        }

        if ($request->filled('point_type')) {
            $query->where('point_type', $request->string('point_type')->toString());
        }

        return PointAdjustmentResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function store(
        StorePointAdjustmentRequest $request,
        User $user,
        PointLotService $pointLotService,
        PointConsumptionService $pointConsumptionService,
        AuditLogService $auditLogService,
    ): PointAdjustmentResource {
        $validated = $request->validated();

        try {
            $adjustment = DB::transaction(function () use (
                $request,
                $user,
                $validated,
                $pointLotService,
                $pointConsumptionService,
                $auditLogService,
            ): PointAdjustment {
                $adjustment = PointAdjustment::query()->create([
                    'user_id' => $user->id,
                    'admin_user_id' => $request->user()?->id,
                    'adjustment_type' => $validated['adjustment_type'],
                    'point_type' => $validated['point_type'] ?? null,
                    'amount' => $validated['amount'],
                    'expire_at' => $validated['expire_at'] ?? null,
                    'reason' => $validated['reason'],
                ]);

                if ($adjustment->adjustment_type === 'grant') {
                    $this->grantPoints($pointLotService, $user, $adjustment);
                } else {
                    $pointConsumptionService->consume(
                        user: $user,
                        amount: (int) $adjustment->amount,
                        relatedType: 'point_adjustment',
                        relatedId: $adjustment->id,
                        description: $adjustment->reason,
                    );
                }

                $auditLogService->record(
                    action: 'admin.point_adjustment.created',
                    adminUser: $request->user(),
                    user: $user,
                    auditable: $adjustment,
                    request: $request,
                    metadata: [
                        'adjustment_type' => $adjustment->adjustment_type,
                        'point_type' => $adjustment->point_type?->value ?? $adjustment->point_type,
                        'amount' => $adjustment->amount,
                    ],
                );

                return $adjustment;
            });
        } catch (InsufficientPointsException $exception) {
            throw ValidationException::withMessages(['amount' => [$exception->getMessage()]]);
        }

        return new PointAdjustmentResource($adjustment->refresh()->load(['user.wallet', 'adminUser']));
    }

    private function grantPoints(PointLotService $pointLotService, User $user, PointAdjustment $adjustment): void
    {
        if ($adjustment->point_type === PointType::Paid) {
            $pointLotService->grantPaid(
                user: $user,
                amount: (int) $adjustment->amount,
                sourceType: PointLotSourceType::Compensation,
                sourceId: $adjustment->id,
                description: $adjustment->reason,
                ledgerType: PointLedgerType::Compensation,
                relatedType: 'point_adjustment',
                relatedId: $adjustment->id,
            );

            return;
        }

        $pointLotService->grantFree(
            user: $user,
            amount: (int) $adjustment->amount,
            expireAt: $adjustment->expire_at,
            sourceType: PointLotSourceType::Compensation,
            sourceId: $adjustment->id,
            ledgerType: PointLedgerType::Compensation,
            relatedType: 'point_adjustment',
            relatedId: $adjustment->id,
            description: $adjustment->reason,
        );
    }
}
