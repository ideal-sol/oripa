<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Normalizer;

final class V2CatalogMasterMutationService
{
    private const RESOURCES = [
        'category' => [
            'table' => 'catalog_categories',
            'resource_type' => 'catalog_category',
            'code_length' => 64,
            'name_length' => 191,
            'slug' => true,
            'description' => true,
        ],
        'tag' => [
            'table' => 'catalog_tags',
            'resource_type' => 'catalog_tag',
            'code_length' => 64,
            'name_length' => 191,
            'slug' => true,
            'description' => false,
        ],
        'rank' => [
            'table' => 'catalog_ranks',
            'resource_type' => 'catalog_rank',
            'code_length' => 32,
            'name_length' => 128,
            'slug' => false,
            'description' => false,
        ],
    ];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2CatalogMutationRateLimiter $rateLimiter,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    public function create(
        V2AdminAuthorizationContext $context,
        string $resource,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', $resource);
        $this->rateLimit($context, $admin, 'create', $resource);
        $definition = $this->definition($resource);
        $payload = $this->validateCreate($definition, $input);

        return $this->execute(
            $context,
            $admin,
            $resource,
            'create',
            $idempotencyKey,
            $payload,
            201,
            function () use ($definition, $payload): object {
                $now = now()->startOfSecond();
                $publicId = (string) Str::uuid7();
                DB::table($definition['table'])->insert([
                    'public_id' => $publicId,
                    'code' => $payload['code'],
                    ...($definition['slug'] ? ['slug' => $payload['slug']] : []),
                    'display_name' => $payload['name'],
                    ...($definition['description']
                        ? ['description' => $payload['description']]
                        : []),
                    'sort_order' => $payload['sort_order'],
                    'is_visible' => $payload['is_visible'],
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->find($definition['table'], $publicId, true);
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    public function update(
        V2AdminAuthorizationContext $context,
        string $resource,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', $resource);
        $this->rateLimit($context, $admin, 'update', $resource);
        $definition = $this->definition($resource);
        $payload = $this->validateUpdate($definition, $input);

        return $this->execute(
            $context,
            $admin,
            $resource,
            'update',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($definition, $publicId, $payload): object {
                $row = $this->find($definition['table'], $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $changes = [
                    'display_name' => $payload['name'],
                    ...($definition['slug'] ? ['slug' => $payload['slug']] : []),
                    ...($definition['description']
                        ? ['description' => $payload['description']]
                        : []),
                    'sort_order' => $payload['sort_order'],
                    'is_visible' => $payload['is_visible'],
                ];
                if ($this->changesPublishedPresentation($row, $changes)) {
                    $this->assertNoPublishedReference($definition['table'], (int) $row->id);
                }
                DB::table($definition['table'])->where('id', $row->id)->update([
                    ...$changes,
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find($definition['table'], $publicId, false);
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    public function archive(
        V2AdminAuthorizationContext $context,
        string $resource,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'archive', $resource);
        $this->rateLimit($context, $admin, 'archive', $resource);
        $definition = $this->definition($resource);
        $payload = $this->validateArchive($input);

        return $this->execute(
            $context,
            $admin,
            $resource,
            'archive',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($definition, $publicId, $payload): object {
                $row = $this->find($definition['table'], $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $this->assertNoPublishedReference($definition['table'], (int) $row->id);
                DB::table($definition['table'])->where('id', $row->id)->update([
                    'is_visible' => false,
                    'archived_at' => now()->startOfSecond(),
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find($definition['table'], $publicId, false);
            }
        );
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(): object $mutation
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    private function execute(
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $resource,
        string $action,
        string $idempotencyKey,
        array $request,
        int $status,
        callable $mutation
    ): array {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new V2CatalogException(
                'IDEMPOTENCY_KEY_REQUIRED',
                422,
                'A valid Idempotency-Key is required.'
            );
        }
        $attempts = (int) config('v2_catalog.mutation.maximum_attempts');
        if ($attempts !== 3) {
            throw new \RuntimeException('Catalog mutation retry configuration is invalid.');
        }

        try {
            return DB::transaction(function () use (
                $context,
                $admin,
                $resource,
                $action,
                $idempotencyKey,
                $request,
                $status,
                $mutation
            ): array {
                try {
                    $claim = $this->idempotency->claim(
                        'catalog_master_mutation',
                        'admin',
                        $admin->public_id,
                        $idempotencyKey,
                        ['resource' => $resource, 'action' => $action, ...$request]
                    );
                } catch (V2PointException $exception) {
                    throw $this->idempotencyException($exception);
                }
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! is_array($response['data'] ?? null)) {
                        throw new \RuntimeException('Catalog replay response is unavailable.');
                    }
                    $this->recordAudit(
                        'catalog.master.idempotent_replay',
                        $context,
                        $admin,
                        $resource,
                        $action,
                        'success',
                        'completed_replay',
                        $response['data']['id'] ?? null
                    );

                    return [
                        'data' => $response['data'],
                        'idempotent_replay' => true,
                        'status' => (int) ($claim->record->response_status ?? $status),
                    ];
                }

                $row = $mutation();
                $data = $this->map($resource, $row);
                $event = 'catalog.master.'.$action.'d';
                $this->recordAudit(
                    $event,
                    $context,
                    $admin,
                    $resource,
                    $action,
                    'success',
                    $action.'_completed',
                    $data['id'],
                    ['revision' => $data['revision']]
                );
                $this->outbox->enqueue(
                    'catalog.change',
                    'catalog_'.$resource,
                    $data['id'],
                    $event,
                    [
                        'catalog_public_id' => $data['id'],
                        'catalog_resource' => $resource,
                        'revision' => $data['revision'],
                    ],
                    'catalog-'.$action.'-'.$claim->record->public_id
                );
                $this->idempotency->complete(
                    $claim->record,
                    'catalog_'.$resource,
                    $data['id'],
                    ['data' => $data]
                );
                DB::table('idempotency_records')
                    ->where('id', $claim->record->id)
                    ->update(['response_status' => $status]);

                return [
                    'data' => $data,
                    'idempotent_replay' => false,
                    'status' => $status,
                ];
            }, $attempts);
        } catch (V2CatalogException $exception) {
            $this->recordAudit(
                $this->failureAuditAction($exception),
                $context,
                $admin,
                $resource,
                $action,
                'failure',
                strtolower($exception->errorCode)
            );
            throw $exception;
        } catch (QueryException $exception) {
            $mapped = $this->queryException($exception);
            $this->recordAudit(
                $this->failureAuditAction($mapped),
                $context,
                $admin,
                $resource,
                $action,
                'failure',
                strtolower($mapped->errorCode)
            );
            throw $mapped;
        }
    }

    private function authorize(
        V2AdminAuthorizationContext $context,
        string $action,
        string $resource
    ): Admin {
        try {
            return $this->authorization->authorizePermission(
                $context,
                V2Permission::ManageCatalog,
                false,
                'catalog.'.$resource.'.'.$action
            );
        } catch (V2AuthenticationException $exception) {
            $this->audit->record('catalog.master.permission_denied', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $context->adminPublicId,
                'actor_role' => $context->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'action' => 'catalog.'.$resource.'.'.$action,
                'target_type' => 'catalog_'.$resource,
                'outcome' => 'failure',
                'reason_code' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
    }

    private function rateLimit(
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $action,
        string $resource
    ): void {
        try {
            $this->rateLimiter->assertAdmin($admin->public_id);
        } catch (V2AuthenticationException $exception) {
            $this->recordAudit(
                'catalog.master.rate_limited',
                $context,
                $admin,
                $resource,
                $action,
                'failure',
                strtolower($exception->errorCode)
            );
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function validateCreate(array $definition, array $input): array
    {
        $allowed = ['code', 'name', 'sort_order', 'is_visible'];
        if ($definition['slug']) {
            $allowed[] = 'slug';
        }
        if ($definition['description']) {
            $allowed[] = 'description';
        }
        $this->assertFields($input, $allowed, $allowed);

        return [
            'code' => $this->code($input['code'], $definition['code_length']),
            ...($definition['slug'] ? ['slug' => $this->slug($input['slug'])] : []),
            'name' => $this->plainText($input['name'], 1, $definition['name_length']),
            ...($definition['description']
                ? ['description' => $this->nullablePlainText($input['description'], 2000)]
                : []),
            'sort_order' => $this->sortOrder($input['sort_order']),
            'is_visible' => $this->boolean($input['is_visible']),
        ];
    }

    /** @return array<string, mixed> */
    private function validateUpdate(array $definition, array $input): array
    {
        if (array_key_exists('code', $input)) {
            throw new V2CatalogException(
                'CATALOG_CODE_IMMUTABLE',
                409,
                'Catalog master codes cannot be changed.'
            );
        }
        $allowed = ['expected_revision', 'name', 'sort_order', 'is_visible'];
        if ($definition['slug']) {
            $allowed[] = 'slug';
        }
        if ($definition['description']) {
            $allowed[] = 'description';
        }
        $this->assertFields($input, $allowed, $allowed);

        return [
            'expected_revision' => $this->revision($input['expected_revision']),
            ...($definition['slug'] ? ['slug' => $this->slug($input['slug'])] : []),
            'name' => $this->plainText($input['name'], 1, $definition['name_length']),
            ...($definition['description']
                ? ['description' => $this->nullablePlainText($input['description'], 2000)]
                : []),
            'sort_order' => $this->sortOrder($input['sort_order']),
            'is_visible' => $this->boolean($input['is_visible']),
        ];
    }

    /** @return array{expected_revision: int} */
    private function validateArchive(array $input): array
    {
        $this->assertFields($input, ['expected_revision'], ['expected_revision']);

        return ['expected_revision' => $this->revision($input['expected_revision'])];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $required
     */
    private function assertFields(array $input, array $allowed, array $required): void
    {
        if (
            array_diff(array_keys($input), $allowed) !== []
            || array_diff($required, array_keys($input)) !== []
        ) {
            throw $this->validationException();
        }
    }

    private function code(mixed $value, int $maximum): string
    {
        if (
            ! is_string($value)
            || strlen($value) > $maximum
            || preg_match('/\A[a-z][a-z0-9_-]*\z/', $value) !== 1
        ) {
            throw $this->validationException();
        }

        return $value;
    }

    private function slug(mixed $value): string
    {
        if (
            ! is_string($value)
            || strlen($value) > 128
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1
        ) {
            throw $this->validationException();
        }

        return $value;
    }

    private function plainText(mixed $value, int $minimum, int $maximum): string
    {
        if (! is_string($value)) {
            throw $this->validationException();
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (
            ! is_string($normalized)
            || mb_strlen($normalized) < $minimum
            || mb_strlen($normalized) > $maximum
            || preg_match('/[<>]/u', $normalized) === 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized) === 1
        ) {
            throw $this->validationException();
        }

        return $normalized;
    }

    private function nullablePlainText(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->plainText($value, 0, $maximum);
    }

    private function sortOrder(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 4294967295) {
            throw $this->validationException();
        }

        return $value;
    }

    private function revision(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw $this->validationException();
        }

        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw $this->validationException();
        }

        return $value;
    }

    private function assertMutable(object $row, int $expectedRevision): void
    {
        if ($row->archived_at !== null) {
            throw new V2CatalogException(
                'CATALOG_RESOURCE_ARCHIVED',
                409,
                'Archived Catalog master records cannot be changed.'
            );
        }
        if ((int) $row->revision !== $expectedRevision) {
            throw new V2CatalogException(
                'CATALOG_REVISION_CONFLICT',
                409,
                'The Catalog master record has changed.'
            );
        }
    }

    /** @param array<string, mixed> $changes */
    private function changesPublishedPresentation(object $row, array $changes): bool
    {
        foreach ($changes as $column => $value) {
            if ($row->{$column} !== $value) {
                return true;
            }
        }

        return false;
    }

    private function assertNoPublishedReference(string $table, int $id): void
    {
        $query = match ($table) {
            'catalog_categories' => DB::table('catalog_gachas as g')
                ->join('catalog_gacha_versions as gv', 'gv.id', '=', 'g.published_version_id')
                ->where('g.category_id', $id),
            'catalog_tags' => DB::table('catalog_gacha_tags as gt')
                ->join('catalog_gachas as g', 'g.id', '=', 'gt.gacha_id')
                ->join('catalog_gacha_versions as gv', 'gv.id', '=', 'g.published_version_id')
                ->where('gt.tag_id', $id),
            'catalog_ranks' => DB::table('catalog_prizes as p')
                ->join('catalog_gacha_version_prizes as gvp', 'gvp.prize_id', '=', 'p.id')
                ->join('catalog_gacha_versions as gv', 'gv.id', '=', 'gvp.gacha_version_id')
                ->where('p.rank_id', $id),
            default => throw new \LogicException('Unsupported Catalog table.'),
        };
        if (
            $query->where('gv.status', 'published')
                ->where(function ($period): void {
                    $period->whereNull('gv.publish_end_at')
                        ->orWhere('gv.publish_end_at', '>', now());
                })
                ->exists()
        ) {
            throw new V2CatalogException(
                'CATALOG_PUBLISHED_REFERENCE_CONFLICT',
                409,
                'A Published Catalog version protects this master record.'
            );
        }
    }

    private function find(string $table, string $publicId, bool $lock): object
    {
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $query = DB::table($table)->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw $this->notFound();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function map(string $resource, object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            ...($resource !== 'rank' ? ['slug' => $row->slug] : []),
            'name' => $row->display_name,
            ...($resource === 'category' ? ['description' => $row->description] : []),
            'sort_order' => (int) $row->sort_order,
            'is_visible' => (bool) $row->is_visible,
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function definition(string $resource): array
    {
        $definition = self::RESOURCES[$resource] ?? null;
        if (! is_array($definition)) {
            throw $this->notFound();
        }

        return $definition;
    }

    private function idempotencyException(V2PointException $exception): V2CatalogException
    {
        return match ($exception->getMessage()) {
            'IDEMPOTENCY_KEY_REUSED' => new V2CatalogException(
                'IDEMPOTENCY_KEY_REUSED',
                409,
                'The Idempotency-Key was used for another request.'
            ),
            'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2CatalogException(
                'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The idempotent request is still processing.'
            ),
            default => throw $exception,
        };
    }

    private function queryException(QueryException $exception): V2CatalogException
    {
        $state = $exception->errorInfo[0] ?? null;
        if ($state === '23505') {
            return new V2CatalogException(
                'CATALOG_MASTER_CONFLICT',
                409,
                'The Catalog code or slug is already in use.'
            );
        }
        $message = $exception->getMessage();
        if (
            $state === 'P0001'
            && str_contains(
                $message,
                'Published Catalog references protect this master record'
            )
        ) {
            return new V2CatalogException(
                'CATALOG_PUBLISHED_REFERENCE_CONFLICT',
                409,
                'A Catalog master invariant rejected the mutation.'
            );
        }
        if ($state === 'P0001' && str_contains($message, 'Catalog master code is immutable')) {
            return new V2CatalogException(
                'CATALOG_CODE_IMMUTABLE',
                409,
                'Catalog master codes cannot be changed.'
            );
        }
        if (
            $state === 'P0001'
            && str_contains($message, 'Archived Catalog master records are immutable')
        ) {
            return new V2CatalogException(
                'CATALOG_RESOURCE_ARCHIVED',
                409,
                'Archived Catalog master records cannot be changed.'
            );
        }
        if (
            $state === 'P0001'
            && str_contains($message, 'Catalog master revision must increase by one')
        ) {
            return new V2CatalogException(
                'CATALOG_REVISION_CONFLICT',
                409,
                'The Catalog master record has changed.'
            );
        }

        throw $exception;
    }

    private function failureAuditAction(V2CatalogException $exception): string
    {
        return match ($exception->errorCode) {
            'CATALOG_PUBLISHED_REFERENCE_CONFLICT' =>
                'catalog.master.published_reference_rejected',
            'IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS' =>
                'catalog.master.conflict',
            default => 'catalog.master.rejected',
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(
        string $event,
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $resource,
        string $action,
        string $outcome,
        string $reason,
        ?string $targetPublicId = null,
        array $metadata = []
    ): void {
        $this->audit->record($event, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'action' => 'catalog.'.$resource.'.'.$action,
            'target_type' => 'catalog_'.$resource,
            'target_public_id' => $targetPublicId,
            'outcome' => $outcome,
            'reason_code' => $reason,
            'metadata' => $metadata,
        ]);
    }

    private function validationException(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_MUTATION_INVALID',
            422,
            'The Catalog mutation request is invalid.'
        );
    }

    private function notFound(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_RESOURCE_NOT_FOUND',
            404,
            'The Catalog resource was not found.'
        );
    }
}
