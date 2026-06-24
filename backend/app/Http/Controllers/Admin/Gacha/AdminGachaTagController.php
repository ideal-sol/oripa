<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreGachaTagRequest;
use App\Http\Requests\Admin\UpdateGachaTagRequest;
use App\Http\Resources\AdminGachaTagResource;
use App\Models\GachaTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminGachaTagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminGachaTagResource::collection(
            GachaTag::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(GachaTag $tag): AdminGachaTagResource
    {
        return new AdminGachaTagResource($tag);
    }

    public function store(StoreGachaTagRequest $request, AuditLogService $auditLogService): JsonResponse
    {
        $payload = $request->validated();
        $tag = GachaTag::query()->create($payload);

        $auditLogService->record(
            action: 'admin.gacha_tag.created',
            adminUser: $request->user(),
            auditable: $tag,
            request: $request,
            metadata: [
                'attributes' => $payload,
            ],
        );

        return (new AdminGachaTagResource($tag))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateGachaTagRequest $request,
        GachaTag $tag,
        AuditLogService $auditLogService,
    ): AdminGachaTagResource {
        $payload = $request->validated();
        $before = $tag->only(array_keys($payload));

        $tag->fill($payload);
        $tag->save();

        $auditLogService->record(
            action: 'admin.gacha_tag.updated',
            adminUser: $request->user(),
            auditable: $tag,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $tag->only(array_keys($payload)),
            ],
        );

        return new AdminGachaTagResource($tag->refresh());
    }
}
