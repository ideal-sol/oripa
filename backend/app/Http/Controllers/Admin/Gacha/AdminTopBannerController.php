<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\BulkUpdateTopBannerStatusRequest;
use App\Http\Requests\Admin\StoreTopBannerRequest;
use App\Http\Requests\Admin\UpdateTopBannerRequest;
use App\Http\Resources\TopBannerResource;
use App\Models\TopBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminTopBannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TopBannerResource::collection(
            TopBanner::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function show(TopBanner $topBanner): TopBannerResource
    {
        return new TopBannerResource($topBanner);
    }

    public function store(StoreTopBannerRequest $request, AuditLogService $auditLogService): JsonResponse
    {
        $payload = $request->validated();
        $banner = TopBanner::query()->create($payload);

        $auditLogService->record(
            action: 'admin.top_banner.created',
            adminUser: $request->user(),
            auditable: $banner,
            request: $request,
            metadata: [
                'attributes' => $payload,
            ],
        );

        return (new TopBannerResource($banner))
            ->response()
            ->setStatusCode(201);
    }

    public function bulkStatus(
        BulkUpdateTopBannerStatusRequest $request,
        AuditLogService $auditLogService,
    ): AnonymousResourceCollection {
        $payload = $request->validated();
        $ids = array_values(array_unique($payload['ids']));
        $isActive = (bool) $payload['is_active'];

        DB::transaction(function () use ($ids, $isActive): void {
            TopBanner::query()
                ->whereIn('id', $ids)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);
        });

        $auditLogService->record(
            action: 'admin.top_banner.bulk_status_updated',
            adminUser: $request->user(),
            request: $request,
            metadata: [
                'ids' => $ids,
                'is_active' => $isActive,
            ],
        );

        return TopBannerResource::collection(
            TopBanner::query()
                ->whereIn('id', $ids)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function update(
        UpdateTopBannerRequest $request,
        TopBanner $topBanner,
        AuditLogService $auditLogService,
    ): TopBannerResource {
        $payload = $request->validated();
        $before = $topBanner->only(array_keys($payload));

        $topBanner->fill($payload);
        $topBanner->save();

        $auditLogService->record(
            action: 'admin.top_banner.updated',
            adminUser: $request->user(),
            auditable: $topBanner,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $topBanner->only(array_keys($payload)),
            ],
        );

        return new TopBannerResource($topBanner->refresh());
    }
}
