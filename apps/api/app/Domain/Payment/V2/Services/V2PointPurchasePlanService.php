<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Payment\V2\Exceptions\V2PointPurchasePlanException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PointPurchasePlanService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function listing(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit = 20
    ): array {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadPointPurchasePlan,
            false,
            'payment.plan.read'
        );
        if ($limit < 1 || $limit > 100) {
            throw $this->invalid('The page size is invalid.');
        }
        [$afterSort, $afterId] = $this->decodeCursor($cursor);
        $latest = DB::table('point_purchase_plans')
            ->selectRaw('code, MAX(version_no) AS version_no')
            ->groupBy('code');
        $query = DB::table('point_purchase_plans as plan')
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.code', '=', 'plan.code')
                ->on('latest.version_no', '=', 'plan.version_no'));
        if ($afterId !== null) {
            $query->where(fn ($next) => $next
                ->where('plan.sort_order', '>', $afterSort)
                ->orWhere(fn ($same) => $same
                    ->where('plan.sort_order', $afterSort)
                    ->where('plan.id', '>', $afterId)));
        }
        $rows = $query->orderBy('plan.sort_order')->orderBy('plan.id')
            ->limit($limit + 1)->select('plan.*')->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => $this->serialize($row))
            ->values()->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore
                ? $this->encodeCursor($rows[$limit - 1])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function show(V2AdminAuthorizationContext $context, string $publicId): array
    {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadPointPurchasePlan,
            false,
            'payment.plan.read'
        );

        return $this->serialize($this->plan($publicId));
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function create(
        V2AdminAuthorizationContext $context,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManagePointPurchasePlan,
            true,
            'payment.plan.create',
            true
        );
        $payload = $this->validate($input, false);

        return $this->mutation(function () use (
            $context, $admin, $payload, $idempotencyKey
        ): array {
            $claim = $this->idempotency->claim(
                'payment.plan.create',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                $payload
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $now = now()->startOfSecond();
            $publicId = (string) Str::uuid7();
            $id = DB::table('point_purchase_plans')->insertGetId([
                ...$this->attributes($payload),
                'public_id' => $publicId,
                'code' => 'plan-'.Str::lower(Str::random(20)),
                'version_no' => 1,
                'revision' => 1,
                'currency' => 'JPY',
                'published_at' => $payload['is_active'] ? $now : null,
                'retired_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $data = $this->serialize(DB::table('point_purchase_plans')->find($id));
            $this->audit->record('payment.plan.created', $this->auditData(
                $context,
                $admin->public_id,
                $admin->role->value,
                $publicId,
                null,
                $data
            ));
            $this->idempotency->complete(
                $claim->record,
                'point_purchase_plan',
                $publicId,
                ['data' => $data]
            );

            return ['data' => $data, 'idempotent_replay' => false];
        });
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function update(
        V2AdminAuthorizationContext $context,
        string $publicId,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManagePointPurchasePlan,
            true,
            'payment.plan.update',
            true
        );
        $payload = $this->validate($input, true);

        return $this->mutation(function () use (
            $context, $admin, $publicId, $payload, $idempotencyKey
        ): array {
            $claim = $this->idempotency->claim(
                'payment.plan.update',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                ['plan_id' => $publicId, ...$payload]
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $current = DB::table('point_purchase_plans')
                ->where('public_id', $publicId)->lockForUpdate()->first();
            if ($current === null) {
                throw new V2PointPurchasePlanException(
                    'POINT_PURCHASE_PLAN_NOT_FOUND', 404, 'The purchase plan was not found.'
                );
            }
            if ($current->status === 'retired'
                || DB::table('point_purchase_plans')
                    ->where('code', $current->code)
                    ->where('version_no', '>', $current->version_no)
                    ->exists()) {
                throw new V2PointPurchasePlanException(
                    'POINT_PURCHASE_PLAN_REVISION_CONFLICT',
                    409,
                    'Only the latest purchase plan version can be updated.'
                );
            }
            if ((int) $current->revision !== $payload['expected_revision']) {
                throw new V2PointPurchasePlanException(
                    'POINT_PURCHASE_PLAN_REVISION_CONFLICT',
                    409,
                    'The purchase plan was updated by another operation.'
                );
            }
            $before = $this->serialize($current);
            $versionedChange = $current->status === 'published' && (
                $current->name !== $payload['name']
                || (int) $current->amount !== $payload['amount']
                || (int) $current->paid_point_amount !== $payload['paid_point_amount']
                || (int) $current->free_point_amount !== $payload['free_point_amount']
                || $current->audience_code !== $payload['audience_code']
            );
            $now = now()->startOfSecond();
            if ($versionedChange) {
                DB::table('point_purchase_plans')->where('id', $current->id)->update([
                    'status' => 'retired',
                    'retired_at' => $now,
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => $now,
                ]);
                $nextPublicId = (string) Str::uuid7();
                $nextId = DB::table('point_purchase_plans')->insertGetId([
                    ...$this->attributes($payload),
                    'public_id' => $nextPublicId,
                    'code' => $current->code,
                    'version_no' => (int) $current->version_no + 1,
                    'revision' => 1,
                    'currency' => 'JPY',
                    'published_at' => $payload['is_active'] ? $now : null,
                    'retired_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $next = DB::table('point_purchase_plans')->find($nextId);
            } else {
                DB::table('point_purchase_plans')->where('id', $current->id)->update([
                    ...$this->attributes($payload),
                    'revision' => DB::raw('revision + 1'),
                    'published_at' => $payload['is_active']
                        ? ($current->published_at ?? $now)
                        : $current->published_at,
                    'retired_at' => $payload['is_active'] ? null : $now,
                    'updated_at' => $now,
                ]);
                $next = DB::table('point_purchase_plans')->find($current->id);
                $nextPublicId = $publicId;
            }
            $data = $this->serialize($next);
            $this->audit->record('payment.plan.updated', $this->auditData(
                $context,
                $admin->public_id,
                $admin->role->value,
                $nextPublicId,
                $before,
                $data
            ));
            $this->idempotency->complete(
                $claim->record,
                'point_purchase_plan',
                $nextPublicId,
                ['data' => $data]
            );

            return ['data' => $data, 'idempotent_replay' => false];
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $input, bool $update): array
    {
        $keys = [
            'name', 'amount', 'paid_point_amount', 'free_point_amount', 'sort_order',
            'is_active', 'available_from', 'available_until', 'audience_code',
        ];
        if ($update) {
            $keys[] = 'expected_revision';
        }
        if (array_diff($keys, array_keys($input)) !== []
            || array_diff(array_keys($input), $keys) !== []) {
            throw $this->invalid();
        }
        foreach (['amount', 'paid_point_amount', 'free_point_amount', 'sort_order'] as $key) {
            if (! is_int($input[$key] ?? null)) {
                throw $this->invalid();
            }
        }
        if ($update && (! is_int($input['expected_revision'] ?? null)
            || $input['expected_revision'] < 1)) {
            throw $this->invalid();
        }
        if (! is_string($input['name'] ?? null)
            || trim($input['name']) === ''
            || mb_strlen(trim($input['name'])) > 191
            || $input['amount'] < 1
            || $input['amount'] > 1_000_000
            || $input['paid_point_amount'] !== $input['amount']
            || $input['free_point_amount'] < 0
            || $input['free_point_amount'] > 1_000_000
            || $input['sort_order'] < 0
            || $input['sort_order'] > 1_000_000
            || ! is_bool($input['is_active'] ?? null)
            || ! in_array($input['audience_code'] ?? null, [
                V2PointPurchaseEligibilityService::AUDIENCE_ALL,
                V2PointPurchaseEligibilityService::AUDIENCE_FIRST_PURCHASE,
            ], true)) {
            throw $this->invalid();
        }
        $from = $this->date($input['available_from'] ?? null);
        $until = $this->date($input['available_until'] ?? null);
        if ($from !== null && $until !== null && $until <= $from) {
            throw $this->invalid('The sale end must be after the sale start.');
        }

        return [
            ...$input,
            'name' => trim($input['name']),
            'available_from' => $from?->utc()->toIso8601String(),
            'available_until' => $until?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(array $payload): array
    {
        return [
            'name' => $payload['name'],
            'amount' => $payload['amount'],
            'paid_point_amount' => $payload['paid_point_amount'],
            'free_point_amount' => $payload['free_point_amount'],
            'sort_order' => $payload['sort_order'],
            'audience_code' => $payload['audience_code'],
            'status' => $payload['is_active'] ? 'published' : 'draft',
            'available_from' => $payload['available_from'],
            'available_until' => $payload['available_until'],
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(object $plan): array
    {
        return [
            'id' => $plan->public_id,
            'name' => $plan->name,
            'amount' => (int) $plan->amount,
            'paid_point_amount' => (int) $plan->paid_point_amount,
            'free_point_amount' => (int) $plan->free_point_amount,
            'sort_order' => (int) $plan->sort_order,
            'audience_code' => $plan->audience_code,
            'is_active' => $plan->status === 'published',
            'status' => $plan->status,
            'available_from' => $this->iso($plan->available_from),
            'available_until' => $this->iso($plan->available_until),
            'version' => (int) $plan->version_no,
            'revision' => (int) $plan->revision,
            'created_at' => $this->iso($plan->created_at),
            'updated_at' => $this->iso($plan->updated_at),
        ];
    }

    private function plan(string $publicId): object
    {
        if (! Str::isUuid($publicId)) {
            throw new V2PointPurchasePlanException(
                'POINT_PURCHASE_PLAN_NOT_FOUND', 404, 'The purchase plan was not found.'
            );
        }
        $plan = DB::table('point_purchase_plans')->where('public_id', $publicId)->first();
        if ($plan === null) {
            throw new V2PointPurchasePlanException(
                'POINT_PURCHASE_PLAN_NOT_FOUND', 404, 'The purchase plan was not found.'
            );
        }

        return $plan;
    }

    private function mutation(callable $operation): array
    {
        try {
            return DB::transaction($operation, 3);
        } catch (V2PointException $exception) {
            throw new V2PointPurchasePlanException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The purchase plan mutation conflicts with another request.'
            );
        }
    }

    private function invalid(string $message = 'The purchase plan is invalid.'): V2PointPurchasePlanException
    {
        return new V2PointPurchasePlanException('POINT_PURCHASE_PLAN_INVALID', 422, $message);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw $this->invalid();
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw $this->invalid();
        }
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    /** @return array{0: int, 1: ?int} */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [0, null];
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded)
            || ! preg_match('/\Av2-point-plan:([0-9]+):([1-9][0-9]*)\z/', $decoded, $matches)) {
            throw $this->invalid('The purchase plan cursor is invalid.');
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function encodeCursor(object $plan): string
    {
        return rtrim(strtr(base64_encode(
            'v2-point-plan:'.(int) $plan->sort_order.':'.(int) $plan->id
        ), '+/', '-_'), '=');
    }

    private function replay(mixed $response): array
    {
        if (! is_array($response) || ! isset($response['data']) || ! is_array($response['data'])) {
            throw new V2PointPurchasePlanException(
                'POINT_PURCHASE_PLAN_UNAVAILABLE', 503, 'The purchase plan is unavailable.', true
            );
        }

        return ['data' => $response['data'], 'idempotent_replay' => true];
    }

    /** @return array<string, mixed> */
    private function auditData(
        V2AdminAuthorizationContext $context,
        string $adminPublicId,
        string $role,
        string $targetPublicId,
        ?array $before,
        array $after
    ): array {
        return [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $adminPublicId,
            'actor_role' => $role,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'point_purchase_plan',
            'target_public_id' => $targetPublicId,
            'before' => $before,
            'after' => $after,
            'outcome' => 'success',
        ];
    }
}
