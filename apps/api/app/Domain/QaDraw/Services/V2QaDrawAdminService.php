<?php

namespace App\Domain\QaDraw\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Models\V2\Admin;
use App\Models\V2\QaDrawPlan;
use App\Models\V2\QaDrawPlanAssignment;
use App\Models\V2\QaDrawPlanItem;
use App\Models\V2\QaTestUserMode;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2QaDrawAdminService
{
    public function __construct(
        private readonly V2AuditLogService $audit,
        private readonly V2AdminFreshMfaAuthorizer $freshMfa
    ) {
    }

    /** @return array<string, mixed> */
    public function mode(V2AdminAuthorizationContext $context, string $userPublicId): array
    {
        $this->freshMfa->authorizeQa($context);
        $user = $this->user($userPublicId);
        $mode = QaTestUserMode::query()->where('user_id', $user->id)->first();

        return [
            'user_id' => $user->public_id,
            'mode' => $mode instanceof QaTestUserMode ? $this->modeResource($mode) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function saveMode(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $reason,
        ?string $startsAt,
        string $endsAt
    ): array {
        $admin = $this->freshMfa->authorizeQa($context, true);
        $user = $this->user($userPublicId);
        if ($user->state !== V2UserState::Active) {
            throw $this->invalid('QA Mode requires an active User.');
        }
        $reason = $this->text($reason, 500, 'QA Mode reason');
        [$starts, $ends] = $this->modeWindow($startsAt, $endsAt);

        return DB::transaction(function () use (
            $admin,
            $user,
            $reason,
            $starts,
            $ends,
            $context
        ): array {
            $mode = QaTestUserMode::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $action = $mode instanceof QaTestUserMode
                ? 'qa.mode.updated'
                : 'qa.mode.enabled';
            $mode ??= new QaTestUserMode();
            $mode->forceFill([
                'user_id' => $user->id,
                'is_enabled' => true,
                'reason' => $reason,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'enabled_by_admin_id' => $admin->id,
                'disabled_at' => null,
                'disabled_by_admin_id' => null,
                'revision' => $mode->exists ? (int) $mode->revision + 1 : 1,
            ])->save();
            $this->adminAudit($action, $admin, $context, $mode->public_id, [
                'user_public_id' => $user->public_id,
                'starts_at' => $starts?->utc()->toIso8601String(),
                'ends_at' => $ends->utc()->toIso8601String(),
            ]);

            return $this->modeResource($mode->refresh());
        });
    }

    /** @return array<string, mixed> */
    public function disableMode(
        V2AdminAuthorizationContext $context,
        string $userPublicId
    ): array {
        $admin = $this->freshMfa->authorizeQa($context, true);
        $user = $this->user($userPublicId);

        return DB::transaction(function () use ($admin, $user, $context): array {
            $mode = QaTestUserMode::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $mode instanceof QaTestUserMode) {
                throw new V2QaDrawException(
                    'QA_MODE_NOT_FOUND',
                    404,
                    'The QA Test User Mode was not found.'
                );
            }
            if ($mode->is_enabled) {
                $mode->forceFill([
                    'is_enabled' => false,
                    'disabled_at' => now()->startOfSecond(),
                    'disabled_by_admin_id' => $admin->id,
                    'revision' => (int) $mode->revision + 1,
                ])->save();
                $this->adminAudit(
                    'qa.mode.disabled',
                    $admin,
                    $context,
                    $mode->public_id,
                    ['user_public_id' => $user->public_id]
                );
            }

            return $this->modeResource($mode->refresh());
        });
    }

    /** @return array<string, mixed> */
    public function plans(V2AdminAuthorizationContext $context, string $userPublicId): array
    {
        $this->freshMfa->authorizeQa($context);
        $user = $this->user($userPublicId);
        $plans = QaDrawPlan::query()
            ->join(
                'qa_draw_plan_assignments as assignment',
                'assignment.qa_draw_plan_id',
                '=',
                'qa_draw_plans.id'
            )
            ->where('assignment.user_id', $user->id)
            ->where('assignment.status', 'assigned')
            ->orderByDesc('qa_draw_plans.created_at')
            ->orderByDesc('qa_draw_plans.id')
            ->get(['qa_draw_plans.*'])
            ->map(fn (QaDrawPlan $plan): array => $this->planResource($plan, false))
            ->all();

        return ['items' => $plans];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function createPlan(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $gachaPublicId,
        string $title,
        string $reason,
        ?string $startsAt,
        ?string $endsAt,
        array $items
    ): array {
        $admin = $this->freshMfa->authorizeQa($context, true);
        $user = $this->user($userPublicId);
        $title = $this->text($title, 191, 'QA Plan title');
        $reason = $this->text($reason, 500, 'QA Plan reason');
        [$starts, $ends] = $this->planWindow($startsAt, $endsAt);
        $gacha = $this->publishedGacha($gachaPublicId);
        $validatedItems = $this->validateItems($gacha, $items);

        return DB::transaction(function () use (
            $admin,
            $user,
            $gacha,
            $title,
            $reason,
            $starts,
            $ends,
            $validatedItems,
            $context
        ): array {
            $this->completeStaleActivePlans(
                $user->id,
                (int) $gacha->id,
                $context->requestId
            );
            $plan = new QaDrawPlan();
            $plan->forceFill([
                'code' => 'QA-'.strtoupper(str_replace('-', '', (string) Str::uuid7())),
                'user_id' => $user->id,
                'gacha_id' => $gacha->id,
                'status' => 'active',
                'title' => $title,
                'reason' => $reason,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'created_by_admin_id' => $admin->id,
                'updated_by_admin_id' => $admin->id,
                'revision' => 1,
            ])->save();
            $assignment = new QaDrawPlanAssignment();
            $assignment->forceFill([
                'qa_draw_plan_id' => $plan->id,
                'user_id' => $user->id,
                'status' => 'assigned',
                'revision' => 1,
                'assigned_at' => now()->startOfSecond(),
                'assigned_by_admin_id' => $admin->id,
            ])->save();
            foreach ($validatedItems as $item) {
                $row = new QaDrawPlanItem();
                $row->forceFill(['qa_draw_plan_id' => $plan->id, ...$item])->save();
            }
            $this->adminAudit(
                'qa.plan.created',
                $admin,
                $context,
                $plan->public_id,
                [
                    'user_public_id' => $user->public_id,
                    'gacha_public_id' => $gacha->public_id,
                    'item_count' => count($validatedItems),
                ]
            );

            return $this->planResource($plan->refresh(), true);
        });
    }

    /** @return array<string, mixed> */
    public function plan(V2AdminAuthorizationContext $context, string $planPublicId): array
    {
        $this->freshMfa->authorizeQa($context);

        return $this->planResource($this->findPlan($planPublicId), true);
    }

    /** @return array<string, mixed> */
    public function updatePlan(
        V2AdminAuthorizationContext $context,
        string $planPublicId,
        string $title,
        string $reason,
        ?string $startsAt,
        ?string $endsAt
    ): array {
        $admin = $this->freshMfa->authorizeQa($context, true);
        $title = $this->text($title, 191, 'QA Plan title');
        $reason = $this->text($reason, 500, 'QA Plan reason');
        [$starts, $ends] = $this->planWindow($startsAt, $endsAt);

        return DB::transaction(function () use (
            $admin,
            $planPublicId,
            $title,
            $reason,
            $starts,
            $ends,
            $context
        ): array {
            $plan = QaDrawPlan::query()
                ->where('public_id', $planPublicId)
                ->lockForUpdate()
                ->first();
            if (! $plan instanceof QaDrawPlan) {
                throw $this->notFound();
            }
            if (in_array($plan->status, ['completed', 'disabled'], true)) {
                throw $this->invalid('Terminal QA Plans cannot be changed.');
            }
            $plan->forceFill([
                'title' => $title,
                'reason' => $reason,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'updated_by_admin_id' => $admin->id,
                'revision' => (int) $plan->revision + 1,
            ])->save();
            $this->adminAudit(
                'qa.plan.updated',
                $admin,
                $context,
                $plan->public_id
            );

            return $this->planResource($plan->refresh(), true);
        });
    }

    /** @return array<string, mixed> */
    public function pausePlan(
        V2AdminAuthorizationContext $context,
        string $planPublicId
    ): array
    {
        return $this->transitionPlan($context, $planPublicId, 'paused');
    }

    /** @return array<string, mixed> */
    public function activatePlan(
        V2AdminAuthorizationContext $context,
        string $planPublicId
    ): array
    {
        $admin = $this->freshMfa->authorizeQa($context, true);

        return DB::transaction(function () use ($admin, $context, $planPublicId): array {
            $plan = QaDrawPlan::query()
                ->where('public_id', $planPublicId)
                ->lockForUpdate()
                ->first();
            if (! $plan instanceof QaDrawPlan) {
                throw $this->notFound();
            }
            if ($plan->status === 'completed') {
                throw $this->invalid('Completed QA Plans cannot be activated.');
            }
            if ($plan->status === 'disabled') {
                throw $this->invalid('Disabled QA Plans cannot be activated.');
            }
            $this->completeStaleActivePlans(
                (int) $plan->user_id,
                (int) $plan->gacha_id,
                $context->requestId,
                (int) $plan->id
            );
            $remaining = DB::table('qa_draw_plan_items')
                ->where('qa_draw_plan_id', $plan->id)
                ->sum(DB::raw('quantity - consumed_count'));
            if ((int) $remaining < 1 || $this->planExpired($plan)) {
                throw $this->invalid('Exhausted or expired QA Plans cannot be activated.');
            }
            $plan->forceFill([
                'status' => 'active',
                'updated_by_admin_id' => $admin->id,
                'revision' => (int) $plan->revision + 1,
            ])->save();
            $this->adminAudit(
                'qa.plan.activated',
                $admin,
                $context,
                $plan->public_id
            );

            return $this->planResource($plan->refresh(), true);
        });
    }

    /** @return array<string, mixed> */
    public function disablePlan(
        V2AdminAuthorizationContext $context,
        string $planPublicId
    ): array
    {
        return $this->transitionPlan($context, $planPublicId, 'disabled');
    }

    /** @return array<string, mixed> */
    public function executions(V2AdminAuthorizationContext $context, array $filters): array
    {
        $this->freshMfa->authorizeQa($context);
        $limit = min(
            max((int) ($filters['limit'] ?? config('v2_qa_draw.execution_page_size', 50)), 1),
            100
        );
        $query = DB::table('qa_draw_executions as execution')
            ->join('users as user', 'user.id', '=', 'execution.user_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'execution.gacha_id')
            ->join('draw_requests as request', 'request.id', '=', 'execution.draw_request_id')
            ->select([
                'execution.id as internal_id',
                'execution.public_id',
                'execution.executed_count',
                'execution.executed_at',
                'user.public_id as user_public_id',
                'gacha.public_id as gacha_public_id',
                'request.public_id as draw_request_public_id',
            ])
            ->orderByDesc('execution.id');
        foreach ([
            'user_id' => 'user.public_id',
            'gacha_id' => 'gacha.public_id',
            'draw_request_id' => 'request.public_id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                if (! is_string($filters[$filter]) || ! Str::isUuid($filters[$filter])) {
                    throw $this->invalid('QA Execution filter is invalid.');
                }
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('execution.executed_at', '>=', $this->date($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('execution.executed_at', '<', $this->date($filters['to']));
        }
        if (isset($filters['cursor'])) {
            $cursor = filter_var($filters['cursor'], FILTER_VALIDATE_INT);
            if ($cursor === false || $cursor < 1) {
                throw $this->invalid('QA Execution cursor is invalid.');
            }
            $query->where('execution.id', '<', $cursor);
        }
        $rows = $query->limit($limit + 1)->get();
        $next = $rows->count() > $limit ? (string) $rows[$limit - 1]->internal_id : null;

        return [
            'items' => $rows->take($limit)->map(fn (object $row): array => [
                'id' => $row->public_id,
                'user_id' => $row->user_public_id,
                'gacha_id' => $row->gacha_public_id,
                'draw_request_id' => $row->draw_request_public_id,
                'executed_count' => (int) $row->executed_count,
                'executed_at' => CarbonImmutable::parse($row->executed_at)
                    ->utc()->toIso8601String(),
            ])->all(),
            'next_cursor' => $next,
        ];
    }

    /** @return array<string, mixed> */
    public function execution(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array
    {
        $admin = $this->freshMfa->authorizeQa($context);
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $row = DB::table('qa_draw_executions as execution')
            ->join('users as user', 'user.id', '=', 'execution.user_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'execution.gacha_id')
            ->join('draw_requests as request', 'request.id', '=', 'execution.draw_request_id')
            ->where('execution.public_id', $publicId)
            ->first([
                'execution.public_id',
                'execution.executed_count',
                'execution.executed_at',
                'execution.metadata_redacted',
                'user.public_id as user_public_id',
                'gacha.public_id as gacha_public_id',
                'request.public_id as draw_request_public_id',
            ]);
        if ($row === null) {
            throw $this->notFound();
        }
        $this->adminAudit('qa.execution.read', $admin, $context, $publicId);

        return [
            'id' => $row->public_id,
            'user_id' => $row->user_public_id,
            'gacha_id' => $row->gacha_public_id,
            'draw_request_id' => $row->draw_request_public_id,
            'executed_count' => (int) $row->executed_count,
            'executed_at' => CarbonImmutable::parse($row->executed_at)
                ->utc()->toIso8601String(),
            'metadata' => json_decode($row->metadata_redacted, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function transitionPlan(
        V2AdminAuthorizationContext $context,
        string $planPublicId,
        string $status
    ): array {
        $admin = $this->freshMfa->authorizeQa($context, true);

        return DB::transaction(function () use (
            $admin,
            $context,
            $planPublicId,
            $status
        ): array {
            $plan = QaDrawPlan::query()
                ->where('public_id', $planPublicId)
                ->lockForUpdate()
                ->first();
            if (! $plan instanceof QaDrawPlan) {
                throw $this->notFound();
            }
            if ($plan->status === 'completed') {
                throw $this->invalid('Completed QA Plans cannot transition.');
            }
            if ($plan->status === 'disabled') {
                throw $this->invalid('Disabled QA Plans cannot transition.');
            }
            $plan->forceFill([
                'status' => $status,
                'updated_by_admin_id' => $admin->id,
                'revision' => (int) $plan->revision + 1,
            ])->save();
            $this->adminAudit(
                'qa.plan.'.$status,
                $admin,
                $context,
                $plan->public_id
            );

            return $this->planResource($plan->refresh(), true);
        });
    }

    private function completeStaleActivePlans(
        int $userId,
        int $gachaId,
        string $requestId,
        ?int $exceptId = null
    ): void {
        $query = QaDrawPlan::query()
            ->join(
                'qa_draw_plan_assignments as assignment',
                'assignment.qa_draw_plan_id',
                '=',
                'qa_draw_plans.id'
            )
            ->where('assignment.user_id', $userId)
            ->where('assignment.status', 'assigned')
            ->where('qa_draw_plans.gacha_id', $gachaId)
            ->where('qa_draw_plans.status', 'active')
            ->whereNull('qa_draw_plans.archived_at')
            ->lockForUpdate();
        if ($exceptId !== null) {
            $query->where('qa_draw_plans.id', '!=', $exceptId);
        }
        foreach ($query->get(['qa_draw_plans.*']) as $plan) {
            $remaining = (int) DB::table('qa_draw_plan_items')
                ->where('qa_draw_plan_id', $plan->id)
                ->sum(DB::raw('quantity - consumed_count'));
            if ($remaining > 0 && ! $this->planExpired($plan)) {
                throw new V2QaDrawException(
                    'QA_ACTIVE_PLAN_CONFLICT',
                    409,
                    'Only one active QA Plan is allowed for the User and Gacha.'
                );
            }
            $plan->forceFill([
                'status' => 'completed',
                'revision' => (int) $plan->revision + 1,
            ])->save();
            $this->audit->record('qa.plan.completed', [
                'request_id' => $requestId,
                'actor_type' => 'system',
                'target_type' => 'qa_draw_plan',
                'target_public_id' => $plan->public_id,
                'metadata' => ['completion_reason' => $remaining === 0 ? 'consumed' : 'expired'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function validateItems(object $gacha, array $items): array
    {
        $maximum = (int) config('v2_qa_draw.maximum_plan_items', 1000);
        if ($items === [] || count($items) > $maximum) {
            throw $this->invalid('QA Plan requires a bounded non-empty Item list.');
        }
        $validated = [];
        $sortOrders = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw $this->invalid('QA Plan Item is invalid.');
            }
            $unknown = array_diff(array_keys($item), [
                'prize_id',
                'quantity',
                'sort_order',
                'fixed_image_asset_id',
                'fixed_video_asset_id',
            ]);
            if ($unknown !== []) {
                throw $this->invalid('QA Plan Item contains unknown fields.');
            }
            $sortOrder = filter_var($item['sort_order'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (
                $sortOrder === false
                || $sortOrder < 1
                || $quantity === false
                || $quantity < 1
                || isset($sortOrders[$sortOrder])
            ) {
                throw $this->invalid('QA Plan Item quantity or sort order is invalid.');
            }
            $sortOrders[$sortOrder] = true;
            $prizeId = $item['prize_id'] ?? null;
            if (! is_string($prizeId) || ! Str::isUuid($prizeId)) {
                throw $this->invalid('QA Plan Prize is invalid.');
            }
            $relation = DB::table('catalog_gacha_version_prizes as relation')
                ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
                ->where('relation.gacha_version_id', $gacha->published_version_id)
                ->where('prize.public_id', $prizeId)
                ->where('prize.is_visible', true)
                ->first(['relation.id']);
            if ($relation === null) {
                throw $this->invalid('QA Plan Prize does not belong to the Published Gacha.');
            }
            $validated[] = [
                'gacha_version_prize_id' => $relation->id,
                'sort_order' => $sortOrder,
                'quantity' => $quantity,
                'consumed_count' => 0,
                'fixed_image_asset_id' => $this->assetId(
                    $item['fixed_image_asset_id'] ?? null,
                    'image'
                ),
                'fixed_video_asset_id' => $this->assetId(
                    $item['fixed_video_asset_id'] ?? null,
                    'video'
                ),
            ];
        }
        usort($validated, fn (array $left, array $right): int =>
            $left['sort_order'] <=> $right['sort_order']);

        return $validated;
    }

    private function assetId(mixed $publicId, string $mediaType): ?int
    {
        if ($publicId === null) {
            return null;
        }
        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            throw $this->invalid('QA Plan fixed Asset is invalid.');
        }
        $asset = DB::table('catalog_presentation_assets')
            ->where('public_id', $publicId)
            ->where('media_type', $mediaType)
            ->where('is_public', true)
            ->first(['id']);
        if ($asset === null) {
            throw $this->invalid("QA Plan {$mediaType} Asset is unavailable.");
        }

        return (int) $asset->id;
    }

    private function user(string $publicId): User
    {
        if (! Str::isUuid($publicId)) {
            throw new V2QaDrawException('QA_USER_NOT_FOUND', 404, 'The User was not found.');
        }
        $user = User::query()->where('public_id', $publicId)->first();
        if (! $user instanceof User) {
            throw new V2QaDrawException('QA_USER_NOT_FOUND', 404, 'The User was not found.');
        }

        return $user;
    }

    private function publishedGacha(string $publicId): object
    {
        if (! Str::isUuid($publicId)) {
            throw $this->invalid('QA Plan Gacha is invalid.');
        }
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', $publicId)
            ->where('state', 'active')
            ->whereNotNull('published_version_id')
            ->first();
        if ($gacha === null) {
            throw $this->invalid('QA Plan Gacha has no Published Version.');
        }

        return $gacha;
    }

    private function findPlan(string $publicId): QaDrawPlan
    {
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $plan = QaDrawPlan::query()->where('public_id', $publicId)->first();
        if (! $plan instanceof QaDrawPlan) {
            throw $this->notFound();
        }

        return $plan;
    }

    /** @return array{?CarbonImmutable, CarbonImmutable} */
    private function modeWindow(?string $startsAt, string $endsAt): array
    {
        $starts = $startsAt === null ? null : $this->date($startsAt);
        $ends = $this->date($endsAt);
        $base = $starts ?? CarbonImmutable::now()->startOfSecond();
        if (
            ! $ends->greaterThan($base)
            || $ends->greaterThan($base->addHours(
                (int) config('v2_qa_draw.maximum_mode_hours', 24)
            ))
        ) {
            throw $this->invalid('QA Mode duration must be greater than zero and at most 24 hours.');
        }

        return [$starts, $ends];
    }

    /** @return array{?CarbonImmutable, ?CarbonImmutable} */
    private function planWindow(?string $startsAt, ?string $endsAt): array
    {
        $starts = $startsAt === null ? null : $this->date($startsAt);
        $ends = $endsAt === null ? null : $this->date($endsAt);
        if ($ends !== null && ! $ends->greaterThan($starts ?? CarbonImmutable::now())) {
            throw $this->invalid('QA Plan end must be after its start.');
        }

        return [$starts, $ends];
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw $this->invalid('QA date-time is invalid.');
        }
        try {
            return CarbonImmutable::parse($value)->startOfSecond();
        } catch (\Throwable) {
            throw $this->invalid('QA date-time is invalid.');
        }
    }

    private function text(string $value, int $maximum, string $label): string
    {
        $value = \Normalizer::normalize(trim($value), \Normalizer::FORM_C);
        if (
            ! is_string($value)
            || $value === ''
            || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
            || preg_match('/<[^>]*>|<|>/u', $value) === 1
        ) {
            throw $this->invalid("{$label} is invalid.");
        }

        return $value;
    }

    private function planExpired(QaDrawPlan $plan): bool
    {
        return $plan->ends_at !== null && ! $plan->ends_at->isFuture();
    }

    /** @return array<string, mixed> */
    private function modeResource(QaTestUserMode $mode): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => $mode->public_id,
            'revision' => (int) $mode->revision,
            'is_enabled' => (bool) $mode->is_enabled,
            'is_active' => $mode->is_enabled
                && $mode->disabled_at === null
                && ($mode->starts_at === null || ! $mode->starts_at->greaterThan($now))
                && $mode->ends_at->greaterThan($now),
            'reason' => $mode->reason,
            'starts_at' => $mode->starts_at?->utc()->toIso8601String(),
            'ends_at' => $mode->ends_at->utc()->toIso8601String(),
            'disabled_at' => $mode->disabled_at?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function planResource(QaDrawPlan $plan, bool $withItems): array
    {
        $gachaPublicId = DB::table('catalog_gachas')
            ->where('id', $plan->gacha_id)->value('public_id');
        $resource = [
            'id' => $plan->public_id,
            'code' => $plan->code,
            'revision' => (int) $plan->revision,
            'user_id' => DB::table('users')->where('id', $plan->user_id)->value('public_id'),
            'gacha_id' => $gachaPublicId,
            'status' => $plan->status,
            'title' => $plan->title,
            'reason' => $plan->reason,
            'starts_at' => $plan->starts_at?->utc()->toIso8601String(),
            'ends_at' => $plan->ends_at?->utc()->toIso8601String(),
            'archived_at' => $plan->archived_at?->utc()->toIso8601String(),
        ];
        if (! $withItems) {
            return $resource;
        }
        $resource['items'] = DB::table('qa_draw_plan_items as item')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'item.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->leftJoin(
                'catalog_presentation_assets as image',
                'image.id',
                '=',
                'item.fixed_image_asset_id'
            )
            ->leftJoin(
                'catalog_presentation_assets as video',
                'video.id',
                '=',
                'item.fixed_video_asset_id'
            )
            ->where('item.qa_draw_plan_id', $plan->id)
            ->orderBy('item.sort_order')
            ->orderBy('item.id')
            ->get([
                'item.public_id',
                'item.sort_order',
                'item.quantity',
                'item.consumed_count',
                'prize.public_id as prize_public_id',
                'image.public_id as image_public_id',
                'video.public_id as video_public_id',
            ])
            ->map(fn (object $item): array => [
                'id' => $item->public_id,
                'sort_order' => (int) $item->sort_order,
                'quantity' => (int) $item->quantity,
                'consumed_count' => (int) $item->consumed_count,
                'prize_id' => $item->prize_public_id,
                'fixed_image_asset_id' => $item->image_public_id,
                'fixed_video_asset_id' => $item->video_public_id,
            ])->all();

        return $resource;
    }

    private function adminAudit(
        string $action,
        Admin $admin,
        V2AdminAuthorizationContext $context,
        string $targetPublicId,
        array $metadata = []
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => str_starts_with($action, 'qa.mode.')
                ? 'qa_test_user_mode'
                : (str_starts_with($action, 'qa.plan.')
                    ? 'qa_draw_plan'
                    : 'qa_draw_execution'),
            'target_public_id' => $targetPublicId,
            'metadata' => $metadata,
        ]);
    }

    private function invalid(string $message): V2QaDrawException
    {
        return new V2QaDrawException('QA_CONFIGURATION_INVALID', 422, $message);
    }

    private function notFound(): V2QaDrawException
    {
        return new V2QaDrawException(
            'QA_PLAN_NOT_FOUND',
            404,
            'The QA Draw Plan was not found.'
        );
    }
}
