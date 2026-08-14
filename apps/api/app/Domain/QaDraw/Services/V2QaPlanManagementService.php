<?php

namespace App\Domain\QaDraw\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Models\V2\Admin;
use App\Models\V2\QaDrawPlan;
use App\Models\V2\QaDrawPlanAssignment;
use App\Models\V2\QaGachaGuaranteeAssignment;
use App\Models\V2\QaTestUserMode;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2QaPlanManagementService
{
    public function __construct(
        private readonly V2QaDrawAdminService $qa,
        private readonly V2AdminFreshMfaAuthorizer $freshMfa,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /** @return array<string, mixed> */
    public function plans(V2AdminAuthorizationContext $context, array $filters): array
    {
        $this->freshMfa->authorizeQa($context);
        $limit = $this->limit($filters['limit'] ?? null);
        $query = DB::table('qa_draw_plans as plan')
            ->join('users as owner', 'owner.id', '=', 'plan.user_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'plan.gacha_id')
            ->select([
                'plan.id as internal_id',
                'plan.public_id',
                'plan.code',
                'plan.revision',
                'plan.status',
                'plan.title',
                'plan.starts_at',
                'plan.ends_at',
                'plan.archived_at',
                'owner.public_id as owner_public_id',
                'gacha.public_id as gacha_public_id',
            ])
            ->orderByDesc('plan.id');
        if (is_string($filters['status'] ?? null) && $filters['status'] !== 'all') {
            $query->where('plan.status', $filters['status']);
        }
        if (is_string($filters['q'] ?? null) && trim($filters['q']) !== '') {
            $term = trim($filters['q']);
            $query->where(function ($scope) use ($term): void {
                $scope->where('plan.code', 'ILIKE', '%'.$this->escapeLike($term).'%')
                    ->orWhere('plan.title', 'ILIKE', '%'.$this->escapeLike($term).'%');
            });
        }
        if (is_string($filters['cursor'] ?? null)) {
            $query->where('plan.id', '<', $this->cursor($filters['cursor']));
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (object $row): array => $this->planSummary($row))->all(),
            'next_cursor' => $hasMore && $rows->isNotEmpty()
                ? $this->encodeCursor((int) $rows->last()->internal_id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function plan(V2AdminAuthorizationContext $context, string $planId): array
    {
        $detail = $this->qa->plan($context, $planId);
        $plan = $this->planRow($planId);

        return [
            ...$detail,
            'assignments' => $this->assignments((int) $plan->id),
            'execution_count' => (int) DB::table('qa_draw_executions')
                ->where('qa_draw_plan_id', $plan->id)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function testUsers(V2AdminAuthorizationContext $context, array $filters): array
    {
        $this->freshMfa->authorizeQa($context);
        $limit = $this->limit($filters['limit'] ?? null);
        $query = DB::table('users as user')
            ->leftJoin('qa_test_user_modes as mode', 'mode.user_id', '=', 'user.id')
            ->select([
                'user.id as internal_id',
                'user.public_id',
                'user.state',
                'mode.public_id as mode_public_id',
                'mode.is_enabled',
                'mode.reason',
                'mode.starts_at',
                'mode.ends_at',
                'mode.disabled_at',
                'mode.revision',
                'mode.updated_at',
            ])
            ->whereNotNull('mode.id')
            ->orderByDesc('user.id');
        if (is_string($filters['cursor'] ?? null)) {
            $query->where('user.id', '<', $this->cursor($filters['cursor']));
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (object $row): array => $this->testUserResource($row))->all(),
            'next_cursor' => $hasMore && $rows->isNotEmpty()
                ? $this->encodeCursor((int) $rows->last()->internal_id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function candidates(V2AdminAuthorizationContext $context, array $filters): array
    {
        $this->freshMfa->authorizeQa($context);
        $limit = $this->limit($filters['limit'] ?? null);
        $query = DB::table('users as user')
            ->leftJoin('qa_test_user_modes as mode', 'mode.user_id', '=', 'user.id')
            ->select([
                'user.id as internal_id',
                'user.public_id',
                'user.state',
                'mode.public_id as mode_public_id',
                'mode.is_enabled',
                'mode.reason',
                'mode.starts_at',
                'mode.ends_at',
                'mode.disabled_at',
                'mode.revision',
                'mode.updated_at',
            ])
            ->where('user.state', 'active')
            ->orderByDesc('user.id');
        $queryValue = $filters['q'] ?? null;
        if (is_string($queryValue) && $queryValue !== '') {
            if (! Str::isUuid($queryValue)) {
                return ['items' => [], 'next_cursor' => null];
            }
            $query->where('user.public_id', $queryValue);
        }
        if (is_string($filters['cursor'] ?? null)) {
            $query->where('user.id', '<', $this->cursor($filters['cursor']));
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (object $row): array => $this->testUserResource($row))->all(),
            'next_cursor' => $hasMore && $rows->isNotEmpty()
                ? $this->encodeCursor((int) $rows->last()->internal_id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function createPlan(
        V2AdminAuthorizationContext $context,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, [
            'user_id',
            'gacha_id',
            'title',
            'reason',
            'starts_at',
            'ends_at',
            'items',
        ]);

        return $this->mutate($context, $key, 'plan.create', $input, function () use (
            $context,
            $input
        ): array {
            $created = $this->qa->createPlan(
                $context,
                $this->requiredString($input, 'user_id'),
                $this->requiredString($input, 'gacha_id'),
                $this->requiredString($input, 'title'),
                $this->requiredString($input, 'reason'),
                $this->nullableString($input, 'starts_at'),
                $this->nullableString($input, 'ends_at'),
                is_array($input['items'] ?? null) ? $input['items'] : []
            );

            return $this->plan($context, $created['id']);
        });
    }

    /** @return array<string, mixed> */
    public function updatePlan(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, [
            'revision',
            'title',
            'reason',
            'starts_at',
            'ends_at',
        ]);

        return $this->mutate(
            $context,
            $key,
            'plan.update',
            ['plan_id' => $planId, ...$input],
            function () use ($context, $planId, $input): array {
                $plan = $this->planRow($planId, true);
                $this->assertRevision($plan, $input);
                if (DB::table('qa_draw_executions')->where('qa_draw_plan_id', $plan->id)->exists()) {
                    throw $this->invalid('Executed QA Plans cannot be changed.');
                }

                $updated = $this->qa->updatePlan(
                    $context,
                    $planId,
                    $this->requiredString($input, 'title'),
                    $this->requiredString($input, 'reason'),
                    $this->nullableString($input, 'starts_at'),
                    $this->nullableString($input, 'ends_at')
                );

                return $this->plan($context, $updated['id']);
            }
        );
    }

    /** @return array<string, mixed> */
    public function transitionPlan(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $key,
        array $input,
        string $action
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision']);
        if (! in_array($action, ['enable', 'disable'], true)) {
            throw $this->invalid('QA Plan transition is invalid.');
        }

        return $this->mutate(
            $context,
            $key,
            'plan.'.$action,
            ['plan_id' => $planId, ...$input],
            function () use ($context, $planId, $input, $action): array {
                $plan = $this->planRow($planId, true);
                $this->assertRevision($plan, $input);
                if ($action === 'enable') {
                    $this->assertPlanHasNoPersistentGuarantee((int) $plan->id);
                }

                $updated = $action === 'enable'
                    ? $this->qa->activatePlan($context, $planId)
                    : $this->qa->pausePlan($context, $planId);

                return $this->plan($context, $updated['id']);
            }
        );
    }

    /** @return array<string, mixed> */
    public function archivePlan(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision']);

        return $this->mutate(
            $context,
            $key,
            'plan.archive',
            ['plan_id' => $planId, ...$input],
            function () use ($context, $planId, $input): array {
                $admin = $this->freshMfa->authorizeQa($context, true);
                $plan = $this->planRow($planId, true);
                $this->assertRevision($plan, $input);
                if ($plan->archived_at !== null) {
                    throw $this->invalid('Archived QA Plans cannot transition.');
                }
                if (DB::table('qa_draw_executions')->where('qa_draw_plan_id', $plan->id)->exists()) {
                    throw $this->invalid('Executed QA Plans cannot be archived.');
                }
                DB::table('qa_draw_plans')->where('id', $plan->id)->update([
                    'status' => 'disabled',
                    'archived_at' => now()->startOfSecond(),
                    'archived_by_admin_id' => $admin->id,
                    'updated_by_admin_id' => $admin->id,
                    'revision' => (int) $plan->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                $this->audit($context, $admin, 'qa.plan.archived', $plan->public_id);

                return $this->plan($context, $planId);
            }
        );
    }

    /** @return array<string, mixed> */
    public function saveTestUser(
        V2AdminAuthorizationContext $context,
        string $userId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision', 'reason', 'starts_at', 'ends_at']);

        return $this->mutate(
            $context,
            $key,
            'test_user.save',
            ['user_id' => $userId, ...$input],
            function () use ($context, $userId, $input): array {
                $current = DB::table('qa_test_user_modes as mode')
                    ->join('users as user', 'user.id', '=', 'mode.user_id')
                    ->where('user.public_id', $userId)
                    ->lockForUpdate()
                    ->first(['mode.revision']);
                if ($current !== null) {
                    $this->assertRevision($current, $input);
                } elseif (array_key_exists('revision', $input)) {
                    throw $this->conflict();
                }

                $this->qa->saveMode(
                    $context,
                    $userId,
                    $this->requiredString($input, 'reason')
                );

                return $this->testUserByPublicId($userId);
            }
        );
    }

    /** @return array<string, mixed> */
    public function disableTestUser(
        V2AdminAuthorizationContext $context,
        string $userId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision']);

        return $this->mutate(
            $context,
            $key,
            'test_user.disable',
            ['user_id' => $userId, ...$input],
            function () use ($context, $userId, $input): array {
                $row = DB::table('qa_test_user_modes as mode')
                    ->join('users as user', 'user.id', '=', 'mode.user_id')
                    ->where('user.public_id', $userId)
                    ->lockForUpdate()
                    ->first(['mode.revision']);
                if ($row === null) {
                    throw new V2QaDrawException(
                        'QA_MODE_NOT_FOUND',
                        404,
                        'The QA Test User Mode was not found.'
                    );
                }
                $this->assertRevision($row, $input);

                $this->qa->disableMode($context, $userId);

                return $this->testUserByPublicId($userId);
            }
        );
    }

    /** @return array<string, mixed> */
    public function gachaGuarantees(
        V2AdminAuthorizationContext $context,
        string $gachaId
    ): array {
        $this->freshMfa->authorizeQa($context);
        $gacha = $this->gachaRow($gachaId);

        return [
            'gacha_id' => $gacha->public_code ?? $gacha->public_id,
            'items' => $this->guaranteeAssignments((int) $gacha->id),
            'test_users' => $this->activeTestUserOptions(),
            'prizes' => $this->publishedPrizeOptions($gacha),
        ];
    }

    /** @return array<string, mixed> */
    public function saveGachaGuarantee(
        V2AdminAuthorizationContext $context,
        string $gachaId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision', 'user_id', 'prize_id']);

        return $this->mutate(
            $context,
            $key,
            'guarantee.save',
            ['gacha_id' => $gachaId, ...$input],
            function () use ($context, $gachaId, $input): array {
                $admin = $this->freshMfa->authorizeQa($context, true);
                $gacha = $this->gachaRow($gachaId, true);
                $userId = $this->requiredString($input, 'user_id');
                $prizeId = $this->requiredString($input, 'prize_id');
                $user = User::query()
                    ->where('public_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (! $user instanceof User || $user->state->value !== 'active') {
                    throw $this->invalid('Only active Users can receive a QA guarantee.');
                }
                $mode = QaTestUserMode::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if (! $mode instanceof QaTestUserMode || ! $this->modeActive($mode)) {
                    throw $this->invalid('QA guarantee requires an active Test User Mode.');
                }
                if ($this->activeLegacyPlanExists((int) $user->id, (int) $gacha->id)) {
                    throw new V2QaDrawException(
                        'QA_ACTIVE_PLAN_CONFLICT',
                        409,
                        'A legacy active QA Plan already exists for this User and Gacha.'
                    );
                }
                $prize = $this->publishedPrize($gacha, $prizeId);
                $assignment = QaGachaGuaranteeAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('gacha_id', $gacha->id)
                    ->lockForUpdate()
                    ->first();
                if ($assignment instanceof QaGachaGuaranteeAssignment) {
                    $this->assertRevision($assignment, $input);
                } elseif (array_key_exists('revision', $input)) {
                    throw $this->conflict();
                }
                $now = now()->startOfSecond();
                $assignment ??= new QaGachaGuaranteeAssignment();
                $assignment->forceFill([
                    'user_id' => $user->id,
                    'gacha_id' => $gacha->id,
                    'prize_id' => $prize->id,
                    'status' => 'assigned',
                    'revision' => $assignment->exists ? (int) $assignment->revision + 1 : 1,
                    'assigned_at' => $now,
                    'assigned_by_admin_id' => $admin->id,
                    'unassigned_at' => null,
                    'unassigned_by_admin_id' => null,
                ])->save();
                $this->audit(
                    $context,
                    $admin,
                    'qa.guarantee.saved',
                    $assignment->public_id,
                    [
                        'user_public_id' => $user->public_id,
                        'gacha_public_id' => $gacha->public_code ?? $gacha->public_id,
                        'prize_public_id' => $prizeId,
                    ]
                );

                return $this->guaranteeAssignment((int) $assignment->id);
            }
        );
    }

    /** @return array<string, mixed> */
    public function disableGachaGuarantee(
        V2AdminAuthorizationContext $context,
        string $gachaId,
        string $userId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision']);

        return $this->mutate(
            $context,
            $key,
            'guarantee.disable',
            ['gacha_id' => $gachaId, 'user_id' => $userId, ...$input],
            function () use ($context, $gachaId, $userId, $input): array {
                $admin = $this->freshMfa->authorizeQa($context, true);
                $gacha = $this->gachaRow($gachaId, true);
                $assignment = QaGachaGuaranteeAssignment::query()
                    ->join('users as user', 'user.id', '=', 'qa_gacha_guarantee_assignments.user_id')
                    ->where('qa_gacha_guarantee_assignments.gacha_id', $gacha->id)
                    ->where('user.public_id', $userId)
                    ->where('qa_gacha_guarantee_assignments.status', 'assigned')
                    ->lockForUpdate()
                    ->first(['qa_gacha_guarantee_assignments.*']);
                if (! $assignment instanceof QaGachaGuaranteeAssignment) {
                    throw new V2QaDrawException(
                        'QA_ASSIGNMENT_NOT_FOUND',
                        404,
                        'The QA Gacha guarantee assignment was not found.'
                    );
                }
                $this->assertRevision($assignment, $input);
                $assignment->forceFill([
                    'status' => 'unassigned',
                    'revision' => (int) $assignment->revision + 1,
                    'unassigned_at' => now()->startOfSecond(),
                    'unassigned_by_admin_id' => $admin->id,
                ])->save();
                $this->audit(
                    $context,
                    $admin,
                    'qa.guarantee.disabled',
                    $assignment->public_id,
                    [
                        'user_public_id' => $userId,
                        'gacha_public_id' => $gacha->public_code ?? $gacha->public_id,
                    ]
                );

                return $this->guaranteeAssignment((int) $assignment->id);
            }
        );
    }

    /** @return array<string, mixed> */
    public function assign(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision', 'user_id']);

        return $this->mutate(
            $context,
            $key,
            'assignment.assign',
            ['plan_id' => $planId, ...$input],
            function () use ($context, $planId, $input): array {
                $admin = $this->freshMfa->authorizeQa($context, true);
                $plan = $this->planRow($planId, true);
                $this->assertRevision($plan, $input);
                $userId = $this->requiredString($input, 'user_id');
                $user = User::query()->where('public_id', $userId)->lockForUpdate()->first();
                if (! $user instanceof User || $user->state->value !== 'active') {
                    throw $this->invalid('Only active Users can be assigned.');
                }
                if ($plan->archived_at !== null || in_array($plan->status, ['completed', 'disabled'], true)) {
                    throw $this->invalid('The QA Plan cannot accept assignments.');
                }
                $mode = QaTestUserMode::query()
                    ->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $mode instanceof QaTestUserMode || ! $this->modeActive($mode)) {
                    throw $this->invalid('Assignment requires an active QA Test User Mode.');
                }
                $this->assertNoPersistentGuarantee((int) $user->id, (int) $plan->gacha_id);
                $this->assertNoActivePlanConflict((int) $user->id, (int) $plan->gacha_id, (int) $plan->id);
                $assignment = QaDrawPlanAssignment::query()
                    ->where('qa_draw_plan_id', $plan->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if ($assignment instanceof QaDrawPlanAssignment && $assignment->status === 'assigned') {
                    throw new V2QaDrawException(
                        'QA_ASSIGNMENT_CONFLICT',
                        409,
                        'The User is already assigned to this QA Plan.'
                    );
                }
                $now = now()->startOfSecond();
                $assignment ??= new QaDrawPlanAssignment();
                $assignment->forceFill([
                    'qa_draw_plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'status' => 'assigned',
                    'revision' => $assignment->exists ? (int) $assignment->revision + 1 : 1,
                    'assigned_at' => $now,
                    'assigned_by_admin_id' => $admin->id,
                    'unassigned_at' => null,
                    'unassigned_by_admin_id' => null,
                ])->save();
                $this->advancePlanRevision($plan, $admin);
                $this->audit(
                    $context,
                    $admin,
                    'qa.plan.assignment.assigned',
                    $plan->public_id,
                    ['user_public_id' => $user->public_id]
                );

                return $this->plan($context, $planId);
            }
        );
    }

    /** @return array<string, mixed> */
    public function unassign(
        V2AdminAuthorizationContext $context,
        string $planId,
        string $key,
        array $input
    ): array {
        $this->freshMfa->authorizeQa($context);
        $this->assertKeys($input, ['revision', 'user_id']);

        return $this->mutate(
            $context,
            $key,
            'assignment.unassign',
            ['plan_id' => $planId, ...$input],
            function () use ($context, $planId, $input): array {
                $admin = $this->freshMfa->authorizeQa($context, true);
                $plan = $this->planRow($planId, true);
                $this->assertRevision($plan, $input);
                $userId = $this->requiredString($input, 'user_id');
                $assignment = QaDrawPlanAssignment::query()
                    ->join('users as user', 'user.id', '=', 'qa_draw_plan_assignments.user_id')
                    ->where('qa_draw_plan_assignments.qa_draw_plan_id', $plan->id)
                    ->where('user.public_id', $userId)
                    ->where('qa_draw_plan_assignments.status', 'assigned')
                    ->lockForUpdate()
                    ->first(['qa_draw_plan_assignments.*']);
                if (! $assignment instanceof QaDrawPlanAssignment) {
                    throw new V2QaDrawException(
                        'QA_ASSIGNMENT_NOT_FOUND',
                        404,
                        'The active QA Plan assignment was not found.'
                    );
                }
                if (DB::table('qa_draw_executions')
                    ->where('qa_draw_plan_id', $plan->id)
                    ->where('user_id', $assignment->user_id)->exists()) {
                    throw $this->invalid('Executed QA Plan assignments cannot be removed.');
                }
                $assignment->forceFill([
                    'status' => 'unassigned',
                    'revision' => (int) $assignment->revision + 1,
                    'unassigned_at' => now()->startOfSecond(),
                    'unassigned_by_admin_id' => $admin->id,
                ])->save();
                $this->advancePlanRevision($plan, $admin);
                $this->audit(
                    $context,
                    $admin,
                    'qa.plan.assignment.unassigned',
                    $plan->public_id,
                    ['user_public_id' => $userId]
                );

                return $this->plan($context, $planId);
            }
        );
    }

    /** @return array<string, mixed> */
    public function preflight(V2AdminAuthorizationContext $context, string $planId): array
    {
        $admin = $this->freshMfa->authorizeQa($context);
        $plan = $this->planRow($planId);
        $codes = [];
        if ($plan->archived_at !== null || $plan->status !== 'active') {
            $codes[] = 'PLAN_NOT_ACTIVE';
        }
        $gacha = DB::table('catalog_gachas')->where('id', $plan->gacha_id)->first();
        $version = $gacha === null || $gacha->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $gacha->published_version_id)->first();
        if ($gacha === null || $gacha->state !== 'active' || $version === null) {
            $codes[] = 'GACHA_VERSION_UNAVAILABLE';
        }
        $probability = $version?->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->where('gacha_version_id', $version->id)
                ->where('status', 'published')
                ->first();
        if (
            $probability === null
            || ! is_string($probability->snapshot_sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $probability->snapshot_sha256) !== 1
        ) {
            $codes[] = 'PROBABILITY_SNAPSHOT_UNAVAILABLE';
        } elseif (! $this->probabilityStagesAreComplete((int) $probability->id)) {
            $codes[] = 'PROBABILITY_SNAPSHOT_INVALID';
        }
        if (! $this->planPrizesBelongToVersion(
            (int) $plan->id,
            $version === null ? null : (int) $version->id
        )) {
            $codes[] = 'PLAN_PRIZE_RELATION_INVALID';
        }
        $itemCount = (int) DB::table('qa_draw_plan_items')
            ->where('qa_draw_plan_id', $plan->id)->count();
        $remaining = (int) DB::table('qa_draw_plan_items')
            ->where('qa_draw_plan_id', $plan->id)
            ->sum(DB::raw('quantity - consumed_count'));
        if ($itemCount < 1 || $remaining < 1) {
            $codes[] = 'PLAN_ITEMS_UNAVAILABLE';
        }
        $assignedCount = (int) DB::table('qa_draw_plan_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('qa_test_user_modes as mode', 'mode.user_id', '=', 'user.id')
            ->where('assignment.qa_draw_plan_id', $plan->id)
            ->where('assignment.status', 'assigned')
            ->where('user.state', 'active')
            ->where('mode.is_enabled', true)
            ->whereNull('mode.disabled_at')
            ->count();
        if ($assignedCount < 1) {
            $codes[] = 'TEST_USER_UNAVAILABLE';
        }
        $startsAt = $plan->starts_at === null
            ? null
            : CarbonImmutable::parse($plan->starts_at);
        $endsAt = $plan->ends_at === null
            ? null
            : CarbonImmutable::parse($plan->ends_at);
        if (
            ($startsAt !== null && $startsAt->isFuture())
            || ($endsAt !== null && ! $endsAt->isFuture())
        ) {
            $codes[] = 'PLAN_OUTSIDE_ACTIVE_PERIOD';
        }
        $this->audit($context, $admin, 'qa.plan.preflight', $plan->public_id, [
            'valid' => $codes === [],
            'validation_code_summary' => $codes === [] ? 'none' : implode(',', $codes),
        ]);

        return [
            'plan_id' => $plan->public_id,
            'revision' => (int) $plan->revision,
            'valid' => $codes === [],
            'validation_codes' => $codes,
            'assigned_test_user_count' => $assignedCount,
            'remaining_draw_count' => $remaining,
            'gacha_version_id' => $version?->public_id,
            'probability_version_id' => $probability?->public_id,
        ];
    }

    private function probabilityStagesAreComplete(int $probabilityVersionId): bool
    {
        $stages = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->pluck('id');
        if ($stages->isEmpty()) {
            return false;
        }

        foreach ($stages as $stageId) {
            $entryTotal = (int) DB::table('catalog_probability_entries')
                ->where('probability_stage_id', $stageId)
                ->sum('probability_ppm');
            $guaranteeTotal = (int) DB::table('catalog_minimum_guarantees')
                ->where('probability_stage_id', $stageId)
                ->sum('probability_ppm');
            if ($entryTotal + $guaranteeTotal !== 1000000) {
                return false;
            }
        }

        return true;
    }

    private function planPrizesBelongToVersion(int $planId, ?int $gachaVersionId): bool
    {
        if ($gachaVersionId === null) {
            return false;
        }

        return ! DB::table('qa_draw_plan_items as item')
            ->leftJoin('catalog_gacha_version_prizes as version_prize', function ($join) use (
                $gachaVersionId
            ): void {
                $join->on('version_prize.id', '=', 'item.gacha_version_prize_id')
                    ->where('version_prize.gacha_version_id', '=', $gachaVersionId);
            })
            ->leftJoin('catalog_prizes as prize', 'prize.id', '=', 'version_prize.prize_id')
            ->where('item.qa_draw_plan_id', $planId)
            ->where(function ($query): void {
                $query->whereNull('version_prize.id')
                    ->orWhere('version_prize.is_visible', false)
                    ->orWhereNotNull('prize.archived_at');
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    private function mutate(
        V2AdminAuthorizationContext $context,
        string $key,
        string $action,
        array $request,
        callable $callback
    ): array {
        if ($key === '' || strlen($key) > 255) {
            throw new V2QaDrawException(
                'IDEMPOTENCY_KEY_REQUIRED',
                422,
                'A valid Idempotency-Key is required.'
            );
        }
        try {
            return DB::transaction(function () use (
                $context,
                $key,
                $action,
                $request,
                $callback
            ): array {
                try {
                    $claim = $this->idempotency->claim(
                        'qa_plan_management',
                        'admin',
                        $context->adminPublicId,
                        $key,
                        ['action' => $action, ...$request]
                    );
                } catch (V2PointException $exception) {
                    throw $this->idempotencyError($exception);
                }
                if ($claim->replay) {
                    $admin = $this->freshMfa->authorizeQa($context);
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! is_array($response['data'] ?? null)) {
                        throw new \RuntimeException('QA management replay response is unavailable.');
                    }
                    $target = $response['data']['id']
                        ?? $response['data']['plan_id']
                        ?? $response['data']['user_id']
                        ?? null;
                    if (is_string($target) && Str::isUuid($target)) {
                        $this->audit(
                            $context,
                            $admin,
                            'qa.management.idempotent_replay',
                            $target,
                            ['action' => $action]
                        );
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }
                $data = $callback();
                $target = $data['id'] ?? $data['plan_id'] ?? $data['user_id'] ?? null;
                if (! is_string($target) || ! Str::isUuid($target)) {
                    throw new \RuntimeException('QA management target is unavailable.');
                }
                $targetType = str_starts_with($action, 'test_user.')
                    ? 'qa_test_user'
                    : (str_starts_with($action, 'guarantee.')
                        ? 'qa_gacha_guarantee_assignment'
                        : 'qa_draw_plan');
                $this->outbox->enqueue(
                    'qa.plan.change',
                    $targetType,
                    $target,
                    'qa.'.str_replace('_', '.', $action),
                    ['target_public_id' => $target, 'action' => $action],
                    'qa-management-'.$claim->record->public_id
                );
                $this->idempotency->complete(
                    $claim->record,
                    $targetType,
                    $target,
                    ['data' => $data]
                );

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23505', 'P0001'], true)) {
                throw new V2QaDrawException(
                    'QA_CONFLICT',
                    409,
                    'The QA management request conflicts with current state.'
                );
            }
            throw $exception;
        }
    }

    private function advancePlanRevision(object $plan, Admin $admin): void
    {
        DB::table('qa_draw_plans')->where('id', $plan->id)->update([
            'updated_by_admin_id' => $admin->id,
            'revision' => (int) $plan->revision + 1,
            'updated_at' => now()->startOfSecond(),
        ]);
    }

    private function assertNoActivePlanConflict(int $userId, int $gachaId, int $exceptPlanId): void
    {
        $conflict = DB::table('qa_draw_plan_assignments as assignment')
            ->join('qa_draw_plans as plan', 'plan.id', '=', 'assignment.qa_draw_plan_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.status', 'assigned')
            ->where('plan.gacha_id', $gachaId)
            ->where('plan.status', 'active')
            ->whereNull('plan.archived_at')
            ->where('plan.id', '!=', $exceptPlanId)
            ->lockForUpdate()
            ->exists();
        if ($conflict) {
            throw new V2QaDrawException(
                'QA_ACTIVE_PLAN_CONFLICT',
                409,
                'Only one active QA Plan is allowed for the User and Gacha.'
            );
        }
    }

    private function assertNoPersistentGuarantee(int $userId, int $gachaId): void
    {
        if (DB::table('qa_gacha_guarantee_assignments')
            ->where('user_id', $userId)
            ->where('gacha_id', $gachaId)
            ->where('status', 'assigned')
            ->lockForUpdate()
            ->exists()) {
            throw new V2QaDrawException(
                'QA_ACTIVE_PLAN_CONFLICT',
                409,
                'A persistent QA guarantee already exists for this User and Gacha.'
            );
        }
    }

    private function assertPlanHasNoPersistentGuarantee(int $planId): void
    {
        if (DB::table('qa_draw_plan_assignments as assignment')
            ->join('qa_draw_plans as plan', 'plan.id', '=', 'assignment.qa_draw_plan_id')
            ->join('qa_gacha_guarantee_assignments as guarantee', function ($join): void {
                $join->on('guarantee.user_id', '=', 'assignment.user_id')
                    ->on('guarantee.gacha_id', '=', 'plan.gacha_id');
            })
            ->where('assignment.qa_draw_plan_id', $planId)
            ->where('assignment.status', 'assigned')
            ->where('guarantee.status', 'assigned')
            ->lockForUpdate()
            ->exists()) {
            throw new V2QaDrawException(
                'QA_ACTIVE_PLAN_CONFLICT',
                409,
                'A persistent QA guarantee conflicts with this QA Plan.'
            );
        }
    }

    private function assertRevision(object $row, array $input): void
    {
        $revision = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 1 || (int) $row->revision !== $revision) {
            throw $this->conflict();
        }
    }

    /** @param list<string> $allowed */
    private function assertKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw $this->invalid('QA management input contains unknown fields.');
        }
    }

    private function conflict(): V2QaDrawException
    {
        return new V2QaDrawException(
            'QA_REVISION_CONFLICT',
            409,
            'The QA resource changed. Reload the canonical resource.'
        );
    }

    private function planRow(string $publicId, bool $lock = false): object
    {
        if (! Str::isUuid($publicId)) {
            throw new V2QaDrawException('QA_PLAN_NOT_FOUND', 404, 'The QA Draw Plan was not found.');
        }
        $query = DB::table('qa_draw_plans')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $plan = $query->first();
        if ($plan === null) {
            throw new V2QaDrawException('QA_PLAN_NOT_FOUND', 404, 'The QA Draw Plan was not found.');
        }

        return $plan;
    }

    /** @return list<array<string, mixed>> */
    private function assignments(int $planId): array
    {
        return DB::table('qa_draw_plan_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->where('assignment.qa_draw_plan_id', $planId)
            ->orderBy('assignment.id')
            ->get([
                'assignment.public_id',
                'assignment.status',
                'assignment.revision',
                'assignment.assigned_at',
                'assignment.unassigned_at',
                'user.public_id as user_public_id',
            ])
            ->map(fn (object $row): array => [
                'id' => $row->public_id,
                'user_id' => $row->user_public_id,
                'status' => $row->status,
                'revision' => (int) $row->revision,
                'assigned_at' => CarbonImmutable::parse($row->assigned_at)->utc()->toIso8601String(),
                'unassigned_at' => $row->unassigned_at === null
                    ? null
                    : CarbonImmutable::parse($row->unassigned_at)->utc()->toIso8601String(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function planSummary(object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'revision' => (int) $row->revision,
            'user_id' => $row->owner_public_id,
            'gacha_id' => $row->gacha_public_id,
            'status' => $row->status,
            'title' => $row->title,
            'starts_at' => $row->starts_at === null
                ? null
                : CarbonImmutable::parse($row->starts_at)->utc()->toIso8601String(),
            'ends_at' => $row->ends_at === null
                ? null
                : CarbonImmutable::parse($row->ends_at)->utc()->toIso8601String(),
            'archived_at' => $row->archived_at === null
                ? null
                : CarbonImmutable::parse($row->archived_at)->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function testUserResource(object $row): array
    {
        $ends = $row->ends_at === null ? null : CarbonImmutable::parse($row->ends_at);
        $starts = $row->starts_at === null ? null : CarbonImmutable::parse($row->starts_at);

        return [
            'user_id' => $row->public_id,
            'user_state' => $row->state,
            'mode_id' => $row->mode_public_id,
            'revision' => $row->revision === null ? null : (int) $row->revision,
            'is_enabled' => (bool) ($row->is_enabled ?? false),
            'is_active' => $row->mode_public_id !== null
                && (bool) $row->is_enabled
                && $row->disabled_at === null,
            'reason' => $row->reason,
            'starts_at' => $starts?->utc()->toIso8601String(),
            'ends_at' => $ends?->utc()->toIso8601String(),
            'updated_at' => $row->updated_at === null
                ? null
                : CarbonImmutable::parse($row->updated_at)->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function testUserByPublicId(string $userId): array
    {
        $row = DB::table('users as user')
            ->leftJoin('qa_test_user_modes as mode', 'mode.user_id', '=', 'user.id')
            ->where('user.public_id', $userId)
            ->first([
                'user.id as internal_id',
                'user.public_id',
                'user.state',
                'mode.public_id as mode_public_id',
                'mode.is_enabled',
                'mode.reason',
                'mode.starts_at',
                'mode.ends_at',
                'mode.disabled_at',
                'mode.revision',
                'mode.updated_at',
            ]);
        if ($row === null) {
            throw new V2QaDrawException('QA_USER_NOT_FOUND', 404, 'The User was not found.');
        }

        return $this->testUserResource($row);
    }

    private function modeActive(QaTestUserMode $mode): bool
    {
        return $mode->is_enabled
            && $mode->disabled_at === null;
    }

    private function gachaRow(string $identifier, bool $lock = false): object
    {
        $isCode = preg_match('/\A[A-Za-z0-9]{11}\z/', $identifier) === 1;
        if (! $isCode && ! Str::isUuid($identifier)) {
            throw new V2QaDrawException(
                'QA_GACHA_NOT_FOUND',
                404,
                'The Gacha was not found.'
            );
        }
        $query = DB::table('catalog_gachas')->where(
            $isCode ? 'public_code' : 'public_id',
            $identifier
        );
        if ($lock) {
            $query->lockForUpdate();
        }
        $gacha = $query->first();
        if ($gacha === null) {
            throw new V2QaDrawException(
                'QA_GACHA_NOT_FOUND',
                404,
                'The Gacha was not found.'
            );
        }

        return $gacha;
    }

    /** @return list<array<string, mixed>> */
    private function guaranteeAssignments(int $gachaId): array
    {
        return DB::table('qa_gacha_guarantee_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'assignment.prize_id')
            ->where('assignment.gacha_id', $gachaId)
            ->orderByDesc('assignment.status')
            ->orderBy('assignment.id')
            ->get([
                'assignment.id as internal_id',
                'assignment.public_id',
                'assignment.revision',
                'assignment.status',
                'assignment.assigned_at',
                'assignment.unassigned_at',
                'assignment.updated_at',
                'user.public_id as user_public_id',
                'user.display_name as user_display_name',
                'user.state as user_state',
                'prize.public_id as prize_public_id',
            ])
            ->map(fn (object $row): array => $this->guaranteeResource($row, $gachaId))
            ->all();
    }

    /** @return array<string, mixed> */
    private function guaranteeAssignment(int $assignmentId): array
    {
        $row = DB::table('qa_gacha_guarantee_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'assignment.prize_id')
            ->where('assignment.id', $assignmentId)
            ->first([
                'assignment.id as internal_id',
                'assignment.public_id',
                'assignment.gacha_id',
                'assignment.revision',
                'assignment.status',
                'assignment.assigned_at',
                'assignment.unassigned_at',
                'assignment.updated_at',
                'user.public_id as user_public_id',
                'user.display_name as user_display_name',
                'user.state as user_state',
                'prize.public_id as prize_public_id',
            ]);
        if ($row === null) {
            throw new \RuntimeException('QA Gacha guarantee assignment disappeared.');
        }

        return $this->guaranteeResource($row, (int) $row->gacha_id);
    }

    /** @return array<string, mixed> */
    private function guaranteeResource(object $row, int $gachaId): array
    {
        $gacha = DB::table('catalog_gachas')->where('id', $gachaId)->first([
            'published_version_id',
            'active_draw_state_id',
        ]);
        $relation = $gacha?->published_version_id === null
            ? null
            : DB::table('catalog_gacha_version_prizes as relation')
                ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
                ->where('relation.gacha_version_id', $gacha->published_version_id)
                ->where('prize.public_id', $row->prize_public_id)
                ->where('relation.is_visible', true)
                ->first([
                    'relation.id',
                    'relation.display_name',
                    'relation.rank_display_name',
                    'relation.presentation_asset_id',
                ]);
        $available = $row->status === 'assigned'
            && $row->user_state === 'active'
            && $relation !== null
            && $gacha?->active_draw_state_id !== null
            && DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $gacha->active_draw_state_id)
                ->where('gacha_version_prize_id', $relation->id)
                ->where('available_quantity', '>', 0)
                ->exists();

        return [
            'id' => $row->public_id,
            'revision' => (int) $row->revision,
            'status' => $row->status,
            'user' => [
                'id' => $row->user_public_id,
                'display_name' => $row->user_display_name,
                'state' => $row->user_state,
            ],
            'prize' => [
                'id' => $row->prize_public_id,
                'name' => $relation?->display_name ?? '公開中の景品から削除済み',
                'rank_name' => $relation?->rank_display_name,
            ],
            'is_resolvable' => $available,
            'issue_code' => $available ? null : 'PUBLISHED_PRIZE_UNAVAILABLE',
            'assigned_at' => CarbonImmutable::parse($row->assigned_at)
                ->utc()->toIso8601String(),
            'unassigned_at' => $row->unassigned_at === null
                ? null
                : CarbonImmutable::parse($row->unassigned_at)->utc()->toIso8601String(),
            'updated_at' => CarbonImmutable::parse($row->updated_at)
                ->utc()->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeTestUserOptions(): array
    {
        return DB::table('users as user')
            ->join('qa_test_user_modes as mode', 'mode.user_id', '=', 'user.id')
            ->where('user.state', 'active')
            ->where('mode.is_enabled', true)
            ->whereNull('mode.disabled_at')
            ->orderByDesc('user.id')
            ->limit(100)
            ->get([
                'user.public_id',
                'user.display_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => $row->public_id,
                'display_name' => $row->display_name,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function publishedPrizeOptions(object $gacha): array
    {
        if ($gacha->published_version_id === null || $gacha->active_draw_state_id === null) {
            return [];
        }
        $probabilityId = DB::table('catalog_gacha_versions')
            ->where('id', $gacha->published_version_id)
            ->value('published_probability_version_id');
        if ($probabilityId === null) {
            return [];
        }

        return DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('prize_inventories as inventory', function ($join) use ($gacha): void {
                $join->on('inventory.gacha_version_prize_id', '=', 'relation.id')
                    ->where('inventory.gacha_draw_state_id', '=', $gacha->active_draw_state_id);
            })
            ->where('relation.gacha_version_id', $gacha->published_version_id)
            ->where('relation.is_visible', true)
            ->whereNull('prize.archived_at')
            ->where('inventory.available_quantity', '>', 0)
            ->where(function ($query) use ($probabilityId): void {
                $query->whereExists(function ($entry) use ($probabilityId): void {
                    $entry->selectRaw('1')
                        ->from('catalog_probability_entries as probability_entry')
                        ->join(
                            'catalog_probability_stages as probability_stage',
                            'probability_stage.id',
                            '=',
                            'probability_entry.probability_stage_id'
                        )
                        ->whereColumn(
                            'probability_entry.gacha_version_prize_id',
                            'relation.id'
                        )
                        ->where('probability_stage.probability_version_id', $probabilityId)
                        ->where('probability_entry.probability_ppm', '>', 0);
                })->orWhereExists(function ($guarantee) use ($probabilityId): void {
                    $guarantee->selectRaw('1')
                        ->from('catalog_minimum_guarantees as minimum_guarantee')
                        ->join(
                            'catalog_probability_stages as probability_stage',
                            'probability_stage.id',
                            '=',
                            'minimum_guarantee.probability_stage_id'
                        )
                        ->whereColumn(
                            'minimum_guarantee.gacha_version_prize_id',
                            'relation.id'
                        )
                        ->where('probability_stage.probability_version_id', $probabilityId)
                        ->where('minimum_guarantee.probability_ppm', '>', 0);
                });
            })
            ->orderBy('relation.rank_sort_order')
            ->orderBy('relation.sort_order')
            ->get([
                'prize.public_id',
                'relation.display_name',
                'relation.rank_display_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => $row->public_id,
                'name' => $row->display_name,
                'rank_name' => $row->rank_display_name,
            ])
            ->all();
    }

    private function publishedPrize(object $gacha, string $publicId): object
    {
        if (! Str::isUuid($publicId)) {
            throw $this->invalid('QA guarantee Prize is invalid.');
        }
        $match = collect($this->publishedPrizeOptions($gacha))
            ->firstWhere('id', $publicId);
        if (! is_array($match)) {
            throw $this->invalid('QA guarantee Prize is not drawable in the Published Gacha.');
        }
        $prize = DB::table('catalog_prizes')
            ->where('public_id', $publicId)
            ->where('gacha_id', $gacha->id)
            ->first(['id', 'public_id']);
        if ($prize === null) {
            throw $this->invalid('QA guarantee Prize ownership is invalid.');
        }

        return $prize;
    }

    private function activeLegacyPlanExists(int $userId, int $gachaId): bool
    {
        return DB::table('qa_draw_plan_assignments as assignment')
            ->join('qa_draw_plans as plan', 'plan.id', '=', 'assignment.qa_draw_plan_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.status', 'assigned')
            ->where('plan.gacha_id', $gachaId)
            ->where('plan.status', 'active')
            ->whereNull('plan.archived_at')
            ->lockForUpdate()
            ->exists();
    }

    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw $this->invalid('QA management input is invalid.');
        }

        return $value;
    }

    private function nullableString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw $this->invalid('QA management input is invalid.');
        }

        return $value;
    }

    private function limit(mixed $value): int
    {
        $limit = $value === null ? 25 : filter_var($value, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            throw $this->invalid('QA pagination limit is invalid.');
        }

        return $limit;
    }

    private function cursor(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || ! ctype_digit($decoded) || (int) $decoded < 1) {
            throw $this->invalid('QA pagination cursor is invalid.');
        }

        return (int) $decoded;
    }

    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function idempotencyError(V2PointException $exception): V2QaDrawException
    {
        return match ($exception->getMessage()) {
            'IDEMPOTENCY_KEY_REUSED' => new V2QaDrawException(
                'IDEMPOTENCY_KEY_REUSED',
                409,
                'The Idempotency-Key was already used for a different request.'
            ),
            'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2QaDrawException(
                'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The idempotent request is still processing.',
                true
            ),
            default => throw $exception,
        };
    }

    private function audit(
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $action,
        string $targetPublicId,
        array $metadata = []
    ): void {
        $targetType = str_starts_with($action, 'qa.guarantee.')
            ? 'qa_gacha_guarantee_assignment'
            : (str_starts_with($action, 'qa.mode.')
                ? 'qa_test_user_mode'
                : 'qa_draw_plan');
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => $targetType,
            'target_public_id' => $targetPublicId,
            'metadata' => $metadata,
        ]);
    }

    private function invalid(string $message): V2QaDrawException
    {
        return new V2QaDrawException('QA_CONFIGURATION_INVALID', 422, $message);
    }
}
