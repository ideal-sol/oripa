<?php

namespace App\Domain\Draw\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawResolver;
use App\Domain\QaDraw\ValueObjects\V2AdminQaDrawCommand;
use App\Models\V2\DrawRequest;
use App\Models\V2\DrawResult;
use App\Models\V2\GachaDrawState;
use App\Models\V2\IdempotencyRecord;
use App\Models\V2\PrizeInventory;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class V2DrawService
{
    public function __construct(
        private readonly V2DrawTransactionRunner $transactions,
        private readonly V2CryptographicRandomSource $random,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox,
        private readonly V2QaDrawResolver $qaDraw,
        private readonly V2DrawEligibilityService $eligibility
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        User $user,
        string $gachaPublicId,
        int $drawCount,
        string $idempotencyKey,
        string $requestId,
        ?V2AdminQaDrawCommand $adminCommand = null
    ): array {
        $this->assertInput($gachaPublicId, $drawCount, $idempotencyKey, $requestId);
        $randomValues = null;
        $started = hrtime(true);
        $qaRetryItemIds = null;
        $qaAttempted = false;

        try {
            return $this->transactions->run(function (int $attempt) use (
                $user,
                $gachaPublicId,
                $drawCount,
                $idempotencyKey,
                $requestId,
                $started,
                &$randomValues,
                &$qaRetryItemIds,
                &$qaAttempted,
                $adminCommand
            ): array {
                $claimRequest = ['gacha_id' => $gachaPublicId, 'draw_count' => $drawCount];
                if ($adminCommand !== null) {
                    $claimRequest['admin_qa_plan_id'] = $adminCommand->planPublicId;
                    $claimRequest['admin_qa_plan_revision'] = $adminCommand->planRevision;
                    $claimRequest['admin_qa_assignment_id'] = $adminCommand->assignmentPublicId;
                    $claimRequest['admin_qa_assignment_revision'] =
                        $adminCommand->assignmentRevision;
                }
                $claim = $this->idempotency->claim(
                    'draw.create',
                    'user',
                    $user->public_id,
                    $idempotencyKey,
                    $claimRequest
                );
                if ($claim->replay) {
                    $request = DrawRequest::query()
                        ->where('public_id', $claim->record->resource_public_id)
                        ->where('user_id', $user->id)
                        ->firstOrFail();
                    $response = $this->canonicalResponse($request);
                    $response['idempotent_replay'] = true;
                    $this->audit->record('draw.idempotent_replay', [
                        'request_id' => $requestId,
                        'actor_type' => 'user',
                        'actor_public_id' => $user->public_id,
                        'auth_realm' => 'user',
                        'target_type' => 'draw_request',
                        'target_public_id' => $request->public_id,
                        'metadata' => [
                            'gacha_public_id' => $gachaPublicId,
                            'requested_count' => $drawCount,
                        ],
                    ]);
                    if ($adminCommand !== null) {
                        $this->adminQaAudit(
                            'qa.execution.admin_replay',
                            $adminCommand,
                            $request->public_id,
                            $drawCount
                        );
                    }

                    return $response;
                }
                if ($randomValues === null) {
                    $randomValues = [];
                    for ($index = 0; $index < $drawCount; $index++) {
                        $randomValues[] = $this->random->integer(0, 999_999);
                    }
                }

                $gacha = DB::table('catalog_gachas')
                    ->where('public_id', $gachaPublicId)
                    ->lockForUpdate()
                    ->first();
                if ($gacha === null) {
                    throw new V2DrawException(
                        'GACHA_NOT_DRAWABLE',
                        404,
                        'The requested Gacha is not available.'
                    );
                }
                $state = GachaDrawState::query()
                    ->where('id', $gacha->active_draw_state_id)
                    ->where('gacha_id', $gacha->id)
                    ->lockForUpdate()
                    ->first();
                if (! $state instanceof GachaDrawState) {
                    throw new V2DrawException(
                        'GACHA_NOT_DRAWABLE',
                        409,
                        'The requested Gacha has no active Draw state.'
                    );
                }
                if ((bool) $gacha->sales_paused) {
                    throw new V2DrawException(
                        'GACHA_SALES_PAUSED',
                        409,
                        'The requested Gacha is temporarily unavailable.'
                    );
                }
                $context = $this->publishedContext($gacha, $state);
                if ($adminCommand === null && ! in_array(
                    $drawCount,
                    $this->allowedDrawCounts($context['version']->allowed_draw_counts ?? null),
                    true
                )) {
                    throw new V2DrawException(
                        'INVALID_DRAW_REQUEST',
                        422,
                        'The Draw request is invalid.'
                    );
                }
                if ($state->status !== 'selling') {
                    throw new V2DrawException(
                        'GACHA_NOT_DRAWABLE',
                        409,
                        'The requested Gacha is not selling.'
                    );
                }
                if ($state->sold_count + $drawCount > $state->total_count) {
                    throw new V2DrawException(
                        'DRAW_COUNT_INSUFFICIENT',
                        409,
                        'The Gacha does not have enough remaining draw count.'
                    );
                }
                $totalCost = $this->totalCost((int) $context['version']->price_points, $drawCount);
                $occurredAt = CarbonImmutable::now()->startOfSecond();
                $qaSelection = $this->qaDraw->resolve(
                    $user,
                    (int) $gacha->id,
                    (int) $context['version']->id,
                    $drawCount,
                    $requestId,
                    $qaRetryItemIds,
                    $adminCommand
                );
                if ($qaSelection['active']) {
                    $qaAttempted = true;
                    $qaRetryItemIds ??= $qaSelection['item_ids'];
                } else {
                    $this->eligibility->assertForDraw(
                        $user,
                        $gacha,
                        $context['version'],
                        $drawCount,
                        $occurredAt
                    );
                }
                $inventories = null;
                if ($qaSelection['active']) {
                    $this->points->lockAndValidateForDraw(
                        $user->id,
                        $totalCost,
                        $occurredAt
                    );
                    $inventories = $this->lockInventories($state);
                    $this->qaDraw->validateInventory($qaSelection, $inventories);
                }
                $drawRequest = new DrawRequest();
                $drawRequest->forceFill([
                    'user_id' => $user->id,
                    'gacha_draw_state_id' => $state->id,
                    'gacha_version_id' => $context['version']->id,
                    'probability_version_id' => $context['probability']->id,
                    'idempotency_record_id' => $claim->record->id,
                    'request_id' => $requestId,
                    'request_hash' => $claim->record->request_hash,
                    'catalog_snapshot_sha256' => $context['probability']->snapshot_sha256,
                    'requested_count' => $drawCount,
                    'executed_count' => 0,
                    'point_cost_total' => $totalCost,
                    'status' => 'processing',
                    'is_qa_draw' => $qaSelection['active'],
                    'qa_test_user_mode_id' => $qaSelection['mode']?->id,
                    'qa_draw_plan_id' => $qaSelection['plan']?->id,
                    'created_at' => $occurredAt,
                ])->save();
                $this->audit->record('draw.started', [
                    'request_id' => $requestId,
                    'actor_type' => 'user',
                    'actor_public_id' => $user->public_id,
                    'auth_realm' => 'user',
                    'target_type' => 'draw_request',
                    'target_public_id' => $drawRequest->public_id,
                    'metadata' => [
                        'gacha_public_id' => $gachaPublicId,
                        'requested_count' => $drawCount,
                        'attempt' => $attempt,
                        'is_qa_draw' => $qaSelection['active'],
                    ],
                ]);

                $pointConsumption = $this->points->consumeForDraw(
                    $user->id,
                    $totalCost,
                    $drawRequest->id,
                    $drawRequest->public_id,
                    $occurredAt
                );
                $inventories ??= $this->lockInventories($state);
                $probability = $this->probabilityContext(
                    (int) $context['probability']->id,
                    (int) $context['version']->id,
                    $inventories
                );
                $outcomes = $qaSelection['active']
                    ? $this->selectQaOutcomes(
                        $state,
                        $probability,
                        $inventories,
                        $randomValues,
                        (int) $context['version']->price_points,
                        $occurredAt,
                        $qaSelection
                    )
                    : $this->selectOutcomes(
                        $state,
                        $probability,
                        $inventories,
                        $randomValues,
                        (int) $context['version']->price_points,
                        $occurredAt
                    );
                $this->persistInventory($inventories, $outcomes['inventory_won'], $occurredAt);
                $state->forceFill([
                    'sold_count' => $state->sold_count + $drawCount,
                    'lock_version' => $state->lock_version + 1,
                ]);
                if ($state->sold_count === $state->total_count) {
                    $state->forceFill([
                        'status' => 'sold_out',
                        'sold_out_at' => $occurredAt,
                    ]);
                }
                $state->save();

                $results = $this->persistResults(
                    $drawRequest,
                    $user,
                    $state,
                    $context,
                    $outcomes['rows'],
                    $occurredAt
                );
                $userPrizeCount = $this->persistUserPrizes(
                    $user,
                    $results,
                    $outcomes['rows'],
                    $occurredAt
                );
                $pointBackGrants = [];
                foreach ($outcomes['rows'] as $row) {
                    if ($row['result_type'] !== 'point_back' || $row['point_back_amount'] === 0) {
                        continue;
                    }
                    $result = $results->get($row['request_sequence']);
                    if (! $result instanceof DrawResult) {
                        throw new V2DrawException(
                            'DRAW_PERSISTENCE_FAILED',
                            500,
                            'A Draw Result could not be mapped.'
                        );
                    }
                    $pointBackGrants[] = [
                        'draw_result_id' => $result->id,
                        'draw_result_public_id' => $result->public_id,
                        'amount' => $row['point_back_amount'],
                    ];
                }
                $pointBack = $this->points->grantDrawPointBackBatch(
                    $user->id,
                    $drawRequest->id,
                    $drawRequest->public_id,
                    $pointBackGrants,
                    $occurredAt
                );
                $qaExecution = $qaSelection['active']
                    ? $this->qaDraw->consume(
                        $qaSelection,
                        $drawRequest,
                        $user,
                        (int) $gacha->id,
                        $occurredAt,
                        $requestId
                    )
                    : null;
                $duration = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                $response = $this->response(
                    $drawRequest,
                    $gachaPublicId,
                    $context,
                    $outcomes['rows'],
                    $results,
                    $pointConsumption,
                    $pointBack,
                    $duration,
                    $requestId,
                    $occurredAt
                );
                $drawRequest->forceFill([
                    'executed_count' => $drawCount,
                    'consumed_paid_points' => $pointConsumption['paid'],
                    'consumed_free_points' => $pointConsumption['free'],
                    'wallet_paid_after' => $pointConsumption['wallet_paid_after'],
                    'wallet_free_after' => $pointBack['wallet_free_after'],
                    'point_back_total' => $pointBack['total'],
                    'status' => 'completed',
                    'processing_duration_ms' => $duration,
                    'response_data' => $response,
                    'completed_at' => $occurredAt,
                ])->save();
                $this->idempotency->complete(
                    $claim->record,
                    'draw_request',
                    $drawRequest->public_id,
                    $response
                );
                $this->auditSuccess(
                    $user,
                    $drawRequest,
                    $state,
                    $pointConsumption,
                    $outcomes,
                    $userPrizeCount,
                    $requestId
                );
                if ($qaExecution !== null) {
                    $this->audit->record('qa.draw.completed', [
                        'request_id' => $requestId,
                        'actor_type' => 'user',
                        'actor_public_id' => $user->public_id,
                        'auth_realm' => 'user',
                        'target_type' => 'qa_draw_execution',
                        'target_public_id' => $qaExecution->public_id,
                        'metadata' => [
                            'draw_request_public_id' => $drawRequest->public_id,
                            'executed_count' => $drawCount,
                            'qa_plan_public_id' => $qaSelection['plan']->public_id,
                        ],
                    ]);
                    if ($adminCommand !== null) {
                        $this->adminQaAudit(
                            'qa.execution.admin_completed',
                            $adminCommand,
                            $qaExecution->public_id,
                            $drawCount
                        );
                    }
                }
                $this->outbox->enqueue(
                    'draw.events',
                    'draw_request',
                    $drawRequest->public_id,
                    'draw.completed',
                    [
                        'draw_request_public_id' => $drawRequest->public_id,
                        'gacha_public_id' => $gachaPublicId,
                        'requested_count' => $drawCount,
                        'point_cost_total' => $totalCost,
                        'is_qa_draw' => $qaSelection['active'],
                    ],
                    'draw.completed:'.$drawRequest->public_id
                );

                return $response;
            });
        } catch (Throwable $exception) {
            $mapped = $this->mapException($exception);
            $this->audit->record('draw.failed', [
                'request_id' => $requestId,
                'actor_type' => 'user',
                'actor_public_id' => $user->public_id,
                'auth_realm' => 'user',
                'target_type' => 'gacha',
                'target_public_id' => $gachaPublicId,
                'outcome' => 'failure',
                'reason_code' => strtolower($mapped->errorCode),
                'metadata' => ['requested_count' => $drawCount],
            ]);
            if (str_starts_with($mapped->errorCode, 'QA_')) {
                $this->qaDraw->completeExpiredPlan(
                    $user,
                    $gachaPublicId,
                    $requestId
                );
            }
            if ($qaAttempted || str_starts_with($mapped->errorCode, 'QA_')) {
                $this->audit->record('qa.draw.failed', [
                    'request_id' => $requestId,
                    'actor_type' => 'user',
                    'actor_public_id' => $user->public_id,
                    'auth_realm' => 'user',
                    'target_type' => 'gacha',
                    'target_public_id' => $gachaPublicId,
                    'outcome' => 'failure',
                    'reason_code' => strtolower($mapped->errorCode),
                    'metadata' => ['requested_count' => $drawCount],
                ]);
            }

            throw $mapped;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $user, string $drawRequestPublicId): array
    {
        if (! Str::isUuid($drawRequestPublicId)) {
            throw new V2DrawException(
                'DRAW_NOT_FOUND',
                404,
                'The requested Draw was not found.'
            );
        }
        $request = DrawRequest::query()
            ->where('public_id', $drawRequestPublicId)
            ->where('user_id', $user->id)
            ->first();
        if (! $request instanceof DrawRequest || $request->status !== 'completed') {
            throw new V2DrawException(
                'DRAW_NOT_FOUND',
                404,
                'The requested Draw was not found.'
            );
        }

        return $this->canonicalResponse($request);
    }

    private function assertInput(
        string $gachaPublicId,
        int $drawCount,
        string $idempotencyKey,
        string $requestId
    ): void {
        $allowed = config('v2_draw.allowed_counts');
        if (
            ! Str::isUuid($gachaPublicId)
            || ! is_array($allowed)
            || ! in_array($drawCount, $allowed, true)
            || $idempotencyKey === ''
            || strlen($idempotencyKey) > 255
            || ! Str::isUuid($requestId)
        ) {
            throw new V2DrawException(
                'INVALID_DRAW_REQUEST',
                422,
                'The Draw request is invalid.'
            );
        }
    }

    /** @return list<int> */
    private function allowedDrawCounts(mixed $value): array
    {
        if ($value === null) {
            return [1, 5, 10];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            [1, 5, 10, 100, 1000],
            static fn (int $count): bool => in_array($count, $value, true)
        ));
    }

    /**
     * @return array{version: object, probability: object}
     */
    private function publishedContext(object $gacha, GachaDrawState $state): array
    {
        $now = now();
        $version = DB::table('catalog_gacha_versions')
            ->where('id', $state->gacha_version_id)
            ->where('gacha_id', $gacha->id)
            ->where('status', 'published')
            ->where('publish_start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('publish_end_at')->orWhere('publish_end_at', '>', $now);
            })
            ->first();
        $probability = DB::table('catalog_probability_versions')
            ->where('id', $state->probability_version_id)
            ->where('gacha_version_id', $state->gacha_version_id)
            ->where('status', 'published')
            ->first();
        if (
            $version === null
            || $probability === null
            || (int) $gacha->published_version_id !== (int) $state->gacha_version_id
            || (int) $version->published_probability_version_id
                !== (int) $state->probability_version_id
            || $gacha->state !== 'active'
        ) {
            throw new V2DrawException(
                'GACHA_NOT_DRAWABLE',
                409,
                'The requested Gacha is outside its published Draw period.'
            );
        }

        return ['version' => $version, 'probability' => $probability];
    }

    private function totalCost(int $price, int $drawCount): int
    {
        if ($price < 1 || $drawCount < 1 || $price > intdiv(PHP_INT_MAX, $drawCount)) {
            throw new V2DrawException(
                'DRAW_COST_OVERFLOW',
                422,
                'The Draw cost exceeds the supported integer range.'
            );
        }

        return $price * $drawCount;
    }

    /** @return Collection<int, PrizeInventory> */
    private function lockInventories(GachaDrawState $state): Collection
    {
        return PrizeInventory::query()
            ->where('gacha_draw_state_id', $state->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('gacha_version_prize_id');
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @return array{
     *   stages: Collection<int, object>,
     *   entries: array<int, list<object>>,
     *   guarantees: array<int, object>,
     *   prizes: array<int, array<string, mixed>>
     * }
     */
    private function probabilityContext(
        int $probabilityVersionId,
        int $gachaVersionId,
        Collection $inventories
    ): array {
        $stages = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->orderBy('min_draw_number')
            ->orderBy('id')
            ->get();
        $entries = DB::table('catalog_probability_entries')
            ->whereIn('probability_stage_id', $stages->pluck('id'))
            ->orderBy('probability_stage_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('probability_stage_id')
            ->map(fn (Collection $rows): array => $rows->all())
            ->all();
        $guarantees = DB::table('catalog_minimum_guarantees')
            ->whereIn('probability_stage_id', $stages->pluck('id'))
            ->get()
            ->keyBy('probability_stage_id')
            ->all();
        $prizeRows = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'prize.presentation_asset_id'
            )
            ->where('relation.gacha_version_id', $gachaVersionId)
            ->select([
                'relation.id as relation_id',
                'relation.sort_order as relation_sort_order',
                'prize.public_id as prize_public_id',
                'prize.display_name as prize_name',
                'prize.exchange_points as prize_exchange_points',
                'rank.id as rank_id',
                'rank.public_id as rank_public_id',
                'rank.code as rank_code',
                'rank.display_name as rank_name',
                'rank.sort_order as rank_sort_order',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.media_type as asset_media_type',
                'asset.mime_type as asset_mime_type',
                'asset.alt_text as asset_alt_text',
            ])
            ->orderBy('relation.id')
            ->get();
        $rankAssets = DB::table('catalog_rank_assets as relation')
            ->join(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'relation.presentation_asset_id'
            )
            ->whereIn('relation.rank_id', $prizeRows->pluck('rank_id')->unique())
            ->where('asset.is_public', true)
            ->orderBy('relation.rank_id')
            ->orderBy('relation.sort_order')
            ->orderBy('relation.id')
            ->get()
            ->groupBy('rank_id');
        $prizes = [];
        foreach ($prizeRows as $row) {
            if (! $inventories->has($row->relation_id)) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_UNAVAILABLE',
                    409,
                    'Prize Inventory is not initialized for this Gacha.'
                );
            }
            $animations = $rankAssets->get($row->rank_id, collect());
            $prizes[(int) $row->relation_id] = [
                'relation_id' => (int) $row->relation_id,
                'relation_sort_order' => (int) $row->relation_sort_order,
                'prize_public_id' => $row->prize_public_id,
                'prize_name' => $row->prize_name,
                'exchange_points' => (int) $row->prize_exchange_points,
                'rank_id' => (int) $row->rank_id,
                'rank_public_id' => $row->rank_public_id,
                'rank_code' => $row->rank_code,
                'rank_name' => $row->rank_name,
                'rank_sort_order' => (int) $row->rank_sort_order,
                'asset' => $this->asset($row),
                'animation_image' => $this->animation($animations, 'result_image')
                    ?? $this->animation($animations, 'image'),
                'animation_video' => $this->animation($animations, 'video'),
            ];
        }
        if ($stages->isEmpty() || count($guarantees) !== $stages->count()) {
            throw new V2DrawException(
                'PROBABILITY_CONFIGURATION_INVALID',
                409,
                'Published Probability configuration is incomplete.'
            );
        }

        return [
            'stages' => $stages,
            'entries' => $entries,
            'guarantees' => $guarantees,
            'prizes' => $prizes,
        ];
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param list<int> $randomValues
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   inventory_won: array<int, int>
     * }
     */
    private function selectOutcomes(
        GachaDrawState $state,
        array $probability,
        Collection $inventories,
        array $randomValues,
        int $pricePoints,
        CarbonImmutable $occurredAt
    ): array {
        $rows = [];
        $stageIndex = 0;
        $rangeCache = [];
        $inventoryWon = $inventories->mapWithKeys(
            fn (PrizeInventory $inventory): array => [
                (int) $inventory->gacha_version_prize_id => (int) $inventory->won_count,
            ]
        )->all();
        foreach ($randomValues as $index => $randomValue) {
            $sequence = $state->sold_count + $index + 1;
            $stage = $this->stageForSequence(
                $probability['stages'],
                $sequence,
                $stageIndex
            );
            $rangeCache[$stage->id] ??= $this->rangeForStage(
                $stage,
                $probability,
                $inventories,
                $inventoryWon
            );
            $selected = $this->pick($rangeCache[$stage->id], $randomValue);
            $publicId = (string) Str::uuid7();
            if ($selected['result_type'] === 'prize') {
                $relationId = $selected['gacha_version_prize_id'];
                $inventory = $inventories->get($relationId);
                if (! $inventory instanceof PrizeInventory) {
                    throw new V2DrawException(
                        'PRIZE_INVENTORY_UNAVAILABLE',
                        409,
                        'Selected Prize Inventory is unavailable.'
                    );
                }
                $nextWon = ($inventoryWon[$relationId] ?? 0) + 1;
                if ($nextWon > $inventory->initial_quantity) {
                    throw new V2DrawException(
                        'PRIZE_INVENTORY_INSUFFICIENT',
                        409,
                        'Selected Prize Inventory is insufficient.'
                    );
                }
                $inventoryWon[$relationId] = $nextWon;
                if ($nextWon === $inventory->initial_quantity) {
                    $rangeCache = [];
                }
                $prize = $probability['prizes'][$relationId];
                $snapshot = [
                    'result_type' => 'prize',
                    'rank' => [
                        'id' => $prize['rank_public_id'],
                        'code' => $prize['rank_code'],
                        'name' => $prize['rank_name'],
                    ],
                    'prize' => [
                        'id' => $prize['prize_public_id'],
                        'name' => $prize['prize_name'],
                        'presentation_asset' => $prize['asset'],
                    ],
                    'animation' => [
                        'image' => $prize['animation_image'],
                        'video' => $prize['animation_video'],
                    ],
                ];
                $rows[] = $this->outcomeRow(
                    $publicId,
                    $index + 1,
                    $sequence,
                    $stage,
                    $randomValue,
                    'prize',
                    $relationId,
                    $prize['rank_id'],
                    0,
                    $pricePoints,
                    $snapshot,
                    $occurredAt,
                    $prize
                );
            } else {
                $amount = (int) $selected['point_amount'];
                $snapshot = [
                    'result_type' => 'point_back',
                    'point_back' => ['amount' => $amount, 'point_type' => 'free'],
                ];
                $rows[] = $this->outcomeRow(
                    $publicId,
                    $index + 1,
                    $sequence,
                    $stage,
                    $randomValue,
                    'point_back',
                    null,
                    null,
                    $amount,
                    $pricePoints,
                    $snapshot,
                    $occurredAt,
                    null
                );
            }
        }

        return ['rows' => $rows, 'inventory_won' => $inventoryWon];
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param list<int> $randomValues
     * @param array<string, mixed> $selection
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   inventory_won: array<int, int>
     * }
     */
    private function selectQaOutcomes(
        GachaDrawState $state,
        array $probability,
        Collection $inventories,
        array $randomValues,
        int $pricePoints,
        CarbonImmutable $occurredAt,
        array $selection
    ): array {
        $rows = [];
        $stageIndex = 0;
        $inventoryWon = $inventories->mapWithKeys(
            fn (PrizeInventory $inventory): array => [
                (int) $inventory->gacha_version_prize_id => (int) $inventory->won_count,
            ]
        )->all();
        foreach ($randomValues as $index => $randomValue) {
            $sequence = $state->sold_count + $index + 1;
            $stage = $this->stageForSequence(
                $probability['stages'],
                $sequence,
                $stageIndex
            );
            $selected = $selection['items'][$index] ?? null;
            if (! is_array($selected)) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw selection is incomplete.'
                );
            }
            $relationId = (int) $selected['relation_id'];
            $inventory = $inventories->get($relationId);
            $prize = $probability['prizes'][$relationId] ?? null;
            if (! $inventory instanceof PrizeInventory || ! is_array($prize)) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw Prize is unavailable.'
                );
            }
            $nextWon = ($inventoryWon[$relationId] ?? 0) + 1;
            if ($nextWon > $inventory->initial_quantity) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw Prize Inventory is insufficient.'
                );
            }
            $inventoryWon[$relationId] = $nextWon;
            $snapshot = [
                'result_type' => 'prize',
                'rank' => [
                    'id' => $prize['rank_public_id'],
                    'code' => $prize['rank_code'],
                    'name' => $prize['rank_name'],
                ],
                'prize' => [
                    'id' => $prize['prize_public_id'],
                    'name' => $prize['prize_name'],
                    'presentation_asset' => $prize['asset'],
                ],
                'animation' => [
                    'image' => $selected['fixed_image'] ?? $prize['animation_image'],
                    'video' => $selected['fixed_video'] ?? $prize['animation_video'],
                ],
            ];
            $rows[] = $this->outcomeRow(
                (string) Str::uuid7(),
                $index + 1,
                $sequence,
                $stage,
                $randomValue,
                'prize',
                $relationId,
                $prize['rank_id'],
                0,
                $pricePoints,
                $snapshot,
                $occurredAt,
                $prize,
                (int) $selected['item_id']
            );
        }

        return ['rows' => $rows, 'inventory_won' => $inventoryWon];
    }

    private function stageForSequence(
        Collection $stages,
        int $sequence,
        int &$stageIndex
    ): object {
        while ($stageIndex < $stages->count()) {
            $stage = $stages->get($stageIndex);
            if (
                (int) $stage->min_draw_number <= $sequence
                && ($stage->max_draw_number === null
                    || (int) $stage->max_draw_number >= $sequence)
            ) {
                return $stage;
            }
            if ($stage->max_draw_number !== null
                && (int) $stage->max_draw_number < $sequence) {
                $stageIndex++;
                continue;
            }
            break;
        }

        throw new V2DrawException(
            'PROBABILITY_STAGE_NOT_FOUND',
            409,
            'No Published Probability Stage covers the Draw sequence.'
        );
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param array<int, int> $inventoryWon
     * @return list<array<string, mixed>>
     */
    private function rangeForStage(
        object $stage,
        array $probability,
        Collection $inventories,
        array $inventoryWon
    ): array {
        $range = [];
        $cursor = 0;
        $exhaustedPpm = 0;
        foreach ($probability['entries'][$stage->id] ?? [] as $entry) {
            $ppm = (int) $entry->probability_ppm;
            if ($entry->result_type === 'prize') {
                $relationId = (int) $entry->gacha_version_prize_id;
                $inventory = $inventories->get($relationId);
                if (! $inventory instanceof PrizeInventory) {
                    throw new V2DrawException(
                        'PRIZE_INVENTORY_UNAVAILABLE',
                        409,
                        'Prize Inventory is unavailable.'
                    );
                }
                if (($inventoryWon[$relationId] ?? 0) >= $inventory->initial_quantity) {
                    $exhaustedPpm += $ppm;
                    continue;
                }
                $target = [
                    'result_type' => 'prize',
                    'gacha_version_prize_id' => $relationId,
                    'point_amount' => null,
                ];
            } elseif ($entry->result_type === 'point_back') {
                $target = [
                    'result_type' => 'point_back',
                    'gacha_version_prize_id' => null,
                    'point_amount' => (int) $entry->point_amount,
                ];
            } else {
                throw new V2DrawException(
                    'PROBABILITY_CONFIGURATION_INVALID',
                    409,
                    'Probability Result Type is invalid.'
                );
            }
            if ($ppm > 0) {
                $range[] = [...$target, 'start' => $cursor, 'end' => $cursor + $ppm];
                $cursor += $ppm;
            }
        }
        $guarantee = $probability['guarantees'][$stage->id];
        $guaranteePpm = (int) $guarantee->probability_ppm + $exhaustedPpm;
        if ($guarantee->result_type === 'prize') {
            $relationId = (int) $guarantee->gacha_version_prize_id;
            $inventory = $inventories->get($relationId);
            if (
                ! $inventory instanceof PrizeInventory
                || ($inventoryWon[$relationId] ?? 0) >= $inventory->initial_quantity
            ) {
                throw new V2DrawException(
                    'MINIMUM_GUARANTEE_UNAVAILABLE',
                    409,
                    'Minimum Guarantee Prize Inventory is unavailable.'
                );
            }
            $target = [
                'result_type' => 'prize',
                'gacha_version_prize_id' => $relationId,
                'point_amount' => null,
            ];
        } elseif ($guarantee->result_type === 'point_back') {
            $target = [
                'result_type' => 'point_back',
                'gacha_version_prize_id' => null,
                'point_amount' => (int) $guarantee->point_amount,
            ];
        } else {
            throw new V2DrawException(
                'PROBABILITY_CONFIGURATION_INVALID',
                409,
                'Minimum Guarantee Result Type is invalid.'
            );
        }
        if ($guaranteePpm > 0) {
            $range[] = [
                ...$target,
                'start' => $cursor,
                'end' => $cursor + $guaranteePpm,
            ];
            $cursor += $guaranteePpm;
        }
        if ($cursor !== 1_000_000) {
            throw new V2DrawException(
                'PROBABILITY_TOTAL_INVALID',
                409,
                'Probability Stage total must be exactly 1,000,000 ppm.'
            );
        }

        return $range;
    }

    /**
     * @param list<array<string, mixed>> $range
     * @return array<string, mixed>
     */
    private function pick(array $range, int $randomValue): array
    {
        foreach ($range as $entry) {
            if ($randomValue >= $entry['start'] && $randomValue < $entry['end']) {
                return $entry;
            }
        }

        throw new V2DrawException(
            'PROBABILITY_SELECTION_FAILED',
            500,
            'Probability selection did not produce a result.'
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $prize
     * @return array<string, mixed>
     */
    private function outcomeRow(
        string $publicId,
        int $requestSequence,
        int $drawSequence,
        object $stage,
        int $randomValue,
        string $resultType,
        ?int $relationId,
        ?int $rankId,
        int $pointBackAmount,
        int $consumedPoints,
        array $snapshot,
        CarbonImmutable $occurredAt,
        ?array $prize,
        ?int $qaDrawPlanItemId = null
    ): array {
        $encoded = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return [
            'public_id' => $publicId,
            'request_sequence' => $requestSequence,
            'draw_sequence_number' => $drawSequence,
            'probability_stage_id' => (int) $stage->id,
            'probability_stage_public_id' => $stage->public_id,
            'result_type' => $resultType,
            'gacha_version_prize_id' => $relationId,
            'rank_id' => $rankId,
            'consumed_points' => $consumedPoints,
            'point_back_amount' => $pointBackAmount,
            'random_value' => $randomValue,
            'display_snapshot' => $snapshot,
            'display_snapshot_json' => $encoded,
            'display_snapshot_sha256' => hash('sha256', $encoded),
            'occurred_at' => $occurredAt,
            'prize' => $prize,
            'qa_draw_plan_item_id' => $qaDrawPlanItemId,
        ];
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param array<int, int> $inventoryWon
     */
    private function persistInventory(
        Collection $inventories,
        array $inventoryWon,
        CarbonImmutable $occurredAt
    ): void {
        $changed = [];
        foreach ($inventories as $relationId => $inventory) {
            $won = $inventoryWon[(int) $relationId] ?? (int) $inventory->won_count;
            if ($won !== (int) $inventory->won_count) {
                $changed[(int) $inventory->id] = $won;
            }
        }
        foreach (array_chunk($changed, 250, true) as $chunk) {
            $cases = [];
            $bindings = [];
            foreach ($chunk as $id => $won) {
                $cases[] = 'WHEN ?::bigint THEN ?::bigint';
                $bindings[] = $id;
                $bindings[] = $won;
            }
            $ids = array_keys($chunk);
            $bindings[] = $occurredAt;
            array_push($bindings, ...$ids);
            DB::update(
                'UPDATE prize_inventories SET won_count = CASE id '.
                implode(' ', $cases).
                ' END, lock_version = lock_version + 1, updated_at = ? '.
                'WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                $bindings
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return Collection<int, DrawResult>
     */
    private function persistResults(
        DrawRequest $request,
        User $user,
        GachaDrawState $state,
        array $context,
        array $rows,
        CarbonImmutable $occurredAt
    ): Collection {
        $insertRows = [];
        foreach ($rows as $row) {
            $insertRows[] = [
                'public_id' => $row['public_id'],
                'draw_request_id' => $request->id,
                'user_id' => $user->id,
                'gacha_draw_state_id' => $state->id,
                'probability_version_id' => $context['probability']->id,
                'probability_stage_id' => $row['probability_stage_id'],
                'request_sequence' => $row['request_sequence'],
                'draw_sequence_number' => $row['draw_sequence_number'],
                'result_type' => $row['result_type'],
                'gacha_version_prize_id' => $row['gacha_version_prize_id'],
                'rank_id' => $row['rank_id'],
                'consumed_points' => $row['consumed_points'],
                'point_back_amount' => $row['point_back_amount'],
                'random_value' => $row['random_value'],
                'display_snapshot' => $row['display_snapshot_json'],
                'display_snapshot_sha256' => $row['display_snapshot_sha256'],
                'occurred_at' => $row['occurred_at'],
                'created_at' => $occurredAt,
                'is_qa_draw' => $row['qa_draw_plan_item_id'] !== null,
                'qa_draw_plan_item_id' => $row['qa_draw_plan_item_id'],
            ];
        }
        $chunkSize = (int) config('v2_draw.insert_chunk_size', 250);
        foreach (array_chunk($insertRows, $chunkSize) as $chunk) {
            DB::table('draw_results')->insert($chunk);
        }
        $results = DrawResult::query()
            ->where('draw_request_id', $request->id)
            ->orderBy('request_sequence')
            ->get()
            ->keyBy('request_sequence');
        if ($results->count() !== count($rows)) {
            throw new V2DrawException(
                'DRAW_PERSISTENCE_FAILED',
                500,
                'Draw Result persistence count does not match.'
            );
        }

        return $results;
    }

    /**
     * @param Collection<int, DrawResult> $results
     * @param list<array<string, mixed>> $rows
     */
    private function persistUserPrizes(
        User $user,
        Collection $results,
        array $rows,
        CarbonImmutable $occurredAt
    ): int {
        $insertRows = [];
        foreach ($rows as $row) {
            if ($row['result_type'] !== 'prize') {
                continue;
            }
            $result = $results->get($row['request_sequence']);
            if (! $result instanceof DrawResult) {
                throw new V2DrawException(
                    'DRAW_PERSISTENCE_FAILED',
                    500,
                    'User Prize could not be mapped to its Draw Result.'
                );
            }
            $insertRows[] = [
                'public_id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'draw_result_id' => $result->id,
                'gacha_version_prize_id' => $row['gacha_version_prize_id'],
                'status' => 'stored',
                'exchange_point_snapshot' => $row['prize']['exchange_points'],
                'exchanged_point_amount' => null,
                'acquired_at' => $occurredAt,
                'storage_expires_at' => $occurredAt->copy()->addDays(
                    (int) config('v2_prize_shipping.storage_days', 60)
                ),
                'terminal_at' => null,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];
        }
        $chunkSize = (int) config('v2_draw.insert_chunk_size', 250);
        foreach (array_chunk($insertRows, $chunkSize) as $chunk) {
            DB::table('user_prizes')->insert($chunk);
        }

        return count($insertRows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param Collection<int, DrawResult> $results
     * @param array<string, mixed> $pointConsumption
     * @param array{total: int, wallet_free_after: int} $pointBack
     * @return array<string, mixed>
     */
    private function response(
        DrawRequest $request,
        string $gachaPublicId,
        array $context,
        array $rows,
        Collection $results,
        array $pointConsumption,
        array $pointBack,
        int $duration,
        string $requestId,
        CarbonImmutable $occurredAt
    ): array {
        $rankCounts = [];
        $prizeCounts = [];
        $highRank = [];
        $highRankTotal = 0;
        $individual = [];
        $highRankMaximum = (int) config('v2_draw.high_rank_sort_order_max', 20);
        $highRankLimit = (int) config('v2_draw.high_rank_result_limit', 20);
        foreach ($rows as $row) {
            $result = $results->get($row['request_sequence']);
            if (! $result instanceof DrawResult) {
                throw new V2DrawException(
                    'DRAW_PERSISTENCE_FAILED',
                    500,
                    'Draw Response could not be mapped.'
                );
            }
            $publicResult = [
                'id' => $result->public_id,
                'sequence_number' => $row['draw_sequence_number'],
                'result_type' => $row['result_type'],
                'rank' => $row['display_snapshot']['rank'] ?? null,
                'prize' => $row['display_snapshot']['prize'] ?? null,
                'point_back' => $row['display_snapshot']['point_back'] ?? null,
                'animation' => $row['display_snapshot']['animation'] ?? null,
            ];
            if (count($rows) < (int) config('v2_draw.bulk_threshold', 100)) {
                $individual[] = $publicResult;
            }
            if ($row['result_type'] !== 'prize') {
                continue;
            }
            $prize = $row['prize'];
            $rankKey = $prize['rank_public_id'];
            $rankCounts[$rankKey] ??= [
                'rank' => [
                    'id' => $prize['rank_public_id'],
                    'code' => $prize['rank_code'],
                    'name' => $prize['rank_name'],
                ],
                'count' => 0,
                '_sort' => $prize['rank_sort_order'],
            ];
            $rankCounts[$rankKey]['count']++;
            $prizeKey = $prize['prize_public_id'];
            $prizeCounts[$prizeKey] ??= [
                'prize' => [
                    'id' => $prize['prize_public_id'],
                    'name' => $prize['prize_name'],
                    'presentation_asset' => $prize['asset'],
                ],
                'rank' => $rankCounts[$rankKey]['rank'],
                'count' => 0,
                '_rank_sort' => $prize['rank_sort_order'],
                '_prize_sort' => $prize['relation_sort_order'],
            ];
            $prizeCounts[$prizeKey]['count']++;
            if ($prize['rank_sort_order'] <= $highRankMaximum) {
                $highRankTotal++;
                if (count($highRank) < $highRankLimit) {
                    $highRank[] = $publicResult;
                }
            }
        }
        usort(
            $rankCounts,
            fn (array $left, array $right): int => $left['_sort'] <=> $right['_sort']
        );
        usort(
            $prizeCounts,
            fn (array $left, array $right): int =>
                [$left['_rank_sort'], $left['_prize_sort']]
                <=> [$right['_rank_sort'], $right['_prize_sort']]
        );
        $rankCounts = array_map(function (array $value): array {
            unset($value['_sort']);

            return $value;
        }, array_values($rankCounts));
        $prizeCounts = array_map(function (array $value): array {
            unset($value['_rank_sort'], $value['_prize_sort']);

            return $value;
        }, array_values($prizeCounts));
        $response = [
            'id' => $request->public_id,
            'gacha_id' => $gachaPublicId,
            'status' => 'completed',
            'requested_count' => count($rows),
            'executed_count' => count($rows),
            'point_cost_total' => (int) $request->point_cost_total,
            'point_consumption' => [
                'paid_points' => $pointConsumption['paid'],
                'free_points' => $pointConsumption['free'],
            ],
            'wallet_after' => [
                'paid_points' => $pointConsumption['wallet_paid_after'],
                'free_points' => $pointBack['wallet_free_after'],
                'total_points' => $pointConsumption['wallet_paid_after']
                    + $pointBack['wallet_free_after'],
            ],
            'rank_counts' => $rankCounts,
            'prize_counts' => $prizeCounts,
            'point_back_total' => $pointBack['total'],
            'high_rank_results' => $highRank,
            'high_rank_results_truncated' => $highRankTotal > count($highRank),
            'probability_version' => [
                'id' => $context['probability']->public_id,
                'version' => (int) $context['probability']->version_number,
            ],
            'idempotent_replay' => false,
            'request_id' => $requestId,
            'processing_duration_ms' => $duration,
            'created_at' => $occurredAt->utc()->toIso8601String(),
        ];
        if ($individual !== []) {
            $response['results'] = $individual;
        }

        return $response;
    }

    private function auditSuccess(
        User $user,
        DrawRequest $request,
        GachaDrawState $state,
        array $pointConsumption,
        array $outcomes,
        int $userPrizeCount,
        string $requestId
    ): void {
        $base = [
            'request_id' => $requestId,
            'actor_type' => 'user',
            'actor_public_id' => $user->public_id,
            'auth_realm' => 'user',
            'target_type' => 'draw_request',
            'target_public_id' => $request->public_id,
        ];
        $this->audit->record('draw.point_consumed', [
            ...$base,
            'metadata' => [
                'paid_points' => $pointConsumption['paid'],
                'free_points' => $pointConsumption['free'],
            ],
        ]);
        $this->audit->record('draw.inventory_changed', [
            ...$base,
            'metadata' => [
                'affected_prize_count' => count(array_unique(array_column(
                    array_filter(
                        $outcomes['rows'],
                        static fn (array $row): bool => $row['result_type'] === 'prize'
                    ),
                    'gacha_version_prize_id'
                ))),
                'sold_count_after' => $state->sold_count,
            ],
        ]);
        $this->audit->record('draw.user_prizes_created', [
            ...$base,
            'metadata' => ['created_count' => $userPrizeCount],
        ]);
        $this->audit->record('draw.completed', [
            ...$base,
            'metadata' => [
                'requested_count' => $request->requested_count,
                'point_cost_total' => $request->point_cost_total,
            ],
        ]);
    }

    private function adminQaAudit(
        string $action,
        V2AdminQaDrawCommand $command,
        string $targetPublicId,
        int $drawCount
    ): void {
        $this->audit->record($action, [
            'request_id' => $command->actor->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $command->actor->adminPublicId,
            'actor_role' => $command->actor->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $command->actor->sessionCorrelationHash,
            'target_type' => 'qa_draw_execution',
            'target_public_id' => $targetPublicId,
            'metadata' => [
                'qa_plan_public_id' => $command->planPublicId,
                'assignment_public_id' => $command->assignmentPublicId,
                'requested_count' => $drawCount,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalResponse(DrawRequest $request): array
    {
        $response = $request->response_data;
        if (! is_array($response)) {
            throw new V2DrawException(
                'DRAW_RESULT_UNAVAILABLE',
                500,
                'The canonical Draw response is unavailable.'
            );
        }

        return $response;
    }

    private function mapException(Throwable $exception): V2DrawException
    {
        if ($exception instanceof V2DrawException) {
            return $exception;
        }
        if ($exception instanceof V2QaDrawException) {
            return new V2DrawException(
                $exception->errorCode,
                $exception->status,
                $exception->getMessage(),
                $exception->retryable
            );
        }
        if ($exception instanceof V2PointException) {
            return match ($exception->getMessage()) {
                'INSUFFICIENT_POINT_BALANCE' => new V2DrawException(
                    'INSUFFICIENT_POINTS',
                    409,
                    'The wallet does not have enough available points.'
                ),
                'IDEMPOTENCY_KEY_REUSED' => new V2DrawException(
                    'IDEMPOTENCY_KEY_REUSED',
                    409,
                    'The Idempotency-Key was used for a different request.'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2DrawException(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The Draw request is still processing.',
                    true,
                    1
                ),
                default => new V2DrawException(
                    'DRAW_POINT_OPERATION_FAILED',
                    409,
                    'The Draw point operation could not be completed.'
                ),
            };
        }

        return new V2DrawException(
            'DRAW_INTERNAL_ERROR',
            500,
            'The Draw could not be completed.'
        );
    }

    private function asset(object $row): ?array
    {
        return $row->asset_public_id === null ? null : [
            'id' => $row->asset_public_id,
            'path' => $row->asset_path,
            'checksum_sha256' => $row->asset_checksum,
            'media_type' => $row->asset_media_type,
            'mime_type' => $row->asset_mime_type,
            'alt_text' => $row->asset_alt_text,
        ];
    }

    private function animation(Collection $assets, string $usageType): ?array
    {
        $asset = $assets->firstWhere('usage_type', $usageType);

        return $asset === null ? null : [
            'id' => $asset->public_id,
            'path' => $asset->public_path,
            'checksum_sha256' => $asset->checksum_sha256,
            'media_type' => $asset->media_type,
            'mime_type' => $asset->mime_type,
            'alt_text' => $asset->alt_text,
        ];
    }
}
