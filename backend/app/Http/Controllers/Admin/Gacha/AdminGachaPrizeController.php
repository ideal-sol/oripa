<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Http\Requests\Admin\StoreGachaPrizeRequest;
use App\Http\Requests\Admin\UpdateGachaPrizeRequest;
use App\Http\Resources\AdminGachaPrizeResource;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class AdminGachaPrizeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GachaPrize::query()
            ->with(['gacha:id,title,slug,status', 'rank:id,display_name,rank_key'])
            ->orderByDesc('id');

        if ($request->filled('gacha_id')) {
            $query->where('gacha_id', (int) $request->input('gacha_id'));
        }

        if ($request->filled('rank_id')) {
            $query->where('rank_id', (int) $request->input('rank_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return AdminGachaPrizeResource::collection(
            $query->paginate((int) $request->integer('per_page', 20))
        );
    }

    public function show(GachaPrize $prize): AdminGachaPrizeResource
    {
        return new AdminGachaPrizeResource(
            $prize->load(['gacha:id,title,slug,status', 'rank:id,display_name,rank_key'])
        );
    }

    public function store(StoreGachaPrizeRequest $request, GachaRank $rank, AuditLogService $auditLogService): JsonResponse
    {
        $rank->loadMissing('gacha');

        if ($rank->gacha?->status === GachaStatus::Active) {
            throw ValidationException::withMessages([
                'gacha_id' => ['Prizes cannot be added while the gacha is active.'],
            ]);
        }

        $prize = $rank->prizes()->create([
            ...$request->validated(),
            'gacha_id' => $rank->gacha_id,
            'won_count' => 0,
        ]);

        $auditLogService->record(
            action: 'admin.gacha_prize.created',
            adminUser: $request->user(),
            auditable: $prize,
            request: $request,
            metadata: [
                'gacha_id' => $rank->gacha_id,
                'rank_id' => $rank->id,
                'attributes' => $request->validated(),
            ],
        );

        return (new AdminGachaPrizeResource($prize))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateGachaPrizeRequest $request, GachaPrize $prize, AuditLogService $auditLogService): AdminGachaPrizeResource
    {
        $payload = $request->validated();
        $this->assertUpdateIsAllowed($prize, $payload);

        if (isset($payload['rank_id'])) {
            $rank = GachaRank::query()->findOrFail($payload['rank_id']);

            if ((int) $rank->gacha_id !== (int) $prize->gacha_id) {
                throw ValidationException::withMessages([
                    'rank_id' => ['The selected rank does not belong to the prize gacha.'],
                ]);
            }
        }

        $before = $prize->only(array_keys($payload));

        $prize->fill($payload);
        $prize->save();

        $auditLogService->record(
            action: 'admin.gacha_prize.updated',
            adminUser: $request->user(),
            auditable: $prize,
            request: $request,
            metadata: [
                'gacha_id' => $prize->gacha_id,
                'rank_id' => $prize->rank_id,
                'before' => $before,
                'after' => $prize->only(array_keys($payload)),
            ],
        );

        return new AdminGachaPrizeResource($prize->refresh());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertUpdateIsAllowed(GachaPrize $prize, array $payload): void
    {
        $prize->loadMissing('gacha');

        if ($prize->gacha?->status !== GachaStatus::Active) {
            return;
        }

        $lockedFields = [
            'rank_id',
            'max_win_count',
            'cost_price',
            'display_price',
            'exchange_point',
        ];
        $requestedLockedFields = array_values(array_intersect($lockedFields, array_keys($payload)));

        if ($requestedLockedFields === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(
            $requestedLockedFields,
            ['This field cannot be changed while the gacha is active.'],
        ));
    }
}
