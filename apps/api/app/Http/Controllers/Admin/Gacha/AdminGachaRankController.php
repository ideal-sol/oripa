<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreGachaRankRequest;
use App\Http\Requests\Admin\UpdateGachaRankRequest;
use App\Http\Resources\AdminGachaRankResource;
use App\Models\Gacha;
use App\Models\GachaRank;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminGachaRankController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GachaRank::query()
            ->with(['gacha:id,title,slug,status', 'rankImageAsset', 'drawVideoAsset', 'rankImageAssets', 'drawVideoAssets'])
            ->withCount('prizes')
            ->orderByDesc('id');

        if ($request->filled('gacha_id')) {
            $query->where('gacha_id', (int) $request->input('gacha_id'));
        }

        if ($request->filled('is_visible')) {
            $query->where('is_visible', filter_var($request->input('is_visible'), FILTER_VALIDATE_BOOLEAN));
        }

        return AdminGachaRankResource::collection(
            $query->paginate((int) $request->integer('per_page', 20))
        );
    }

    public function show(GachaRank $rank): AdminGachaRankResource
    {
        return new AdminGachaRankResource(
            $rank->load(['gacha:id,title,slug,status', 'prizes', 'rankImageAsset', 'drawVideoAsset', 'rankImageAssets', 'drawVideoAssets'])->loadCount('prizes')
        );
    }

    public function store(StoreGachaRankRequest $request, Gacha $gacha, AuditLogService $auditLogService): JsonResponse
    {
        $validated = $request->validated();
        $rank = $gacha->ranks()->create($this->rankAttributes($validated));
        $this->syncRankAssets(
            rank: $rank,
            imageAssetIds: $this->assetIdsFor($validated, 'rank_image_asset_ids', 'rank_image_asset_id'),
            videoAssetIds: $this->assetIdsFor($validated, 'draw_video_asset_ids', 'draw_video_asset_id'),
        );

        $auditLogService->record(
            action: 'admin.gacha_rank.created',
            adminUser: $request->user(),
            auditable: $rank,
            request: $request,
            metadata: [
                'gacha_id' => $gacha->id,
                'attributes' => $validated,
            ],
        );

        return (new AdminGachaRankResource($rank->load(['prizes', 'rankImageAsset', 'drawVideoAsset', 'rankImageAssets', 'drawVideoAssets'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateGachaRankRequest $request, GachaRank $rank, AuditLogService $auditLogService): AdminGachaRankResource
    {
        $validated = $request->validated();
        $attributes = $this->rankAttributes($validated);
        $before = $rank->only(array_keys($attributes));

        $rank->fill($attributes);
        $rank->save();

        if ($request->has('rank_image_asset_ids') || $request->has('rank_image_asset_id')) {
            $this->syncRankAssetType($rank, 'image', $this->assetIdsFor($validated, 'rank_image_asset_ids', 'rank_image_asset_id'));
        }

        if ($request->has('draw_video_asset_ids') || $request->has('draw_video_asset_id')) {
            $this->syncRankAssetType($rank, 'video', $this->assetIdsFor($validated, 'draw_video_asset_ids', 'draw_video_asset_id'));
        }

        $auditLogService->record(
            action: 'admin.gacha_rank.updated',
            adminUser: $request->user(),
            auditable: $rank,
            request: $request,
            metadata: [
                'gacha_id' => $rank->gacha_id,
                'before' => $before,
                'after' => $rank->only(array_keys($attributes)),
                'asset_ids' => Arr::only($validated, ['rank_image_asset_ids', 'draw_video_asset_ids']),
            ],
        );

        return new AdminGachaRankResource($rank->refresh()->load(['prizes', 'rankImageAsset', 'drawVideoAsset', 'rankImageAssets', 'drawVideoAssets']));
    }

    private function rankAttributes(array $validated): array
    {
        return Arr::except($validated, ['rank_image_asset_ids', 'draw_video_asset_ids']);
    }

    private function assetIdsFor(array $validated, string $arrayKey, string $singleKey): array
    {
        if (array_key_exists($arrayKey, $validated)) {
            return array_values(array_map('intval', $validated[$arrayKey] ?? []));
        }

        if (! empty($validated[$singleKey])) {
            return [(int) $validated[$singleKey]];
        }

        return [];
    }

    private function syncRankAssets(GachaRank $rank, array $imageAssetIds, array $videoAssetIds): void
    {
        $this->syncRankAssetType($rank, 'image', $imageAssetIds);
        $this->syncRankAssetType($rank, 'video', $videoAssetIds);
    }

    private function syncRankAssetType(GachaRank $rank, string $usageType, array $assetIds): void
    {
        DB::table('gacha_rank_assets')
            ->where('gacha_rank_id', $rank->id)
            ->where('usage_type', $usageType)
            ->delete();

        foreach (array_values(array_unique($assetIds)) as $index => $assetId) {
            DB::table('gacha_rank_assets')->insert([
                'gacha_rank_id' => $rank->id,
                'rank_asset_id' => $assetId,
                'usage_type' => $usageType,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
