<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreGachaCategoryRequest;
use App\Http\Requests\Admin\UpdateGachaCategoryRequest;
use App\Http\Resources\AdminGachaCategoryResource;
use App\Models\GachaCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminGachaCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminGachaCategoryResource::collection(
            GachaCategory::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(GachaCategory $category): AdminGachaCategoryResource
    {
        return new AdminGachaCategoryResource($category);
    }

    public function store(StoreGachaCategoryRequest $request, AuditLogService $auditLogService): JsonResponse
    {
        $payload = $request->validated();
        $category = GachaCategory::query()->create($payload);

        $auditLogService->record(
            action: 'admin.gacha_category.created',
            adminUser: $request->user(),
            auditable: $category,
            request: $request,
            metadata: [
                'attributes' => $payload,
            ],
        );

        return (new AdminGachaCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateGachaCategoryRequest $request,
        GachaCategory $category,
        AuditLogService $auditLogService,
    ): AdminGachaCategoryResource {
        $payload = $request->validated();
        $before = $category->only(array_keys($payload));

        $category->fill($payload);
        $category->save();

        $auditLogService->record(
            action: 'admin.gacha_category.updated',
            adminUser: $request->user(),
            auditable: $category,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $category->only(array_keys($payload)),
            ],
        );

        return new AdminGachaCategoryResource($category->refresh());
    }
}
