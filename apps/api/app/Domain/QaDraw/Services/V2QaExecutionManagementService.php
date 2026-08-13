<?php

namespace App\Domain\QaDraw\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\ValueObjects\V2AdminQaDrawCommand;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2QaExecutionManagementService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $freshMfa,
        private readonly V2QaPlanManagementService $plans,
        private readonly V2DrawService $draws,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function preflight(
        V2AdminAuthorizationContext $context,
        string $planId,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $request = $this->input($planId, $input);
        $planPreflight = $this->plans->preflight($context, $planId);
        $codes = $planPreflight['validation_codes'];
        $assignment = $this->assignment($request, false);
        if ($assignment === null) {
            $codes[] = 'ASSIGNMENT_UNAVAILABLE';
        }
        if ($request['draw_count'] > (int) $planPreflight['remaining_draw_count']) {
            $codes[] = 'PLAN_DRAW_COUNT_INSUFFICIENT';
        }

        $impact = $this->impact($request, $assignment);
        foreach ($impact['validation_codes'] as $code) {
            $codes[] = $code;
        }
        $codes = array_values(array_unique($codes));

        return [
            'plan_id' => $planId,
            'assignment_id' => $request['assignment_id'],
            'user_id' => $assignment?->user_public_id,
            'gacha_id' => $assignment?->gacha_public_id,
            'draw_count' => $request['draw_count'],
            'plan_revision' => $request['plan_revision'],
            'assignment_revision' => $request['assignment_revision'],
            'valid' => $codes === [],
            'validation_codes' => $codes,
            'required_points' => $impact['required_points'],
            'available_points' => $impact['available_points'],
            'remaining_sales_count' => $impact['remaining_sales_count'],
            'remaining_plan_count' => (int) $planPreflight['remaining_draw_count'],
            'gacha_version_id' => $planPreflight['gacha_version_id'],
            'probability_version_id' => $planPreflight['probability_version_id'],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $idempotencyKey,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context, true);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new V2QaDrawException(
                'IDEMPOTENCY_KEY_REQUIRED',
                422,
                'A valid Idempotency-Key is required.'
            );
        }
        $request = $this->input($planId, $input);
        $assignment = $this->assignment($request, false);
        if ($assignment === null) {
            throw $this->invalid('The QA Plan assignment is unavailable.');
        }
        $user = User::query()->whereKey($assignment->user_internal_id)->first();
        if (! $user instanceof User) {
            throw $this->invalid('The QA Test User is unavailable.');
        }
        $command = new V2AdminQaDrawCommand(
            $context,
            $planId,
            $request['plan_revision'],
            $request['assignment_id'],
            $request['assignment_revision']
        );
        $derivedKey = 'admin-qa-'.hash(
            'sha256',
            $context->adminPublicId."\0".$idempotencyKey
        );

        try {
            $draw = $this->draws->create(
                $user,
                $assignment->gacha_public_id,
                $request['draw_count'],
                $derivedKey,
                $context->requestId,
                $command
            );
        } catch (V2DrawException $exception) {
            $this->auditFailure($context, $planId, $exception->errorCode);
            throw new V2QaDrawException(
                $exception->errorCode,
                $exception->status,
                $exception->getMessage(),
                $exception->retryable
            );
        }

        $executionId = DB::table('qa_draw_executions as execution')
            ->join('draw_requests as request', 'request.id', '=', 'execution.draw_request_id')
            ->where('request.public_id', $draw['id'])
            ->value('execution.public_id');
        if (! is_string($executionId)) {
            throw new \RuntimeException('The QA Draw execution is unavailable.');
        }

        return [
            'data' => $this->executionResource($executionId),
            'idempotent_replay' => (bool) $draw['idempotent_replay'],
        ];
    }

    /** @return array<string, mixed> */
    public function executions(V2AdminAuthorizationContext $context, array $filters): array
    {
        $this->freshMfa->authorizeQa($context);
        $limit = min(max((int) ($filters['limit'] ?? 25), 1), 100);
        $query = $this->executionQuery()->orderByDesc('execution.id');
        if (is_string($filters['plan_id'] ?? null)) {
            $query->where('plan.public_id', $filters['plan_id']);
        }
        if (is_string($filters['user_id'] ?? null)) {
            $query->where('user.public_id', $filters['user_id']);
        }
        if (is_string($filters['gacha_id'] ?? null)) {
            $query->where('gacha.public_id', $filters['gacha_id']);
        }
        if (is_string($filters['draw_request_id'] ?? null)) {
            $query->where('request.public_id', $filters['draw_request_id']);
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('execution.executed_at', '>=', $this->instant($filters['from']));
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('execution.executed_at', '<=', $this->instant($filters['to']));
        }
        if (is_string($filters['cursor'] ?? null)) {
            $cursor = $this->decodeCursor($filters['cursor']);
            $query->where('execution.id', '<', $cursor);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (object $row): array => $this->summary($row))->all(),
            'next_cursor' => $hasMore && $rows->isNotEmpty()
                ? $this->encodeCursor((int) $rows->last()->internal_id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function execution(
        V2AdminAuthorizationContext $context,
        string $executionId
    ): array {
        $admin = $this->freshMfa->authorizeQa($context);
        $resource = $this->executionResource($executionId);
        $this->audit->record('qa.execution.read', [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'qa_draw_execution',
            'target_public_id' => $executionId,
        ]);

        return $resource;
    }

    /** @return array<string, mixed> */
    private function executionResource(string $executionId): array
    {
        if (! Str::isUuid($executionId)) {
            throw $this->notFound();
        }
        $row = $this->executionQuery()
            ->where('execution.public_id', $executionId)
            ->first();
        if ($row === null) {
            throw $this->notFound();
        }
        $response = json_decode($row->response_data, true, flags: JSON_THROW_ON_ERROR);

        return [
            ...$this->summary($row),
            'status' => $row->request_status,
            'requested_count' => (int) $row->requested_count,
            'point_cost_total' => (int) $row->point_cost_total,
            'consumed_paid_points' => (int) $row->consumed_paid_points,
            'consumed_free_points' => (int) $row->consumed_free_points,
            'point_back_total' => (int) $row->point_back_total,
            'sales_count_delta' => (int) $row->executed_count,
            'inventory_prize_delta_total' => array_sum(array_column(
                $response['prize_counts'] ?? [],
                'count'
            )),
            'rank_counts' => $response['rank_counts'] ?? [],
            'prize_counts' => $response['prize_counts'] ?? [],
            'probability_version' => $response['probability_version'] ?? null,
            'processing_duration_ms' => (int) $row->processing_duration_ms,
            'failure_reason' => null,
            'metadata' => json_decode(
                $row->metadata_redacted,
                true,
                flags: JSON_THROW_ON_ERROR
            ),
        ];
    }

    private function executionQuery()
    {
        return DB::table('qa_draw_executions as execution')
            ->leftJoin('qa_draw_plans as plan', 'plan.id', '=', 'execution.qa_draw_plan_id')
            ->leftJoin('qa_draw_plan_assignments as assignment', function ($join): void {
                $join->on('assignment.qa_draw_plan_id', '=', 'plan.id')
                    ->on('assignment.user_id', '=', 'execution.user_id');
            })
            ->leftJoin(
                'qa_gacha_guarantee_assignments as guarantee',
                'guarantee.id',
                '=',
                'execution.qa_gacha_guarantee_assignment_id'
            )
            ->join('users as user', 'user.id', '=', 'execution.user_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'execution.gacha_id')
            ->join('draw_requests as request', 'request.id', '=', 'execution.draw_request_id')
            ->join(
                'catalog_gacha_versions as version',
                'version.id',
                '=',
                'request.gacha_version_id'
            )
            ->select([
                'execution.id as internal_id',
                'execution.public_id',
                'execution.executed_count',
                'execution.executed_at',
                'execution.metadata_redacted',
                'plan.public_id as plan_public_id',
                'assignment.public_id as assignment_public_id',
                'guarantee.public_id as guarantee_assignment_public_id',
                'user.public_id as user_public_id',
                'gacha.public_id as gacha_public_id',
                'version.public_id as gacha_version_public_id',
                'request.public_id as draw_request_public_id',
                'request.status as request_status',
                'request.requested_count',
                'request.point_cost_total',
                'request.consumed_paid_points',
                'request.consumed_free_points',
                'request.point_back_total',
                'request.processing_duration_ms',
                'request.response_data',
            ]);
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => $row->public_id,
            'plan_id' => $row->plan_public_id,
            'assignment_id' => $row->assignment_public_id,
            'guarantee_assignment_id' => $row->guarantee_assignment_public_id,
            'user_id' => $row->user_public_id,
            'gacha_id' => $row->gacha_public_id,
            'gacha_version_id' => $row->gacha_version_public_id,
            'draw_request_id' => $row->draw_request_public_id,
            'executed_count' => (int) $row->executed_count,
            'status' => $row->request_status,
            'executed_at' => CarbonImmutable::parse($row->executed_at)
                ->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function input(string $planId, array $input): array
    {
        if (! Str::isUuid($planId) || array_diff(array_keys($input), [
            'assignment_id',
            'plan_revision',
            'assignment_revision',
            'draw_count',
        ]) !== []) {
            throw $this->invalid('The QA Draw execution input is invalid.');
        }
        $assignmentId = $input['assignment_id'] ?? null;
        $planRevision = filter_var($input['plan_revision'] ?? null, FILTER_VALIDATE_INT);
        $assignmentRevision = filter_var(
            $input['assignment_revision'] ?? null,
            FILTER_VALIDATE_INT
        );
        $drawCount = filter_var($input['draw_count'] ?? null, FILTER_VALIDATE_INT);
        if (
            ! is_string($assignmentId)
            || ! Str::isUuid($assignmentId)
            || $planRevision === false
            || $planRevision < 1
            || $assignmentRevision === false
            || $assignmentRevision < 1
            || $drawCount === false
            || ! in_array($drawCount, config('v2_draw.allowed_counts', []), true)
        ) {
            throw $this->invalid('The QA Draw execution input is invalid.');
        }

        return [
            'plan_id' => $planId,
            'assignment_id' => $assignmentId,
            'plan_revision' => $planRevision,
            'assignment_revision' => $assignmentRevision,
            'draw_count' => $drawCount,
        ];
    }

    private function assignment(array $request, bool $lock): ?object
    {
        $query = DB::table('qa_draw_plan_assignments as assignment')
            ->join('qa_draw_plans as plan', 'plan.id', '=', 'assignment.qa_draw_plan_id')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'plan.gacha_id')
            ->where('assignment.public_id', $request['assignment_id'])
            ->where('assignment.revision', $request['assignment_revision'])
            ->where('assignment.status', 'assigned')
            ->where('plan.public_id', $request['plan_id'])
            ->where('plan.revision', $request['plan_revision']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'assignment.id as assignment_internal_id',
            'assignment.public_id as assignment_public_id',
            'user.id as user_internal_id',
            'user.public_id as user_public_id',
            'gacha.id as gacha_internal_id',
            'gacha.public_id as gacha_public_id',
            'gacha.active_draw_state_id',
            'plan.id as plan_internal_id',
        ]);
    }

    /** @return array<string, mixed> */
    private function impact(array $request, ?object $assignment): array
    {
        if ($assignment === null) {
            return [
                'required_points' => 0,
                'available_points' => 0,
                'remaining_sales_count' => 0,
                'validation_codes' => [],
            ];
        }
        $state = DB::table('gacha_draw_states')
            ->where('id', $assignment->active_draw_state_id)->first();
        $version = $state === null ? null : DB::table('catalog_gacha_versions')
            ->where('id', $state->gacha_version_id)->first();
        $wallet = DB::table('wallets')->where('user_id', $assignment->user_internal_id)
            ->first();
        $required = $version === null
            ? 0
            : (int) $version->price_points * $request['draw_count'];
        $available = $wallet === null
            ? 0
            : (int) $wallet->paid_balance + (int) $wallet->free_balance;
        $remaining = $state === null
            ? 0
            : (int) $state->total_count - (int) $state->sold_count;
        $codes = [];
        if ($required > $available) {
            $codes[] = 'POINT_BALANCE_INSUFFICIENT';
        }
        if ($request['draw_count'] > $remaining) {
            $codes[] = 'GACHA_DRAW_COUNT_INSUFFICIENT';
        }
        if (! $this->hasPrizeInventory($assignment, $request['draw_count'])) {
            $codes[] = 'PRIZE_INVENTORY_INSUFFICIENT';
        }

        return [
            'required_points' => $required,
            'available_points' => $available,
            'remaining_sales_count' => $remaining,
            'validation_codes' => $codes,
        ];
    }

    private function hasPrizeInventory(object $assignment, int $drawCount): bool
    {
        $items = DB::table('qa_draw_plan_items')
            ->where('qa_draw_plan_id', $assignment->plan_internal_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'gacha_version_prize_id',
                'quantity',
                'consumed_count',
            ]);
        $required = [];
        $selected = 0;
        foreach ($items as $item) {
            $quantity = min(
                max((int) $item->quantity - (int) $item->consumed_count, 0),
                $drawCount - $selected
            );
            if ($quantity === 0) {
                continue;
            }
            $relationId = (int) $item->gacha_version_prize_id;
            $required[$relationId] = ($required[$relationId] ?? 0) + $quantity;
            $selected += $quantity;
            if ($selected === $drawCount) {
                break;
            }
        }
        if ($selected !== $drawCount) {
            return false;
        }

        $inventories = DB::table('prize_inventories')
            ->whereIn('gacha_version_prize_id', array_keys($required))
            ->get()
            ->keyBy('gacha_version_prize_id');
        foreach ($required as $relationId => $quantity) {
            $inventory = $inventories->get($relationId);
            if (
                $inventory === null
                || (int) $inventory->won_count + $quantity > (int) $inventory->initial_quantity
            ) {
                return false;
            }
        }

        return true;
    }

    private function auditFailure(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $reason
    ): void {
        $this->audit->record('qa.execution.admin_failed', [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'qa_draw_plan',
            'target_public_id' => $planId,
            'outcome' => 'failure',
            'reason_code' => strtolower($reason),
        ]);
    }

    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || ! ctype_digit($decoded) || (int) $decoded < 1) {
            throw $this->invalid('The QA Draw execution cursor is invalid.');
        }

        return (int) $decoded;
    }

    private function instant(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw $this->invalid('The QA Draw execution time filter is invalid.');
        }
    }

    private function invalid(string $message): V2QaDrawException
    {
        return new V2QaDrawException('QA_CONFIGURATION_INVALID', 422, $message);
    }

    private function notFound(): V2QaDrawException
    {
        return new V2QaDrawException(
            'QA_EXECUTION_NOT_FOUND',
            404,
            'The QA Draw Execution was not found.'
        );
    }
}
