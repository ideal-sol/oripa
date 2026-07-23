<?php

namespace App\Http\Controllers\Admin\Payment;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StorePointPurchasePlanRequest;
use App\Http\Requests\Admin\UpdatePointPurchasePlanRequest;
use App\Http\Resources\PointPurchasePlanResource;
use App\Models\PointPurchasePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminPointPurchasePlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PointPurchasePlan::query()
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return PointPurchasePlanResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function store(StorePointPurchasePlanRequest $request, AuditLogService $auditLogService): PointPurchasePlanResource
    {
        $payload = $request->validated();
        $plan = PointPurchasePlan::query()->create($payload);

        $auditLogService->record(
            action: 'admin.point_purchase_plan.created',
            adminUser: $request->user(),
            auditable: $plan,
            request: $request,
            metadata: ['attributes' => $payload],
        );

        return new PointPurchasePlanResource($plan);
    }

    public function show(PointPurchasePlan $pointPurchasePlan): PointPurchasePlanResource
    {
        return new PointPurchasePlanResource($pointPurchasePlan);
    }

    public function update(UpdatePointPurchasePlanRequest $request, PointPurchasePlan $pointPurchasePlan, AuditLogService $auditLogService): PointPurchasePlanResource
    {
        $payload = $request->validated();
        $before = $pointPurchasePlan->only(array_keys($payload));

        $pointPurchasePlan->fill($payload)->save();

        $auditLogService->record(
            action: 'admin.point_purchase_plan.updated',
            adminUser: $request->user(),
            auditable: $pointPurchasePlan,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $pointPurchasePlan->only(array_keys($payload)),
            ],
        );

        return new PointPurchasePlanResource($pointPurchasePlan->refresh());
    }
}
