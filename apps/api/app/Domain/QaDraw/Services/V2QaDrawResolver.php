<?php

namespace App\Domain\QaDraw\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Models\V2\DrawRequest;
use App\Models\V2\QaDrawExecution;
use App\Models\V2\QaDrawPlan;
use App\Models\V2\QaDrawPlanItem;
use App\Models\V2\QaTestUserMode;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Domain\QaDraw\ValueObjects\V2AdminQaDrawCommand;

final class V2QaDrawResolver
{
    public function __construct(private readonly V2AuditLogService $audit)
    {
    }

    /**
     * @param list<int>|null $expectedItemIds
     * @return array{
     *   active: bool,
     *   mode: ?QaTestUserMode,
     *   plan: ?QaDrawPlan,
     *   items: list<array<string, mixed>>,
     *   item_ids: list<int>
     * }
     */
    public function resolve(
        User $user,
        int $gachaId,
        int $gachaVersionId,
        int $drawCount,
        string $requestId,
        ?array $expectedItemIds = null,
        ?V2AdminQaDrawCommand $adminCommand = null
    ): array {
        $mode = QaTestUserMode::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (! $mode instanceof QaTestUserMode || ! $this->modeActive($mode)) {
            return $this->inactive();
        }
        if ($user->state->value !== 'active') {
            throw $this->configuration('QA Mode cannot bypass User account restrictions.');
        }

        $plan = QaDrawPlan::query()
            ->join(
                'qa_draw_plan_assignments as assignment',
                'assignment.qa_draw_plan_id',
                '=',
                'qa_draw_plans.id'
            )
            ->where('assignment.user_id', $user->id)
            ->where('assignment.status', 'assigned')
            ->where('qa_draw_plans.gacha_id', $gachaId)
            ->where('qa_draw_plans.status', 'active')
            ->whereNull('qa_draw_plans.archived_at')
            ->when($adminCommand !== null, function ($query) use ($adminCommand): void {
                $query->where('qa_draw_plans.public_id', $adminCommand->planPublicId)
                    ->where('qa_draw_plans.revision', $adminCommand->planRevision)
                    ->where('assignment.public_id', $adminCommand->assignmentPublicId)
                    ->where('assignment.revision', $adminCommand->assignmentRevision);
            })
            ->lockForUpdate()
            ->first(['qa_draw_plans.*']);
        if (! $plan instanceof QaDrawPlan) {
            throw $this->configuration('Active QA Mode requires an active QA Draw Plan.');
        }
        if (
            ($plan->starts_at !== null && $plan->starts_at->isFuture())
            || ($plan->ends_at !== null && ! $plan->ends_at->isFuture())
        ) {
            throw $this->configuration('The active QA Draw Plan is outside its valid period.');
        }

        $items = QaDrawPlanItem::query()
            ->where('qa_draw_plan_id', $plan->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $selected = [];
        foreach ($items as $item) {
            $remaining = $item->quantity - $item->consumed_count;
            $validated = null;
            for ($offset = 0; $offset < $remaining && count($selected) < $drawCount; $offset++) {
                $validated ??= $this->validatedItem($item, $gachaVersionId);
                $selected[] = $validated;
            }
            if (count($selected) === $drawCount) {
                break;
            }
        }
        if (count($selected) !== $drawCount) {
            throw $this->configuration('The QA Draw Plan has insufficient remaining Items.');
        }
        $itemIds = array_map(static fn (array $item): int => $item['item_id'], $selected);
        if ($expectedItemIds !== null && $itemIds !== $expectedItemIds) {
            throw new V2QaDrawException(
                'QA_RETRY_SELECTION_CONFLICT',
                409,
                'The QA Draw selection changed during a database retry.'
            );
        }

        return [
            'active' => true,
            'mode' => $mode,
            'plan' => $plan,
            'items' => $selected,
            'item_ids' => $itemIds,
        ];
    }

    /**
     * @param array<string, mixed> $selection
     * @param Collection<int, \App\Models\V2\PrizeInventory> $inventories
     */
    public function validateInventory(array $selection, Collection $inventories): void
    {
        if (! $selection['active']) {
            return;
        }
        $required = [];
        foreach ($selection['items'] as $item) {
            $required[$item['relation_id']] = ($required[$item['relation_id']] ?? 0) + 1;
        }
        foreach ($required as $relationId => $quantity) {
            $inventory = $inventories->get($relationId);
            if (
                $inventory === null
                || (int) $inventory->won_count + $quantity > (int) $inventory->initial_quantity
            ) {
                throw $this->configuration('QA Draw Prize Inventory is insufficient.');
            }
        }
    }

    /**
     * @param array<string, mixed> $selection
     */
    public function consume(
        array $selection,
        DrawRequest $drawRequest,
        User $user,
        int $gachaId,
        CarbonImmutable $occurredAt,
        string $requestId
    ): QaDrawExecution {
        if (
            ! $selection['active']
            || ! $selection['mode'] instanceof QaTestUserMode
            || ! $selection['plan'] instanceof QaDrawPlan
        ) {
            throw new \LogicException('Inactive QA selection cannot be consumed.');
        }
        $increments = array_count_values($selection['item_ids']);
        foreach ($increments as $itemId => $count) {
            $updated = DB::table('qa_draw_plan_items')
                ->where('id', $itemId)
                ->whereColumn('consumed_count', '<=', DB::raw('quantity - '.(int) $count))
                ->update([
                    'consumed_count' => DB::raw('consumed_count + '.(int) $count),
                    'updated_at' => $occurredAt,
                ]);
            if ($updated !== 1) {
                throw $this->configuration('QA Draw Plan Item consumption conflicted.');
            }
        }
        $remaining = (int) DB::table('qa_draw_plan_items')
            ->where('qa_draw_plan_id', $selection['plan']->id)
            ->sum(DB::raw('quantity - consumed_count'));
        if ($remaining === 0) {
            $this->complete($selection['plan'], $requestId, 'consumed');
        }

        $execution = new QaDrawExecution();
        $execution->forceFill([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gachaId,
            'qa_test_user_mode_id' => $selection['mode']->id,
            'qa_draw_plan_id' => $selection['plan']->id,
            'executed_count' => $drawRequest->requested_count,
            'executed_at' => $occurredAt,
            'metadata_redacted' => [
                'qa_mode_public_id' => $selection['mode']->public_id,
                'qa_plan_public_id' => $selection['plan']->public_id,
                'plan_item_public_ids' => array_values(array_unique(array_map(
                    static fn (array $item): string => $item['item_public_id'],
                    $selection['items']
                ))),
            ],
            'created_at' => $occurredAt,
        ])->save();

        return $execution;
    }

    public function completeExpiredPlan(
        User $user,
        string $gachaPublicId,
        string $requestId
    ): void {
        DB::transaction(function () use ($user, $gachaPublicId, $requestId): void {
            $plan = QaDrawPlan::query()
                ->join('catalog_gachas as gacha', 'gacha.id', '=', 'qa_draw_plans.gacha_id')
                ->join(
                    'qa_draw_plan_assignments as assignment',
                    'assignment.qa_draw_plan_id',
                    '=',
                    'qa_draw_plans.id'
                )
                ->where('assignment.user_id', $user->id)
                ->where('assignment.status', 'assigned')
                ->where('gacha.public_id', $gachaPublicId)
                ->where('qa_draw_plans.status', 'active')
                ->whereNull('qa_draw_plans.archived_at')
                ->whereNotNull('qa_draw_plans.ends_at')
                ->where('qa_draw_plans.ends_at', '<=', CarbonImmutable::now())
                ->lockForUpdate()
                ->first(['qa_draw_plans.*']);
            if ($plan instanceof QaDrawPlan) {
                $this->complete($plan, $requestId, 'expired');
            }
        });
    }

    /** @return array<string, mixed> */
    private function validatedItem(QaDrawPlanItem $item, int $gachaVersionId): array
    {
        $row = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'relation.rank_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'relation.presentation_asset_id'
            )
            ->where('relation.id', $item->gacha_version_prize_id)
            ->where('relation.gacha_version_id', $gachaVersionId)
            ->where('relation.is_visible', true)
            ->first([
                'relation.id as relation_id',
                'relation.sort_order as relation_sort_order',
                'prize.public_id as prize_public_id',
                'relation.display_name as prize_name',
                'relation.exchange_points',
                'rank.id as rank_id',
                'rank.public_id as rank_public_id',
                'relation.rank_code as rank_code',
                'relation.rank_display_name as rank_name',
                'relation.rank_sort_order as rank_sort_order',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.media_type as asset_media_type',
                'asset.mime_type as asset_mime_type',
                'asset.alt_text as asset_alt_text',
            ]);
        if ($row === null) {
            throw $this->configuration(
                'QA Draw Plan Prize is inactive or belongs to another Gacha Version.'
            );
        }

        return [
            'item_id' => (int) $item->id,
            'item_public_id' => $item->public_id,
            'relation_id' => (int) $row->relation_id,
            'fixed_image' => $this->asset($item->fixed_image_asset_id, 'image'),
            'fixed_video' => $this->asset($item->fixed_video_asset_id, 'video'),
            'prize' => [
                'relation_id' => (int) $row->relation_id,
                'relation_sort_order' => (int) $row->relation_sort_order,
                'prize_public_id' => $row->prize_public_id,
                'prize_name' => $row->prize_name,
                'exchange_points' => (int) $row->exchange_points,
                'rank_id' => (int) $row->rank_id,
                'rank_public_id' => $row->rank_public_id,
                'rank_code' => $row->rank_code,
                'rank_name' => $row->rank_name,
                'rank_sort_order' => (int) $row->rank_sort_order,
                'asset' => $row->asset_public_id === null ? null : [
                    'id' => $row->asset_public_id,
                    'path' => $row->asset_path,
                    'checksum_sha256' => $row->asset_checksum,
                    'media_type' => $row->asset_media_type,
                    'mime_type' => $row->asset_mime_type,
                    'alt_text' => $row->asset_alt_text,
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function asset(?int $id, string $expectedType): ?array
    {
        if ($id === null) {
            return null;
        }
        $asset = DB::table('catalog_presentation_assets')
            ->where('id', $id)
            ->where('media_type', $expectedType)
            ->where('is_public', true)
            ->first([
                'public_id',
                'public_path',
                'checksum_sha256',
                'media_type',
                'mime_type',
                'alt_text',
            ]);
        if ($asset === null) {
            throw $this->configuration("QA Draw fixed {$expectedType} Asset is invalid.");
        }

        return [
            'id' => $asset->public_id,
            'path' => $asset->public_path,
            'checksum_sha256' => $asset->checksum_sha256,
            'media_type' => $asset->media_type,
            'mime_type' => $asset->mime_type,
            'alt_text' => $asset->alt_text,
        ];
    }

    private function complete(QaDrawPlan $plan, string $requestId, string $reason): void
    {
        if ($plan->status !== 'completed') {
            $plan->forceFill([
                'status' => 'completed',
                'revision' => (int) $plan->revision + 1,
            ])->save();
            $this->audit->record('qa.plan.completed', [
                'request_id' => $requestId,
                'actor_type' => 'system',
                'target_type' => 'qa_draw_plan',
                'target_public_id' => $plan->public_id,
                'metadata' => ['completion_reason' => $reason],
            ]);
        }
    }

    private function modeActive(QaTestUserMode $mode): bool
    {
        $now = CarbonImmutable::now();

        return $mode->is_enabled
            && $mode->disabled_at === null
            && ($mode->starts_at === null || ! $mode->starts_at->greaterThan($now))
            && $mode->ends_at->greaterThan($now);
    }

    /** @return array<string, mixed> */
    private function inactive(): array
    {
        return [
            'active' => false,
            'mode' => null,
            'plan' => null,
            'items' => [],
            'item_ids' => [],
        ];
    }

    private function configuration(string $message): V2QaDrawException
    {
        return new V2QaDrawException('QA_CONFIGURATION_INVALID', 422, $message);
    }
}
