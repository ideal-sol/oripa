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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
                $drawMetadata = $this->drawMetadata(
                    (int) $context['probability']->id,
                    (int) $context['version']->id
                );
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
                }
                if (! $qaSelection['active'] && $adminCommand === null && ! in_array(
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
                $inventories = $this->lockInventories($state);
                $this->assertPresentationInventories($drawMetadata, $inventories);
                $remainingCount = $this->remainingInventory($inventories);
                $this->qaDraw->validateInventory($qaSelection, $inventories);
                if (
                    $state->status !== 'selling'
                    && ! ($state->status === 'sold_out' && $remainingCount > 0)
                ) {
                    throw new V2DrawException(
                        'GACHA_NOT_DRAWABLE',
                        409,
                        'The requested Gacha is not selling.'
                    );
                }
                if ($remainingCount === 0) {
                    throw new V2DrawException(
                        'DRAW_COUNT_INSUFFICIENT',
                        409,
                        'The Gacha does not have enough remaining draw count.'
                    );
                }
                $legacyQa = $qaSelection['kind'] === 'legacy_plan';
                $executedCount = $legacyQa
                    ? $drawCount
                    : min($drawCount, $remainingCount);
                if ($legacyQa && $executedCount > $remainingCount) {
                    throw new V2DrawException(
                        'DRAW_COUNT_INSUFFICIENT',
                        409,
                        'The Gacha does not have enough remaining draw count.'
                    );
                }
                $totalCost = $this->totalCost(
                    (int) $context['version']->price_points,
                    $executedCount
                );
                $occurredAt = CarbonImmutable::now()->startOfSecond();
                if (! $qaSelection['active']) {
                    $this->eligibility->assertForDraw(
                        $user,
                        $gacha,
                        $context['version'],
                        $drawCount,
                        $occurredAt
                    );
                }
                if ($qaSelection['active']) {
                    $this->points->lockAndValidateForDraw(
                        $user->id,
                        $totalCost,
                        $occurredAt
                    );
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
                    'qa_gacha_guarantee_assignment_id' => $qaSelection['assignment']?->id,
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
                $outcomes = $qaSelection['kind'] === 'legacy_plan'
                    ? $this->selectQaOutcomes(
                        $state,
                        $drawMetadata,
                        $inventories,
                        (int) $context['version']->price_points,
                        $occurredAt,
                        $qaSelection
                    )
                    : ($qaSelection['kind'] === 'persistent_guarantee'
                        ? $this->selectGuaranteedOutcomes(
                            $state,
                            $drawMetadata,
                            $inventories,
                            $executedCount,
                            (int) $context['version']->price_points,
                            $occurredAt,
                            $qaSelection
                        )
                        : $this->selectOutcomes(
                        $state,
                        $drawMetadata,
                        $inventories,
                        $executedCount,
                        (int) $context['version']->price_points,
                        $occurredAt
                    ));
                $this->persistInventory($inventories, $outcomes['inventory_won'], $occurredAt);
                $state->forceFill([
                    'sold_count' => $state->sold_count + $executedCount,
                    'lock_version' => $state->lock_version + 1,
                ]);
                $remainingAfter = $remainingCount - $executedCount;
                if ($remainingAfter === 0) {
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
                $pointBack = [
                    'total' => 0,
                    'wallet_free_after' => $pointConsumption['wallet_free_after'],
                ];
                $qaExecution = $qaSelection['active']
                    ? $this->qaDraw->consume(
                        $qaSelection,
                        $drawRequest,
                        $user,
                        (int) $gacha->id,
                        $occurredAt,
                        $requestId,
                        $executedCount
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
                    'executed_count' => $executedCount,
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
                            'executed_count' => $executedCount,
                            'qa_kind' => $qaSelection['kind'],
                            'qa_plan_public_id' => $qaSelection['plan']?->public_id,
                            'qa_guarantee_assignment_public_id' =>
                                $qaSelection['assignment']?->public_id,
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
                        'executed_count' => $executedCount,
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

    /** @return array{items: list<array<string, mixed>>, next_cursor: string|null} */
    public function history(User $user, ?string $cursor, int $limit): array
    {
        $limit = $this->historyLimit($limit);
        $query = $this->historyQuery($user->id);
        $this->applyHistoryCursor($query, $user->id, $this->decodeHistoryCursor($cursor));
        $rows = $query
            ->orderByDesc('request.created_at')
            ->orderByDesc('request.id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit);
        $items = $visible->map(fn (object $row): array => [
            'id' => $row->public_id,
            'gacha' => [
                'id' => $row->gacha_public_id,
                'title' => $row->gacha_title,
                'presentation_asset' => $this->asset($row),
            ],
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)
                ->utc()->startOfSecond()->toIso8601ZuluString(),
            'requested_count' => (int) $row->requested_count,
            'executed_count' => (int) $row->executed_count,
            'status' => [
                'code' => $row->status,
                'label' => '完了',
            ],
        ])->values()->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $visible->isNotEmpty()
                ? $this->encodeHistoryCursor((string) $visible->last()->public_id)
                : null,
        ];
    }

    private function historyQuery(int $userId): Builder
    {
        return DB::table('draw_requests as request')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'version.gacha_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                function (JoinClause $join): void {
                    $join->on('asset.id', '=', 'version.presentation_asset_id')
                        ->where('asset.is_public', true);
                }
            )
            ->where('request.user_id', $userId)
            ->where('request.status', 'completed')
            ->select([
                'request.id',
                'request.public_id',
                'request.created_at as occurred_at',
                'request.requested_count',
                'request.executed_count',
                'request.status',
                'gacha.public_id as gacha_public_id',
                'version.title as gacha_title',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.media_type as asset_media_type',
                'asset.mime_type as asset_mime_type',
                'asset.alt_text as asset_alt_text',
            ]);
    }

    private function applyHistoryCursor(Builder $query, int $userId, ?string $publicId): void
    {
        if ($publicId === null) {
            return;
        }
        $row = DB::table('draw_requests')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->where('public_id', $publicId)
            ->first(['id', 'created_at']);
        if ($row === null) {
            throw $this->invalidHistoryCursor();
        }
        $query->where(function (Builder $page) use ($row): void {
            $page->where('request.created_at', '<', $row->created_at)
                ->orWhere(function (Builder $sameTime) use ($row): void {
                    $sameTime->where('request.created_at', '=', $row->created_at)
                        ->where('request.id', '<', $row->id);
                });
        });
    }

    private function historyLimit(int $limit): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new V2DrawException(
                'INVALID_PAGINATION',
                422,
                'The pagination input is invalid.'
            );
        }

        return $limit;
    }

    private function encodeHistoryCursor(string $publicId): string
    {
        return rtrim(strtr(base64_encode($publicId), '+/', '-_'), '=');
    }

    private function decodeHistoryCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $cursor)) {
            throw $this->invalidHistoryCursor();
        }
        $decoded = base64_decode(
            strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4),
            true
        );
        if (! is_string($decoded) || ! Str::isUuid($decoded)) {
            throw $this->invalidHistoryCursor();
        }

        return $decoded;
    }

    private function invalidHistoryCursor(): V2DrawException
    {
        return new V2DrawException('INVALID_CURSOR', 422, 'The cursor is invalid.');
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
            || ! in_array((string) $gacha->management_status, [
                'scheduled', 'published',
            ], true)
        ) {
            throw new V2DrawException(
                'GACHA_NOT_DRAWABLE',
                409,
                'The requested Gacha is outside its published Draw period.'
            );
        }
        $startsAt = CarbonImmutable::parse(
            (string) ($gacha->current_publish_start_at ?? $version->publish_start_at)
        );
        $endsAtValue = $gacha->current_publish_end_at
            ?? $version->publish_end_at;
        $endsAt = $endsAtValue === null
            ? null
            : CarbonImmutable::parse((string) $endsAtValue);
        if ($startsAt->greaterThan($now) || ($endsAt !== null && ! $endsAt->greaterThan($now))) {
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

    /** @param Collection<int, PrizeInventory> $inventories */
    private function remainingInventory(Collection $inventories): int
    {
        $remaining = 0;
        foreach ($inventories as $inventory) {
            $available = (int) $inventory->available_quantity;
            if ($available < 0 || $remaining > PHP_INT_MAX - $available) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_UNAVAILABLE',
                    409,
                    'Prize Inventory cannot be represented safely.'
                );
            }
            $remaining += $available;
        }

        return $remaining;
    }

    /** @return array{legacy_stage: object, prizes: array<int, array<string, mixed>>} */
    private function drawMetadata(
        int $probabilityVersionId,
        int $gachaVersionId
    ): array {
        $legacyStage = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->orderBy('min_draw_number')
            ->orderBy('id')
            ->first();
        $prizeRows = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join(
                'catalog_gacha_ranks as gacha_rank',
                'gacha_rank.id',
                '=',
                'relation.gacha_rank_id'
            )
            ->join(
                'catalog_rank_masters as rank_master',
                'rank_master.id',
                '=',
                'gacha_rank.rank_master_id'
            )
            ->join(
                'catalog_rank_master_revisions as rank_revision',
                'rank_revision.id',
                '=',
                'rank_master.current_revision_id'
            )
            ->join(
                'catalog_presentation_assets as result_asset',
                'result_asset.id',
                '=',
                'rank_revision.result_image_asset_id'
            )
            ->join(
                'catalog_gacha_rank_video_revisions as video_revision',
                'video_revision.id',
                '=',
                'gacha_rank.current_video_revision_id'
            )
            ->join(
                'catalog_presentation_assets as video_asset',
                'video_asset.id',
                '=',
                'video_revision.video_asset_id'
            )
            ->leftJoin(
                'catalog_presentation_assets as prize_asset',
                'prize_asset.id',
                '=',
                DB::raw(
                    'COALESCE(prize.presentation_asset_id, relation.presentation_asset_id)'
                )
            )
            ->where('relation.gacha_version_id', $gachaVersionId)
            ->select([
                'relation.id as relation_id',
                'relation.sort_order as relation_sort_order',
                'prize.public_id as prize_public_id',
                'prize.display_name as prize_name',
                'relation.exchange_points as prize_exchange_points',
                'rank_revision.id as rank_master_revision_id',
                'video_revision.id as gacha_rank_video_revision_id',
                'rank_master.public_id as rank_public_id',
                'rank_revision.rank_name',
                'rank_revision.display_order as rank_sort_order',
                'prize_asset.public_id as asset_public_id',
                'prize_asset.public_path as asset_path',
                'prize_asset.checksum_sha256 as asset_checksum',
                'prize_asset.media_type as asset_media_type',
                'prize_asset.mime_type as asset_mime_type',
                'prize_asset.alt_text as asset_alt_text',
                'result_asset.public_id as result_asset_public_id',
                'result_asset.public_path as result_asset_path',
                'result_asset.checksum_sha256 as result_asset_checksum',
                'result_asset.media_type as result_asset_media_type',
                'result_asset.mime_type as result_asset_mime_type',
                'result_asset.alt_text as result_asset_alt_text',
                'video_asset.public_id as video_asset_public_id',
                'video_asset.public_path as video_asset_path',
                'video_asset.checksum_sha256 as video_asset_checksum',
                'video_asset.media_type as video_asset_media_type',
                'video_asset.mime_type as video_asset_mime_type',
                'video_asset.alt_text as video_asset_alt_text',
            ])
            ->orderBy('relation.id')
            ->get();
        $relationCount = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $gachaVersionId)
            ->count();
        if ($relationCount === 0 || $prizeRows->count() !== $relationCount) {
            throw new V2DrawException(
                'PRESENTATION_CONFIGURATION_INVALID',
                409,
                'Canonical Rank presentation is incomplete for this Gacha.'
            );
        }
        $prizes = [];
        foreach ($prizeRows as $row) {
            $prizes[(int) $row->relation_id] = [
                'relation_id' => (int) $row->relation_id,
                'relation_sort_order' => (int) $row->relation_sort_order,
                'prize_public_id' => $row->prize_public_id,
                'prize_name' => $row->prize_name,
                'exchange_points' => (int) $row->prize_exchange_points,
                'rank_master_revision_id' => (int) $row->rank_master_revision_id,
                'gacha_rank_video_revision_id' =>
                    (int) $row->gacha_rank_video_revision_id,
                'rank_public_id' => $row->rank_public_id,
                'rank_name' => $row->rank_name,
                'rank_sort_order' => (int) $row->rank_sort_order,
                'asset' => $this->asset($row),
                'animation_image' => $this->prefixedAsset($row, 'result_asset'),
                'animation_video' => $this->prefixedAsset($row, 'video_asset'),
            ];
        }
        if ($legacyStage === null) {
            throw new V2DrawException(
                'PROBABILITY_CONFIGURATION_INVALID',
                409,
                'Published Probability legacy metadata is incomplete.'
            );
        }

        return [
            'legacy_stage' => $legacyStage,
            'prizes' => $prizes,
        ];
    }

    /** @param Collection<int, PrizeInventory> $inventories */
    private function assertPresentationInventories(
        array $drawMetadata,
        Collection $inventories
    ): void {
        foreach (array_keys($drawMetadata['prizes']) as $relationId) {
            if (! $inventories->has($relationId)) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_UNAVAILABLE',
                    409,
                    'Prize Inventory is not initialized for this Gacha.'
                );
            }
        }
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   inventory_won: array<int, int>
     * }
     */
    private function selectOutcomes(
        GachaDrawState $state,
        array $drawMetadata,
        Collection $inventories,
        int $drawCount,
        int $pricePoints,
        CarbonImmutable $occurredAt,
        int $requestSequenceOffset = 0,
        ?array $inventoryWonSeed = null
    ): array {
        $rows = [];
        $inventoryWon = $inventoryWonSeed ?? $inventories->mapWithKeys(
            fn (PrizeInventory $inventory): array => [
                (int) $inventory->gacha_version_prize_id => (int) $inventory->awarded_count,
            ]
        )->all();
        $available = $this->availableInventory($inventories, $inventoryWon);
        $totalWeight = $this->totalInventoryWeight($available);
        for ($index = 0; $index < $drawCount; $index++) {
            $requestSequence = $requestSequenceOffset + $index + 1;
            $sequence = $state->sold_count + $requestSequence;
            if ($totalWeight < 1) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_INSUFFICIENT',
                    409,
                    'Selected Prize Inventory is insufficient.'
                );
            }
            $ticket = $this->random->integer(1, $totalWeight);
            $relationId = $this->pickInventory($available, $ticket);
            $available[$relationId]--;
            $totalWeight--;
            $inventoryWon[$relationId]++;
            $prize = $drawMetadata['prizes'][$relationId] ?? null;
            if (! is_array($prize)) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_UNAVAILABLE',
                    409,
                    'Selected Prize metadata is unavailable.'
                );
            }
            $rows[] = $this->prizeOutcomeRow(
                $requestSequence,
                $sequence,
                $drawMetadata['legacy_stage'],
                $this->legacyRandomValue($ticket),
                $relationId,
                $pricePoints,
                $occurredAt,
                $prize
            );
        }

        return ['rows' => $rows, 'inventory_won' => $inventoryWon];
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param array<int, int> $inventoryWon
     * @return array<int, int>
     */
    private function availableInventory(Collection $inventories, array $inventoryWon): array
    {
        $available = [];
        foreach ($inventories as $relationId => $inventory) {
            $awarded = (int) $inventory->awarded_count;
            $inventoryAvailable = (int) $inventory->available_quantity;
            $selected = $inventoryWon[(int) $relationId] ?? $awarded;
            $used = $selected - $awarded;
            if ($used < 0 || $used > $inventoryAvailable) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_INSUFFICIENT',
                    409,
                    'Selected Prize Inventory is insufficient.'
                );
            }
            $available[(int) $relationId] = $inventoryAvailable - $used;
        }

        return $available;
    }

    /** @param array<int, int> $available */
    private function totalInventoryWeight(array $available): int
    {
        $total = 0;
        foreach ($available as $weight) {
            if ($weight < 0 || $total > PHP_INT_MAX - $weight) {
                throw new V2DrawException(
                    'PRIZE_INVENTORY_UNAVAILABLE',
                    409,
                    'Prize Inventory cannot be represented safely.'
                );
            }
            $total += $weight;
        }

        return $total;
    }

    /** @param array<int, int> $available */
    private function pickInventory(array $available, int $ticket): int
    {
        $cursor = 0;
        foreach ($available as $relationId => $weight) {
            if ($weight === 0) {
                continue;
            }
            $cursor += $weight;
            if ($ticket <= $cursor) {
                return $relationId;
            }
        }

        throw new V2DrawException(
            'PRIZE_INVENTORY_INSUFFICIENT',
            409,
            'Weighted Prize selection exceeded available Inventory.'
        );
    }

    private function legacyRandomValue(int $ticket): int
    {
        return $ticket <= 1_000_000 ? $ticket - 1 : 0;
    }

    /**
     * @param array<string, mixed> $prize
     * @return array<string, mixed>
     */
    private function prizeOutcomeRow(
        int $requestSequence,
        int $drawSequence,
        object $legacyStage,
        int $legacyRandomValue,
        int $relationId,
        int $consumedPoints,
        CarbonImmutable $occurredAt,
        array $prize,
        ?int $qaDrawPlanItemId = null,
        ?int $qaGuaranteeAssignmentId = null
    ): array {
        $snapshot = [
            'result_type' => 'prize',
            'rank' => [
                'id' => $prize['rank_public_id'],
                'name' => $prize['rank_name'],
            ],
            'rank_name_snapshot' => $prize['rank_name'],
            'result_image_snapshot' => $prize['animation_image'],
            'video_snapshot' => $prize['animation_video'],
            'prize' => [
                'id' => $prize['prize_public_id'],
                'name' => $prize['prize_name'],
                'presentation_asset' => $prize['asset'],
            ],
        ];

        return $this->outcomeRow(
            (string) Str::uuid7(),
            $requestSequence,
            $drawSequence,
            $legacyStage,
            $legacyRandomValue,
            'prize',
            $relationId,
            null,
            $prize['rank_master_revision_id'],
            $prize['gacha_rank_video_revision_id'],
            0,
            $consumedPoints,
            $snapshot,
            $occurredAt,
            $prize,
            $qaDrawPlanItemId,
            $qaGuaranteeAssignmentId
        );
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param array<string, mixed> $selection
     * @return array{rows: list<array<string, mixed>>, inventory_won: array<int, int>}
     */
    private function selectGuaranteedOutcomes(
        GachaDrawState $state,
        array $drawMetadata,
        Collection $inventories,
        int $drawCount,
        int $pricePoints,
        CarbonImmutable $occurredAt,
        array $selection
    ): array {
        $selected = $selection['items'][0] ?? null;
        if (! is_array($selected)) {
            throw new V2DrawException(
                'QA_CONFIGURATION_INVALID',
                422,
                'The persistent QA guarantee selection is incomplete.'
            );
        }
        $relationId = (int) $selected['relation_id'];
        $inventory = $inventories->get($relationId);
        $prize = $drawMetadata['prizes'][$relationId] ?? null;
        if (! $inventory instanceof PrizeInventory || ! is_array($prize)) {
            throw new V2DrawException(
                'QA_CONFIGURATION_INVALID',
                422,
                'The persistent QA guarantee Prize is unavailable.'
            );
        }
        $inventoryWon = $inventories->mapWithKeys(
            fn (PrizeInventory $row): array => [
                (int) $row->gacha_version_prize_id => (int) $row->awarded_count,
            ]
        )->all();
        $awarded = (int) $inventory->awarded_count;
        $used = ($inventoryWon[$relationId] ?? $awarded) - $awarded;
        if ($used < 0 || $used >= (int) $inventory->available_quantity) {
            throw new V2DrawException(
                'QA_CONFIGURATION_INVALID',
                422,
                'The persistent QA guarantee Prize Inventory is insufficient.'
            );
        }
        $inventoryWon[$relationId] = $awarded + $used + 1;
        $guaranteed = $this->prizeOutcomeRow(
            1,
            $state->sold_count + 1,
            $drawMetadata['legacy_stage'],
            0,
            $relationId,
            $pricePoints,
            $occurredAt,
            $prize,
            null,
            (int) $selection['assignment']->id
        );
        $normal = $this->selectOutcomes(
            $state,
            $drawMetadata,
            $inventories,
            $drawCount - 1,
            $pricePoints,
            $occurredAt,
            1,
            $inventoryWon
        );

        return [
            'rows' => [$guaranteed, ...$normal['rows']],
            'inventory_won' => $normal['inventory_won'],
        ];
    }

    /**
     * @param Collection<int, PrizeInventory> $inventories
     * @param array<string, mixed> $selection
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   inventory_won: array<int, int>
     * }
     */
    private function selectQaOutcomes(
        GachaDrawState $state,
        array $drawMetadata,
        Collection $inventories,
        int $pricePoints,
        CarbonImmutable $occurredAt,
        array $selection
    ): array {
        $rows = [];
        $inventoryWon = $inventories->mapWithKeys(
            fn (PrizeInventory $inventory): array => [
                (int) $inventory->gacha_version_prize_id => (int) $inventory->awarded_count,
            ]
        )->all();
        foreach ($selection['items'] as $index => $selected) {
            $sequence = $state->sold_count + $index + 1;
            if (! is_array($selected)) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw selection is incomplete.'
                );
            }
            $relationId = (int) $selected['relation_id'];
            $inventory = $inventories->get($relationId);
            $prize = $drawMetadata['prizes'][$relationId] ?? null;
            if (! $inventory instanceof PrizeInventory || ! is_array($prize)) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw Prize is unavailable.'
                );
            }
            $awarded = (int) $inventory->awarded_count;
            $used = ($inventoryWon[$relationId] ?? $awarded) - $awarded;
            if ($used < 0 || $used >= (int) $inventory->available_quantity) {
                throw new V2DrawException(
                    'QA_CONFIGURATION_INVALID',
                    422,
                    'The QA Draw Prize Inventory is insufficient.'
                );
            }
            $inventoryWon[$relationId] = $awarded + $used + 1;
            $qaPrize = [
                ...$prize,
                'animation_image' => $selected['fixed_image'] ?? $prize['animation_image'],
                'animation_video' => $selected['fixed_video'] ?? $prize['animation_video'],
            ];
            $rows[] = $this->prizeOutcomeRow(
                $index + 1,
                $sequence,
                $drawMetadata['legacy_stage'],
                0,
                $relationId,
                $pricePoints,
                $occurredAt,
                $qaPrize,
                (int) $selected['item_id']
            );
        }

        return ['rows' => $rows, 'inventory_won' => $inventoryWon];
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
        ?int $rankMasterRevisionId,
        ?int $gachaRankVideoRevisionId,
        int $pointBackAmount,
        int $consumedPoints,
        array $snapshot,
        CarbonImmutable $occurredAt,
        ?array $prize,
        ?int $qaDrawPlanItemId = null,
        ?int $qaGuaranteeAssignmentId = null
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
            'rank_master_revision_id' => $rankMasterRevisionId,
            'gacha_rank_video_revision_id' => $gachaRankVideoRevisionId,
            'consumed_points' => $consumedPoints,
            'point_back_amount' => $pointBackAmount,
            'random_value' => $randomValue,
            'display_snapshot' => $snapshot,
            'display_snapshot_json' => $encoded,
            'display_snapshot_sha256' => hash('sha256', $encoded),
            'occurred_at' => $occurredAt,
            'prize' => $prize,
            'qa_draw_plan_item_id' => $qaDrawPlanItemId,
            'qa_gacha_guarantee_assignment_id' => $qaGuaranteeAssignmentId,
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
            $awarded = $inventoryWon[(int) $relationId]
                ?? (int) $inventory->awarded_count;
            if ($awarded !== (int) $inventory->awarded_count) {
                $delta = $awarded - (int) $inventory->awarded_count;
                if ($delta < 0 || $delta > (int) $inventory->available_quantity) {
                    throw new V2DrawException(
                        'PRIZE_INVENTORY_INSUFFICIENT',
                        409,
                        'Selected Prize Inventory is insufficient.'
                    );
                }
                $changed[(int) $inventory->id] = [
                    'awarded' => $awarded,
                    'available' => (int) $inventory->available_quantity - $delta,
                ];
            }
        }
        foreach (array_chunk($changed, 250, true) as $chunk) {
            $cases = [];
            $awardedBindings = [];
            $availableCases = [];
            $availableBindings = [];
            foreach ($chunk as $id => $quantities) {
                $cases[] = 'WHEN ?::bigint THEN ?::bigint';
                $awardedBindings[] = $id;
                $awardedBindings[] = $quantities['awarded'];
                $availableCases[] = 'WHEN ?::bigint THEN ?::bigint';
                $availableBindings[] = $id;
                $availableBindings[] = $quantities['available'];
            }
            $ids = array_keys($chunk);
            $bindings = [...$awardedBindings, ...$availableBindings];
            $bindings[] = $occurredAt;
            array_push($bindings, ...$ids);
            DB::update(
                'UPDATE prize_inventories SET awarded_count = CASE id '.
                implode(' ', $cases).
                ' END, available_quantity = CASE id '.
                implode(' ', $availableCases).
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
                'rank_master_revision_id' => $row['rank_master_revision_id'],
                'gacha_rank_video_revision_id' => $row['gacha_rank_video_revision_id'],
                'consumed_points' => $row['consumed_points'],
                'point_back_amount' => $row['point_back_amount'],
                'random_value' => $row['random_value'],
                'display_snapshot' => $row['display_snapshot_json'],
                'display_snapshot_sha256' => $row['display_snapshot_sha256'],
                'occurred_at' => $row['occurred_at'],
                'created_at' => $occurredAt,
                'is_qa_draw' => $row['qa_draw_plan_item_id'] !== null
                    || $row['qa_gacha_guarantee_assignment_id'] !== null,
                'qa_draw_plan_item_id' => $row['qa_draw_plan_item_id'],
                'qa_gacha_guarantee_assignment_id' =>
                    $row['qa_gacha_guarantee_assignment_id'],
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
                'rank_name_snapshot' =>
                    $row['display_snapshot']['rank_name_snapshot'] ?? null,
                'result_image_snapshot' =>
                    $row['display_snapshot']['result_image_snapshot'] ?? null,
                'video_snapshot' => $row['display_snapshot']['video_snapshot'] ?? null,
                'prize' => $row['display_snapshot']['prize'] ?? null,
                'point_back' => $row['display_snapshot']['point_back'] ?? null,
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
            'requested_count' => (int) $request->requested_count,
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
                'executed_count' => count($outcomes['rows']),
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

        foreach (['rank_counts', 'prize_counts'] as $collection) {
            if (! is_array($response[$collection] ?? null)) {
                continue;
            }
            $response[$collection] = array_map(function (mixed $item): mixed {
                if (is_array($item) && is_array($item['rank'] ?? null)) {
                    unset($item['rank']['code']);
                }

                return $item;
            }, $response[$collection]);
        }
        foreach (['high_rank_results', 'results'] as $collection) {
            if (! is_array($response[$collection] ?? null)) {
                continue;
            }
            $response[$collection] = array_map(
                function (mixed $item): mixed {
                    if (! is_array($item)) {
                        return $item;
                    }
                    $rank = is_array($item['rank'] ?? null) ? $item['rank'] : null;
                    if ($rank !== null) {
                        unset($rank['code']);
                    }
                    $animation = is_array($item['animation'] ?? null)
                        ? $item['animation']
                        : [];
                    $item['rank'] = $rank;
                    $item['rank_name_snapshot'] ??= $rank['name'] ?? null;
                    $item['result_image_snapshot'] ??= $animation['image'] ?? null;
                    $item['video_snapshot'] ??= $animation['video'] ?? null;
                    unset($item['animation']);

                    return $item;
                },
                $response[$collection]
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

    private function prefixedAsset(object $row, string $prefix): array
    {
        $publicId = $row->{$prefix.'_public_id'};
        if ($publicId === null) {
            throw new V2DrawException(
                'PRESENTATION_CONFIGURATION_INVALID',
                409,
                'Canonical Draw presentation Asset is unavailable.'
            );
        }

        return [
            'id' => $publicId,
            'path' => $row->{$prefix.'_path'},
            'checksum_sha256' => $row->{$prefix.'_checksum'},
            'media_type' => $row->{$prefix.'_media_type'},
            'mime_type' => $row->{$prefix.'_mime_type'},
            'alt_text' => $row->{$prefix.'_alt_text'},
        ];
    }
}
