<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2UserTagException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Reporting\Services\V2ReportingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2UserTagService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function listing(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit = 50
    ): array {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadUserTag,
            false,
            'user.tag.read'
        );
        if ($limit < 1 || $limit > 100) {
            throw $this->invalid('The page size is invalid.');
        }
        $after = $this->cursor->decode($cursor);
        $query = DB::table('user_tags')->orderByDesc('id');
        if ($after !== null) {
            $query->where('id', '<', $after);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return [
            'items' => $page->map(fn (object $tag): array => $this->tag($tag))
                ->values()->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->cursor->encode((int) $last->id)
                : null,
        ];
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function create(
        V2AdminAuthorizationContext $context,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageUserTag,
            true,
            'user.tag.create',
            true
        );
        $payload = $this->tagInput($input, false);

        return $this->mutation(function () use (
            $admin,
            $context,
            $idempotencyKey,
            $payload
        ): array {
            $claim = $this->claim(
                'user.tag.create',
                $admin->public_id,
                $idempotencyKey,
                $payload
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $now = CarbonImmutable::parse(now())->startOfSecond();
            $publicId = (string) Str::uuid7();
            try {
                DB::table('user_tags')->insert([
                    'public_id' => $publicId,
                    'name' => $payload['name'],
                    'normalized_name' => $payload['normalized_name'],
                    'is_active' => $payload['is_active'],
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                throw $this->duplicate($exception);
            }
            $data = $this->tag($this->tagRow($publicId));
            $this->audit->record('user.tag.created', $this->auditAttributes(
                $context,
                'user_tag',
                $publicId,
                null,
                $data
            ));
            $response = ['data' => $data, 'idempotent_replay' => false];
            $this->idempotency->complete(
                $claim->record,
                'user_tag',
                $publicId,
                $response
            );

            return $response;
        });
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function update(
        V2AdminAuthorizationContext $context,
        string $tagPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageUserTag,
            true,
            'user.tag.update',
            true
        );
        $payload = $this->tagInput($input, true);

        return $this->mutation(function () use (
            $admin,
            $context,
            $idempotencyKey,
            $payload,
            $tagPublicId
        ): array {
            $claim = $this->claim(
                'user.tag.update',
                $admin->public_id,
                $idempotencyKey,
                ['tag_id' => $tagPublicId, ...$payload]
            );
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $tag = DB::table('user_tags')->where('public_id', $tagPublicId)
                ->lockForUpdate()->first();
            if ($tag === null) {
                throw $this->notFound();
            }
            if ((int) $tag->revision !== $payload['expected_revision']) {
                throw $this->conflict();
            }
            try {
                $updated = DB::table('user_tags')
                    ->where('id', $tag->id)
                    ->where('revision', $payload['expected_revision'])
                    ->update([
                        'name' => $payload['name'],
                        'normalized_name' => $payload['normalized_name'],
                        'is_active' => $payload['is_active'],
                        'revision' => $payload['expected_revision'] + 1,
                        'updated_at' => CarbonImmutable::parse(now())->startOfSecond(),
                    ]);
            } catch (QueryException $exception) {
                throw $this->duplicate($exception);
            }
            if ($updated !== 1) {
                throw $this->conflict();
            }
            $data = $this->tag($this->tagRow($tagPublicId));
            $this->audit->record('user.tag.updated', $this->auditAttributes(
                $context,
                'user_tag',
                $tagPublicId,
                $this->tag($tag),
                $data
            ));
            $response = ['data' => $data, 'idempotent_replay' => false];
            $this->idempotency->complete(
                $claim->record,
                'user_tag',
                $tagPublicId,
                $response
            );

            return $response;
        });
    }

    /** @return array{user_id: string, revision: int, tags: list<array<string, mixed>>} */
    public function userTags(
        V2AdminAuthorizationContext $context,
        string $userPublicId
    ): array {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadUserTag,
            false,
            'user.tag.assignment.read'
        );
        $user = DB::table('users')->where('public_id', $userPublicId)->first([
            'id', 'public_id', 'tag_assignment_revision',
        ]);
        if ($user === null) {
            throw $this->userNotFound();
        }

        return $this->tagSet($user);
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function assign(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $tagPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        return $this->changeAssignment(
            $context,
            $userPublicId,
            $tagPublicId,
            $input,
            $idempotencyKey,
            true
        );
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function detach(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $tagPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        return $this->changeAssignment(
            $context,
            $userPublicId,
            $tagPublicId,
            $input,
            $idempotencyKey,
            false
        );
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    private function changeAssignment(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $tagPublicId,
        array $input,
        string $idempotencyKey,
        bool $assign
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageUserTag,
            true,
            $assign ? 'user.tag.assign' : 'user.tag.detach',
            true
        );
        $expectedRevision = $this->expectedRevision($input);
        $scope = $assign ? 'user.tag.assign' : 'user.tag.detach';

        return $this->mutation(function () use (
            $admin,
            $assign,
            $context,
            $expectedRevision,
            $idempotencyKey,
            $scope,
            $tagPublicId,
            $userPublicId
        ): array {
            $claim = $this->claim($scope, $admin->public_id, $idempotencyKey, [
                'user_id' => $userPublicId,
                'tag_id' => $tagPublicId,
                'expected_revision' => $expectedRevision,
            ]);
            if ($claim->replay) {
                return $this->replay($claim->record->response_data);
            }
            $user = DB::table('users')->where('public_id', $userPublicId)
                ->lockForUpdate()->first(['id', 'public_id', 'tag_assignment_revision']);
            if ($user === null) {
                throw $this->userNotFound();
            }
            if ((int) $user->tag_assignment_revision !== $expectedRevision) {
                throw $this->assignmentConflict();
            }
            $tag = DB::table('user_tags')->where('public_id', $tagPublicId)
                ->lockForUpdate()->first();
            if ($tag === null) {
                throw $this->notFound();
            }
            $existing = DB::table('user_tag_assignments')
                ->where('user_id', $user->id)
                ->where('user_tag_id', $tag->id)
                ->exists();
            if ($assign) {
                if (! (bool) $tag->is_active) {
                    throw new V2UserTagException(
                        'USER_TAG_INACTIVE',
                        409,
                        'Inactive User Tags cannot be newly assigned.'
                    );
                }
                if ($existing) {
                    throw new V2UserTagException(
                        'USER_TAG_ALREADY_ASSIGNED',
                        409,
                        'The User Tag is already assigned.'
                    );
                }
                DB::table('user_tag_assignments')->insert([
                    'user_id' => $user->id,
                    'user_tag_id' => $tag->id,
                    'assigned_by_admin_public_id' => $admin->public_id,
                    'assigned_at' => CarbonImmutable::parse(now())->startOfSecond(),
                ]);
            } else {
                if (! $existing) {
                    throw new V2UserTagException(
                        'USER_TAG_ASSIGNMENT_NOT_FOUND',
                        404,
                        'The User Tag assignment was not found.'
                    );
                }
                DB::table('user_tag_assignments')
                    ->where('user_id', $user->id)
                    ->where('user_tag_id', $tag->id)
                    ->delete();
            }
            $updated = DB::table('users')->where('id', $user->id)
                ->where('tag_assignment_revision', $expectedRevision)
                ->update(['tag_assignment_revision' => $expectedRevision + 1]);
            if ($updated !== 1) {
                throw $this->assignmentConflict();
            }
            $freshUser = DB::table('users')->where('id', $user->id)->first([
                'id', 'public_id', 'tag_assignment_revision',
            ]);
            $data = $this->tagSet($freshUser);
            $this->audit->record(
                $assign ? 'user.tag.assigned' : 'user.tag.detached',
                $this->auditAttributes(
                    $context,
                    'user',
                    $userPublicId,
                    ['revision' => $expectedRevision],
                    [
                        'revision' => $expectedRevision + 1,
                        'tag_id' => $tagPublicId,
                    ]
                )
            );
            $response = ['data' => $data, 'idempotent_replay' => false];
            $this->idempotency->complete(
                $claim->record,
                'user',
                $userPublicId,
                $response
            );

            return $response;
        });
    }

    /** @return array<string, mixed> */
    private function tagInput(array $input, bool $updating): array
    {
        $name = $this->name($input['name'] ?? null);
        if (! is_bool($input['is_active'] ?? null)) {
            throw $this->invalid('The active state is invalid.');
        }
        $payload = [
            'name' => $name,
            'normalized_name' => mb_strtolower($name, 'UTF-8'),
            'is_active' => $input['is_active'],
        ];
        if ($updating) {
            $payload['expected_revision'] = $this->expectedRevision($input);
        }

        return $payload;
    }

    private function name(mixed $value): string
    {
        if (! is_string($value)) {
            throw $this->invalid('The User Tag name is invalid.');
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($value));
        if (
            ! is_string($normalized)
            || $normalized === ''
            || mb_strlen($normalized, 'UTF-8') > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $normalized)
        ) {
            throw $this->invalid('The User Tag name is invalid.');
        }

        return $normalized;
    }

    private function expectedRevision(array $input): int
    {
        $value = $input['expected_revision'] ?? null;
        if (! is_int($value) || $value < 1) {
            throw $this->invalid('The expected revision is invalid.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function tag(object $tag): array
    {
        return [
            'id' => (string) $tag->public_id,
            'name' => (string) $tag->name,
            'is_active' => (bool) $tag->is_active,
            'revision' => (int) $tag->revision,
            'created_at' => CarbonImmutable::parse($tag->created_at)->utc()->toIso8601String(),
            'updated_at' => CarbonImmutable::parse($tag->updated_at)->utc()->toIso8601String(),
        ];
    }

    /** @return array{user_id: string, revision: int, tags: list<array<string, mixed>>} */
    private function tagSet(object $user): array
    {
        $tags = DB::table('user_tag_assignments as assignment')
            ->join('user_tags as tag', 'tag.id', '=', 'assignment.user_tag_id')
            ->where('assignment.user_id', $user->id)
            ->orderBy('tag.normalized_name')
            ->orderBy('tag.id')
            ->get([
                'tag.public_id',
                'tag.name',
                'tag.is_active',
                'assignment.assigned_at',
            ])
            ->map(static fn (object $tag): array => [
                'id' => (string) $tag->public_id,
                'name' => (string) $tag->name,
                'is_active' => (bool) $tag->is_active,
                'assigned_at' => CarbonImmutable::parse($tag->assigned_at)
                    ->utc()->toIso8601String(),
            ])->values()->all();

        return [
            'user_id' => (string) $user->public_id,
            'revision' => (int) $user->tag_assignment_revision,
            'tags' => $tags,
        ];
    }

    private function tagRow(string $publicId): object
    {
        $tag = DB::table('user_tags')->where('public_id', $publicId)->first();
        if ($tag === null) {
            throw $this->notFound();
        }

        return $tag;
    }

    private function claim(
        string $scope,
        string $adminPublicId,
        string $idempotencyKey,
        array $payload
    ): object {
        try {
            return $this->idempotency->claim(
                $scope,
                'admin',
                $adminPublicId,
                $idempotencyKey,
                $payload
            );
        } catch (V2PointException $exception) {
            throw new V2UserTagException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The User Tag mutation conflicts with another request.'
            );
        }
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    private function replay(mixed $response): array
    {
        if (! is_array($response) || ! is_array($response['data'] ?? null)) {
            throw new V2UserTagException(
                'USER_TAG_IDEMPOTENCY_INVALID',
                500,
                'The User Tag replay state is invalid.'
            );
        }

        return [...$response, 'idempotent_replay' => true];
    }

    private function mutation(callable $operation): array
    {
        return DB::transaction($operation, 3);
    }

    /** @return array<string, mixed> */
    private function auditAttributes(
        V2AdminAuthorizationContext $context,
        string $targetType,
        string $targetPublicId,
        ?array $before,
        ?array $after
    ): array {
        return [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => $targetType,
            'target_public_id' => $targetPublicId,
            'outcome' => 'success',
            'before' => $before,
            'after' => $after,
        ];
    }

    private function duplicate(QueryException $exception): V2UserTagException
    {
        return new V2UserTagException(
            'USER_TAG_NAME_CONFLICT',
            409,
            'A User Tag with the same name already exists.',
            false
        );
    }

    private function invalid(string $message): V2UserTagException
    {
        return new V2UserTagException('USER_TAG_INVALID', 422, $message);
    }

    private function conflict(): V2UserTagException
    {
        return new V2UserTagException(
            'USER_TAG_REVISION_CONFLICT',
            409,
            'The User Tag was updated by another request.'
        );
    }

    private function assignmentConflict(): V2UserTagException
    {
        return new V2UserTagException(
            'USER_TAG_ASSIGNMENT_REVISION_CONFLICT',
            409,
            'The User Tag assignments were updated by another request.'
        );
    }

    private function notFound(): V2UserTagException
    {
        return new V2UserTagException('USER_TAG_NOT_FOUND', 404, 'The User Tag was not found.');
    }

    private function userNotFound(): V2UserTagException
    {
        return new V2UserTagException('ADMIN_USER_NOT_FOUND', 404, 'The User was not found.');
    }
}
