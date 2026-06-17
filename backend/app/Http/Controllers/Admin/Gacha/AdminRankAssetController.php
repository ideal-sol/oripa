<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreRankAssetRequest;
use App\Http\Requests\Admin\UpdateRankAssetRequest;
use App\Http\Resources\RankAssetResource;
use App\Models\RankAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminRankAssetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RankAsset::query()->orderByDesc('id');

        if ($request->filled('asset_type')) {
            $query->where('asset_type', $request->string('asset_type')->toString());
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return RankAssetResource::collection($query->paginate((int) $request->integer('per_page', 100)));
    }

    public function store(StoreRankAssetRequest $request, AuditLogService $auditLogService): JsonResponse
    {
        $payload = $request->validated();
        $asset = RankAsset::query()->create($payload);

        $auditLogService->record(
            action: 'admin.rank_asset.created',
            adminUser: $request->user(),
            auditable: $asset,
            request: $request,
            metadata: ['attributes' => $payload],
        );

        return (new RankAssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(RankAsset $rankAsset): RankAssetResource
    {
        return new RankAssetResource($rankAsset);
    }

    public function update(UpdateRankAssetRequest $request, RankAsset $rankAsset, AuditLogService $auditLogService): RankAssetResource
    {
        $payload = $request->validated();
        $before = $rankAsset->only(array_keys($payload));

        $rankAsset->fill($payload)->save();

        $auditLogService->record(
            action: 'admin.rank_asset.updated',
            adminUser: $request->user(),
            auditable: $rankAsset,
            request: $request,
            metadata: ['before' => $before, 'after' => $rankAsset->only(array_keys($payload))],
        );

        return new RankAssetResource($rankAsset->refresh());
    }
}
