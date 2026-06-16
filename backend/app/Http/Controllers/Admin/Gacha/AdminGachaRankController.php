<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Requests\Admin\StoreGachaRankRequest;
use App\Http\Requests\Admin\UpdateGachaRankRequest;
use App\Http\Resources\AdminGachaRankResource;
use App\Models\Gacha;
use App\Models\GachaRank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AdminGachaRankController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GachaRank::query()
            ->with(['gacha:id,title,slug,status'])
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
            $rank->load(['gacha:id,title,slug,status', 'prizes'])->loadCount('prizes')
        );
    }

    public function store(StoreGachaRankRequest $request, Gacha $gacha, AuditLogService $auditLogService): JsonResponse
    {
        $rank = $gacha->ranks()->create($request->validated());

        $auditLogService->record(
            action: 'admin.gacha_rank.created',
            adminUser: $request->user(),
            auditable: $rank,
            request: $request,
            metadata: [
                'gacha_id' => $gacha->id,
                'attributes' => $request->validated(),
            ],
        );

        return (new AdminGachaRankResource($rank->load('prizes')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateGachaRankRequest $request, GachaRank $rank, AuditLogService $auditLogService): AdminGachaRankResource
    {
        $before = $rank->only(array_keys($request->validated()));

        $rank->fill($request->validated());
        $rank->save();

        $auditLogService->record(
            action: 'admin.gacha_rank.updated',
            adminUser: $request->user(),
            auditable: $rank,
            request: $request,
            metadata: [
                'gacha_id' => $rank->gacha_id,
                'before' => $before,
                'after' => $rank->only(array_keys($request->validated())),
            ],
        );

        return new AdminGachaRankResource($rank->refresh()->load('prizes'));
    }
}
