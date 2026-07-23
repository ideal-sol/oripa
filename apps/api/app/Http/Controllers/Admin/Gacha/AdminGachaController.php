<?php

namespace App\Http\Controllers\Admin\Gacha;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Services\GachaReadinessService;
use App\Http\Requests\Admin\StoreGachaRequest;
use App\Http\Requests\Admin\UpdateGachaRequest;
use App\Http\Resources\AdminGachaResource;
use App\Models\Gacha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class AdminGachaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Gacha::query()
            ->with(['category', 'tags', 'currentProbabilityVersion'])
            ->withCount(['ranks', 'prizes'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        return AdminGachaResource::collection($query->paginate((int) $request->integer('per_page', 20)));
    }

    public function store(StoreGachaRequest $request, AuditLogService $auditLogService): JsonResponse
    {
        $payload = $request->validated();
        $tagIds = Arr::pull($payload, 'tag_ids', []);
        $this->assertStoreIsAllowed($payload);

        $gacha = Gacha::query()->create($payload);
        $gacha->tags()->sync($tagIds);

        $auditLogService->record(
            action: 'admin.gacha.created',
            adminUser: $request->user(),
            auditable: $gacha,
            request: $request,
            metadata: [
                'attributes' => $payload,
                'tag_ids' => $tagIds,
            ],
        );

        return (new AdminGachaResource($gacha->load(['category', 'tags', 'currentProbabilityVersion'])->loadCount(['ranks', 'prizes'])))
            ->response()
            ->setStatusCode(201);
    }
    private function assertStoreIsAllowed(array $payload): void
    {
        if (($payload['status'] ?? null) !== GachaStatus::Active->value) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => ['A gacha cannot be created as active before a published probability version exists.'],
        ]);
    }

    public function show(Gacha $gacha): AdminGachaResource
    {
        return new AdminGachaResource($gacha->load([
            'category',
            'tags',
            'currentProbabilityVersion',
            'ranks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'ranks.rankImageAsset',
            'ranks.rankImageAssets',
            'ranks.drawVideoAsset',
            'ranks.drawVideoAssets',
            'ranks.prizes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ])->loadCount(['ranks', 'prizes']));
    }

    public function update(
        UpdateGachaRequest $request,
        Gacha $gacha,
        AuditLogService $auditLogService,
        GachaReadinessService $readinessService,
    ): AdminGachaResource {
        $payload = $request->validated();
        $tagIds = Arr::pull($payload, 'tag_ids', null);
        $this->assertUpdateIsAllowed($gacha, $payload, $readinessService);

        $before = $gacha->only(array_keys($payload));
        $beforeTagIds = $gacha->tags()->pluck('gacha_tags.id')->values()->all();

        $gacha->fill($payload);
        $gacha->save();
        if ($tagIds !== null) {
            $gacha->tags()->sync($tagIds);
        }

        $auditLogService->record(
            action: 'admin.gacha.updated',
            adminUser: $request->user(),
            auditable: $gacha,
            request: $request,
            metadata: [
                'before' => $before,
                'after' => $gacha->only(array_keys($payload)),
                'before_tag_ids' => $beforeTagIds,
                'after_tag_ids' => $tagIds ?? $beforeTagIds,
            ],
        );

        return new AdminGachaResource($gacha->refresh()->load(['category', 'tags', 'currentProbabilityVersion'])->loadCount(['ranks', 'prizes']));
    }
    private function assertUpdateIsAllowed(Gacha $gacha, array $payload, GachaReadinessService $readinessService): void
    {
        if ($gacha->status !== GachaStatus::Active && ($payload['status'] ?? null) === GachaStatus::Active->value) {
            $preview = (clone $gacha)->fill($payload);
            $failedChecks = $readinessService->failedChecks($preview);

            if ($failedChecks->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => $failedChecks
                        ->map(fn (array $check): string => (string) $check['message'])
                        ->all(),
                ]);
            }
        }

        if ($gacha->status !== GachaStatus::Active) {
            return;
        }

        $lockedFields = [
            'price',
            'total_count',
            'probability_mode',
            'minimum_guarantee_type',
            'minimum_guarantee_value',
            'minimum_guarantee_cost',
            'target_margin',
        ];
        $requestedLockedFields = array_values(array_filter(
            array_intersect($lockedFields, array_keys($payload)),
            fn (string $field): bool => $this->lockedFieldHasChanged($gacha, $field, $payload[$field]),
        ));

        if ($requestedLockedFields === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(
            $requestedLockedFields,
            ['This field cannot be changed while the gacha is active.'],
        ));
    }

    private function lockedFieldHasChanged(Gacha $gacha, string $field, mixed $value): bool
    {
        $currentValue = $gacha->getAttribute($field);

        if ($field === 'target_margin') {
            if ($currentValue === null || $value === null) {
                return $currentValue !== $value;
            }

            return (float) $currentValue !== (float) $value;
        }

        if ($currentValue instanceof \BackedEnum) {
            $currentValue = $currentValue->value;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return (string) $currentValue !== (string) $value;
    }
}
