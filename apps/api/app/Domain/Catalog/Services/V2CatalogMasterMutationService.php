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
        private readonly V2RateLimiter $criticalRateLimiter,
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
    public function updateGacha(
        V2AdminAuthorizationContext $context,
        string $publicId,
        string $idempotencyKey,
        array $input
    ): array {
        foreach (['code', 'slug', 'state', 'sold_count', 'published_version_id'] as $field) {
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
                $this->assertGachaHasNoPublishedOrDrawnReference((int) $row->id);
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
                DB::table('catalog_gachas')->where('id', $row->id)->update([
                    'category_id' => $category->id,
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now()->startOfSecond(),
                ]);
                $this->replaceGachaTags((int) $row->id, $tags);

                return $this->find('catalog_gachas', $publicId, false);
            }
        );
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
                    $this->validateGachaVersion($payload, false),
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
                    $context,
                    $gacha,
                    $version
                );
            },
            false,
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
                    'publish' => 'published',
                    default => throw new \LogicException(
                        'Unsupported Catalog mutation action.'
                    ),
                };
                $targetPublicId = is_string($data['id'] ?? null)
                    ? $data['id']
                    : (is_string($data['gacha_version_id'] ?? null)
                        ? $data['gacha_version_id']
                        : null);
                $this->recordAudit(
                    $event,
                    $context,
                    $admin,
                    $resource,
                    $action,
                    ($action === 'gacha_publish_preflight'
                        && ! ($data['publishable'] ?? false))
                        ? 'failure'
                        : 'success',
                    ($action === 'gacha_publish_preflight'
                        && ! ($data['publishable'] ?? false))
                        ? 'publish_preflight_blocked'
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
                    ]
                );
                if ($enqueueOutbox) {
                    $this->outbox->enqueue(
                        'catalog.change',
                        'catalog_'.$resource,
                        $targetPublicId ?? throw new \RuntimeException(
                            'Catalog mutation target is unavailable.'
                        ),
                        $event,
                        [
                            'catalog_public_id' => $targetPublicId,
                            'catalog_resource' => $resource,
                            'revision' => $data['revision']
                                ?? $data['gacha_version_revision']
                                ?? null,
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
    private function validateGachaUpdate(array $input): array
    {
        $this->assertFields(
            $input,
            ['expected_revision', 'category_id', 'tag_ids'],
            ['expected_revision', 'category_id', 'tag_ids']
        );

        return [
            'expected_revision' => $this->revision($input['expected_revision']),
            'category_id' => $this->uuid($input['category_id']),
            'tag_ids' => $this->uuidList($input['tag_ids'], true),
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
        $versionNumber = (int) DB::table('catalog_gacha_versions')
            ->where('gacha_id', $gacha->id)
            ->max('version_number') + 1;
        $now = now()->startOfSecond();
        $publicId = (string) Str::uuid7();
        $versionId = DB::table('catalog_gacha_versions')->insertGetId([
            'public_id' => $publicId,
            'gacha_id' => $gacha->id,
            'version_number' => $versionNumber,
            'status' => 'draft',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'notices' => $payload['notices'],
            'price_points' => $payload['price_points'],
            'total_count' => $payload['total_count'],
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

        return $this->find('catalog_gacha_versions', $publicId, false);
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
        V2AdminAuthorizationContext $context,
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
            'request_id' => $context->requestId,
        ];
    }

    private function probabilityPublishException(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_PROBABILITY_PUBLISH_INVALID',
            422,
            'The Probability Draft cannot be published.'
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
    private function mapGacha(object $row): array
    {
        $category = DB::table('catalog_categories')
            ->where('id', $row->category_id)
            ->firstOrFail();
        $tags = DB::table('catalog_gacha_tags as relation')
            ->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')
            ->where('relation.gacha_id', $row->id)
            ->orderBy('tag.sort_order')
            ->orderBy('tag.public_id')
            ->get([
                'tag.public_id',
                'tag.code',
                'tag.display_name',
            ])->map(fn (object $tag): array => [
                'id' => $tag->public_id,
                'code' => $tag->code,
                'name' => $tag->display_name,
            ])->all();
        $publishedVersion = $row->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $row->published_version_id)
                ->first();

        return [
            'id' => $row->public_id,
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
