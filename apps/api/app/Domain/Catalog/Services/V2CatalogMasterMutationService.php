<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Normalizer;

final class V2CatalogMasterMutationService
{
    private const GACHA_SALES_PAUSE_REASONS = [
        'operations_review',
        'inventory_review',
        'incident_response',
    ];

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
        private readonly V2RateLimiter $criticalRateLimiter,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox,
        private readonly V2GachaPublicCodeGenerator $gachaPublicCodes
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
        if ($resource === 'prize') {
            return $this->createPrize($context, $idempotencyKey, $input);
        }
        if ($resource === 'asset') {
            return $this->createAsset($context, $idempotencyKey, $input);
        }
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
        if ($resource === 'prize') {
            return $this->updatePrize($context, $publicId, $idempotencyKey, $input);
        }
        if ($resource === 'asset') {
            return $this->updateAsset($context, $publicId, $idempotencyKey, $input);
        }
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
        if ($resource === 'prize' || $resource === 'asset') {
            return $this->archiveExtendedResource(
                $context,
                $resource,
                $publicId,
                $idempotencyKey,
                $input
            );
        }
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

    /** @param array<string, mixed> $input */
    public function createGacha(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'gacha');
        $this->rateLimit($context, $admin, 'create', 'gacha');
        $payload = $this->validateGachaCreate($input);

        return $this->execute(
            $context,
            $admin,
            'gacha',
            'create',
            $idempotencyKey,
            $payload,
            201,
            function () use ($payload): object {
                $now = now()->startOfSecond();
                $category = $this->resolveReference(
                    'catalog_categories',
                    $payload['category_id'],
                    'is_visible'
                );
                $tags = $this->resolveReferences(
                    'catalog_tags',
                    $payload['tag_ids'],
                    'is_visible'
                );
                $publicId = (string) Str::uuid7();
                $gachaId = DB::table('catalog_gachas')->insertGetId([
                    'public_id' => $publicId,
                    'public_code' => $this->gachaPublicCodes->unique(),
                    'code' => $payload['code'],
                    'slug' => $payload['slug'],
                    'category_id' => $category->id,
                    'state' => 'draft',
                    'sold_count' => 0,
                    'published_version_id' => null,
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->replaceGachaTags((int) $gachaId, $tags);

                return $this->find('catalog_gachas', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function createGachaCore(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'gacha');
        $this->rateLimit($context, $admin, 'create', 'gacha');
        $payload = $this->validateGachaCoreCreate($input);

        return $this->execute(
            $context,
            $admin,
            'gacha',
            'create',
            $idempotencyKey,
            $payload,
            201,
            function () use ($payload): object {
                $now = now()->startOfSecond();
                $category = $this->resolveReference(
                    'catalog_categories',
                    $payload['category_id'],
                    'is_visible'
                );
                $tags = $this->resolveReferences(
                    'catalog_tags',
                    $payload['tag_ids'],
                    'is_visible'
                );
                $asset = $this->resolveReference(
                    'catalog_presentation_assets',
                    $payload['presentation_asset_id'],
                    'is_public'
                );
                if ($asset->media_type !== 'image') {
                    throw $this->validationException();
                }
                $publicId = (string) Str::uuid7();
                $identity = str_replace('-', '', strtolower($publicId));
                $gachaId = DB::table('catalog_gachas')->insertGetId([
                    'public_id' => $publicId,
                    'public_code' => $this->gachaPublicCodes->unique(),
                    'code' => 'gacha_'.substr($identity, 0, 26),
                    'slug' => 'gacha-'.substr($identity, 0, 26),
                    'category_id' => $category->id,
                    'state' => 'draft',
                    'sold_count' => 0,
                    'published_version_id' => null,
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->replaceGachaTags((int) $gachaId, $tags);
                DB::table('catalog_gacha_versions')->insert([
                    'public_id' => (string) Str::uuid7(),
                    'gacha_id' => $gachaId,
                    'category_id' => $category->id,
                    'version_number' => 1,
                    'status' => 'draft',
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'notices' => $payload['notices'],
                    'price_points' => $payload['price_points'],
                    'total_count' => $payload['total_count'],
                    'daily_draw_limit' => $payload['daily_draw_limit'],
                    'audience_code' => $payload['audience_code'],
                    'presentation_asset_id' => $asset->id,
                    'published_probability_version_id' => null,
                    'publish_start_at' => $payload['publish_start_at'],
                    'publish_end_at' => $payload['publish_end_at'],
                    'published_at' => null,
                    'revision' => 1,
                    'archived_at' => null,
                    'cloned_from_version_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $versionId = (int) DB::table('catalog_gacha_versions')
                    ->where('gacha_id', $gachaId)->where('version_number', 1)->value('id');
                $this->replaceGachaVersionTags($versionId, $tags);

                return $this->find('catalog_gachas', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function updateGacha(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        foreach (['code', 'slug', 'state', 'sold_count', 'published_version_id', 'public_code'] as $field) {
            if (array_key_exists($field, $input)) {
                throw new V2CatalogException(
                    'CATALOG_GACHA_IDENTITY_IMMUTABLE',
                    409,
                    'Gacha identity and lifecycle fields cannot be changed here.'
                );
            }
        }
        $admin = $this->authorize($context, 'update', 'gacha');
        $this->rateLimit($context, $admin, 'update', 'gacha');
        $payload = $this->validateGachaUpdate($input);

        return $this->execute(
            $context,
            $admin,
            'gacha',
            'update',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($publicId, $payload): object {
                $row = $this->find('catalog_gachas', $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $category = $this->resolveReference(
                    'catalog_categories',
                    $payload['category_id'],
                    'is_visible'
                );
                $tags = $this->resolveReferences(
                    'catalog_tags',
                    $payload['tag_ids'],
                    'is_visible'
                );
                if (! $payload['updates_draft']) {
                    DB::table('catalog_gachas')->where('id', $row->id)->update([
                        'category_id' => $category->id,
                        'revision' => (int) $row->revision + 1,
                        'updated_at' => now()->startOfSecond(),
                    ]);
                    $this->replaceGachaTags((int) $row->id, $tags);

                    return $this->find('catalog_gachas', $publicId, false);
                }
                $asset = $this->resolveReference(
                    'catalog_presentation_assets',
                    $payload['presentation_asset_id'],
                    'is_public'
                );
                if ($asset->media_type !== 'image') {
                    throw $this->validationException();
                }
                $version = DB::table('catalog_gacha_versions')
                    ->where('gacha_id', $row->id)
                    ->where('status', 'draft')
                    ->whereNull('archived_at')
                    ->orderByDesc('version_number')
                    ->lockForUpdate()
                    ->first();
                if ($version === null) {
                    $source = $row->published_version_id === null ? null
                        : DB::table('catalog_gacha_versions')
                            ->where('id', $row->published_version_id)
                            ->lockForUpdate()->first();
                    if ($source === null) {
                        throw $this->notFound();
                    }
                    if ((int) $source->revision !== $payload['expected_version_revision']) {
                        throw new V2CatalogException(
                            'CATALOG_REVISION_CONFLICT',
                            409,
                            'The Catalog draft source has changed.'
                        );
                    }
                    $version = $this->clonePublishedVersionForMasterEdit($row, $source);
                } elseif ((int) $version->revision !== $payload['expected_version_revision']) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog draft has changed.'
                    );
                }
                if ($payload['total_count'] < (int) $row->sold_count) {
                    throw new V2CatalogException(
                        'CATALOG_GACHA_TOTAL_COUNT_CONFLICT',
                        409,
                        'Total count cannot be lower than completed draws.'
                    );
                }
                DB::table('catalog_gachas')->where('id', $row->id)->update([
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
                    'category_id' => $category->id,
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'notices' => $payload['notices'],
                    'price_points' => $payload['price_points'],
                    'total_count' => $payload['total_count'],
                    'daily_draw_limit' => $payload['daily_draw_limit'],
                    'audience_code' => $payload['audience_code'],
                    'presentation_asset_id' => $asset->id,
                    'publish_start_at' => $payload['publish_start_at'],
                    'publish_end_at' => $payload['publish_end_at'],
                    'revision' => (int) $version->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                $this->replaceGachaVersionTags((int) $version->id, $tags);

                return $this->find('catalog_gachas', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function uploadGachaThumbnail(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'upload_thumbnail', 'asset');
        $this->rateLimit($context, $admin, 'upload_thumbnail', 'asset');
        $payload = $this->validateGachaThumbnail($input);
        $storedPath = null;

        try {
            return $this->execute(
                $context,
                $admin,
                'asset',
                'create',
                $idempotencyKey,
                [
                    'file_name' => $payload['file_name'],
                    'mime_type' => $payload['mime_type'],
                    'checksum_sha256' => $payload['checksum_sha256'],
                ],
                201,
                function () use ($payload, &$storedPath): object {
                    $publicId = (string) Str::uuid7();
                    $storedPath = sprintf(
                        'admin-assets/gacha/%s/%s.%s',
                        now()->format('Y/m'),
                        $publicId,
                        $payload['extension']
                    );
                    if (! Storage::disk(config('filesystems.default'))->put(
                        $storedPath,
                        $payload['bytes'],
                        ['ContentType' => $payload['mime_type']]
                    )) {
                        throw new \RuntimeException('Gacha thumbnail storage failed.');
                    }
                    $now = now()->startOfSecond();
                    DB::table('catalog_presentation_assets')->insert([
                        'public_id' => $publicId,
                        'storage_identifier' => $storedPath,
                        'public_path' => '/admin/api/v2/catalog/presentation-assets/'
                            .$publicId.'/content',
                        'checksum_sha256' => $payload['checksum_sha256'],
                        'media_type' => 'image',
                        'mime_type' => $payload['mime_type'],
                        'byte_size' => strlen($payload['bytes']),
                        'alt_text' => $payload['file_name'],
                        'is_public' => true,
                        'revision' => 1,
                        'archived_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return $this->find('catalog_presentation_assets', $publicId, false);
                }
            );
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(config('filesystems.default'))->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function createRankEffect(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'rank_effect');
        $this->rateLimit($context, $admin, 'create', 'rank_effect');
        $payload = $this->validateRankEffect($input, false);
        $storedPath = null;

        try {
            return $this->execute(
                $context,
                $admin,
                'asset',
                'create',
                $idempotencyKey,
                $this->rankEffectIdempotencyPayload($payload),
                201,
                function () use ($payload, &$storedPath): object {
                    $row = $this->storeRankEffectAsset($payload, $storedPath);
                    $this->replaceRankEffectAssignments(
                        (int) $row->id,
                        $payload['asset_type'],
                        $payload['rank_assignments']
                    );

                    return $row;
                },
                true,
                fn (object $row): array => $this->mapRankEffect($row)
            );
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(config('filesystems.default'))->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function updateRankEffect(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', 'rank_effect');
        $this->rateLimit($context, $admin, 'update', 'rank_effect');
        $payload = $this->validateRankEffect($input, true);
        $storedPath = null;

        try {
            return $this->execute(
                $context,
                $admin,
                'asset',
                'update',
                $idempotencyKey,
                ['id' => $publicId, ...$this->rankEffectIdempotencyPayload($payload)],
                200,
                function () use ($publicId, $payload, &$storedPath): object {
                    $current = $this->find('catalog_presentation_assets', $publicId, true);
                    $this->assertMutable($current, $payload['expected_revision']);
                    if (! in_array($current->media_type, ['image', 'video'], true)) {
                        throw $this->validationException();
                    }

                    if ($payload['file'] === null) {
                        if ($current->media_type !== $payload['asset_type']) {
                            throw $this->validationException();
                        }
                        DB::table('catalog_presentation_assets')->where('id', $current->id)->update([
                            'alt_text' => $payload['title'],
                            'is_public' => $payload['is_active'],
                            'revision' => (int) $current->revision + 1,
                            'updated_at' => now()->startOfSecond(),
                        ]);
                        $row = $this->find('catalog_presentation_assets', $publicId, false);
                    } else {
                        $row = $this->storeRankEffectAsset($payload, $storedPath);
                        DB::table('catalog_rank_assets')
                            ->where('presentation_asset_id', $current->id)
                            ->whereIn('usage_type', ['image', 'video'])
                            ->delete();
                    }
                    $this->replaceRankEffectAssignments(
                        (int) $row->id,
                        $payload['asset_type'],
                        $payload['rank_assignments']
                    );

                    return $this->find(
                        'catalog_presentation_assets',
                        (string) $row->public_id,
                        false
                    );
                },
                true,
                fn (object $row): array => $this->mapRankEffect($row)
            );
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(config('filesystems.default'))->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function archiveGacha(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'archive', 'gacha');
        $this->rateLimit($context, $admin, 'archive', 'gacha');
        $payload = $this->validateArchive($input);

        return $this->execute(
            $context,
            $admin,
            'gacha',
            'archive',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($publicId, $payload): object {
                $row = $this->find('catalog_gachas', $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $this->assertGachaHasNoPublishedOrDrawnReference((int) $row->id);
                DB::table('catalog_gachas')->where('id', $row->id)->update([
                    'state' => 'disabled',
                    'archived_at' => now()->startOfSecond(),
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find('catalog_gachas', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function createGachaDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'gacha_version');
        $this->rateLimit($context, $admin, 'create', 'gacha_version');
        $payload = $this->validateGachaVersion($input, false);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'create',
            $idempotencyKey,
            ['gacha_id' => $gachaPublicId, ...$payload],
            201,
            function () use ($gachaPublicId, $payload): object {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $this->assertGachaAvailable($gacha);

                return $this->insertGachaDraft(
                    $gacha,
                    $payload,
                    null
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function cloneGachaDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $sourceVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'clone', 'gacha_version');
        $this->rateLimit($context, $admin, 'clone', 'gacha_version');
        $this->assertFields($input, [], []);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'clone',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'source_version_id' => $sourceVersionPublicId,
            ],
            201,
            function () use ($gachaPublicId, $sourceVersionPublicId): object {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $this->assertGachaAvailable($gacha);
                $source = $this->find('catalog_gacha_versions', $sourceVersionPublicId, true);
                if ((int) $source->gacha_id !== (int) $gacha->id) {
                    throw $this->notFound();
                }
                $payload = [
                    'title' => $source->title,
                    'description' => $source->description,
                    'notices' => $source->notices,
                    'price_points' => (int) $source->price_points,
                    'total_count' => (int) $source->total_count,
                    'presentation_asset_id' => $source->presentation_asset_id === null
                        ? null
                        : DB::table('catalog_presentation_assets')
                            ->where('id', $source->presentation_asset_id)
                            ->value('public_id'),
                    'publish_start_at' => CarbonImmutable::parse(
                        (string) $source->publish_start_at
                    )->toIso8601String(),
                    'publish_end_at' => $source->publish_end_at === null
                        ? null
                        : CarbonImmutable::parse(
                            (string) $source->publish_end_at
                        )->toIso8601String(),
                    'prizes' => DB::table('catalog_gacha_version_prizes as relation')
                        ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
                        ->where('relation.gacha_version_id', $source->id)
                        ->orderBy('relation.sort_order')
                        ->orderBy('relation.id')
                        ->get([
                            'prize.public_id as prize_id',
                            'relation.initial_inventory',
                            'relation.sort_order',
                        ])
                        ->map(fn (object $row): array => [
                            'prize_id' => $row->prize_id,
                            'initial_inventory' => (int) $row->initial_inventory,
                            'sort_order' => (int) $row->sort_order,
                        ])->all(),
                ];

                return $this->insertGachaDraft(
                    $gacha,
                    [
                        ...$this->validateGachaVersion($payload, false),
                        'daily_draw_limit' => (int) ($source->daily_draw_limit ?? 0),
                        'audience_code' => $source->audience_code ?? 'all_users',
                    ],
                    (int) $source->id
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function updateGachaDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        foreach ([
            'version_number',
            'status',
            'published_probability_version_id',
            'published_at',
            'cloned_from_version_id',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                throw new V2CatalogException(
                    'CATALOG_GACHA_VERSION_IDENTITY_IMMUTABLE',
                    409,
                    'Gacha Version identity and publication fields cannot be changed here.'
                );
            }
        }
        $admin = $this->authorize($context, 'update', 'gacha_version');
        $this->rateLimit($context, $admin, 'update', 'gacha_version');
        $payload = $this->validateGachaVersion($input, true);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'update',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'version_id' => $versionPublicId,
                ...$payload,
            ],
            200,
            function () use ($gachaPublicId, $versionPublicId, $payload): object {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $this->assertGachaAvailable($gacha);
                $version = $this->find('catalog_gacha_versions', $versionPublicId, true);
                $this->assertGachaVersionMutable(
                    $version,
                    (int) $gacha->id,
                    $payload['expected_revision']
                );
                $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
                $prizes = $this->resolveGachaPrizes($payload['prizes']);
                DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'notices' => $payload['notices'],
                    'price_points' => $payload['price_points'],
                    'total_count' => $payload['total_count'],
                    'presentation_asset_id' => $asset?->id,
                    'publish_start_at' => $payload['publish_start_at'],
                    'publish_end_at' => $payload['publish_end_at'],
                    'revision' => (int) $version->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                $this->replaceGachaVersionPrizes((int) $version->id, $prizes);

                return $this->find('catalog_gacha_versions', $versionPublicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function archiveGachaDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'archive', 'gacha_version');
        $this->rateLimit($context, $admin, 'archive', 'gacha_version');
        $payload = $this->validateArchive($input);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'discard',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'version_id' => $versionPublicId,
                ...$payload,
            ],
            200,
            function () use ($gachaPublicId, $versionPublicId, $payload): object {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $this->assertGachaAvailable($gacha);
                $version = $this->find('catalog_gacha_versions', $versionPublicId, true);
                $this->assertGachaVersionMutable(
                    $version,
                    (int) $gacha->id,
                    $payload['expected_revision']
                );
                DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
                    'archived_at' => now()->startOfSecond(),
                    'revision' => (int) $version->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find('catalog_gacha_versions', $versionPublicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function createGachaDraftRank(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'gacha_rank');
        $this->rateLimit($context, $admin, 'create', 'gacha_rank');
        $payload = $this->validateGachaDraftRank($input, false);

        return $this->execute(
            $context,
            $admin,
            'rank',
            'create',
            $idempotencyKey,
            ['gacha_id' => $gachaPublicId, 'version_id' => $versionPublicId, ...$payload],
            201,
            function () use ($gachaPublicId, $versionPublicId, $payload): object {
                [$gacha, $version] = $this->editableGachaVersion(
                    $gachaPublicId,
                    $versionPublicId,
                    $payload['expected_version_revision']
                );
                $now = now()->startOfSecond();
                $publicId = (string) Str::uuid7();
                $sortOrder = (int) DB::table('catalog_gacha_version_ranks')
                    ->where('gacha_version_id', $version->id)
                    ->max('sort_order') + 1;
                DB::table('catalog_ranks')->insert([
                    'public_id' => $publicId,
                    'code' => $payload['code'],
                    'display_name' => $payload['name'],
                    'description' => $payload['description'],
                    'sort_order' => $sortOrder,
                    'is_visible' => true,
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $rank = $this->find('catalog_ranks', $publicId, true);
                DB::table('catalog_gacha_version_ranks')->insert([
                    'gacha_version_id' => $version->id,
                    'rank_id' => $rank->id,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->replaceRankAssets(
                    (int) $rank->id,
                    $payload['image_asset_id'],
                    $payload['video_asset_id']
                );
                $this->incrementGachaVersionRevision($version);

                return $this->find('catalog_ranks', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function updateGachaDraftRank(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $rankPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        if (array_key_exists('code', $input)) {
            throw new V2CatalogException(
                'CATALOG_CODE_IMMUTABLE',
                409,
                'Catalog master codes cannot be changed.'
            );
        }
        $admin = $this->authorize($context, 'update', 'gacha_rank');
        $this->rateLimit($context, $admin, 'update', 'gacha_rank');
        $payload = $this->validateGachaDraftRank($input, true);

        return $this->execute(
            $context,
            $admin,
            'rank',
            'update',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'version_id' => $versionPublicId,
                'rank_id' => $rankPublicId,
                ...$payload,
            ],
            200,
            function () use ($gachaPublicId, $versionPublicId, $rankPublicId, $payload): object {
                [, $version] = $this->editableGachaVersion(
                    $gachaPublicId,
                    $versionPublicId,
                    $payload['expected_version_revision']
                );
                $rank = $this->find('catalog_ranks', $rankPublicId, true);
                $this->assertMutable($rank, $payload['expected_revision']);
                $this->assertRankBelongsToVersion((int) $version->id, (int) $rank->id);
                $this->assertNoPublishedReference('catalog_ranks', (int) $rank->id);
                DB::table('catalog_ranks')->where('id', $rank->id)->update([
                    'display_name' => $payload['name'],
                    'description' => $payload['description'],
                    'revision' => (int) $rank->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                $this->replaceRankAssets(
                    (int) $rank->id,
                    $payload['image_asset_id'],
                    $payload['video_asset_id']
                );
                $this->incrementGachaVersionRevision($version);

                return $this->find('catalog_ranks', $rankPublicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function createGachaDraftPrize(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'gacha_prize');
        $this->rateLimit($context, $admin, 'create', 'gacha_prize');
        $payload = $this->validateGachaDraftPrize($input, false);

        return $this->execute(
            $context,
            $admin,
            'prize',
            'create',
            $idempotencyKey,
            ['gacha_id' => $gachaPublicId, 'version_id' => $versionPublicId, ...$payload],
            201,
            function () use ($gachaPublicId, $versionPublicId, $payload): object {
                [, $version] = $this->editableGachaVersion(
                    $gachaPublicId,
                    $versionPublicId,
                    $payload['expected_version_revision']
                );
                $rank = $this->find('catalog_ranks', $payload['rank_id'], true);
                $this->assertRankBelongsToVersion((int) $version->id, (int) $rank->id);
                $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
                if ($asset !== null && $asset->media_type !== 'image') {
                    throw $this->validationException();
                }
                $now = now()->startOfSecond();
                $publicId = (string) Str::uuid7();
                $code = 'prize-'.str_replace('-', '', $publicId);
                DB::table('catalog_prizes')->insert([
                    'public_id' => $publicId,
                    'code' => $code,
                    'rank_id' => $rank->id,
                    'presentation_asset_id' => $asset?->id,
                    'display_name' => $payload['name'],
                    'description' => null,
                    'display_price' => 0,
                    'exchange_points' => $payload['exchange_points'],
                    'cost_price' => $payload['cost_price'],
                    'is_visible' => $payload['is_active'],
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $prize = $this->find('catalog_prizes', $publicId, true);
                $sortOrder = (int) DB::table('catalog_gacha_version_prizes')
                    ->where('gacha_version_id', $version->id)
                    ->max('sort_order') + 1;
                DB::table('catalog_gacha_version_prizes')->insert([
                    'gacha_version_id' => $version->id,
                    'prize_id' => $prize->id,
                    'initial_inventory' => $payload['total_inventory'],
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->incrementGachaVersionRevision($version);

                return $this->find('catalog_prizes', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function updateGachaDraftPrize(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId,
        string $prizePublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', 'gacha_prize');
        $this->rateLimit($context, $admin, 'update', 'gacha_prize');
        $payload = $this->validateGachaDraftPrize($input, true);

        return $this->execute(
            $context,
            $admin,
            'prize',
            'update',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'version_id' => $versionPublicId,
                'prize_id' => $prizePublicId,
                ...$payload,
            ],
            200,
            function () use ($gachaPublicId, $versionPublicId, $prizePublicId, $payload): object {
                [, $version] = $this->editableGachaVersion(
                    $gachaPublicId,
                    $versionPublicId,
                    $payload['expected_version_revision']
                );
                $prize = $this->find('catalog_prizes', $prizePublicId, true);
                $this->assertMutable($prize, $payload['expected_revision']);
                $relation = DB::table('catalog_gacha_version_prizes')
                    ->where('gacha_version_id', $version->id)
                    ->where('prize_id', $prize->id)
                    ->lockForUpdate()
                    ->first();
                if ($relation === null) {
                    throw $this->notFound();
                }
                $rank = $this->find('catalog_ranks', $payload['rank_id'], true);
                $this->assertRankBelongsToVersion((int) $version->id, (int) $rank->id);
                $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
                if ($asset !== null && $asset->media_type !== 'image') {
                    throw $this->validationException();
                }
                $inventory = DB::table('prize_inventories')
                    ->where('gacha_version_prize_id', $relation->id)
                    ->lockForUpdate()
                    ->first();
                if ($inventory !== null && $payload['total_inventory'] < (int) $inventory->won_count) {
                    throw new V2CatalogException(
                        'CATALOG_PRIZE_INVENTORY_CONFLICT',
                        409,
                        'Total inventory cannot be lower than confirmed inventory usage.'
                    );
                }
                $this->assertNoPublishedReference('catalog_prizes', (int) $prize->id);
                DB::table('catalog_prizes')->where('id', $prize->id)->update([
                    'rank_id' => $rank->id,
                    'presentation_asset_id' => $asset?->id,
                    'display_name' => $payload['name'],
                    'exchange_points' => $payload['exchange_points'],
                    'cost_price' => $payload['cost_price'],
                    'is_visible' => $payload['is_active'],
                    'revision' => (int) $prize->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                DB::table('catalog_gacha_version_prizes')->where('id', $relation->id)->update([
                    'initial_inventory' => $payload['total_inventory'],
                    'updated_at' => now()->startOfSecond(),
                ]);
                if ($inventory !== null) {
                    DB::table('prize_inventories')->where('id', $inventory->id)->update([
                        'initial_quantity' => $payload['total_inventory'],
                        'updated_at' => now()->startOfSecond(),
                    ]);
                }
                $this->incrementGachaVersionRevision($version);

                return $this->find('catalog_prizes', $prizePublicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function createProbabilityDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'probability_version');
        $this->rateLimit($context, $admin, 'create', 'probability_version');
        $this->assertFields($input, [], []);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            'create',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
            ],
            201,
            function () use ($gachaPublicId, $gachaVersionPublicId): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );

                return $this->insertProbabilityDraft($gachaVersion, null, []);
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function cloneProbabilityDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $sourcePublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'clone', 'probability_version');
        $this->rateLimit($context, $admin, 'clone', 'probability_version');
        $this->assertFields($input, [], []);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            'clone',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'source_probability_version_id' => $sourcePublicId,
            ],
            201,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $sourcePublicId
            ): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );
                $source = $this->find(
                    'catalog_probability_versions',
                    $sourcePublicId,
                    true
                );
                if (
                    (int) $source->gacha_version_id !== (int) $gachaVersion->id
                    || $source->archived_at !== null
                ) {
                    throw $this->notFound();
                }

                return $this->insertProbabilityDraft(
                    $gachaVersion,
                    (int) $source->id,
                    $this->probabilityStructure((int) $source->id)
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function replaceProbabilityEntries(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', 'probability_version');
        $this->rateLimit($context, $admin, 'update', 'probability_version');
        $payload = $this->validateProbabilityStructure($input);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            'update',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'probability_version_id' => $probabilityVersionPublicId,
                ...$payload,
            ],
            200,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $probabilityVersionPublicId,
                $payload
            ): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );
                $version = $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    true
                );
                $this->assertProbabilityVersionMutable(
                    $version,
                    (int) $gachaVersion->id,
                    $payload['expected_revision']
                );
                $structure = $this->resolveProbabilityStructure(
                    (int) $gachaVersion->id,
                    $payload['stages']
                );
                $this->replaceProbabilityStructure((int) $version->id, $structure);
                DB::table('catalog_probability_versions')
                    ->where('id', $version->id)
                    ->update([
                        'snapshot_sha256' => $this->probabilityChecksum($structure),
                        'revision' => (int) $version->revision + 1,
                        'updated_at' => now()->startOfSecond(),
                    ]);

                return $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    false
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function validateProbabilityDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'validate', 'probability_version');
        $this->rateLimit($context, $admin, 'validate', 'probability_version');
        $this->assertFields($input, ['expected_revision'], ['expected_revision']);
        $expectedRevision = $this->revision($input['expected_revision']);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            'validate',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'probability_version_id' => $probabilityVersionPublicId,
                'expected_revision' => $expectedRevision,
            ],
            200,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $probabilityVersionPublicId,
                $expectedRevision
            ): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );
                $version = $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    true
                );
                $this->assertProbabilityVersionMutable(
                    $version,
                    (int) $gachaVersion->id,
                    $expectedRevision
                );

                return $version;
            },
            false
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightProbabilityPublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeProbabilityPublish(
            $context,
            $gachaPublicId,
            $gachaVersionPublicId,
            $probabilityVersionPublicId,
            $idempotencyKey,
            $input,
            true
        );
    }

    /** @param array<string, mixed> $input */
    public function publishProbabilityDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeProbabilityPublish(
            $context,
            $gachaPublicId,
            $gachaVersionPublicId,
            $probabilityVersionPublicId,
            $idempotencyKey,
            $input,
            false
        );
    }

    /** @param array<string, mixed> $input */
    public function selectPublishedProbability(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'probability_selection',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'probability_selection',
            'gacha_version'
        );
        $this->assertFields(
            $input,
            ['expected_revision', 'probability_version_id'],
            ['expected_revision', 'probability_version_id']
        );
        $expectedRevision = $this->revision($input['expected_revision']);
        $probabilityVersionPublicId = $this->uuid(
            $input['probability_version_id']
        );

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'probability_selection',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'probability_version_id' => $probabilityVersionPublicId,
                'expected_revision' => $expectedRevision,
            ],
            200,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $probabilityVersionPublicId,
                $expectedRevision
            ): object {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $version = $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    true
                );
                $this->assertGachaVersionMutable(
                    $version,
                    (int) $gacha->id,
                    $expectedRevision
                );
                $probability = $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    true
                );
                if (
                    (int) $probability->gacha_version_id !== (int) $version->id
                    || ! $this->publishedProbabilitySnapshotIsValid($probability)
                ) {
                    throw new V2CatalogException(
                        'CATALOG_PROBABILITY_SELECTION_INVALID',
                        422,
                        'The Published Probability Snapshot cannot be selected.'
                    );
                }
                DB::table('catalog_gacha_versions')
                    ->where('id', $version->id)
                    ->update([
                        'published_probability_version_id' => $probability->id,
                        'revision' => (int) $version->revision + 1,
                        'updated_at' => now()->startOfSecond(),
                    ]);

                return $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    false
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightGachaPublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'publish_preflight',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'publish_preflight',
            'gacha_version'
        );
        $this->assertFields($input, ['expected_revision'], ['expected_revision']);
        $expectedRevision = $this->revision($input['expected_revision']);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'gacha_publish_preflight',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'expected_revision' => $expectedRevision,
            ],
            200,
            function () use (
                $context,
                $gachaPublicId,
                $gachaVersionPublicId,
                $expectedRevision
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $version = $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    true
                );
                if ((int) $version->gacha_id !== (int) $gacha->id) {
                    throw $this->notFound();
                }
                if ((int) $version->revision !== $expectedRevision) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog record has changed.'
                    );
                }

                return $this->gachaPublishPreflight(
                    $context->requestId,
                    $gacha,
                    $version
                );
            },
            false,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    public function publishGachaVersionImmediately(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'immediate_publish',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'immediate_publish',
            'gacha_version'
        );
        $this->assertFields(
            $input,
            ['expected_revision', 'expected_gacha_revision'],
            ['expected_revision', 'expected_gacha_revision']
        );
        $expectedRevision = $this->revision($input['expected_revision']);
        $expectedGachaRevision = $this->revision(
            $input['expected_gacha_revision']
        );

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'gacha_immediate_publish',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'expected_revision' => $expectedRevision,
                'expected_gacha_revision' => $expectedGachaRevision,
            ],
            200,
            function () use (
                $context,
                $gachaPublicId,
                $gachaVersionPublicId,
                $expectedRevision,
                $expectedGachaRevision
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                if ((int) $gacha->revision !== $expectedGachaRevision) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog record has changed.'
                    );
                }
                $this->assertNoActivePublishSchedule((int) $gacha->id);

                return $this->activateGachaVersion(
                    $context->requestId,
                    $gacha,
                    $gachaVersionPublicId,
                    $expectedRevision
                );
            },
            true,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightGachaPublishSchedule(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'schedule_preflight',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'schedule_preflight',
            'gacha_version'
        );
        $payload = $this->schedulePayload($input);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'gacha_schedule_preflight',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                ...$payload,
            ],
            200,
            function () use (
                $context,
                $gachaPublicId,
                $gachaVersionPublicId,
                $payload
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $version = $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    true
                );
                $this->assertScheduleRevisions($gacha, $version, $payload);

                return $this->schedulePreflightResult(
                    $context->requestId,
                    $gacha,
                    $version,
                    $payload['scheduled_for']
                );
            },
            false,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    public function scheduleGachaVersionPublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'schedule_create',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'schedule_create',
            'gacha_version'
        );
        $payload = $this->schedulePayload($input);

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'gacha_schedule_create',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                ...$payload,
            ],
            201,
            function () use (
                $context,
                $admin,
                $gachaPublicId,
                $gachaVersionPublicId,
                $payload
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $version = $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    true
                );
                $this->assertScheduleRevisions($gacha, $version, $payload);
                $preflight = $this->schedulePreflightResult(
                    $context->requestId,
                    $gacha,
                    $version,
                    $payload['scheduled_for']
                );
                if (! $preflight['publishable']) {
                    throw $this->gachaScheduleException();
                }
                $probability = DB::table('catalog_probability_versions')
                    ->where('id', $version->published_probability_version_id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $probability === null
                    || ! $this->publishedProbabilitySnapshotIsValid($probability)
                ) {
                    throw $this->gachaScheduleException();
                }
                if (
                    DB::table('catalog_gacha_publish_schedules')
                        ->where('gacha_id', $gacha->id)
                        ->whereIn('status', ['scheduled', 'processing'])
                        ->lockForUpdate()
                        ->first(['id']) !== null
                ) {
                    throw new V2CatalogException(
                        'CATALOG_GACHA_SCHEDULE_CONFLICT',
                        409,
                        'The Gacha already has an active Publish Schedule.'
                    );
                }

                DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                    'revision' => (int) $gacha->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
                    'revision' => (int) $version->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                $publicId = (string) Str::uuid7();
                DB::table('catalog_gacha_publish_schedules')->insert([
                    'public_id' => $publicId,
                    'gacha_id' => $gacha->id,
                    'gacha_version_id' => $version->id,
                    'probability_version_id' => $probability->id,
                    'status' => 'scheduled',
                    'scheduled_for' =>
                        $payload['scheduled_for']->toIso8601String(),
                    'next_attempt_at' =>
                        $payload['scheduled_for']->toIso8601String(),
                    'expected_gacha_revision' => (int) $gacha->revision + 1,
                    'expected_version_revision' => (int) $version->revision + 1,
                    'revision' => 1,
                    'requested_by_admin_id' => $admin->id,
                    'cancelled_by_admin_id' => null,
                    'request_id' => $context->requestId,
                    'attempts' => 0,
                    'locked_at' => null,
                    'locked_by_hash' => null,
                    'lease_expires_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'cancelled_at' => null,
                    'failed_at' => null,
                    'failure_code' => null,
                    'created_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);

                return $this->mapPublishSchedule(
                    DB::table('catalog_gacha_publish_schedules')
                        ->where('public_id', $publicId)
                        ->firstOrFail(),
                    $gachaVersionPublicId,
                    $probability->public_id,
                    $probability->snapshot_sha256,
                    (int) $gacha->revision + 1,
                    (int) $version->revision + 1,
                    $context->requestId
                );
            },
            true,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    public function cancelGachaVersionPublishSchedule(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $schedulePublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorizeCatalogPublish(
            $context,
            'schedule_cancel',
            'gacha_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            'schedule_cancel',
            'gacha_version'
        );
        $this->assertFields(
            $input,
            [
                'expected_schedule_revision',
                'expected_gacha_revision',
                'expected_version_revision',
            ],
            [
                'expected_schedule_revision',
                'expected_gacha_revision',
                'expected_version_revision',
            ]
        );
        $payload = [
            'expected_schedule_revision' =>
                $this->revision($input['expected_schedule_revision']),
            'expected_gacha_revision' =>
                $this->revision($input['expected_gacha_revision']),
            'expected_version_revision' =>
                $this->revision($input['expected_version_revision']),
        ];

        return $this->execute(
            $context,
            $admin,
            'gacha_version',
            'gacha_schedule_cancel',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'schedule_id' => $schedulePublicId,
                ...$payload,
            ],
            200,
            function () use (
                $context,
                $admin,
                $gachaPublicId,
                $gachaVersionPublicId,
                $schedulePublicId,
                $payload
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                $schedule = DB::table('catalog_gacha_publish_schedules')
                    ->where('public_id', $schedulePublicId)
                    ->where('gacha_id', $gacha->id)
                    ->lockForUpdate()
                    ->first();
                $version = $this->find(
                    'catalog_gacha_versions',
                    $gachaVersionPublicId,
                    true
                );
                if (
                    $schedule === null
                    || (int) $schedule->gacha_version_id !== (int) $version->id
                    || $schedule->status !== 'scheduled'
                ) {
                    throw new V2CatalogException(
                        'CATALOG_GACHA_SCHEDULE_CONFLICT',
                        409,
                        'The Publish Schedule cannot be cancelled.'
                    );
                }
                if (
                    (int) $schedule->revision !==
                        $payload['expected_schedule_revision']
                    || (int) $gacha->revision !==
                        $payload['expected_gacha_revision']
                    || (int) $version->revision !==
                        $payload['expected_version_revision']
                ) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog record has changed.'
                    );
                }
                DB::table('catalog_gacha_publish_schedules')
                    ->where('id', $schedule->id)
                    ->update([
                        'status' => 'cancelled',
                        'cancelled_by_admin_id' => $admin->id,
                        'cancelled_at' => DB::raw('CURRENT_TIMESTAMP'),
                        'revision' => (int) $schedule->revision + 1,
                        'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                    ]);
                DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                    'revision' => (int) $gacha->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
                    'revision' => (int) $version->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                $probability = DB::table('catalog_probability_versions')
                    ->where('id', $schedule->probability_version_id)
                    ->firstOrFail();

                return $this->mapPublishSchedule(
                    DB::table('catalog_gacha_publish_schedules')
                        ->where('id', $schedule->id)
                        ->firstOrFail(),
                    $version->public_id,
                    $probability->public_id,
                    $probability->snapshot_sha256,
                    (int) $gacha->revision + 1,
                    (int) $version->revision + 1,
                    $context->requestId
                );
            },
            true,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightGachaSalesPause(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaSalesOperation(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            'pause',
            true
        );
    }

    /** @param array<string, mixed> $input */
    public function pauseGachaSales(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaSalesOperation(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            'pause',
            false
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightGachaSalesResume(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaSalesOperation(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            'resume',
            true
        );
    }

    /** @param array<string, mixed> $input */
    public function resumeGachaSales(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaSalesOperation(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            'resume',
            false
        );
    }

    /** @param array<string, mixed> $input */
    public function preflightGachaUnpublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaUnpublish(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            true
        );
    }

    /** @param array<string, mixed> $input */
    public function unpublishGacha(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        return $this->executeGachaUnpublish(
            $context,
            $gachaPublicId,
            $idempotencyKey,
            $input,
            false
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    private function executeGachaUnpublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input,
        bool $preflight
    ): array {
        $action = $preflight ? 'gacha_unpublish_preflight' : 'gacha_unpublish';
        $admin = $this->authorizeCatalogPublish($context, $action, 'gacha');
        $this->rateLimitCatalogPublish($context, $admin, $action, 'gacha');
        $this->assertFields(
            $input,
            ['expected_gacha_revision'],
            ['expected_gacha_revision']
        );
        $expectedRevision = $this->revision($input['expected_gacha_revision']);

        return $this->execute(
            $context,
            $admin,
            'gacha',
            $action,
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'expected_gacha_revision' => $expectedRevision,
            ],
            200,
            function () use (
                $context,
                $admin,
                $gachaPublicId,
                $expectedRevision,
                $preflight
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                if ((int) $gacha->revision !== $expectedRevision) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog record has changed.'
                    );
                }
                $contextRows = $this->lockGachaSalesContext($gacha);
                $result = $this->gachaUnpublishPreflightResult(
                    $context->requestId,
                    $gacha,
                    $contextRows
                );
                if ($preflight) {
                    return $result;
                }
                if (! $result['allowed']) {
                    throw new V2CatalogException(
                        'CATALOG_GACHA_UNPUBLISH_INVALID',
                        422,
                        'The Gacha cannot be unpublished.'
                    );
                }

                DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                    'published_version_id' => null,
                    'active_draw_state_id' => null,
                    'public_deactivated_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'public_deactivated_by_admin_public_id' => $admin->public_id,
                    'public_deactivation_request_id' => $context->requestId,
                    'revision' => (int) $gacha->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);

                return $this->mapGachaUnpublishState(
                    $this->find('catalog_gachas', $gachaPublicId, false),
                    $context->requestId
                );
            },
            ! $preflight,
            static fn (array $result): array => $result
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool, status: int}
     */
    private function executeGachaSalesOperation(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $idempotencyKey,
        array $input,
        string $operation,
        bool $preflight
    ): array {
        $action = 'sales_'.$operation.($preflight ? '_preflight' : '');
        $admin = $this->authorizeCatalogPublish($context, $action, 'gacha');
        $this->rateLimitCatalogPublish($context, $admin, $action, 'gacha');
        $allowed = $operation === 'pause'
            ? ['expected_gacha_revision', 'reason_code']
            : ['expected_gacha_revision'];
        $this->assertFields($input, $allowed, $allowed);
        $payload = [
            'expected_gacha_revision' =>
                $this->revision($input['expected_gacha_revision']),
            ...($operation === 'pause'
                ? ['reason_code' => $this->gachaSalesPauseReason($input['reason_code'])]
                : []),
        ];

        return $this->execute(
            $context,
            $admin,
            'gacha',
            'gacha_sales_'.$operation.($preflight ? '_preflight' : ''),
            $idempotencyKey,
            ['gacha_id' => $gachaPublicId, ...$payload],
            200,
            function () use (
                $context,
                $admin,
                $gachaPublicId,
                $payload,
                $operation,
                $preflight
            ): array {
                $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
                if ((int) $gacha->revision !== $payload['expected_gacha_revision']) {
                    throw new V2CatalogException(
                        'CATALOG_REVISION_CONFLICT',
                        409,
                        'The Catalog record has changed.'
                    );
                }
                $contextRows = $this->lockGachaSalesContext($gacha);
                $result = $this->gachaSalesPreflightResult(
                    $context->requestId,
                    $gacha,
                    $contextRows,
                    $operation
                );
                if ($preflight) {
                    return $result;
                }
                if (! $result['allowed']) {
                    throw $this->gachaSalesException($operation);
                }

                $nextRevision = (int) $gacha->revision + 1;
                DB::table('catalog_gachas')->where('id', $gacha->id)->update(
                    $operation === 'pause'
                        ? [
                            'sales_paused' => true,
                            'sales_paused_at' => DB::raw('CURRENT_TIMESTAMP'),
                            'sales_paused_by_admin_public_id' => $admin->public_id,
                            'sales_pause_reason_code' => $payload['reason_code'],
                            'sales_resumed_at' => null,
                            'sales_last_mutation_request_id' => $context->requestId,
                            'revision' => $nextRevision,
                            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                        ]
                        : [
                            'sales_paused' => false,
                            'sales_resumed_at' => DB::raw('CURRENT_TIMESTAMP'),
                            'sales_last_mutation_request_id' => $context->requestId,
                            'revision' => $nextRevision,
                            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                        ]
                );
                $schedule = $contextRows['active_schedule'];
                if ($schedule !== null) {
                    DB::table('catalog_gacha_publish_schedules')
                        ->where('id', $schedule->id)
                        ->update([
                            'expected_gacha_revision' => $nextRevision,
                            'revision' => (int) $schedule->revision + 1,
                            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                        ]);
                }

                return $this->mapGachaSalesState(
                    $this->find('catalog_gachas', $gachaPublicId, false),
                    $context->requestId
                );
            },
            ! $preflight,
            static fn (array $result): array => $result
        );
    }

    /** @param array<string, mixed> $input */
    private function executeProbabilityPublish(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input,
        bool $preflight
    ): array {
        $action = $preflight ? 'publish_preflight' : 'publish';
        $admin = $this->authorizeCatalogPublish(
            $context,
            $action,
            'probability_version'
        );
        $this->rateLimitCatalogPublish(
            $context,
            $admin,
            $action,
            'probability_version'
        );
        $this->assertFields($input, ['expected_revision'], ['expected_revision']);
        $expectedRevision = $this->revision($input['expected_revision']);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            $action,
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'probability_version_id' => $probabilityVersionPublicId,
                'expected_revision' => $expectedRevision,
            ],
            200,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $probabilityVersionPublicId,
                $expectedRevision,
                $preflight
            ): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );
                $version = $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    true
                );
                $this->assertProbabilityVersionMutable(
                    $version,
                    (int) $gachaVersion->id,
                    $expectedRevision
                );
                $checksum = $this->validateProbabilityForPublish(
                    $version,
                    $gachaVersion
                );
                if ($preflight) {
                    return $version;
                }

                DB::table('catalog_probability_versions')
                    ->where('id', $version->id)
                    ->update([
                        'status' => 'published',
                        'snapshot_sha256' => $checksum,
                        'published_at' => now()->startOfSecond(),
                        'revision' => (int) $version->revision + 1,
                        'updated_at' => now()->startOfSecond(),
                    ]);

                return $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    false
                );
            },
            ! $preflight
        );
    }

    /** @param array<string, mixed> $input */
    public function archiveProbabilityDraft(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'archive', 'probability_version');
        $this->rateLimit($context, $admin, 'archive', 'probability_version');
        $payload = $this->validateArchive($input);

        return $this->execute(
            $context,
            $admin,
            'probability_version',
            'discard',
            $idempotencyKey,
            [
                'gacha_id' => $gachaPublicId,
                'gacha_version_id' => $gachaVersionPublicId,
                'probability_version_id' => $probabilityVersionPublicId,
                ...$payload,
            ],
            200,
            function () use (
                $gachaPublicId,
                $gachaVersionPublicId,
                $probabilityVersionPublicId,
                $payload
            ): object {
                $gachaVersion = $this->probabilityParent(
                    $gachaPublicId,
                    $gachaVersionPublicId,
                    true
                );
                $version = $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    true
                );
                $this->assertProbabilityVersionMutable(
                    $version,
                    (int) $gachaVersion->id,
                    $payload['expected_revision']
                );
                if (
                    (int) ($gachaVersion->published_probability_version_id ?? 0)
                    === (int) $version->id
                ) {
                    throw new V2CatalogException(
                        'CATALOG_PUBLISHED_REFERENCE_CONFLICT',
                        409,
                        'A Published Gacha Version protects this Probability Version.'
                    );
                }
                DB::table('catalog_probability_versions')
                    ->where('id', $version->id)
                    ->update([
                        'archived_at' => now()->startOfSecond(),
                        'revision' => (int) $version->revision + 1,
                        'updated_at' => now()->startOfSecond(),
                    ]);

                return $this->find(
                    'catalog_probability_versions',
                    $probabilityVersionPublicId,
                    false
                );
            }
        );
    }

    /** @param array<string, mixed> $input */
    private function createPrize(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'prize');
        $this->rateLimit($context, $admin, 'create', 'prize');
        $payload = $this->validatePrizeCreate($input);

        return $this->execute(
            $context,
            $admin,
            'prize',
            'create',
            $idempotencyKey,
            $payload,
            201,
            function () use ($payload): object {
                $now = now()->startOfSecond();
                $rank = $this->resolveReference(
                    'catalog_ranks',
                    $payload['rank_id'],
                    'is_visible'
                );
                $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
                $publicId = (string) Str::uuid7();
                DB::table('catalog_prizes')->insert([
                    'public_id' => $publicId,
                    'code' => $payload['code'],
                    'rank_id' => $rank->id,
                    'presentation_asset_id' => $asset?->id,
                    'display_name' => $payload['name'],
                    'description' => $payload['description'],
                    'display_price' => $payload['display_price'],
                    'exchange_points' => $payload['exchange_points'],
                    'is_visible' => $payload['is_visible'],
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->find('catalog_prizes', $publicId, true);
            }
        );
    }

    /** @param array<string, mixed> $input */
    private function updatePrize(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', 'prize');
        $this->rateLimit($context, $admin, 'update', 'prize');
        if (array_key_exists('code', $input)) {
            throw new V2CatalogException(
                'CATALOG_CODE_IMMUTABLE',
                409,
                'Catalog master codes cannot be changed.'
            );
        }
        $payload = $this->validatePrizeUpdate($input);

        return $this->execute(
            $context,
            $admin,
            'prize',
            'update',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($publicId, $payload): object {
                $row = $this->find('catalog_prizes', $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $rank = $this->resolveReference(
                    'catalog_ranks',
                    $payload['rank_id'],
                    'is_visible'
                );
                $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
                $changes = [
                    'rank_id' => $rank->id,
                    'presentation_asset_id' => $asset?->id,
                    'display_name' => $payload['name'],
                    'description' => $payload['description'],
                    'display_price' => $payload['display_price'],
                    'exchange_points' => $payload['exchange_points'],
                    'is_visible' => $payload['is_visible'],
                ];
                if ($this->changesPublishedPresentation($row, $changes)) {
                    $this->assertNoPublishedReference('catalog_prizes', (int) $row->id);
                }
                DB::table('catalog_prizes')->where('id', $row->id)->update([
                    ...$changes,
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find('catalog_prizes', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    private function createAsset(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'create', 'asset');
        $this->rateLimit($context, $admin, 'create', 'asset');
        $payload = $this->validateAssetCreate($input);

        return $this->execute(
            $context,
            $admin,
            'asset',
            'create',
            $idempotencyKey,
            $payload,
            201,
            function () use ($payload): object {
                $now = now()->startOfSecond();
                $publicId = (string) Str::uuid7();
                DB::table('catalog_presentation_assets')->insert([
                    'public_id' => $publicId,
                    'storage_identifier' => $payload['storage_identifier'],
                    'public_path' => $payload['public_path'],
                    'checksum_sha256' => $payload['checksum_sha256'],
                    'media_type' => $payload['media_type'],
                    'mime_type' => $payload['mime_type'],
                    'byte_size' => $payload['byte_size'],
                    'alt_text' => $payload['alt_text'],
                    'is_public' => $payload['is_public'],
                    'revision' => 1,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $this->find('catalog_presentation_assets', $publicId, true);
            }
        );
    }

    /** @param array<string, mixed> $input */
    private function updateAsset(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'update', 'asset');
        $this->rateLimit($context, $admin, 'update', 'asset');
        foreach ([
            'storage_identifier',
            'public_path',
            'checksum_sha256',
            'media_type',
            'mime_type',
            'byte_size',
        ] as $immutable) {
            if (array_key_exists($immutable, $input)) {
                throw new V2CatalogException(
                    'CATALOG_ASSET_IDENTITY_IMMUTABLE',
                    409,
                    'Presentation Asset object identity cannot be changed.'
                );
            }
        }
        $this->assertFields(
            $input,
            ['expected_revision', 'alt_text', 'is_public'],
            ['expected_revision', 'alt_text', 'is_public']
        );
        $payload = [
            'expected_revision' => $this->revision($input['expected_revision']),
            'alt_text' => $this->nullablePlainText($input['alt_text'], 191),
            'is_public' => $this->boolean($input['is_public']),
        ];

        return $this->execute(
            $context,
            $admin,
            'asset',
            'update',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($publicId, $payload): object {
                $row = $this->find('catalog_presentation_assets', $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $changes = [
                    'alt_text' => $payload['alt_text'],
                    'is_public' => $payload['is_public'],
                ];
                if ($this->changesPublishedPresentation($row, $changes)) {
                    $this->assertNoPublishedReference(
                        'catalog_presentation_assets',
                        (int) $row->id
                    );
                }
                DB::table('catalog_presentation_assets')->where('id', $row->id)->update([
                    ...$changes,
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find('catalog_presentation_assets', $publicId, false);
            }
        );
    }

    /** @param array<string, mixed> $input */
    private function archiveExtendedResource(
        V2AdminAuthorizationContext $context,
        string $resource,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorize($context, 'archive', $resource);
        $this->rateLimit($context, $admin, 'archive', $resource);
        $payload = $this->validateArchive($input);
        $table = $resource === 'prize'
            ? 'catalog_prizes'
            : 'catalog_presentation_assets';
        $visibility = $resource === 'prize' ? 'is_visible' : 'is_public';

        return $this->execute(
            $context,
            $admin,
            $resource,
            'archive',
            $idempotencyKey,
            ['id' => $publicId, ...$payload],
            200,
            function () use ($table, $visibility, $publicId, $payload): object {
                $row = $this->find($table, $publicId, true);
                $this->assertMutable($row, $payload['expected_revision']);
                $this->assertNoPublishedReference($table, (int) $row->id);
                DB::table($table)->where('id', $row->id)->update([
                    $visibility => false,
                    'archived_at' => now()->startOfSecond(),
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);

                return $this->find($table, $publicId, false);
            }
        );
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(): mixed $mutation
     * @param (callable(mixed): array<string, mixed>)|null $mapper
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
        callable $mutation,
        bool $enqueueOutbox = true,
        ?callable $mapper = null
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
                $mutation,
                $enqueueOutbox,
                $mapper
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
                $data = $mapper === null
                    ? $this->map($resource, $row)
                    : $mapper($row);
                $event = 'catalog.master.'.match ($action) {
                    'create' => 'created',
                    'update' => 'updated',
                    'archive' => 'archived',
                    'clone' => 'cloned',
                    'discard' => 'discarded',
                    'validate' => 'validated',
                    'publish_preflight' => 'publish_preflight_completed',
                    'gacha_publish_preflight' =>
                        ($data['publishable'] ?? false)
                            ? 'publish_preflight_completed'
                            : 'publish_preflight_failed',
                    'probability_selection' => 'probability_selected',
                    'gacha_immediate_publish' => 'immediately_published',
                    'gacha_schedule_preflight' =>
                        ($data['publishable'] ?? false)
                            ? 'publish_schedule_preflight_completed'
                            : 'publish_schedule_preflight_failed',
                    'gacha_schedule_create' => 'publish_scheduled',
                    'gacha_schedule_cancel' => 'publish_schedule_cancelled',
                    'gacha_sales_pause_preflight' =>
                        ($data['allowed'] ?? false)
                            ? 'sales_pause_preflight_completed'
                            : 'sales_pause_preflight_failed',
                    'gacha_sales_pause' => 'sales_paused',
                    'gacha_sales_resume_preflight' =>
                        ($data['allowed'] ?? false)
                            ? 'sales_resume_preflight_completed'
                            : 'sales_resume_preflight_failed',
                    'gacha_sales_resume' => 'sales_resumed',
                    'gacha_unpublish_preflight' =>
                        ($data['allowed'] ?? false)
                            ? 'unpublish_preflight_completed'
                            : 'unpublish_preflight_failed',
                    'gacha_unpublish' => 'unpublished',
                    'publish' => 'published',
                    default => throw new \LogicException(
                        'Unsupported Catalog mutation action.'
                    ),
                };
                $targetPublicId = is_string($data['id'] ?? null)
                    ? $data['id']
                    : (is_string($data['gacha_version_id'] ?? null)
                        ? $data['gacha_version_id']
                        : (is_string($data['gacha_id'] ?? null)
                            ? $data['gacha_id']
                            : (is_string($data['sales_state']['gacha_id'] ?? null)
                                ? $data['sales_state']['gacha_id']
                                : (is_string($data['state']['gacha_id'] ?? null)
                                    ? $data['state']['gacha_id']
                                    : null))));
                $preflightBlocked = in_array(
                    $action,
                    [
                        'gacha_publish_preflight',
                        'gacha_schedule_preflight',
                        'gacha_sales_pause_preflight',
                        'gacha_sales_resume_preflight',
                        'gacha_unpublish_preflight',
                    ],
                    true
                ) && ! (
                    str_starts_with($action, 'gacha_sales_')
                        || $action === 'gacha_unpublish_preflight'
                        ? ($data['allowed'] ?? false)
                        : ($data['publishable'] ?? false)
                );
                $this->recordAudit(
                    $event,
                    $context,
                    $admin,
                    $resource,
                    $action,
                    $preflightBlocked ? 'failure' : 'success',
                    $preflightBlocked
                        ? (
                            $action === 'gacha_unpublish_preflight'
                                ? 'unpublish_preflight_blocked'
                                : (str_starts_with($action, 'gacha_sales_')
                                ? 'sales_preflight_blocked'
                                : 'publish_preflight_blocked')
                        )
                        : $action.'_completed',
                    $targetPublicId,
                    [
                        ...isset($data['revision'])
                            ? ['revision' => $data['revision']]
                            : [],
                        ...isset($data['gacha_version_revision'])
                            ? ['revision' => $data['gacha_version_revision']]
                            : [],
                        ...isset($data['publishable'])
                            ? ['publishable' => $data['publishable']]
                            : [],
                        ...isset($data['allowed'])
                            ? ['allowed' => $data['allowed']]
                            : [],
                        ...isset($data['previous_published_version'])
                            ? [
                                'previous_published_version_id' =>
                                    $data['previous_published_version']['id']
                                        ?? null,
                            ]
                            : [],
                    ]
                );
                if ($enqueueOutbox) {
                    $aggregatePublicId = in_array(
                        $action,
                        ['gacha_schedule_create', 'gacha_schedule_cancel'],
                        true
                    )
                        ? ($data['gacha_version_id'] ?? null)
                        : $targetPublicId;
                    $this->outbox->enqueue(
                        'catalog.change',
                        'catalog_'.$resource,
                        $aggregatePublicId ?? throw new \RuntimeException(
                            'Catalog mutation target is unavailable.'
                        ),
                        $event,
                        [
                            'catalog_public_id' => $aggregatePublicId,
                            'catalog_resource' => $resource,
                            'revision' => $data['revision']
                                ?? $data['gacha_version_revision']
                                ?? $data['gacha_revision']
                                ?? null,
                            ...isset($data['id'], $data['gacha_version_id'])
                                ? ['schedule_public_id' => $data['id']]
                                : [],
                            ...isset($data['current_published_version'])
                                ? [
                                    'current_published_version_id' =>
                                        $data['current_published_version']['id'],
                                    'previous_published_version_id' =>
                                        $data['previous_published_version']['id']
                                            ?? null,
                                    'probability_snapshot_sha256' =>
                                        $data['selected_probability']
                                            ['snapshot_sha256'],
                                ]
                                : [],
                        ],
                        'catalog-'.$action.'-'.$claim->record->public_id
                    );
                }
                $this->idempotency->complete(
                    $claim->record,
                    'catalog_'.$resource,
                    $targetPublicId ?? throw new \RuntimeException(
                        'Catalog mutation target is unavailable.'
                    ),
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

    private function authorizeCatalogPublish(
        V2AdminAuthorizationContext $context,
        string $action,
        string $resource
    ): Admin {
        $domain = $resource === 'probability_version'
            ? 'probability'
            : 'gacha';
        try {
            return $this->authorization->authorizePermission(
                $context,
                V2Permission::PublishCatalog,
                true,
                'catalog.'.$resource.'.'.$action
            );
        } catch (V2AuthenticationException $exception) {
            $this->audit->record('catalog.'.$domain.'.publish.authorization_failed', [
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

    private function rateLimitCatalogPublish(
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $action,
        string $resource
    ): void {
        $domain = $resource === 'probability_version'
            ? 'probability'
            : 'gacha';
        try {
            $this->criticalRateLimiter->assertSubject(
                'critical_admin_mutation',
                $admin->public_id
            );
        } catch (V2AuthenticationException $exception) {
            $this->recordAudit(
                $exception->errorCode === 'RATE_LIMITED'
                    ? 'catalog.'.$domain.'.publish.rate_limited'
                    : 'catalog.'.$domain.'.publish.authorization_failed',
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

    /** @return array<string, mixed> */
    private function validateGachaCreate(array $input): array
    {
        $this->assertFields(
            $input,
            ['code', 'slug', 'category_id', 'tag_ids'],
            ['code', 'slug', 'category_id', 'tag_ids']
        );

        return [
            'code' => $this->code($input['code'], 64),
            'slug' => $this->slug($input['slug']),
            'category_id' => $this->uuid($input['category_id']),
            'tag_ids' => $this->uuidList($input['tag_ids'], true),
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaCoreCreate(array $input): array
    {
        $fields = [
            'title',
            'category_id',
            'tag_ids',
            'price_points',
            'total_count',
            'daily_draw_limit',
            'audience_code',
            'presentation_asset_id',
            'publish_start_at',
            'publish_end_at',
            'description',
            'notices',
        ];
        $this->assertFields($input, $fields, $fields);
        $startsAt = $this->timestamp($input['publish_start_at']);
        $endsAt = $input['publish_end_at'] === null
            ? null
            : $this->timestamp($input['publish_end_at']);
        if (
            $endsAt !== null
            && CarbonImmutable::parse($endsAt)->lessThanOrEqualTo(
                CarbonImmutable::parse($startsAt)
            )
        ) {
            throw $this->validationException();
        }
        if (
            ! is_string($input['audience_code'])
            || ! in_array(
                $input['audience_code'],
                ['all_users', 'first_time_users', 'line_users'],
                true
            )
        ) {
            throw $this->validationException();
        }

        return [
            'title' => $this->plainText($input['title'], 1, 191),
            'category_id' => $this->uuid($input['category_id']),
            'tag_ids' => $this->uuidList($input['tag_ids'], true),
            'price_points' => $this->positiveInteger($input['price_points']),
            'total_count' => $this->positiveInteger($input['total_count']),
            'daily_draw_limit' => $this->nonNegativeInteger(
                $input['daily_draw_limit']
            ),
            'audience_code' => $input['audience_code'],
            'presentation_asset_id' => $this->uuid(
                $input['presentation_asset_id']
            ),
            'publish_start_at' => $startsAt,
            'publish_end_at' => $endsAt,
            'description' => $this->nullablePlainText($input['description'], 10000),
            'notices' => $this->nullablePlainText($input['notices'], 10000),
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaUpdate(array $input): array
    {
        $baseFields = ['expected_revision', 'category_id', 'tag_ids'];
        $draftFields = [
            'expected_version_revision', 'title', 'price_points', 'total_count',
            'daily_draw_limit', 'audience_code', 'presentation_asset_id',
            'publish_start_at', 'publish_end_at', 'description', 'notices',
        ];
        $this->assertFields($input, [...$baseFields, ...$draftFields], $baseFields);
        $updatesDraft = count(array_intersect($draftFields, array_keys($input))) > 0;
        if (! $updatesDraft) {
            return [
                'expected_revision' => $this->revision($input['expected_revision']),
                'category_id' => $this->uuid($input['category_id']),
                'tag_ids' => $this->uuidList($input['tag_ids'], true),
                'updates_draft' => false,
            ];
        }
        foreach ($draftFields as $field) {
            if (! array_key_exists($field, $input)) {
                throw $this->validationException();
            }
        }
        $core = $this->validateGachaCoreCreate(array_diff_key(
            $input,
            ['expected_revision' => true, 'expected_version_revision' => true]
        ));

        return [
            ...$core,
            'expected_revision' => $this->revision($input['expected_revision']),
            'expected_version_revision' => $this->revision(
                $input['expected_version_revision']
            ),
            'updates_draft' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaThumbnail(array $input): array
    {
        $this->assertFields(
            $input,
            ['file_name', 'mime_type', 'content_base64'],
            ['file_name', 'mime_type', 'content_base64']
        );
        $fileName = $this->plainText($input['file_name'], 1, 191);
        $declaredMime = $this->plainText($input['mime_type'], 1, 128);
        if (! is_string($input['content_base64'])) {
            throw $this->validationException();
        }
        $bytes = base64_decode($input['content_base64'], true);
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            throw $this->validationException();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extensions = [
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (! is_string($mime) || ! isset($extensions[$mime]) || $mime !== $declaredMime) {
            throw $this->validationException();
        }

        return [
            'file_name' => $fileName,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'extension' => $extensions[$mime],
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    /** @return array<string, mixed> */
    private function validateRankEffect(array $input, bool $updating): array
    {
        $allowed = [
            'title', 'asset_type', 'rank_assignments', 'is_active',
            'file_name', 'mime_type', 'content_base64',
        ];
        $required = ['title', 'asset_type', 'rank_assignments', 'is_active'];
        if ($updating) {
            array_unshift($allowed, 'expected_revision');
            array_unshift($required, 'expected_revision');
        } else {
            array_push($required, 'file_name', 'mime_type', 'content_base64');
        }
        $this->assertFields($input, $allowed, $required);
        $fileFields = array_filter([
            array_key_exists('file_name', $input),
            array_key_exists('mime_type', $input),
            array_key_exists('content_base64', $input),
        ]);
        if (count($fileFields) !== 0 && count($fileFields) !== 3) {
            throw $this->validationException();
        }
        $assetType = $input['asset_type'] ?? null;
        if (! is_string($assetType) || ! in_array($assetType, ['image', 'video'], true)) {
            throw $this->validationException();
        }
        if (! is_array($input['rank_assignments']) || $input['rank_assignments'] === []) {
            throw $this->validationException();
        }
        $rankAssignments = [];
        $rankIds = [];
        foreach ($input['rank_assignments'] as $assignment) {
            if (! is_array($assignment)) {
                throw $this->validationException();
            }
            $this->assertFields($assignment, ['rank_id', 'sort_order'], ['rank_id', 'sort_order']);
            $rankId = $this->uuid($assignment['rank_id']);
            if (isset($rankIds[$rankId])) {
                throw $this->validationException();
            }
            $rankIds[$rankId] = true;
            $rankAssignments[] = [
                'rank_id' => $rankId,
                'sort_order' => $this->sortOrder($assignment['sort_order']),
            ];
        }

        $file = count($fileFields) === 3
            ? $this->validateRankEffectFile($input, $assetType)
            : null;

        return [
            ...($updating ? ['expected_revision' => $this->revision($input['expected_revision'])] : []),
            'title' => $this->plainText($input['title'], 1, 191),
            'asset_type' => $assetType,
            'rank_assignments' => $rankAssignments,
            'is_active' => $this->boolean($input['is_active']),
            'file' => $file,
        ];
    }

    /** @return array<string, mixed> */
    private function rankEffectIdempotencyPayload(array $payload): array
    {
        $file = $payload['file'];

        return [
            ...array_diff_key($payload, ['file' => true]),
            'file' => $file === null ? null : [
                'file_name' => $file['file_name'],
                'mime_type' => $file['mime_type'],
                'checksum_sha256' => $file['checksum_sha256'],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function storeRankEffectAsset(array $payload, ?string &$storedPath): object
    {
        $file = $payload['file'];
        if (! is_array($file)) {
            throw $this->validationException();
        }
        $publicId = (string) Str::uuid7();
        $storedPath = sprintf(
            'admin-assets/rank-effects/%s/%s.%s',
            now()->format('Y/m'),
            $publicId,
            $file['extension']
        );
        if (! Storage::disk(config('filesystems.default'))->put(
            $storedPath,
            $file['bytes'],
            ['ContentType' => $file['mime_type']]
        )) {
            throw new \RuntimeException('Rank effect storage failed.');
        }
        $now = now()->startOfSecond();
        DB::table('catalog_presentation_assets')->insert([
            'public_id' => $publicId,
            'storage_identifier' => $storedPath,
            'public_path' => '/admin/api/v2/catalog/presentation-assets/'
                .$publicId.'/content',
            'checksum_sha256' => $file['checksum_sha256'],
            'media_type' => $payload['asset_type'],
            'mime_type' => $file['mime_type'],
            'byte_size' => strlen($file['bytes']),
            'alt_text' => $payload['title'],
            'is_public' => $payload['is_active'],
            'revision' => 1,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find('catalog_presentation_assets', $publicId, false);
    }

    /** @param list<array{rank_id: string, sort_order: int}> $assignments */
    private function replaceRankEffectAssignments(
        int $assetId,
        string $usageType,
        array $assignments
    ): void {
        DB::table('catalog_rank_assets')
            ->where('presentation_asset_id', $assetId)
            ->whereIn('usage_type', ['image', 'video'])
            ->delete();
        $now = now()->startOfSecond();
        foreach ($assignments as $assignment) {
            $rank = $this->find('catalog_ranks', $assignment['rank_id'], true);
            if ($rank->archived_at !== null) {
                throw $this->validationException();
            }
            DB::table('catalog_rank_assets')->insert([
                'rank_id' => $rank->id,
                'presentation_asset_id' => $assetId,
                'usage_type' => $usageType,
                'sort_order' => $assignment['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validateRankEffectFile(array $input, string $assetType): array
    {
        $fileName = $this->plainText($input['file_name'], 1, 191);
        $declaredMime = $this->plainText($input['mime_type'], 1, 128);
        if (! is_string($input['content_base64'])) {
            throw $this->validationException();
        }
        $bytes = base64_decode($input['content_base64'], true);
        $types = $assetType === 'image'
            ? [
                'image/gif' => 'gif',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ]
            : [
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                'video/quicktime' => 'mov',
            ];
        $maximum = $assetType === 'image' ? 5 * 1024 * 1024 : 50 * 1024 * 1024;
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $maximum) {
            throw $this->validationException();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! isset($types[$mime]) || $mime !== $declaredMime) {
            throw $this->validationException();
        }

        return [
            'file_name' => $fileName,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'extension' => $types[$mime],
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaVersion(array $input, bool $updating): array
    {
        $allowed = [
            'title',
            'description',
            'notices',
            'price_points',
            'total_count',
            'presentation_asset_id',
            'publish_start_at',
            'publish_end_at',
            'prizes',
        ];
        if ($updating) {
            array_unshift($allowed, 'expected_revision');
        }
        $this->assertFields($input, $allowed, $allowed);
        if (! is_array($input['prizes']) || $input['prizes'] === []) {
            throw $this->validationException();
        }
        $prizes = [];
        $prizeIds = [];
        $sortOrders = [];
        foreach ($input['prizes'] as $prize) {
            if (! is_array($prize)) {
                throw $this->validationException();
            }
            $this->assertFields(
                $prize,
                ['prize_id', 'initial_inventory', 'sort_order'],
                ['prize_id', 'initial_inventory', 'sort_order']
            );
            $prizeId = $this->uuid($prize['prize_id']);
            $sortOrder = $this->sortOrder($prize['sort_order']);
            if (isset($prizeIds[$prizeId]) || isset($sortOrders[$sortOrder])) {
                throw $this->validationException();
            }
            $prizeIds[$prizeId] = true;
            $sortOrders[$sortOrder] = true;
            $prizes[] = [
                'prize_id' => $prizeId,
                'initial_inventory' => $this->nonNegativeInteger(
                    $prize['initial_inventory']
                ),
                'sort_order' => $sortOrder,
            ];
        }
        if (count($prizes) > 1000) {
            throw $this->validationException();
        }
        usort(
            $prizes,
            fn (array $left, array $right): int =>
                [$left['sort_order'], $left['prize_id']]
                <=> [$right['sort_order'], $right['prize_id']]
        );
        $startsAt = $this->timestamp($input['publish_start_at']);
        $endsAt = $input['publish_end_at'] === null
            ? null
            : $this->timestamp($input['publish_end_at']);
        if (
            $endsAt !== null
            && CarbonImmutable::parse($endsAt)->lessThanOrEqualTo(
                CarbonImmutable::parse($startsAt)
            )
        ) {
            throw $this->validationException();
        }

        return [
            ...($updating
                ? ['expected_revision' => $this->revision($input['expected_revision'])]
                : []),
            'title' => $this->plainText($input['title'], 1, 191),
            'description' => $this->nullablePlainText($input['description'], 10000),
            'notices' => $this->nullablePlainText($input['notices'], 10000),
            'price_points' => $this->positiveInteger($input['price_points']),
            'total_count' => $this->positiveInteger($input['total_count']),
            'presentation_asset_id' => $this->nullableUuid(
                $input['presentation_asset_id']
            ),
            'publish_start_at' => $startsAt,
            'publish_end_at' => $endsAt,
            'prizes' => $prizes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{expected_revision: int, stages: array<int, array<string, mixed>>}
     */
    private function validateProbabilityStructure(array $input): array
    {
        $this->assertFields(
            $input,
            ['expected_revision', 'stages'],
            ['expected_revision', 'stages']
        );
        if (! is_array($input['stages'])) {
            throw $this->validationException();
        }

        $stages = [];
        $codes = [];
        $previousMaximum = null;
        foreach (array_values($input['stages']) as $stageIndex => $stage) {
            if (! is_array($stage)) {
                throw $this->validationException();
            }
            $this->assertFields(
                $stage,
                [
                    'code',
                    'name',
                    'min_draw_number',
                    'max_draw_number',
                    'entries',
                    'minimum_guarantee',
                ],
                [
                    'code',
                    'name',
                    'min_draw_number',
                    'max_draw_number',
                    'entries',
                    'minimum_guarantee',
                ]
            );
            $code = $this->code($stage['code'], 64);
            if (isset($codes[$code]) || ! is_array($stage['entries'])) {
                throw $this->validationException();
            }
            $codes[$code] = true;
            $minimum = $this->positiveInteger($stage['min_draw_number']);
            $maximum = $stage['max_draw_number'] === null
                ? null
                : $this->positiveInteger($stage['max_draw_number']);
            if (
                ($maximum !== null && $maximum < $minimum)
                || ($stageIndex === 0 && $minimum !== 1)
                || ($stageIndex > 0 && $previousMaximum === null)
                || (
                    $stageIndex > 0
                    && $minimum !== ((int) $previousMaximum + 1)
                )
            ) {
                throw $this->validationException();
            }
            if (
                $stageIndex < count($input['stages']) - 1
                && $maximum === null
            ) {
                throw $this->validationException();
            }

            $entries = [];
            $entryPrizeIds = [];
            foreach (array_values($stage['entries']) as $entry) {
                $target = $this->validateProbabilityTarget($entry);
                if (
                    $target['result_type'] === 'prize'
                    && isset($entryPrizeIds[$target['prize_id']])
                ) {
                    throw new V2CatalogException(
                        'CATALOG_PROBABILITY_DUPLICATE_PRIZE',
                        422,
                        'A Probability Stage cannot contain duplicate Prize entries.'
                    );
                }
                if ($target['result_type'] === 'prize') {
                    $entryPrizeIds[$target['prize_id']] = true;
                }
                $entries[] = [
                    ...$target,
                    'sort_order' => count($entries) * 10 + 10,
                ];
            }
            $guarantee = $stage['minimum_guarantee'] === null
                ? null
                : $this->validateProbabilityTarget($stage['minimum_guarantee']);
            $stages[] = [
                'code' => $code,
                'name' => $this->plainText($stage['name'], 1, 128),
                'condition_type' => 'sold_count',
                'min_draw_number' => $minimum,
                'max_draw_number' => $maximum,
                'sort_order' => $stageIndex * 10 + 10,
                'entries' => $entries,
                'minimum_guarantee' => $guarantee,
            ];
            $previousMaximum = $maximum;
        }

        return [
            'expected_revision' => $this->revision($input['expected_revision']),
            'stages' => $stages,
        ];
    }

    /** @return array<string, mixed> */
    private function validateProbabilityTarget(mixed $target): array
    {
        if (! is_array($target)) {
            throw $this->validationException();
        }
        $this->assertFields(
            $target,
            ['result_type', 'prize_id', 'point_amount', 'probability_ppm'],
            ['result_type', 'prize_id', 'point_amount', 'probability_ppm']
        );
        if (! in_array($target['result_type'], ['prize', 'point_back'], true)) {
            throw $this->validationException();
        }
        $ppm = $this->nonNegativeInteger($target['probability_ppm']);
        if ($ppm > 1000000) {
            throw $this->validationException();
        }
        if ($target['result_type'] === 'prize') {
            if ($target['point_amount'] !== null) {
                throw $this->validationException();
            }

            return [
                'result_type' => 'prize',
                'prize_id' => $this->uuid($target['prize_id']),
                'point_amount' => null,
                'probability_ppm' => $ppm,
            ];
        }
        if ($target['prize_id'] !== null) {
            throw $this->validationException();
        }

        return [
            'result_type' => 'point_back',
            'prize_id' => null,
            'point_amount' => $this->nonNegativeInteger($target['point_amount']),
            'probability_ppm' => $ppm,
        ];
    }

    /** @return array<string, mixed> */
    private function validatePrizeCreate(array $input): array
    {
        $allowed = [
            'code',
            'rank_id',
            'presentation_asset_id',
            'name',
            'description',
            'display_price',
            'exchange_points',
            'is_visible',
        ];
        $this->assertFields($input, $allowed, $allowed);

        return [
            'code' => $this->code($input['code'], 64),
            ...$this->validatePrizeFields($input),
        ];
    }

    /** @return array<string, mixed> */
    private function validatePrizeUpdate(array $input): array
    {
        $allowed = [
            'expected_revision',
            'rank_id',
            'presentation_asset_id',
            'name',
            'description',
            'display_price',
            'exchange_points',
            'is_visible',
        ];
        $this->assertFields($input, $allowed, $allowed);

        return [
            'expected_revision' => $this->revision($input['expected_revision']),
            ...$this->validatePrizeFields($input),
        ];
    }

    /** @return array<string, mixed> */
    private function validatePrizeFields(array $input): array
    {
        return [
            'rank_id' => $this->uuid($input['rank_id']),
            'presentation_asset_id' => $this->nullableUuid(
                $input['presentation_asset_id']
            ),
            'name' => $this->plainText($input['name'], 1, 191),
            'description' => $this->nullablePlainText($input['description'], 2000),
            'display_price' => $this->nonNegativeInteger($input['display_price']),
            'exchange_points' => $this->nonNegativeInteger($input['exchange_points']),
            'is_visible' => $this->boolean($input['is_visible']),
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaDraftRank(array $input, bool $updating): array
    {
        $required = [
            'expected_version_revision',
            'name',
            'description',
            'image_asset_id',
            'video_asset_id',
        ];
        $allowed = $required;
        if ($updating) {
            $required[] = 'expected_revision';
            $allowed[] = 'expected_revision';
        } else {
            $required[] = 'code';
            $allowed[] = 'code';
        }
        $this->assertFields($input, $allowed, $required);

        return [
            ...($updating ? [
                'expected_revision' => $this->revision($input['expected_revision']),
            ] : [
                'code' => $this->code($input['code'], 32),
            ]),
            'expected_version_revision' => $this->revision(
                $input['expected_version_revision']
            ),
            'name' => $this->plainText($input['name'], 1, 128),
            'description' => $this->nullablePlainText($input['description'], 2000),
            'image_asset_id' => $this->nullableUuid($input['image_asset_id']),
            'video_asset_id' => $this->nullableUuid($input['video_asset_id']),
        ];
    }

    /** @return array<string, mixed> */
    private function validateGachaDraftPrize(array $input, bool $updating): array
    {
        $required = [
            'expected_version_revision',
            'rank_id',
            'presentation_asset_id',
            'name',
            'total_inventory',
            'exchange_points',
            'cost_price',
            'is_active',
        ];
        $allowed = $required;
        if ($updating) {
            $required[] = 'expected_revision';
            $allowed[] = 'expected_revision';
        }
        $this->assertFields($input, $allowed, $required);

        return [
            ...($updating ? [
                'expected_revision' => $this->revision($input['expected_revision']),
            ] : []),
            'expected_version_revision' => $this->revision(
                $input['expected_version_revision']
            ),
            'rank_id' => $this->uuid($input['rank_id']),
            'presentation_asset_id' => $this->nullableUuid(
                $input['presentation_asset_id']
            ),
            'name' => $this->plainText($input['name'], 1, 191),
            'total_inventory' => $this->nonNegativeInteger($input['total_inventory']),
            'exchange_points' => $this->nonNegativeInteger($input['exchange_points']),
            'cost_price' => $this->nonNegativeInteger($input['cost_price']),
            'is_active' => $this->boolean($input['is_active']),
        ];
    }

    /** @return array<string, mixed> */
    private function validateAssetCreate(array $input): array
    {
        $allowed = [
            'storage_identifier',
            'public_path',
            'checksum_sha256',
            'media_type',
            'mime_type',
            'byte_size',
            'alt_text',
            'is_public',
        ];
        $this->assertFields($input, $allowed, $allowed);
        $mediaType = $input['media_type'] ?? null;
        $mimeType = $input['mime_type'] ?? null;
        if (
            ! is_string($mediaType)
            || ! in_array($mediaType, ['image', 'video'], true)
            || ! is_string($mimeType)
            || strlen($mimeType) > 128
            || preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/', $mimeType) !== 1
            || ! str_starts_with($mimeType, $mediaType.'/')
        ) {
            throw $this->validationException();
        }

        return [
            'storage_identifier' => $this->storageIdentifier(
                $input['storage_identifier']
            ),
            'public_path' => $this->publicPath($input['public_path']),
            'checksum_sha256' => $this->checksum($input['checksum_sha256']),
            'media_type' => $mediaType,
            'mime_type' => $mimeType,
            'byte_size' => $this->nonNegativeInteger($input['byte_size']),
            'alt_text' => $this->nullablePlainText($input['alt_text'], 191),
            'is_public' => $this->boolean($input['is_public']),
        ];
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

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > PHP_INT_MAX) {
            throw $this->validationException();
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        $number = $this->nonNegativeInteger($value);
        if ($number === 0) {
            throw $this->validationException();
        }

        return $number;
    }

    /** @return list<string> */
    private function uuidList(mixed $value, bool $allowEmpty): array
    {
        if (! is_array($value) || (! $allowEmpty && $value === [])) {
            throw $this->validationException();
        }
        $ids = [];
        foreach ($value as $item) {
            $id = $this->uuid($item);
            if (isset($ids[$id])) {
                throw $this->validationException();
            }
            $ids[$id] = true;
        }
        if (count($ids) > 1000) {
            throw $this->validationException();
        }

        return array_keys($ids);
    }

    private function timestamp(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value) !== 1
        ) {
            throw $this->validationException();
        }
        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (\Throwable) {
            throw $this->validationException();
        }
    }

    private function uuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw $this->validationException();
        }

        return $value;
    }

    private function nullableUuid(mixed $value): ?string
    {
        return $value === null ? null : $this->uuid($value);
    }

    private function checksum(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw $this->validationException();
        }

        return $value;
    }

    private function storageIdentifier(mixed $value): string
    {
        if (
            ! is_string($value)
            || strlen($value) < 1
            || strlen($value) > 512
            || str_contains($value, '..')
            || str_contains($value, '\\')
            || str_starts_with($value, '/')
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]*\z/', $value) !== 1
        ) {
            throw $this->validationException();
        }

        return $value;
    }

    private function publicPath(mixed $value): string
    {
        if (
            ! is_string($value)
            || strlen($value) < 2
            || strlen($value) > 512
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '..')
            || str_contains($value, '\\')
            || preg_match('/[?#\x00-\x20]/', $value) === 1
        ) {
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
            'catalog_prizes' => DB::table('catalog_gacha_version_prizes as gvp')
                ->join('catalog_gacha_versions as gv', 'gv.id', '=', 'gvp.gacha_version_id')
                ->where('gvp.prize_id', $id),
            'catalog_presentation_assets' => DB::query()->fromSub(
                DB::table('catalog_gacha_versions as direct')
                    ->select('direct.id as gacha_version_id')
                    ->where('direct.presentation_asset_id', $id)
                    ->union(
                        DB::table('catalog_prizes as p')
                            ->join(
                                'catalog_gacha_version_prizes as prize_relation',
                                'prize_relation.prize_id',
                                '=',
                                'p.id'
                            )
                            ->select('prize_relation.gacha_version_id')
                            ->where('p.presentation_asset_id', $id)
                    )
                    ->union(
                        DB::table('catalog_rank_assets as ra')
                            ->join('catalog_prizes as rp', 'rp.rank_id', '=', 'ra.rank_id')
                            ->join(
                                'catalog_gacha_version_prizes as rank_relation',
                                'rank_relation.prize_id',
                                '=',
                                'rp.id'
                            )
                            ->select('rank_relation.gacha_version_id')
                            ->where('ra.presentation_asset_id', $id)
                    ),
                'asset_reference'
            )->join(
                'catalog_gacha_versions as gv',
                'gv.id',
                '=',
                'asset_reference.gacha_version_id'
            ),
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

    private function resolveReference(
        string $table,
        string $publicId,
        string $visibilityColumn
    ): object {
        $row = $this->find($table, $publicId, true);
        if (
            ($row->archived_at ?? null) !== null
            || ! (bool) $row->{$visibilityColumn}
        ) {
            throw new V2CatalogException(
                'CATALOG_REFERENCE_INVALID',
                422,
                'The selected Catalog reference is unavailable.'
            );
        }

        return $row;
    }

    /**
     * @param list<string> $publicIds
     * @return list<object>
     */
    private function resolveReferences(
        string $table,
        array $publicIds,
        string $visibilityColumn
    ): array {
        if ($publicIds === []) {
            return [];
        }

        $rowsByPublicId = DB::table($table)
            ->whereIn('public_id', $publicIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');
        if ($rowsByPublicId->count() !== count($publicIds)) {
            throw new V2CatalogException(
                'CATALOG_REFERENCE_INVALID',
                422,
                'The selected Catalog reference is unavailable.'
            );
        }

        return array_map(function (string $publicId) use (
            $rowsByPublicId,
            $visibilityColumn
        ): object {
            $row = $rowsByPublicId->get($publicId);
            if (
                $row === null
                || ($row->archived_at ?? null) !== null
                || ! (bool) $row->{$visibilityColumn}
            ) {
                throw new V2CatalogException(
                    'CATALOG_REFERENCE_INVALID',
                    422,
                    'The selected Catalog reference is unavailable.'
                );
            }

            return $row;
        }, $publicIds);
    }

    private function resolveNullableAsset(?string $publicId): ?object
    {
        return $publicId === null
            ? null
            : $this->resolveReference(
                'catalog_presentation_assets',
                $publicId,
                'is_public'
            );
    }

    /** @param list<object> $tags */
    private function replaceGachaTags(int $gachaId, array $tags): void
    {
        DB::table('catalog_gacha_tags')->where('gacha_id', $gachaId)->delete();
        if ($tags === []) {
            return;
        }
        DB::table('catalog_gacha_tags')->insert(array_map(
            fn (object $tag): array => [
                'gacha_id' => $gachaId,
                'tag_id' => (int) $tag->id,
            ],
            $tags
        ));
    }

    /** @param iterable<object> $tags */
    private function replaceGachaVersionTags(int $versionId, iterable $tags): void
    {
        DB::table('catalog_gacha_version_tags')
            ->where('gacha_version_id', $versionId)->delete();
        $rows = [];
        foreach ($tags as $tag) {
            $rows[] = [
                'gacha_version_id' => $versionId,
                'tag_id' => (int) $tag->id,
            ];
        }
        if ($rows !== []) {
            DB::table('catalog_gacha_version_tags')->insert($rows);
        }
    }

    /**
     * @param list<array{prize_id: string, initial_inventory: int, sort_order: int}> $prizes
     * @return list<array{prize_id: int, initial_inventory: int, sort_order: int}>
     */
    private function resolveGachaPrizes(array $prizes): array
    {
        $resolved = $this->resolveReferences(
            'catalog_prizes',
            array_column($prizes, 'prize_id'),
            'is_visible'
        );
        $prizesByPublicId = [];
        foreach ($resolved as $prize) {
            $prizesByPublicId[$prize->public_id] = $prize;
        }

        return array_map(function (array $relation) use ($prizesByPublicId): array {
            return [
                'prize_id' => (int) $prizesByPublicId[$relation['prize_id']]->id,
                'initial_inventory' => $relation['initial_inventory'],
                'sort_order' => $relation['sort_order'],
            ];
        }, $prizes);
    }

    /**
     * @param list<array{prize_id: int, initial_inventory: int, sort_order: int}> $prizes
     */
    private function replaceGachaVersionPrizes(int $versionId, array $prizes): void
    {
        DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $versionId)
            ->delete();
        $now = now()->startOfSecond();
        DB::table('catalog_gacha_version_prizes')->insert(array_map(
            fn (array $relation): array => [
                'gacha_version_id' => $versionId,
                ...$relation,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $prizes
        ));
        $rankIds = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('relation.gacha_version_id', $versionId)
            ->orderBy('relation.sort_order')
            ->pluck('prize.rank_id')
            ->unique()
            ->values();
        $existingRankIds = DB::table('catalog_gacha_version_ranks')
            ->where('gacha_version_id', $versionId)
            ->pluck('rank_id');
        $nextSortOrder = (int) DB::table('catalog_gacha_version_ranks')
            ->where('gacha_version_id', $versionId)
            ->max('sort_order') + 1;
        foreach ($rankIds->diff($existingRankIds) as $rankId) {
            DB::table('catalog_gacha_version_ranks')->insert([
                'gacha_version_id' => $versionId,
                'rank_id' => $rankId,
                'sort_order' => $nextSortOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array{0: object, 1: object} */
    private function editableGachaVersion(
        string $gachaPublicId,
        string $versionPublicId,
        int $expectedRevision
    ): array {
        $gacha = $this->find('catalog_gachas', $gachaPublicId, true);
        $this->assertGachaAvailable($gacha);
        $version = $this->find('catalog_gacha_versions', $versionPublicId, true);
        $this->assertGachaVersionMutable(
            $version,
            (int) $gacha->id,
            $expectedRevision
        );

        return [$gacha, $version];
    }

    private function assertRankBelongsToVersion(int $versionId, int $rankId): void
    {
        if (! DB::table('catalog_gacha_version_ranks')
            ->where('gacha_version_id', $versionId)
            ->where('rank_id', $rankId)
            ->lockForUpdate()
            ->exists()) {
            throw $this->notFound();
        }
    }

    private function incrementGachaVersionRevision(object $version): void
    {
        DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
            'revision' => (int) $version->revision + 1,
            'updated_at' => now()->startOfSecond(),
        ]);
    }

    private function replaceRankAssets(
        int $rankId,
        ?string $imageAssetPublicId,
        ?string $videoAssetPublicId
    ): void {
        $assets = [];
        foreach ([
            'image' => $imageAssetPublicId,
            'video' => $videoAssetPublicId,
        ] as $mediaType => $assetPublicId) {
            if ($assetPublicId === null) {
                continue;
            }
            $asset = $this->resolveNullableAsset($assetPublicId);
            if ($asset === null || $asset->media_type !== $mediaType) {
                throw $this->validationException();
            }
            $assets[] = ['asset' => $asset, 'usage_type' => $mediaType];
        }
        DB::table('catalog_rank_assets')->where('rank_id', $rankId)->delete();
        $now = now()->startOfSecond();
        foreach ($assets as $asset) {
            DB::table('catalog_rank_assets')->insert([
                'rank_id' => $rankId,
                'presentation_asset_id' => $asset['asset']->id,
                'usage_type' => $asset['usage_type'],
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function assertGachaAvailable(object $gacha): void
    {
        if ($gacha->archived_at !== null || $gacha->state === 'disabled') {
            throw new V2CatalogException(
                'CATALOG_RESOURCE_ARCHIVED',
                409,
                'Archived Gacha records cannot be changed.'
            );
        }
    }

    private function assertGachaHasNoPublishedOrDrawnReference(int $gachaId): void
    {
        $published = DB::table('catalog_gacha_versions')
            ->where('gacha_id', $gachaId)
            ->where('status', 'published')
            ->exists();
        $drawn = DB::table('draw_requests as draw')
            ->join(
                'gacha_draw_states as state',
                'state.id',
                '=',
                'draw.gacha_draw_state_id'
            )
            ->where('state.gacha_id', $gachaId)
            ->exists();
        if ($published || $drawn) {
            throw new V2CatalogException(
                'CATALOG_PUBLISHED_REFERENCE_CONFLICT',
                409,
                'Published or drawn Gacha references protect this record.'
            );
        }
    }

    private function assertGachaVersionMutable(
        object $version,
        int $gachaId,
        int $expectedRevision
    ): void {
        if ((int) $version->gacha_id !== $gachaId) {
            throw $this->notFound();
        }
        if ($version->status !== 'draft') {
            throw new V2CatalogException(
                'CATALOG_GACHA_VERSION_IMMUTABLE',
                409,
                'Published Gacha Versions cannot be changed.'
            );
        }
        $this->assertMutable($version, $expectedRevision);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertGachaDraft(
        object $gacha,
        array $payload,
        ?int $clonedFromVersionId
    ): object {
        $asset = $this->resolveNullableAsset($payload['presentation_asset_id']);
        $prizes = $this->resolveGachaPrizes($payload['prizes']);
        $category = isset($payload['category_id'])
            ? $this->resolveReference('catalog_categories', $payload['category_id'], 'is_visible')
            : DB::table('catalog_categories')->where('id', $gacha->category_id)->firstOrFail();
        $tags = isset($payload['tag_ids'])
            ? $this->resolveReferences('catalog_tags', $payload['tag_ids'], 'is_visible')
            : DB::table('catalog_gacha_tags as relation')
                ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
                ->where('relation.gacha_id', $gacha->id)
                ->get(['tag.id']);
        $versionNumber = (int) DB::table('catalog_gacha_versions')
            ->where('gacha_id', $gacha->id)
            ->max('version_number') + 1;
        $now = now()->startOfSecond();
        $publicId = (string) Str::uuid7();
        $versionId = DB::table('catalog_gacha_versions')->insertGetId([
            'public_id' => $publicId,
            'gacha_id' => $gacha->id,
            'category_id' => $category->id,
            'version_number' => $versionNumber,
            'status' => 'draft',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'notices' => $payload['notices'],
            'price_points' => $payload['price_points'],
            'total_count' => $payload['total_count'],
            'daily_draw_limit' => $payload['daily_draw_limit'] ?? 0,
            'audience_code' => $payload['audience_code'] ?? 'all_users',
            'presentation_asset_id' => $asset?->id,
            'published_probability_version_id' => null,
            'publish_start_at' => $payload['publish_start_at'],
            'publish_end_at' => $payload['publish_end_at'],
            'published_at' => null,
            'revision' => 1,
            'archived_at' => null,
            'cloned_from_version_id' => $clonedFromVersionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->replaceGachaVersionPrizes((int) $versionId, $prizes);
        $this->replaceGachaVersionTags((int) $versionId, $tags);

        return $this->find('catalog_gacha_versions', $publicId, false);
    }

    private function clonePublishedVersionForMasterEdit(object $gacha, object $source): object
    {
        $assetPublicId = $source->presentation_asset_id === null ? null
            : DB::table('catalog_presentation_assets')
                ->where('id', $source->presentation_asset_id)->value('public_id');
        $categoryPublicId = $source->category_id === null
            ? DB::table('catalog_categories')->where('id', $gacha->category_id)->value('public_id')
            : DB::table('catalog_categories')->where('id', $source->category_id)->value('public_id');
        $tagIds = DB::table('catalog_gacha_version_tags as relation')
            ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
            ->where('relation.gacha_version_id', $source->id)
            ->orderBy('tag.public_id')->pluck('tag.public_id')->all();
        if ($tagIds === []) {
            $tagIds = DB::table('catalog_gacha_tags as relation')
                ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
                ->where('relation.gacha_id', $gacha->id)
                ->orderBy('tag.public_id')->pluck('tag.public_id')->all();
        }
        $prizes = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('relation.gacha_version_id', $source->id)
            ->orderBy('relation.sort_order')->orderBy('relation.id')
            ->get(['prize.public_id', 'relation.initial_inventory', 'relation.sort_order'])
            ->map(fn (object $row): array => [
                'prize_id' => $row->public_id,
                'initial_inventory' => (int) $row->initial_inventory,
                'sort_order' => (int) $row->sort_order,
            ])->all();
        $draft = $this->insertGachaDraft($gacha, [
            'category_id' => $categoryPublicId,
            'tag_ids' => $tagIds,
            'title' => $source->title,
            'description' => $source->description,
            'notices' => $source->notices,
            'price_points' => (int) $source->price_points,
            'total_count' => (int) $source->total_count,
            'daily_draw_limit' => (int) ($source->daily_draw_limit ?? 0),
            'audience_code' => $source->audience_code ?? 'all_users',
            'presentation_asset_id' => $assetPublicId,
            'publish_start_at' => (string) $source->publish_start_at,
            'publish_end_at' => $source->publish_end_at,
            'prizes' => $prizes,
        ], (int) $source->id);

        $rankRows = DB::table('catalog_gacha_version_ranks')
            ->where('gacha_version_id', $source->id)
            ->orderBy('sort_order')->get(['rank_id', 'sort_order']);
        foreach ($rankRows as $rank) {
            if (DB::table('catalog_gacha_version_ranks')
                ->where('gacha_version_id', $draft->id)
                ->where('rank_id', $rank->rank_id)->exists()) {
                continue;
            }
            DB::table('catalog_gacha_version_ranks')->insert([
                'gacha_version_id' => $draft->id,
                'rank_id' => $rank->rank_id,
                'sort_order' => $rank->sort_order,
                'created_at' => now()->startOfSecond(),
                'updated_at' => now()->startOfSecond(),
            ]);
        }

        return $draft;
    }

    private function probabilityParent(
        string $gachaPublicId,
        string $gachaVersionPublicId,
        bool $lock
    ): object {
        $gacha = $this->find('catalog_gachas', $gachaPublicId, $lock);
        $this->assertGachaAvailable($gacha);
        $version = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId,
            $lock
        );
        if (
            (int) $version->gacha_id !== (int) $gacha->id
            || $version->archived_at !== null
        ) {
            throw $this->notFound();
        }

        return $version;
    }

    private function assertProbabilityVersionMutable(
        object $version,
        int $gachaVersionId,
        int $expectedRevision
    ): void {
        if ((int) $version->gacha_version_id !== $gachaVersionId) {
            throw $this->notFound();
        }
        if ($version->status !== 'draft') {
            throw new V2CatalogException(
                'CATALOG_PROBABILITY_VERSION_IMMUTABLE',
                409,
                'Published Probability Versions cannot be changed.'
            );
        }
        $this->assertMutable($version, $expectedRevision);
    }

    /**
     * @param array<int, array<string, mixed>> $stages
     * @return array<int, array<string, mixed>>
     */
    private function resolveProbabilityStructure(
        int $gachaVersionId,
        array $stages
    ): array {
        $relations = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('relation.gacha_version_id', $gachaVersionId)
            ->where('prize.is_visible', true)
            ->whereNull('prize.archived_at')
            ->get([
                'relation.id',
                'prize.public_id',
            ])->keyBy('public_id');
        $resolved = [];
        foreach ($stages as $stage) {
            $entries = [];
            foreach ($stage['entries'] as $entry) {
                $entries[] = $this->resolveProbabilityTarget($entry, $relations);
            }
            $resolved[] = [
                ...$stage,
                'entries' => $entries,
                'minimum_guarantee' => $stage['minimum_guarantee'] === null
                    ? null
                    : $this->resolveProbabilityTarget(
                        $stage['minimum_guarantee'],
                        $relations
                    ),
            ];
        }

        return $resolved;
    }

    /** @param \Illuminate\Support\Collection<int, object> $relations */
    private function resolveProbabilityTarget(
        array $target,
        $relations
    ): array {
        if ($target['result_type'] === 'point_back') {
            return [
                ...$target,
                'gacha_version_prize_id' => null,
                'prize_public_id' => null,
            ];
        }
        $relation = $relations->get($target['prize_id']);
        if ($relation === null) {
            throw new V2CatalogException(
                'CATALOG_PROBABILITY_PRIZE_INVALID',
                422,
                'A Probability Prize is unavailable for this Gacha Version.'
            );
        }

        return [
            ...$target,
            'gacha_version_prize_id' => (int) $relation->id,
            'prize_public_id' => $relation->public_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function probabilityStructure(int $probabilityVersionId): array
    {
        return DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (object $stage): array {
                $entries = DB::table('catalog_probability_entries as entry')
                    ->leftJoin(
                        'catalog_gacha_version_prizes as relation',
                        'relation.id',
                        '=',
                        'entry.gacha_version_prize_id'
                    )
                    ->leftJoin(
                        'catalog_prizes as prize',
                        'prize.id',
                        '=',
                        'relation.prize_id'
                    )
                    ->where('entry.probability_stage_id', $stage->id)
                    ->orderBy('entry.sort_order')
                    ->orderBy('entry.id')
                    ->get([
                        'entry.result_type',
                        'entry.gacha_version_prize_id',
                        'entry.point_amount',
                        'entry.probability_ppm',
                        'entry.sort_order',
                        'prize.public_id as prize_public_id',
                    ])->map(fn (object $entry): array => [
                        'result_type' => $entry->result_type,
                        'prize_id' => $entry->prize_public_id,
                        'point_amount' => $entry->point_amount === null
                            ? null
                            : (int) $entry->point_amount,
                        'probability_ppm' => (int) $entry->probability_ppm,
                        'sort_order' => (int) $entry->sort_order,
                        'gacha_version_prize_id' =>
                            $entry->gacha_version_prize_id === null
                                ? null
                                : (int) $entry->gacha_version_prize_id,
                        'prize_public_id' => $entry->prize_public_id,
                    ])->all();
                $guarantee = DB::table('catalog_minimum_guarantees as guarantee')
                    ->leftJoin(
                        'catalog_gacha_version_prizes as relation',
                        'relation.id',
                        '=',
                        'guarantee.gacha_version_prize_id'
                    )
                    ->leftJoin(
                        'catalog_prizes as prize',
                        'prize.id',
                        '=',
                        'relation.prize_id'
                    )
                    ->where('guarantee.probability_stage_id', $stage->id)
                    ->first([
                        'guarantee.result_type',
                        'guarantee.gacha_version_prize_id',
                        'guarantee.point_amount',
                        'guarantee.probability_ppm',
                        'prize.public_id as prize_public_id',
                    ]);

                return [
                    'id' => $stage->public_id,
                    'code' => $stage->code,
                    'name' => $stage->display_name,
                    'condition_type' => $stage->condition_type,
                    'min_draw_number' => (int) $stage->min_draw_number,
                    'max_draw_number' => $stage->max_draw_number === null
                        ? null
                        : (int) $stage->max_draw_number,
                    'sort_order' => (int) $stage->sort_order,
                    'entries' => $entries,
                    'minimum_guarantee' => $guarantee === null ? null : [
                        'result_type' => $guarantee->result_type,
                        'prize_id' => $guarantee->prize_public_id,
                        'point_amount' => $guarantee->point_amount === null
                            ? null
                            : (int) $guarantee->point_amount,
                        'probability_ppm' => (int) $guarantee->probability_ppm,
                        'gacha_version_prize_id' =>
                            $guarantee->gacha_version_prize_id === null
                                ? null
                                : (int) $guarantee->gacha_version_prize_id,
                        'prize_public_id' => $guarantee->prize_public_id,
                    ],
                ];
            })->all();
    }

    /**
     * @param array<int, array<string, mixed>> $stages
     */
    private function replaceProbabilityStructure(
        int $probabilityVersionId,
        array $stages
    ): void {
        $stageIds = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->pluck('id');
        if ($stageIds->isNotEmpty()) {
            DB::table('catalog_minimum_guarantees')
                ->whereIn('probability_stage_id', $stageIds)->delete();
            DB::table('catalog_probability_entries')
                ->whereIn('probability_stage_id', $stageIds)->delete();
            DB::table('catalog_probability_stages')
                ->whereIn('id', $stageIds)->delete();
        }
        $now = now()->startOfSecond();
        foreach ($stages as $stage) {
            $stageId = DB::table('catalog_probability_stages')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'probability_version_id' => $probabilityVersionId,
                'code' => $stage['code'],
                'display_name' => $stage['name'],
                'condition_type' => 'sold_count',
                'min_draw_number' => $stage['min_draw_number'],
                'max_draw_number' => $stage['max_draw_number'],
                'sort_order' => $stage['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($stage['entries'] as $entry) {
                DB::table('catalog_probability_entries')->insert([
                    'probability_stage_id' => $stageId,
                    'result_type' => $entry['result_type'],
                    'gacha_version_prize_id' => $entry['gacha_version_prize_id'],
                    'point_amount' => $entry['point_amount'],
                    'probability_ppm' => $entry['probability_ppm'],
                    'sort_order' => $entry['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($stage['minimum_guarantee'] !== null) {
                $guarantee = $stage['minimum_guarantee'];
                DB::table('catalog_minimum_guarantees')->insert([
                    'probability_stage_id' => $stageId,
                    'result_type' => $guarantee['result_type'],
                    'gacha_version_prize_id' =>
                        $guarantee['gacha_version_prize_id'],
                    'point_amount' => $guarantee['point_amount'],
                    'probability_ppm' => $guarantee['probability_ppm'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $stages
     */
    private function probabilityChecksum(array $stages): string
    {
        $canonical = array_map(function (array $stage): array {
            $target = static fn (array $value): array => [
                'result_type' => $value['result_type'],
                'prize_id' => $value['prize_public_id'] ?? $value['prize_id'] ?? null,
                'point_amount' => $value['point_amount'],
                'probability_ppm' => $value['probability_ppm'],
            ];

            return [
                'code' => $stage['code'],
                'name' => $stage['name'],
                'condition_type' => 'sold_count',
                'min_draw_number' => $stage['min_draw_number'],
                'max_draw_number' => $stage['max_draw_number'],
                'sort_order' => $stage['sort_order'],
                'entries' => array_map(
                    fn (array $entry): array => [
                        ...$target($entry),
                        'sort_order' => $entry['sort_order'],
                    ],
                    $stage['entries']
                ),
                'minimum_guarantee' => $stage['minimum_guarantee'] === null
                    ? null
                    : $target($stage['minimum_guarantee']),
            ];
        }, $stages);

        return hash(
            'sha256',
            json_encode(
                ['stages' => $canonical],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    private function validateProbabilityForPublish(
        object $version,
        object $gachaVersion
    ): string {
        $structure = $this->resolveProbabilityStructure(
            (int) $gachaVersion->id,
            $this->probabilityStructure((int) $version->id)
        );
        $validation = $this->probabilityValidation(
            $structure,
            (int) $gachaVersion->total_count
        );
        $previousMaximum = null;
        foreach ($structure as $index => $stage) {
            if (
                $stage['condition_type'] !== 'sold_count'
                || ($index === 0 && $stage['min_draw_number'] !== 1)
                || ($index > 0 && $previousMaximum === null)
                || (
                    $index > 0
                    && $stage['min_draw_number'] !== ((int) $previousMaximum + 1)
                )
                || (
                    $index < count($structure) - 1
                    && $stage['max_draw_number'] === null
                )
            ) {
                throw $this->probabilityPublishException();
            }
            $previousMaximum = $stage['max_draw_number'];
        }
        if (! $validation['is_valid']) {
            throw $this->probabilityPublishException();
        }

        return $this->probabilityChecksum($structure);
    }

    private function publishedProbabilitySnapshotIsValid(object $version): bool
    {
        if (
            $version->status !== 'published'
            || $version->archived_at !== null
            || ! is_string($version->snapshot_sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $version->snapshot_sha256) !== 1
        ) {
            return false;
        }
        $gachaVersion = DB::table('catalog_gacha_versions')
            ->where('id', $version->gacha_version_id)
            ->first();
        if ($gachaVersion === null) {
            return false;
        }
        try {
            $checksum = $this->validateProbabilityForPublish(
                $version,
                $gachaVersion
            );
        } catch (V2CatalogException) {
            return false;
        }

        return hash_equals($version->snapshot_sha256, $checksum);
    }

    /**
     * @return array{
     *   id: string,
     *   version_number: int,
     *   published_at: ?string,
     *   snapshot_sha256: string,
     *   stage_count: int,
     *   validation_status: string
     * }
     */
    public function mapPublishedProbabilityCandidate(object $version): array
    {
        return [
            'id' => $version->public_id,
            'version_number' => (int) $version->version_number,
            'published_at' => $version->published_at,
            'snapshot_sha256' => $version->snapshot_sha256,
            'stage_count' => DB::table('catalog_probability_stages')
                ->where('probability_version_id', $version->id)
                ->count(),
            'validation_status' => $this->publishedProbabilitySnapshotIsValid($version)
                ? 'valid'
                : 'invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function gachaPublishPreflight(
        string $requestId,
        object $gacha,
        object $version
    ): array {
        $codes = [];
        $blockingReasons = [];
        $block = static function (
            string $code,
            string $message
        ) use (&$codes, &$blockingReasons): void {
            if (in_array($code, $codes, true)) {
                return;
            }
            $codes[] = $code;
            $blockingReasons[] = ['code' => $code, 'message' => $message];
        };

        if ($gacha->state !== 'active' || $gacha->archived_at !== null) {
            $block(
                'GACHA_MASTER_NOT_ACTIVE',
                'The Gacha Master must be active and not archived.'
            );
        }
        if ($version->status !== 'draft') {
            $block(
                'GACHA_VERSION_NOT_DRAFT',
                'Only a Draft Gacha Version can pass publish preflight.'
            );
        }
        if ($version->archived_at !== null) {
            $block(
                'GACHA_VERSION_ARCHIVED',
                'An archived Gacha Version cannot be published.'
            );
        }
        $category = DB::table('catalog_categories')
            ->where('id', $gacha->category_id)
            ->first();
        if (
            $category === null
            || ! $category->is_visible
            || $category->archived_at !== null
        ) {
            $block(
                'GACHA_CATEGORY_UNAVAILABLE',
                'The selected Category is not available.'
            );
        }
        $invalidTagExists = DB::table('catalog_gacha_tags as relation')
            ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
            ->where('relation.gacha_id', $gacha->id)
            ->where(static function ($query): void {
                $query->where('tag.is_visible', false)
                    ->orWhereNotNull('tag.archived_at');
            })
            ->exists();
        if ($invalidTagExists) {
            $block(
                'GACHA_TAG_UNAVAILABLE',
                'A selected Tag is not available.'
            );
        }

        if ($version->presentation_asset_id === null) {
            $block(
                'GACHA_PRESENTATION_ASSET_REQUIRED',
                'A public Presentation Asset is required.'
            );
        } else {
            $asset = DB::table('catalog_presentation_assets')
                ->where('id', $version->presentation_asset_id)
                ->first();
            if (
                $asset === null
                || ! $asset->is_public
                || $asset->archived_at !== null
            ) {
                $block(
                    'GACHA_PRESENTATION_ASSET_UNAVAILABLE',
                    'The Presentation Asset is not publicly available.'
                );
            }
        }

        $prizeRelations = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'prize.presentation_asset_id'
            )
            ->where('relation.gacha_version_id', $version->id)
            ->get([
                'prize.is_visible as prize_is_visible',
                'prize.archived_at as prize_archived_at',
                'rank.is_visible as rank_is_visible',
                'rank.archived_at as rank_archived_at',
                'prize.presentation_asset_id',
                'asset.is_public as asset_is_public',
                'asset.archived_at as asset_archived_at',
            ]);
        if ($prizeRelations->isEmpty()) {
            $block('GACHA_PRIZE_REQUIRED', 'At least one Prize is required.');
        }
        foreach ($prizeRelations as $relation) {
            if (
                ! $relation->prize_is_visible
                || $relation->prize_archived_at !== null
                || ! $relation->rank_is_visible
                || $relation->rank_archived_at !== null
                || (
                    $relation->presentation_asset_id !== null
                    && (
                        ! $relation->asset_is_public
                        || $relation->asset_archived_at !== null
                    )
                )
            ) {
                $block(
                    'GACHA_PRIZE_RELATION_INVALID',
                    'A Prize, Rank, or related Asset is not available.'
                );
                break;
            }
        }

        if (
            ! is_string($version->title)
            || trim($version->title) === ''
            || (int) $version->price_points <= 0
            || (int) $version->total_count <= 0
        ) {
            $block(
                'GACHA_VERSION_VALUES_INVALID',
                'Required Gacha Version values are invalid.'
            );
        }
        if (
            $version->publish_end_at !== null
            && CarbonImmutable::parse((string) $version->publish_end_at)
                ->lessThanOrEqualTo(
                    CarbonImmutable::parse((string) $version->publish_start_at)
                )
        ) {
            $block(
                'GACHA_PUBLICATION_PERIOD_INVALID',
                'The publication period is invalid.'
            );
        }

        $probability = $version->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->first();
        if ($probability === null) {
            $block(
                'GACHA_PROBABILITY_NOT_SELECTED',
                'A Published Probability Snapshot must be selected.'
            );
        } elseif (
            (int) $probability->gacha_version_id !== (int) $version->id
            || ! $this->publishedProbabilitySnapshotIsValid($probability)
        ) {
            $block(
                'GACHA_PROBABILITY_SNAPSHOT_INVALID',
                'The selected Probability Snapshot is incomplete or invalid.'
            );
        }

        $publishable = $blockingReasons === [];

        return [
            'gacha_version_id' => $version->public_id,
            'publishable' => $publishable,
            'selected_probability' => $probability === null
                ? null
                : [
                    'id' => $probability->public_id,
                    'snapshot_sha256' => $probability->snapshot_sha256,
                ],
            'validation_codes' => $publishable
                ? ['GACHA_PUBLISH_PREFLIGHT_READY']
                : $codes,
            'blocking_reasons' => $blockingReasons,
            'gacha_version_revision' => (int) $version->revision,
            'request_id' => $requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function activateClaimedGachaPublishSchedule(
        string $schedulePublicId,
        string $workerHash
    ): array {
        if (
            preg_match('/\A[0-9a-f]{64}\z/', $workerHash) !== 1
            || ! Str::isUuid($schedulePublicId)
        ) {
            throw new \RuntimeException('Scheduled Publish Worker identity is invalid.');
        }

        return DB::transaction(function () use (
            $schedulePublicId,
            $workerHash
        ): array {
            $scheduleIdentity = DB::table('catalog_gacha_publish_schedules')
                ->where('public_id', $schedulePublicId)
                ->first();
            if ($scheduleIdentity === null) {
                throw $this->gachaScheduleException();
            }
            $gacha = DB::table('catalog_gachas')
                ->where('id', $scheduleIdentity->gacha_id)
                ->lockForUpdate()
                ->first();
            $schedule = DB::table('catalog_gacha_publish_schedules')
                ->where('id', $scheduleIdentity->id)
                ->lockForUpdate()
                ->first();
            $databaseNow = $this->databaseNow();
            if (
                $gacha === null
                || $schedule === null
                || $schedule->status !== 'processing'
                || ! hash_equals((string) $schedule->locked_by_hash, $workerHash)
                || CarbonImmutable::parse((string) $schedule->lease_expires_at)
                    ->lessThanOrEqualTo($databaseNow)
                || (int) $gacha->revision !==
                    (int) $schedule->expected_gacha_revision
            ) {
                throw new V2CatalogException(
                    'CATALOG_GACHA_SCHEDULE_CONFLICT',
                    409,
                    'The Publish Schedule Worker claim is no longer valid.'
                );
            }
            $version = DB::table('catalog_gacha_versions')
                ->where('id', $schedule->gacha_version_id)
                ->lockForUpdate()
                ->first();
            if (
                $version === null
                || (int) $version->revision !==
                    (int) $schedule->expected_version_revision
                || (int) $version->published_probability_version_id !==
                    (int) $schedule->probability_version_id
            ) {
                throw $this->gachaScheduleException();
            }

            $result = $this->activateGachaVersion(
                (string) $schedule->request_id,
                $gacha,
                (string) $version->public_id,
                (int) $schedule->expected_version_revision
            );
            DB::table('catalog_gacha_publish_schedules')
                ->where('id', $schedule->id)
                ->update([
                    'status' => 'completed',
                    'locked_at' => null,
                    'locked_by_hash' => null,
                    'lease_expires_at' => null,
                    'completed_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'failure_code' => null,
                    'revision' => (int) $schedule->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            $this->audit->record('catalog.gacha.schedule.publish_succeeded', [
                'request_id' => $schedule->request_id,
                'actor_type' => 'system',
                'target_type' => 'gacha_publish_schedule',
                'target_public_id' => $schedule->public_id,
                'outcome' => 'success',
                'metadata' => [
                    'gacha_version_public_id' => $version->public_id,
                    'attempt' => (int) $schedule->attempts,
                ],
            ]);
            $this->outbox->enqueue(
                'catalog.change',
                'catalog_gacha_version',
                (string) $version->public_id,
                'scheduled_publish_completed',
                [
                    'catalog_public_id' => $version->public_id,
                    'catalog_resource' => 'gacha_version',
                    'revision' => $result['gacha_version_revision'],
                    'current_published_version_id' =>
                        $result['current_published_version']['id'],
                    'previous_published_version_id' =>
                        $result['previous_published_version']['id'] ?? null,
                    'probability_snapshot_sha256' =>
                        $result['selected_probability']['snapshot_sha256'],
                    'schedule_public_id' => $schedule->public_id,
                ],
                'catalog-scheduled-publish-'.$schedule->public_id
            );

            return [
                ...$result,
                'schedule_id' => $schedule->public_id,
                'schedule_status' => 'completed',
            ];
        }, 3);
    }

    private function gachaSalesPauseReason(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, self::GACHA_SALES_PAUSE_REASONS, true)) {
            throw new V2CatalogException(
                'CATALOG_GACHA_SALES_PAUSE_INVALID',
                422,
                'The Gacha Sales Pause request is invalid.'
            );
        }

        return $value;
    }

    /**
     * @return array{
     *   version: ?object,
     *   draw_state: ?object,
     *   probability: ?object,
     *   active_schedule: ?object
     * }
     */
    private function lockGachaSalesContext(object $gacha): array
    {
        $version = $gacha->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $gacha->published_version_id)
                ->lockForUpdate()
                ->first();
        $drawState = $gacha->active_draw_state_id === null
            ? null
            : DB::table('gacha_draw_states')
                ->where('id', $gacha->active_draw_state_id)
                ->lockForUpdate()
                ->first();
        $probability = $version?->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->lockForUpdate()
                ->first();
        $schedule = DB::table('catalog_gacha_publish_schedules')
            ->where('gacha_id', $gacha->id)
            ->whereIn('status', ['scheduled', 'processing'])
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        return [
            'version' => $version,
            'draw_state' => $drawState,
            'probability' => $probability,
            'active_schedule' => $schedule,
        ];
    }

    /**
     * @param array{
     *   version: ?object,
     *   draw_state: ?object,
     *   probability: ?object,
     *   active_schedule: ?object
     * } $contextRows
     * @return array<string, mixed>
     */
    private function gachaSalesPreflightResult(
        string $requestId,
        object $gacha,
        array $contextRows,
        string $operation
    ): array {
        $codes = [];
        $blockingReasons = [];
        $block = static function (
            string $code,
            string $message
        ) use (&$codes, &$blockingReasons): void {
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
                $blockingReasons[] = ['code' => $code, 'message' => $message];
            }
        };
        $version = $contextRows['version'];
        $drawState = $contextRows['draw_state'];
        $probability = $contextRows['probability'];
        $schedule = $contextRows['active_schedule'];

        if ($gacha->state !== 'active' || $gacha->archived_at !== null) {
            $block(
                'GACHA_MASTER_NOT_ACTIVE',
                'The Gacha Master must be active and not archived.'
            );
        }
        if (
            $version === null
            || $version->status !== 'published'
            || $version->archived_at !== null
            || $version->published_at === null
            || (int) $version->gacha_id !== (int) $gacha->id
        ) {
            $block(
                'GACHA_PUBLISHED_VERSION_UNAVAILABLE',
                'A current Published Gacha Version is required.'
            );
        }
        if (
            $drawState === null
            || (int) $drawState->gacha_id !== (int) $gacha->id
            || $version === null
            || (int) $drawState->gacha_version_id !== (int) $version->id
            || $drawState->status !== 'selling'
        ) {
            $block(
                'GACHA_DRAW_STATE_MISMATCH',
                'The active Draw state does not match the Published Gacha Version.'
            );
        }
        if (
            $probability === null
            || $version === null
            || (int) $probability->gacha_version_id !== (int) $version->id
            || (int) $drawState?->probability_version_id !== (int) $probability->id
            || $probability->status !== 'published'
            || $probability->archived_at !== null
            || ! is_string($probability->snapshot_sha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $probability->snapshot_sha256) !== 1
        ) {
            $block(
                'GACHA_PROBABILITY_SNAPSHOT_INVALID',
                'The active Probability Snapshot is invalid.'
            );
        }
        if (
            $version !== null
            && (
                (int) $version->price_points <= 0
                || (int) $version->total_count <= 0
            )
        ) {
            $block(
                'GACHA_SALES_CONFIGURATION_INVALID',
                'The Gacha sales configuration is invalid.'
            );
        }
        if ($operation === 'pause') {
            if ((bool) $gacha->sales_paused) {
                $block('GACHA_ALREADY_PAUSED', 'Gacha Sales is already paused.');
            }
        } else {
            if (! (bool) $gacha->sales_paused) {
                $block('GACHA_NOT_PAUSED', 'Gacha Sales is not paused.');
            }
            if (
                $drawState !== null
                && (int) $drawState->sold_count >= (int) $drawState->total_count
            ) {
                $block(
                    'GACHA_SOLD_OUT',
                    'A sold-out Gacha cannot resume Sales.'
                );
            }
            if (
                $drawState !== null
                && DB::table('prize_inventories')
                    ->where('gacha_draw_state_id', $drawState->id)
                    ->whereColumn('won_count', '>', 'initial_quantity')
                    ->exists()
            ) {
                $block(
                    'GACHA_INVENTORY_INVALID',
                    'The active Prize Inventory is inconsistent.'
                );
            }
            $databaseNow = $this->databaseNow();
            if (
                $version !== null
                && (
                    CarbonImmutable::parse((string) $version->publish_start_at)
                        ->greaterThan($databaseNow)
                    || (
                        $version->publish_end_at !== null
                        && CarbonImmutable::parse((string) $version->publish_end_at)
                            ->lessThanOrEqualTo($databaseNow)
                    )
                )
            ) {
                $block(
                    'GACHA_SALES_PERIOD_INACTIVE',
                    'The Gacha publication period is not active.'
                );
            }
            if ($schedule?->status === 'processing') {
                $block(
                    'GACHA_PUBLISH_ACTIVATION_IN_PROGRESS',
                    'A Scheduled Publish activation is in progress.'
                );
            }
            if (
                $probability !== null
                && ! $this->publishedProbabilitySnapshotIsValid($probability)
            ) {
                $block(
                    'GACHA_PROBABILITY_SNAPSHOT_INVALID',
                    'The active Probability Snapshot is invalid.'
                );
            }
        }

        $allowed = $blockingReasons === [];

        return [
            'operation' => $operation,
            'allowed' => $allowed,
            'validation_codes' => $allowed
                ? [
                    $operation === 'pause'
                        ? 'GACHA_SALES_PAUSE_READY'
                        : 'GACHA_SALES_RESUME_READY',
                ]
                : $codes,
            'blocking_reasons' => $blockingReasons,
            'sales_state' => $this->mapGachaSalesState($gacha, $requestId),
            'request_id' => $requestId,
        ];
    }

    /**
     * @param array{
     *   version: ?object,
     *   draw_state: ?object,
     *   probability: ?object,
     *   active_schedule: ?object
     * } $contextRows
     * @return array<string, mixed>
     */
    private function gachaUnpublishPreflightResult(
        string $requestId,
        object $gacha,
        array $contextRows
    ): array {
        $codes = [];
        $blockingReasons = [];
        $block = static function (
            string $code,
            string $message
        ) use (&$codes, &$blockingReasons): void {
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
                $blockingReasons[] = ['code' => $code, 'message' => $message];
            }
        };
        $version = $contextRows['version'];
        $drawState = $contextRows['draw_state'];
        $probability = $contextRows['probability'];
        $schedule = $contextRows['active_schedule'];

        if ($gacha->state !== 'active' || $gacha->archived_at !== null) {
            $block(
                'GACHA_MASTER_NOT_ACTIVE',
                'The Gacha Master must be active and not archived.'
            );
        }
        if (! (bool) $gacha->sales_paused) {
            $block(
                'GACHA_SALES_PAUSE_REQUIRED',
                'Gacha Sales must be paused before Public deactivation.'
            );
        }
        if (
            $version === null
            || $gacha->published_version_id === null
            || $version->status !== 'published'
            || $version->archived_at !== null
            || $version->published_at === null
            || (int) $version->gacha_id !== (int) $gacha->id
        ) {
            $block(
                'GACHA_PUBLISHED_VERSION_UNAVAILABLE',
                'A current Published Gacha Version is required.'
            );
        }
        if (
            $drawState === null
            || $gacha->active_draw_state_id === null
            || (int) $drawState->gacha_id !== (int) $gacha->id
            || $version === null
            || (int) $drawState->gacha_version_id !== (int) $version->id
            || $drawState->status !== 'selling'
        ) {
            $block(
                'GACHA_DRAW_STATE_MISMATCH',
                'The active Draw state does not match the Published Gacha Version.'
            );
        }
        if (
            $probability === null
            || $version === null
            || (int) $probability->gacha_version_id !== (int) $version->id
            || (int) $drawState?->probability_version_id !== (int) $probability->id
            || $probability->status !== 'published'
            || $probability->archived_at !== null
            || ! $this->publishedProbabilitySnapshotIsValid($probability)
        ) {
            $block(
                'GACHA_PROBABILITY_SNAPSHOT_INVALID',
                'The active Probability Snapshot is invalid.'
            );
        }
        if ($schedule?->status === 'processing') {
            $block(
                'GACHA_PUBLISH_ACTIVATION_IN_PROGRESS',
                'A Scheduled Publish activation is in progress.'
            );
        } elseif ($schedule?->status === 'scheduled') {
            $block(
                'GACHA_FUTURE_PUBLISH_SCHEDULE_EXISTS',
                'Cancel the future Publish Schedule before Public deactivation.'
            );
        }

        $allowed = $blockingReasons === [];

        return [
            'allowed' => $allowed,
            'validation_codes' => $allowed
                ? ['GACHA_UNPUBLISH_READY']
                : $codes,
            'blocking_reasons' => $blockingReasons,
            'state' => $this->mapGachaUnpublishState($gacha, $requestId),
            'request_id' => $requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function mapGachaUnpublishState(
        object $gacha,
        string $requestId
    ): array {
        $salesState = $this->mapGachaSalesState($gacha, $requestId);

        return [
            'gacha_id' => $salesState['gacha_id'],
            'status' => $gacha->published_version_id === null
                ? 'unpublished'
                : 'published',
            'gacha_revision' => $salesState['gacha_revision'],
            'sales_status' => $salesState['status'],
            'deactivated_at' => $gacha->public_deactivated_at,
            'current_published_version' =>
                $salesState['current_published_version'],
            'selected_probability' => $salesState['selected_probability'],
            'draw_state' => $salesState['draw_state'],
            'publish_schedule' => $salesState['publish_schedule'],
            'request_id' => $requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function mapGachaSalesState(object $gacha, string $requestId): array
    {
        $version = $gacha->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $gacha->published_version_id)
                ->first();
        $probability = $version?->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->first();
        $drawState = $gacha->active_draw_state_id === null
            ? null
            : DB::table('gacha_draw_states')
                ->where('id', $gacha->active_draw_state_id)
                ->first();
        $schedule = DB::table('catalog_gacha_publish_schedules as schedule')
            ->join(
                'catalog_gacha_versions as version',
                'version.id',
                '=',
                'schedule.gacha_version_id'
            )
            ->join(
                'catalog_probability_versions as probability',
                'probability.id',
                '=',
                'schedule.probability_version_id'
            )
            ->where('schedule.gacha_id', $gacha->id)
            ->orderByDesc('schedule.id')
            ->first([
                'schedule.*',
                'version.public_id as version_public_id',
                'version.revision as version_revision',
                'probability.public_id as probability_public_id',
                'probability.snapshot_sha256',
            ]);

        return [
            'gacha_id' => $gacha->public_id,
            'status' => (bool) $gacha->sales_paused ? 'paused' : 'selling',
            'gacha_revision' => (int) $gacha->revision,
            'paused_at' => $gacha->sales_paused_at,
            'reason_code' => $gacha->sales_pause_reason_code,
            'resumed_at' => $gacha->sales_resumed_at,
            'current_published_version' => $version === null
                ? null
                : [
                    'id' => $version->public_id,
                    'version_number' => (int) $version->version_number,
                    'status' => $version->status,
                    'published_at' => $version->published_at,
                ],
            'selected_probability' => $probability === null
                ? null
                : [
                    'id' => $probability->public_id,
                    'snapshot_sha256' => $probability->snapshot_sha256,
                ],
            'draw_state' => $drawState === null
                ? null
                : [
                    'status' => $drawState->status,
                    'sold_count' => (int) $drawState->sold_count,
                    'total_count' => (int) $drawState->total_count,
                ],
            'publish_schedule' => $schedule === null
                ? null
                : $this->mapPublishSchedule(
                    $schedule,
                    (string) $schedule->version_public_id,
                    (string) $schedule->probability_public_id,
                    (string) $schedule->snapshot_sha256,
                    (int) $gacha->revision,
                    (int) $schedule->version_revision,
                    $requestId
                ),
            'request_id' => $requestId,
        ];
    }

    private function gachaSalesException(string $operation): V2CatalogException
    {
        return new V2CatalogException(
            $operation === 'pause'
                ? 'CATALOG_GACHA_SALES_PAUSE_INVALID'
                : 'CATALOG_GACHA_SALES_RESUME_INVALID',
            422,
            $operation === 'pause'
                ? 'Gacha Sales cannot be paused.'
                : 'Gacha Sales cannot be resumed.'
        );
    }

    /** @param array<string, mixed> $input */
    private function schedulePayload(array $input): array
    {
        $this->assertFields(
            $input,
            ['scheduled_for', 'expected_revision', 'expected_gacha_revision'],
            ['scheduled_for', 'expected_revision', 'expected_gacha_revision']
        );
        if (
            ! is_string($input['scheduled_for'])
            || preg_match(
                '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T.+(?:Z|[+-][0-9]{2}:[0-9]{2})\z/',
                $input['scheduled_for']
            ) !== 1
        ) {
            throw new V2CatalogException(
                'CATALOG_GACHA_SCHEDULE_INVALID',
                422,
                'The Publish Schedule timestamp is invalid.'
            );
        }
        try {
            $scheduledFor = CarbonImmutable::parse($input['scheduled_for'])
                ->utc()
                ->startOfSecond();
        } catch (\Throwable) {
            throw new V2CatalogException(
                'CATALOG_GACHA_SCHEDULE_INVALID',
                422,
                'The Publish Schedule timestamp is invalid.'
            );
        }

        return [
            'scheduled_for' => $scheduledFor,
            'expected_revision' => $this->revision($input['expected_revision']),
            'expected_gacha_revision' =>
                $this->revision($input['expected_gacha_revision']),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function assertScheduleRevisions(
        object $gacha,
        object $version,
        array $payload
    ): void {
        if (
            (int) $version->gacha_id !== (int) $gacha->id
            || (int) $version->revision !== $payload['expected_revision']
            || (int) $gacha->revision !== $payload['expected_gacha_revision']
        ) {
            throw new V2CatalogException(
                'CATALOG_REVISION_CONFLICT',
                409,
                'The Catalog record has changed.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function schedulePreflightResult(
        string $requestId,
        object $gacha,
        object $version,
        CarbonImmutable $scheduledFor
    ): array {
        $preflight = $this->gachaPublishPreflight(
            $requestId,
            $gacha,
            $version
        );
        $blocking = $preflight['blocking_reasons'];
        $codes = $preflight['validation_codes'];
        $add = static function (
            string $code,
            string $message
        ) use (&$blocking, &$codes): void {
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
                $blocking[] = ['code' => $code, 'message' => $message];
            }
        };
        $databaseNow = $this->databaseNow();
        if ($scheduledFor->lessThanOrEqualTo($databaseNow)) {
            $add(
                'GACHA_SCHEDULE_NOT_FUTURE',
                'The Publish Schedule must use a future DB Server timestamp.'
            );
        }
        if (
            CarbonImmutable::parse((string) $version->publish_start_at)
                ->greaterThan($scheduledFor)
            || (
                $version->publish_end_at !== null
                && CarbonImmutable::parse((string) $version->publish_end_at)
                    ->lessThanOrEqualTo($scheduledFor)
            )
        ) {
            $add(
                'GACHA_SCHEDULE_OUTSIDE_PUBLICATION_PERIOD',
                'The Publish Schedule must be inside the publication period.'
            );
        }
        if (
            DB::table('catalog_gacha_publish_schedules')
                ->where('gacha_id', $gacha->id)
                ->whereIn('status', ['scheduled', 'processing'])
                ->exists()
        ) {
            $add(
                'GACHA_ACTIVE_SCHEDULE_EXISTS',
                'The Gacha already has an active Publish Schedule.'
            );
        }
        $publishable = $blocking === [];

        return [
            ...$preflight,
            'publishable' => $publishable,
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'server_timezone' => 'UTC',
            'display_timezone' => (string) config('v2_catalog.timezone'),
            'validation_codes' => $publishable
                ? ['GACHA_SCHEDULE_PREFLIGHT_READY']
                : $codes,
            'blocking_reasons' => $blocking,
        ];
    }

    /** @return array<string, mixed> */
    private function activateGachaVersion(
        string $requestId,
        object $gacha,
        string $gachaVersionPublicId,
        int $expectedRevision
    ): array {
        $previousVersion = $gacha->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $gacha->published_version_id)
                ->lockForUpdate()
                ->first();
        $version = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId,
            true
        );
        if (
            (int) $version->gacha_id !== (int) $gacha->id
            || (int) $version->revision !== $expectedRevision
        ) {
            throw new V2CatalogException(
                'CATALOG_REVISION_CONFLICT',
                409,
                'The Catalog record has changed.'
            );
        }
        $probability = $version->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->lockForUpdate()
                ->first();
        if ($probability === null) {
            throw $this->gachaPublishException();
        }
        $preflight = $this->gachaPublishPreflight(
            $requestId,
            $gacha,
            $version
        );
        $databaseNow = $this->databaseNow();
        if (
            ! ($preflight['publishable'] ?? false)
            || CarbonImmutable::parse((string) $version->publish_start_at)
                ->greaterThan($databaseNow)
            || (
                $version->publish_end_at !== null
                && CarbonImmutable::parse((string) $version->publish_end_at)
                    ->lessThanOrEqualTo($databaseNow)
            )
            || ! $this->publishedProbabilitySnapshotIsValid($probability)
        ) {
            throw $this->gachaPublishException();
        }

        DB::table('catalog_gacha_versions')->where('id', $version->id)->update([
            'status' => 'published',
            'published_at' => DB::raw('CURRENT_TIMESTAMP'),
            'revision' => (int) $version->revision + 1,
            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
        $drawStateId = DB::table('gacha_draw_states')->insertGetId([
            'gacha_id' => $gacha->id,
            'gacha_version_id' => $version->id,
            'probability_version_id' => $probability->id,
            'status' => 'selling',
            'total_count' => $version->total_count,
            'sold_count' => 0,
            'lock_version' => 0,
            'started_at' => DB::raw('CURRENT_TIMESTAMP'),
            'sold_out_at' => null,
            'created_at' => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
        $inventoryRows = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $version->id)
            ->orderBy('id')
            ->get(['id', 'initial_inventory'])
            ->map(static fn (object $relation): array => [
                'gacha_draw_state_id' => $drawStateId,
                'gacha_version_prize_id' => $relation->id,
                'initial_quantity' => $relation->initial_inventory,
                'won_count' => 0,
                'lock_version' => 0,
                'created_at' => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ])->all();
        if ($inventoryRows === []) {
            throw $this->gachaPublishException();
        }
        DB::table('prize_inventories')->insert($inventoryRows);
        DB::table('catalog_gachas')->where('id', $gacha->id)->update([
            'published_version_id' => $version->id,
            'active_draw_state_id' => $drawStateId,
            'sold_count' => 0,
            'revision' => (int) $gacha->revision + 1,
            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
        $published = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId,
            false
        );

        return [
            'gacha_version_id' => $published->public_id,
            'status' => $published->status,
            'published_at' => $published->published_at,
            'gacha_version_revision' => (int) $published->revision,
            'gacha_revision' => (int) $gacha->revision + 1,
            'selected_probability' => [
                'id' => $probability->public_id,
                'snapshot_sha256' => $probability->snapshot_sha256,
            ],
            'previous_published_version' => $previousVersion === null
                ? null
                : [
                    'id' => $previousVersion->public_id,
                    'version_number' => (int) $previousVersion->version_number,
                ],
            'current_published_version' => [
                'id' => $published->public_id,
                'version_number' => (int) $published->version_number,
            ],
            'draw_state' => [
                'status' => 'selling',
                'sold_count' => 0,
                'total_count' => (int) $published->total_count,
            ],
            'request_id' => $requestId,
        ];
    }

    private function assertNoActivePublishSchedule(int $gachaId): void
    {
        if (
            DB::table('catalog_gacha_publish_schedules')
                ->where('gacha_id', $gachaId)
                ->whereIn('status', ['scheduled', 'processing'])
                ->lockForUpdate()
                ->first(['id']) !== null
        ) {
            throw new V2CatalogException(
                'CATALOG_GACHA_SCHEDULE_CONFLICT',
                409,
                'An active Publish Schedule conflicts with Immediate Publish.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function mapPublishSchedule(
        object $schedule,
        string $versionPublicId,
        string $probabilityPublicId,
        string $snapshotSha256,
        int $gachaRevision,
        int $versionRevision,
        string $requestId
    ): array {
        return [
            'id' => $schedule->public_id,
            'status' => $schedule->status,
            'scheduled_for' => CarbonImmutable::parse(
                (string) $schedule->scheduled_for
            )->utc()->toIso8601String(),
            'next_attempt_at' => CarbonImmutable::parse(
                (string) $schedule->next_attempt_at
            )->utc()->toIso8601String(),
            'server_timezone' => 'UTC',
            'display_timezone' => (string) config('v2_catalog.timezone'),
            'gacha_version_id' => $versionPublicId,
            'selected_probability' => [
                'id' => $probabilityPublicId,
                'snapshot_sha256' => $snapshotSha256,
            ],
            'attempts' => (int) $schedule->attempts,
            'failure_code' => $schedule->failure_code,
            'revision' => (int) $schedule->revision,
            'gacha_revision' => $gachaRevision,
            'gacha_version_revision' => $versionRevision,
            'started_at' => $schedule->started_at,
            'completed_at' => $schedule->completed_at,
            'cancelled_at' => $schedule->cancelled_at,
            'failed_at' => $schedule->failed_at,
            'request_id' => $requestId,
        ];
    }

    private function databaseNow(): CarbonImmutable
    {
        $value = DB::selectOne(
            'SELECT CURRENT_TIMESTAMP AS occurred_at'
        )?->occurred_at;
        if (! is_string($value)) {
            throw new \RuntimeException('DB Server timestamp is unavailable.');
        }

        return CarbonImmutable::parse($value);
    }

    private function probabilityPublishException(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_PROBABILITY_PUBLISH_INVALID',
            422,
            'The Probability Draft cannot be published.'
        );
    }

    private function gachaPublishException(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_GACHA_PUBLISH_INVALID',
            422,
            'The Gacha Draft cannot be published immediately.'
        );
    }

    private function gachaScheduleException(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_GACHA_SCHEDULE_INVALID',
            422,
            'The Gacha Draft cannot be scheduled for Publish.'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $stages
     */
    private function insertProbabilityDraft(
        object $gachaVersion,
        ?int $clonedFromVersionId,
        array $stages
    ): object {
        $versionNumber = (int) DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $gachaVersion->id)
            ->max('version_number') + 1;
        $now = now()->startOfSecond();
        $publicId = (string) Str::uuid7();
        $versionId = DB::table('catalog_probability_versions')->insertGetId([
            'public_id' => $publicId,
            'gacha_version_id' => $gachaVersion->id,
            'version_number' => $versionNumber,
            'status' => 'draft',
            'snapshot_sha256' => $this->probabilityChecksum($stages),
            'published_at' => null,
            'revision' => 1,
            'archived_at' => null,
            'cloned_from_probability_version_id' => $clonedFromVersionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($stages !== []) {
            $this->replaceProbabilityStructure((int) $versionId, $stages);
        }

        return $this->find('catalog_probability_versions', $publicId, false);
    }

    private function find(string $table, string $publicId, bool $lock): object
    {
        $isGachaCode = $table === 'catalog_gachas'
            && preg_match('/\A[A-Za-z0-9]{11}\z/', $publicId) === 1;
        if (! $isGachaCode && ! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $query = DB::table($table)->where(
            $isGachaCode ? 'public_code' : 'public_id',
            $publicId
        );
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
        if ($resource === 'gacha') {
            return $this->mapGacha($row);
        }
        if ($resource === 'gacha_version') {
            return $this->mapGachaVersion($row);
        }
        if ($resource === 'probability_version') {
            return $this->mapProbabilityVersion($row);
        }
        if ($resource === 'prize') {
            return $this->mapPrize($row);
        }
        if ($resource === 'asset') {
            return $this->mapAsset($row);
        }

        $rankAssets = $resource === 'rank'
            ? DB::table('catalog_rank_assets as relation')
                ->join(
                    'catalog_presentation_assets as asset',
                    'asset.id',
                    '=',
                    'relation.presentation_asset_id'
                )
                ->where('relation.rank_id', $row->id)
                ->whereIn('relation.usage_type', ['image', 'video'])
                ->get([
                    'relation.usage_type',
                    'asset.public_id',
                    'asset.media_type',
                    'asset.mime_type',
                    'asset.alt_text',
                    'asset.public_path',
                    'asset.is_public',
                ])->keyBy('usage_type')
            : collect();

        return [
            'id' => $row->public_id,
            'code' => $row->code,
            ...($resource !== 'rank' ? ['slug' => $row->slug] : []),
            'name' => $row->display_name,
            ...($resource === 'category' ? ['description' => $row->description] : []),
            ...($resource === 'rank' ? [
                'description' => $row->description,
                'image_asset' => $this->mapRankAsset($rankAssets->get('image')),
                'video_asset' => $this->mapRankAsset($rankAssets->get('video')),
            ] : []),
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
    private function mapGacha(object $row): array
    {
        $publishedVersion = $row->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $row->published_version_id)
                ->first();
        $currentVersion = DB::table('catalog_gacha_versions')
            ->where('gacha_id', $row->id)
            ->where('status', 'draft')
            ->whereNull('archived_at')
            ->orderByDesc('version_number')
            ->first() ?? $publishedVersion;
        $category = DB::table('catalog_categories')
            ->where('id', $currentVersion?->category_id ?? $row->category_id)
            ->firstOrFail();
        $versionTags = $currentVersion === null ? collect() : DB::table(
            'catalog_gacha_version_tags as relation'
        )
            ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
            ->where('relation.gacha_version_id', $currentVersion->id)
            ->orderBy('tag.sort_order')->orderBy('tag.public_id')
            ->get(['tag.public_id', 'tag.code', 'tag.display_name']);
        $tags = ($versionTags->isNotEmpty() ? $versionTags : DB::table('catalog_gacha_tags as relation')
            ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
            ->where('relation.gacha_id', $row->id)
            ->orderBy('tag.sort_order')
            ->orderBy('tag.public_id')
            ->get([
                'tag.public_id',
                'tag.code',
                'tag.display_name',
            ]))->map(fn (object $tag): array => [
                'id' => $tag->public_id,
                'code' => $tag->code,
                'name' => $tag->display_name,
            ])->all();
        $hasActiveSchedule = DB::table('catalog_gacha_publish_schedules')
            ->where('gacha_id', $row->id)
            ->whereIn('status', ['scheduled', 'processing'])
            ->exists();
        $publicationStatus = match (true) {
            $row->archived_at !== null,
            $row->public_deactivated_at !== null && $publishedVersion === null => 'unpublished',
            $publishedVersion !== null && (bool) ($row->sales_paused ?? false) => 'sales_paused',
            $publishedVersion !== null => 'published',
            $hasActiveSchedule => 'scheduled',
            default => 'draft',
        };

        return [
            'id' => $row->public_id,
            'public_code' => $row->public_code,
            'code' => $row->code,
            'slug' => $row->slug,
            'state' => $row->state,
            'sold_count' => (int) $row->sold_count,
            'category' => [
                'id' => $category->public_id,
                'code' => $category->code,
                'name' => $category->display_name,
            ],
            'tags' => $tags,
            'published_version' => $publishedVersion === null ? null : [
                'id' => $publishedVersion->public_id,
                'version_number' => (int) $publishedVersion->version_number,
                'status' => $publishedVersion->status,
                'title' => $publishedVersion->title,
            ],
            'current_version' => $currentVersion === null
                ? null
                : $this->mapGachaCoreVersion($currentVersion),
            'publication_status' => $publicationStatus,
            'version_count' => DB::table('catalog_gacha_versions')
                ->where('gacha_id', $row->id)->count(),
            'has_draw_history' => DB::table('draw_requests as draw')
                ->join(
                    'gacha_draw_states as state',
                    'state.id',
                    '=',
                    'draw.gacha_draw_state_id'
                )
                ->where('state.gacha_id', $row->id)
                ->exists(),
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function mapGachaCoreVersion(object $row): array
    {
        $asset = $row->presentation_asset_id === null
            ? null
            : DB::table('catalog_presentation_assets')
                ->where('id', $row->presentation_asset_id)
                ->first();

        return [
            'id' => $row->public_id,
            'version_number' => (int) $row->version_number,
            'status' => $row->status,
            'title' => $row->title,
            'description' => $row->description,
            'notices' => $row->notices,
            'price_points' => (int) $row->price_points,
            'total_count' => (int) $row->total_count,
            'daily_draw_limit' => (int) ($row->daily_draw_limit ?? 0),
            'audience_code' => $row->audience_code ?? 'all_users',
            'presentation_asset' => $asset === null ? null : [
                'id' => $asset->public_id,
                'media_type' => $asset->media_type,
                'mime_type' => $asset->mime_type,
                'alt_text' => $asset->alt_text,
                'public_path' => $asset->is_public ? $asset->public_path : null,
                'is_public' => (bool) $asset->is_public,
            ],
            'publish_start_at' => $row->publish_start_at,
            'publish_end_at' => $row->publish_end_at,
            'revision' => (int) $row->revision,
        ];
    }

    /** @return array<string, mixed> */
    private function mapGachaVersion(object $row): array
    {
        $asset = $row->presentation_asset_id === null
            ? null
            : DB::table('catalog_presentation_assets')
                ->where('id', $row->presentation_asset_id)
                ->firstOrFail();
        $probability = $row->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $row->published_probability_version_id)
                ->firstOrFail();
        $clonedFrom = $row->cloned_from_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $row->cloned_from_version_id)
                ->firstOrFail();
        $prizes = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->where('relation.gacha_version_id', $row->id)
            ->orderBy('relation.sort_order')
            ->orderBy('relation.id')
            ->get([
                'prize.public_id as prize_id',
                'prize.code as prize_code',
                'prize.display_name as prize_name',
                'rank.public_id as rank_id',
                'rank.code as rank_code',
                'rank.display_name as rank_name',
                'relation.initial_inventory',
                'relation.sort_order',
            ])->map(fn (object $relation): array => [
                'prize' => [
                    'id' => $relation->prize_id,
                    'code' => $relation->prize_code,
                    'name' => $relation->prize_name,
                    'rank' => [
                        'id' => $relation->rank_id,
                        'code' => $relation->rank_code,
                        'name' => $relation->rank_name,
                    ],
                ],
                'initial_inventory' => (int) $relation->initial_inventory,
                'sort_order' => (int) $relation->sort_order,
            ])->all();

        return [
            'id' => $row->public_id,
            'version_number' => (int) $row->version_number,
            'status' => $row->status,
            'title' => $row->title,
            'description' => $row->description,
            'notices' => $row->notices,
            'price_points' => (int) $row->price_points,
            'total_count' => (int) $row->total_count,
            'daily_draw_limit' => (int) ($row->daily_draw_limit ?? 0),
            'audience_code' => $row->audience_code ?? 'all_users',
            'presentation_asset' => $asset === null ? null : [
                'id' => $asset->public_id,
                'media_type' => $asset->media_type,
                'mime_type' => $asset->mime_type,
                'alt_text' => $asset->alt_text,
                'public_path' => $asset->is_public ? $asset->public_path : null,
                'is_public' => (bool) $asset->is_public,
            ],
            'published_probability_version' => $probability === null ? null : [
                'id' => $probability->public_id,
                'version_number' => (int) $probability->version_number,
                'status' => $probability->status,
            ],
            'cloned_from_version' => $clonedFrom === null ? null : [
                'id' => $clonedFrom->public_id,
                'version_number' => (int) $clonedFrom->version_number,
            ],
            'publish_start_at' => $row->publish_start_at,
            'publish_end_at' => $row->publish_end_at,
            'published_at' => $row->published_at,
            'prizes' => $prizes,
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public function mapProbabilityVersion(object $row): array
    {
        $gachaVersion = DB::table('catalog_gacha_versions')
            ->where('id', $row->gacha_version_id)
            ->firstOrFail();
        $clonedFrom = $row->cloned_from_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $row->cloned_from_probability_version_id)
                ->firstOrFail();
        $structure = $this->probabilityStructure((int) $row->id);
        $prizes = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->where('relation.gacha_version_id', $row->gacha_version_id)
            ->get([
                'prize.public_id',
                'prize.code',
                'prize.display_name',
                'rank.public_id as rank_public_id',
                'rank.code as rank_code',
                'rank.display_name as rank_name',
                'rank.sort_order as rank_sort_order',
            ])->keyBy('public_id');
        $target = static function (array $value) use ($prizes): array {
            $prize = $value['prize_public_id'] === null
                ? null
                : $prizes->get($value['prize_public_id']);

            return [
                'result_type' => $value['result_type'],
                'prize' => $prize === null ? null : [
                    'id' => $prize->public_id,
                    'code' => $prize->code,
                    'name' => $prize->display_name,
                    'rank' => [
                        'id' => $prize->rank_public_id,
                        'code' => $prize->rank_code,
                        'name' => $prize->rank_name,
                        'sort_order' => (int) $prize->rank_sort_order,
                    ],
                ],
                'point_amount' => $value['point_amount'],
                'probability_ppm' => $value['probability_ppm'],
            ];
        };
        $mappedStages = array_map(
            static fn (array $stage): array => [
                'id' => $stage['id'],
                'code' => $stage['code'],
                'name' => $stage['name'],
                'condition_type' => $stage['condition_type'],
                'min_draw_number' => $stage['min_draw_number'],
                'max_draw_number' => $stage['max_draw_number'],
                'sort_order' => $stage['sort_order'],
                'entries' => array_map(
                    static fn (array $entry): array => [
                        ...$target($entry),
                        'sort_order' => $entry['sort_order'],
                    ],
                    $stage['entries']
                ),
                'minimum_guarantee' => $stage['minimum_guarantee'] === null
                    ? null
                    : $target($stage['minimum_guarantee']),
            ],
            $structure
        );

        return [
            'id' => $row->public_id,
            'gacha_version_id' => $gachaVersion->public_id,
            'version_number' => (int) $row->version_number,
            'status' => $row->status,
            'snapshot_sha256' => $row->snapshot_sha256,
            'cloned_from_version' => $clonedFrom === null ? null : [
                'id' => $clonedFrom->public_id,
                'version_number' => (int) $clonedFrom->version_number,
                'status' => $clonedFrom->status,
            ],
            'stages' => $mappedStages,
            'validation' => $this->probabilityValidation(
                $mappedStages,
                (int) $gachaVersion->total_count
            ),
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'published_at' => $row->published_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $stages
     * @return array<string, mixed>
     */
    private function probabilityValidation(array $stages, int $totalCount): array
    {
        $required = count($stages) * 1000000;
        $current = 0;
        $errors = [];
        $stageResults = [];
        if ($stages === []) {
            $errors[] = 'PROBABILITY_STAGE_REQUIRED';
        }
        foreach ($stages as $index => $stage) {
            $stageCurrent = array_sum(
                array_column($stage['entries'], 'probability_ppm')
            ) + (int) ($stage['minimum_guarantee']['probability_ppm'] ?? 0);
            $stageErrors = [];
            if ($stage['minimum_guarantee'] === null) {
                $stageErrors[] = 'MINIMUM_GUARANTEE_REQUIRED';
            }
            if ($stageCurrent < 1000000) {
                $stageErrors[] = 'PROBABILITY_TOTAL_INCOMPLETE';
            } elseif ($stageCurrent > 1000000) {
                $stageErrors[] = 'PROBABILITY_TOTAL_EXCEEDED';
            }
            if (
                $index === count($stages) - 1
                && $stage['max_draw_number'] !== null
                && $stage['max_draw_number'] < $totalCount
            ) {
                $stageErrors[] = 'PROBABILITY_STAGE_RANGE_INCOMPLETE';
            }
            $current += $stageCurrent;
            $stageResults[] = [
                'stage_id' => $stage['id'],
                'code' => $stage['code'],
                'current_total_ppm' => $stageCurrent,
                'required_total_ppm' => 1000000,
                'remaining_ppm' => max(0, 1000000 - $stageCurrent),
                'excess_ppm' => max(0, $stageCurrent - 1000000),
                'errors' => $stageErrors,
            ];
            foreach ($stageErrors as $stageError) {
                $errors[] = $stage['code'].':'.$stageError;
            }
        }

        return [
            'is_valid' => $errors === [],
            'current_total_ppm' => $current,
            'required_total_ppm' => $required,
            'remaining_ppm' => max(0, $required - $current),
            'excess_ppm' => max(0, $current - $required),
            'errors' => $errors,
            'stages' => $stageResults,
        ];
    }

    /** @return array<string, mixed> */
    private function mapPrize(object $row): array
    {
        $rank = DB::table('catalog_ranks')->where('id', $row->rank_id)->firstOrFail();
        $asset = $row->presentation_asset_id === null
            ? null
            : DB::table('catalog_presentation_assets')
                ->where('id', $row->presentation_asset_id)
                ->firstOrFail();

        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'name' => $row->display_name,
            'description' => $row->description,
            'display_price' => (int) $row->display_price,
            'exchange_points' => (int) $row->exchange_points,
            'cost_price' => (int) $row->cost_price,
            'is_visible' => (bool) $row->is_visible,
            'rank' => [
                'id' => $rank->public_id,
                'code' => $rank->code,
                'name' => $rank->display_name,
                'sort_order' => (int) $rank->sort_order,
            ],
            'presentation_asset' => $asset === null ? null : [
                'id' => $asset->public_id,
                'media_type' => $asset->media_type,
                'mime_type' => $asset->mime_type,
                'alt_text' => $asset->alt_text,
                'public_path' => $asset->is_public ? $asset->public_path : null,
                'is_public' => (bool) $asset->is_public,
            ],
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function mapRankAsset(?object $asset): ?array
    {
        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->public_id,
            'media_type' => $asset->media_type,
            'mime_type' => $asset->mime_type,
            'alt_text' => $asset->alt_text,
            'public_path' => $asset->is_public ? $asset->public_path : null,
            'is_public' => (bool) $asset->is_public,
        ];
    }

    /** @return array<string, mixed> */
    private function mapAsset(object $row): array
    {
        return [
            'id' => $row->public_id,
            'media_type' => $row->media_type,
            'mime_type' => $row->mime_type,
            'byte_size' => (int) $row->byte_size,
            'alt_text' => $row->alt_text,
            'public_path' => $row->is_public ? $row->public_path : null,
            'checksum_sha256' => $row->checksum_sha256,
            'is_public' => (bool) $row->is_public,
            'is_archived' => $row->archived_at !== null,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function mapRankEffect(object $row): array
    {
        $relations = DB::table('catalog_rank_assets as relation')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'relation.rank_id')
            ->where('relation.presentation_asset_id', $row->id)
            ->whereIn('relation.usage_type', ['image', 'video'])
            ->orderBy('relation.sort_order')
            ->orderBy('rank.public_id')
            ->get([
                'rank.public_id',
                'rank.code',
                'rank.display_name',
                'relation.sort_order',
            ]);

        return [
            ...$this->mapAsset($row),
            'content_path' => '/admin/api/v2/catalog/presentation-assets/'
                .$row->public_id.'/content',
            'rank_assignments' => $relations->map(fn (object $relation): array => [
                'rank' => [
                    'id' => $relation->public_id,
                    'code' => $relation->code,
                    'name' => $relation->display_name,
                ],
                'sort_order' => (int) $relation->sort_order,
            ])->values()->all(),
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
            && str_contains($message, 'Presentation Asset object identity is immutable')
        ) {
            return new V2CatalogException(
                'CATALOG_ASSET_IDENTITY_IMMUTABLE',
                409,
                'Presentation Asset object identity cannot be changed.'
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
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Published or drawn Gacha references protect')
                || str_contains($message, 'Published Gacha Version is immutable')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_PUBLISHED_REFERENCE_CONFLICT',
                409,
                'A Published or drawn Gacha invariant rejected the mutation.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Archived Catalog Gacha records are immutable')
                || str_contains($message, 'Archived Gacha Draft Version is immutable')
                || str_contains($message, 'Archived Gacha Draft Version relations are immutable')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_RESOURCE_ARCHIVED',
                409,
                'Archived Catalog records cannot be changed.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Catalog Gacha revision must increase by one')
                || str_contains($message, 'Gacha Draft Version revision must increase by one')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_REVISION_CONFLICT',
                409,
                'The Catalog record has changed.'
            );
        }
        if (
            $state === 'P0001'
            && str_contains(
                $message,
                'Only an active Draft Gacha Version can select Probability'
            )
        ) {
            return new V2CatalogException(
                'CATALOG_GACHA_VERSION_IMMUTABLE',
                409,
                'Only an active Draft Gacha Version can select Probability.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains(
                    $message,
                    'Selected Published Probability cannot be cleared'
                )
                || str_contains(
                    $message,
                    'Gacha Version requires its immutable Published Probability Snapshot'
                )
            )
        ) {
            return new V2CatalogException(
                'CATALOG_PROBABILITY_SELECTION_INVALID',
                409,
                'The selected Published Probability Snapshot is not available.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Catalog Gacha code and slug are immutable')
                || str_contains($message, 'Gacha Draft Version identity is immutable')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_GACHA_IDENTITY_IMMUTABLE',
                409,
                'Gacha identity fields cannot be changed.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Gacha Publish Schedule')
                || str_contains($message, 'Scheduled Gacha')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_GACHA_SCHEDULE_CONFLICT',
                409,
                'A Gacha Publish Schedule invariant rejected the mutation.'
            );
        }
        if (
            $state === 'P0001'
            && (
                str_contains($message, 'Gacha Sales state')
                || str_contains($message, 'Gacha Sales Resume')
                || str_contains($message, 'Paused Gacha')
            )
        ) {
            return new V2CatalogException(
                'CATALOG_GACHA_SALES_STATE_CONFLICT',
                409,
                'A Gacha Sales state invariant rejected the mutation.'
            );
        }
        if (
            $state === 'P0001'
            && str_contains($message, 'Gacha Public deactivation')
        ) {
            return new V2CatalogException(
                'CATALOG_GACHA_UNPUBLISH_CONFLICT',
                409,
                'A Gacha Public deactivation invariant rejected the mutation.'
            );
        }

        throw $exception;
    }

    private function failureAuditAction(V2CatalogException $exception): string
    {
        return match ($exception->errorCode) {
            'CATALOG_PUBLISHED_REFERENCE_CONFLICT' =>
                'catalog.master.published_reference_rejected',
            'CATALOG_GACHA_SALES_STATE_CONFLICT',
            'CATALOG_GACHA_SALES_PAUSE_INVALID',
            'CATALOG_GACHA_SALES_RESUME_INVALID' =>
                'catalog.master.sales_state_rejected',
            'CATALOG_GACHA_UNPUBLISH_INVALID',
            'CATALOG_GACHA_UNPUBLISH_CONFLICT' =>
                'catalog.master.unpublish_rejected',
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
