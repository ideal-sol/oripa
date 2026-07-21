<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Models\AdminUser;
use App\Models\GachaPrize;
use App\Models\QaDrawPlan;
use App\Models\RankAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QaDrawPlanService
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function listForUser(User $user): Collection
    {
        return QaDrawPlan::query()
            ->with(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();
    }

    public function create(User $user, AdminUser $adminUser, array $payload, ?Request $request = null): QaDrawPlan
    {
        return DB::transaction(function () use ($user, $adminUser, $payload, $request): QaDrawPlan {
            $status = QaDrawPlanStatus::from($payload['status'] ?? QaDrawPlanStatus::Active->value);

            if ($status === QaDrawPlanStatus::Active) {
                $this->completeExpiredOrConsumedActivePlans($user->id, (int) $payload['gacha_id'], $adminUser, $request);
                $this->assertNoOtherActivePlan($user->id, (int) $payload['gacha_id']);
            }

            $this->validateItems((int) $payload['gacha_id'], $payload['items']);

            $plan = QaDrawPlan::query()->create([
                'user_id' => $user->id,
                'gacha_id' => $payload['gacha_id'],
                'status' => $status,
                'title' => $payload['title'] ?? null,
                'reason' => $payload['reason'],
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'created_by_admin_user_id' => $adminUser->id,
                'updated_by_admin_user_id' => $adminUser->id,
            ]);

            $this->replaceItems($plan, $payload['items']);

            $this->audit('admin.qa_draw_plan.created', $plan, $adminUser, $request, [
                'after' => $this->snapshot($plan),
            ]);

            return $plan->refresh()->load(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset']);
        });
    }

    public function update(QaDrawPlan $plan, AdminUser $adminUser, array $payload, ?Request $request = null): QaDrawPlan
    {
        return DB::transaction(function () use ($plan, $adminUser, $payload, $request): QaDrawPlan {
            $plan = QaDrawPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $before = $this->snapshot($plan);
            $status = isset($payload['status'])
                ? QaDrawPlanStatus::from($payload['status'])
                : $plan->status;

            if ($plan->status === QaDrawPlanStatus::Completed && $status === QaDrawPlanStatus::Active) {
                throw ValidationException::withMessages([
                    'status' => ['Completed QA draw plans cannot be activated again.'],
                ]);
            }

            if ($status === QaDrawPlanStatus::Active) {
                $this->completeExpiredOrConsumedActivePlans($plan->user_id, $plan->gacha_id, $adminUser, $request, $plan->id);
                $this->assertNoOtherActivePlan($plan->user_id, $plan->gacha_id, $plan->id);
            }

            if (array_key_exists('items', $payload)) {
                $this->validateItems($plan->gacha_id, $payload['items']);
            }

            $plan->fill([
                'status' => $status,
                'title' => $payload['title'] ?? $plan->title,
                'reason' => $payload['reason'] ?? $plan->reason,
                'starts_at' => array_key_exists('starts_at', $payload) ? $payload['starts_at'] : $plan->starts_at,
                'ends_at' => array_key_exists('ends_at', $payload) ? $payload['ends_at'] : $plan->ends_at,
                'updated_by_admin_user_id' => $adminUser->id,
            ])->save();

            if (array_key_exists('items', $payload)) {
                $this->replaceItems($plan, $payload['items']);
            }

            $this->audit('admin.qa_draw_plan.updated', $plan, $adminUser, $request, [
                'before' => $before,
                'after' => $this->snapshot($plan),
            ]);

            return $plan->refresh()->load(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset']);
        });
    }

    public function disable(QaDrawPlan $plan, AdminUser $adminUser, ?Request $request = null): QaDrawPlan
    {
        return $this->changeStatus($plan, QaDrawPlanStatus::Disabled, 'admin.qa_draw_plan.disabled', $adminUser, $request);
    }

    public function pause(QaDrawPlan $plan, AdminUser $adminUser, ?Request $request = null): QaDrawPlan
    {
        return $this->changeStatus($plan, QaDrawPlanStatus::Paused, 'admin.qa_draw_plan.paused', $adminUser, $request);
    }

    public function activate(QaDrawPlan $plan, AdminUser $adminUser, ?Request $request = null): QaDrawPlan
    {
        return DB::transaction(function () use ($plan, $adminUser, $request): QaDrawPlan {
            $plan = QaDrawPlan::query()->with('items')->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status === QaDrawPlanStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => ['Completed QA draw plans cannot be activated again.'],
                ]);
            }

            $this->completeExpiredOrConsumedActivePlans($plan->user_id, $plan->gacha_id, $adminUser, $request, $plan->id);
            $this->assertNoOtherActivePlan($plan->user_id, $plan->gacha_id, $plan->id);
            $this->assertPlanIsUsable($plan);

            $before = $this->snapshot($plan);
            $plan->forceFill([
                'status' => QaDrawPlanStatus::Active,
                'updated_by_admin_user_id' => $adminUser->id,
            ])->save();

            $this->audit('admin.qa_draw_plan.activated', $plan, $adminUser, $request, [
                'before' => $before,
                'after' => $this->snapshot($plan),
            ]);

            return $plan->refresh()->load(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset']);
        });
    }

    private function changeStatus(
        QaDrawPlan $plan,
        QaDrawPlanStatus $status,
        string $action,
        AdminUser $adminUser,
        ?Request $request,
    ): QaDrawPlan {
        return DB::transaction(function () use ($plan, $status, $action, $adminUser, $request): QaDrawPlan {
            $plan = QaDrawPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $before = $this->snapshot($plan);

            $plan->forceFill([
                'status' => $status,
                'updated_by_admin_user_id' => $adminUser->id,
            ])->save();

            $this->audit($action, $plan, $adminUser, $request, [
                'before' => $before,
                'after' => $this->snapshot($plan),
            ]);

            return $plan->refresh()->load(['gacha', 'items.prize.rank', 'items.rankImageAsset', 'items.drawVideoAsset']);
        });
    }

    private function completeExpiredOrConsumedActivePlans(
        int $userId,
        int $gachaId,
        AdminUser $adminUser,
        ?Request $request,
        ?int $exceptPlanId = null,
    ): void {
        $plans = QaDrawPlan::query()
            ->with('items')
            ->where('user_id', $userId)
            ->where('gacha_id', $gachaId)
            ->where('status', QaDrawPlanStatus::Active)
            ->when($exceptPlanId, fn ($query) => $query->whereKeyNot($exceptPlanId))
            ->lockForUpdate()
            ->get();

        foreach ($plans as $plan) {
            if (! $this->shouldComplete($plan)) {
                continue;
            }

            $before = $this->snapshot($plan);
            $plan->forceFill([
                'status' => QaDrawPlanStatus::Completed,
                'updated_by_admin_user_id' => $adminUser->id,
            ])->save();

            $this->audit('admin.qa_draw_plan.completed', $plan, $adminUser, $request, [
                'before' => $before,
                'after' => $this->snapshot($plan),
                'reason' => 'auto_completed_before_activation',
            ]);
        }
    }

    private function shouldComplete(QaDrawPlan $plan): bool
    {
        if ($plan->ends_at !== null && $plan->ends_at->isPast()) {
            return true;
        }

        return $plan->items->isNotEmpty()
            && $plan->items->every(fn ($item) => $item->consumed_count >= $item->quantity);
    }

    private function assertNoOtherActivePlan(int $userId, int $gachaId, ?int $exceptPlanId = null): void
    {
        $exists = QaDrawPlan::query()
            ->where('user_id', $userId)
            ->where('gacha_id', $gachaId)
            ->where('status', QaDrawPlanStatus::Active)
            ->when($exceptPlanId, fn ($query) => $query->whereKeyNot($exceptPlanId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'gacha_id' => ['An active QA draw plan already exists for this user and gacha.'],
            ]);
        }
    }

    private function assertPlanIsUsable(QaDrawPlan $plan): void
    {
        if ($plan->ends_at !== null && $plan->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'ends_at' => ['Expired QA draw plans cannot be activated.'],
            ]);
        }

        if ($plan->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['At least one QA draw plan item is required.'],
            ]);
        }

        if ($plan->items->every(fn ($item) => $item->consumed_count >= $item->quantity)) {
            throw ValidationException::withMessages([
                'items' => ['Consumed QA draw plans cannot be activated.'],
            ]);
        }
    }

    private function validateItems(int $gachaId, array $items): void
    {
        $sortOrders = collect($items)->pluck('sort_order');
        if ($sortOrders->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['The QA draw plan item sort_order values must be unique.'],
            ]);
        }

        foreach ($items as $index => $item) {
            $prize = GachaPrize::query()->find($item['gacha_prize_id']);
            if (! $prize || (int) $prize->gacha_id !== $gachaId) {
                throw ValidationException::withMessages([
                    "items.{$index}.gacha_prize_id" => ['The selected prize does not belong to the target gacha.'],
                ]);
            }

            if (isset($item['rank_image_asset_id'])) {
                $this->assertAssetType((int) $item['rank_image_asset_id'], 'image', "items.{$index}.rank_image_asset_id");
            }

            if (isset($item['draw_video_asset_id'])) {
                $this->assertAssetType((int) $item['draw_video_asset_id'], 'video', "items.{$index}.draw_video_asset_id");
            }
        }
    }

    private function assertAssetType(int $assetId, string $expectedType, string $field): void
    {
        $asset = RankAsset::query()->find($assetId);

        if (! $asset || $asset->asset_type !== $expectedType) {
            throw ValidationException::withMessages([
                $field => ["The selected asset must be a {$expectedType} asset."],
            ]);
        }
    }

    private function replaceItems(QaDrawPlan $plan, array $items): void
    {
        $plan->items()->delete();

        foreach ($items as $item) {
            $plan->items()->create([
                'sort_order' => $item['sort_order'],
                'gacha_prize_id' => $item['gacha_prize_id'],
                'quantity' => $item['quantity'],
                'consumed_count' => 0,
                'rank_image_asset_id' => $item['rank_image_asset_id'] ?? null,
                'draw_video_asset_id' => $item['draw_video_asset_id'] ?? null,
            ]);
        }
    }

    private function snapshot(QaDrawPlan $plan): array
    {
        return $plan->fresh(['items'])?->only([
            'id',
            'user_id',
            'gacha_id',
            'status',
            'title',
            'reason',
            'starts_at',
            'ends_at',
            'created_by_admin_user_id',
            'updated_by_admin_user_id',
        ]) + [
            'items' => $plan->fresh(['items'])?->items
                ->map(fn ($item) => $item->only([
                    'id',
                    'sort_order',
                    'gacha_prize_id',
                    'quantity',
                    'consumed_count',
                    'rank_image_asset_id',
                    'draw_video_asset_id',
                ]))
                ->values()
                ->all(),
        ];
    }

    private function audit(string $action, QaDrawPlan $plan, AdminUser $adminUser, ?Request $request, array $metadata): void
    {
        $this->auditLogService->record(
            action: $action,
            adminUser: $adminUser,
            user: $plan->user,
            auditable: $plan,
            request: $request,
            metadata: $metadata,
        );
    }
}
