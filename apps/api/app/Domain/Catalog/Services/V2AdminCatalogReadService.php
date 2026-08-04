<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class V2AdminCatalogReadService
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2CatalogMasterMutationService $mutations
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function categories(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);

        return $this->masterList(
            'categories',
            'catalog_categories',
            $filters,
            ['sort_order', 'name', 'code'],
            'sort_order',
            fn (object $row): array => $this->mapCategory($row)
        );
    }

    public function category(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);

        return ['data' => $this->mapCategory(
            $this->find('catalog_categories', $publicId)
        )];
    }

    /** @param array<string, mixed> $filters */
    public function tags(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);

        return $this->masterList(
            'tags',
            'catalog_tags',
            $filters,
            ['sort_order', 'name', 'code'],
            'sort_order',
            fn (object $row): array => $this->mapTag($row)
        );
    }

    public function tag(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);

        return ['data' => $this->mapTag(
            $this->find('catalog_tags', $publicId)
        )];
    }

    /** @param array<string, mixed> $filters */
    public function ranks(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);

        return $this->masterList(
            'ranks',
            'catalog_ranks',
            $filters,
            ['sort_order', 'name', 'code'],
            'sort_order',
            fn (object $row): array => $this->mapRank($row)
        );
    }

    public function rank(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);

        return ['data' => $this->mapRank(
            $this->find('catalog_ranks', $publicId)
        )];
    }

    /** @param array<string, mixed> $filters */
    public function gachas(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);
        $sort = $this->enum($filters, 'sort', ['created_at', 'code', 'state'], 'created_at');
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'desc');
        $columns = [
            'created_at' => 'gacha.created_at',
            'code' => 'gacha.code',
            'state' => 'gacha.state',
        ];
        $query = DB::table('catalog_gachas as gacha')
            ->join('catalog_categories as category', 'category.id', '=', 'gacha.category_id')
            ->select([
                'gacha.*',
                'category.public_id as category_public_id',
                'category.code as category_code',
                'category.display_name as category_name',
            ]);
        $this->applySearch($query, $filters, [
            'gacha.code',
            'gacha.slug',
            'category.code',
            'category.display_name',
        ]);
        $state = $this->enum(
            $filters,
            'state',
            ['all', 'draft', 'active', 'disabled'],
            'all'
        );
        if ($state !== 'all') {
            $query->where('gacha.state', $state);
        }
        $archive = $this->enum(
            $filters,
            'archive',
            ['all', 'active', 'archived'],
            'all'
        );
        if ($archive === 'active') {
            $query->whereNull('gacha.archived_at');
        } elseif ($archive === 'archived') {
            $query->whereNotNull('gacha.archived_at');
        }

        return $this->paginate(
            'gachas',
            $query,
            $filters,
            $columns[$sort],
            'gacha.public_id',
            $sort,
            $direction,
            $this->limit($filters),
            fn (object $row): array => $this->mapGacha($row)
        );
    }

    public function gacha(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);
        $this->assertUuid($publicId);
        $row = DB::table('catalog_gachas as gacha')
            ->join('catalog_categories as category', 'category.id', '=', 'gacha.category_id')
            ->where('gacha.public_id', $publicId)
            ->select([
                'gacha.*',
                'category.public_id as category_public_id',
                'category.code as category_code',
                'category.display_name as category_name',
            ])->first();
        if ($row === null) {
            throw $this->notFound();
        }

        return ['data' => $this->mapGacha($row)];
    }

    /** @param array<string, mixed> $filters */
    public function gachaVersions(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        array $filters
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'desc');
        $status = $this->enum(
            $filters,
            'status',
            ['all', 'draft', 'published'],
            'all'
        );
        $archive = $this->enum(
            $filters,
            'archive',
            ['all', 'active', 'archived'],
            'all'
        );
        $query = DB::table('catalog_gacha_versions as version')
            ->where('version.gacha_id', $gacha->id)
            ->select('version.*');
        $this->applySearch($query, $filters, [
            'version.title',
            'version.description',
            'version.notices',
        ]);
        if ($status !== 'all') {
            $query->where('version.status', $status);
        }
        if ($archive === 'active') {
            $query->whereNull('version.archived_at');
        } elseif ($archive === 'archived') {
            $query->whereNotNull('version.archived_at');
        }

        return $this->paginate(
            'gacha_versions:'.$gachaPublicId,
            $query,
            $filters,
            'version.version_number',
            'version.public_id',
            'version_number',
            $direction,
            $this->limit($filters),
            fn (object $row): array => $this->mapGachaVersion($row)
        );
    }

    public function gachaVersion(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $versionPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $version = $this->find('catalog_gacha_versions', $versionPublicId);
        if ((int) $version->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }

        return ['data' => $this->mapGachaVersion($version)];
    }

    /** @param array<string, mixed> $filters */
    public function probabilityVersions(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        array $filters
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $gachaVersion = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId
        );
        if ((int) $gachaVersion->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'desc');
        $status = $this->enum(
            $filters,
            'status',
            ['all', 'draft', 'published'],
            'all'
        );
        $archive = $this->enum(
            $filters,
            'archive',
            ['all', 'active', 'archived'],
            'all'
        );
        $query = DB::table('catalog_probability_versions as version')
            ->where('version.gacha_version_id', $gachaVersion->id)
            ->select('version.*');
        if ($status !== 'all') {
            $query->where('version.status', $status);
        }
        if ($archive === 'active') {
            $query->whereNull('version.archived_at');
        } elseif ($archive === 'archived') {
            $query->whereNotNull('version.archived_at');
        }

        return $this->paginate(
            'probability_versions:'.$gachaVersionPublicId,
            $query,
            $filters,
            'version.version_number',
            'version.public_id',
            'version_number',
            $direction,
            $this->limit($filters),
            fn (object $row): array =>
                $this->mutations->mapProbabilityVersion($row)
        );
    }

    public function probabilityVersion(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        string $probabilityVersionPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $gachaVersion = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId
        );
        $probabilityVersion = $this->find(
            'catalog_probability_versions',
            $probabilityVersionPublicId
        );
        if (
            (int) $gachaVersion->gacha_id !== (int) $gacha->id
            || (int) $probabilityVersion->gacha_version_id
                !== (int) $gachaVersion->id
        ) {
            throw $this->notFound();
        }

        return [
            'data' => $this->mutations->mapProbabilityVersion($probabilityVersion),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function publishedProbabilityCandidates(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId,
        array $filters
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $gachaVersion = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId
        );
        if ((int) $gachaVersion->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'desc');
        $query = DB::table('catalog_probability_versions as version')
            ->where('version.gacha_version_id', $gachaVersion->id)
            ->where('version.status', 'published')
            ->whereNull('version.archived_at')
            ->select('version.*');

        return $this->paginate(
            'published_probability_candidates:'.$gachaVersionPublicId,
            $query,
            $filters,
            'version.version_number',
            'version.public_id',
            'version_number',
            $direction,
            $this->limit($filters),
            fn (object $row): array =>
                $this->mutations->mapPublishedProbabilityCandidate($row)
        );
    }

    public function gachaProbabilitySelection(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $gachaVersion = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId
        );
        if ((int) $gachaVersion->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }
        $selected = $gachaVersion->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $gachaVersion->published_probability_version_id)
                ->firstOrFail();

        return [
            'data' => [
                'gacha_version_id' => $gachaVersion->public_id,
                'gacha_version_revision' => (int) $gachaVersion->revision,
                'selected_probability' => $selected === null
                    ? null
                    : $this->mutations->mapPublishedProbabilityCandidate($selected),
            ],
        ];
    }

    public function gachaPublishState(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $version = $gacha->published_version_id === null
            ? null
            : DB::table('catalog_gacha_versions')
                ->where('id', $gacha->published_version_id)
                ->firstOrFail();
        $probability = $version?->published_probability_version_id === null
            ? null
            : DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->firstOrFail();
        $state = $gacha->active_draw_state_id === null
            ? null
            : DB::table('gacha_draw_states')
                ->where('id', $gacha->active_draw_state_id)
                ->firstOrFail();
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
            ->first($this->publishScheduleColumns());

        return [
            'data' => [
                'gacha_id' => $gacha->public_id,
                'gacha_revision' => (int) $gacha->revision,
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
                'draw_state' => $state === null
                    ? null
                    : [
                        'status' => $state->status,
                        'sold_count' => (int) $state->sold_count,
                        'total_count' => (int) $state->total_count,
                    ],
                'publish_schedule' => $schedule === null
                    ? null
                    : $this->mapPublishSchedule(
                        $schedule,
                        (int) $gacha->revision
                    ),
            ],
        ];
    }

    public function gachaSalesState(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);

        return [
            'data' => $this->mutations->mapGachaSalesState(
                $gacha,
                $context->requestId
            ),
        ];
    }

    public function gachaUnpublishState(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);

        return [
            'data' => $this->mutations->mapGachaUnpublishState(
                $gacha,
                $context->requestId
            ),
        ];
    }

    public function gachaPublishSchedule(
        V2AdminAuthorizationContext $context,
        string $gachaPublicId,
        string $gachaVersionPublicId
    ): array {
        $this->authorize($context);
        $gacha = $this->find('catalog_gachas', $gachaPublicId);
        $version = $this->find(
            'catalog_gacha_versions',
            $gachaVersionPublicId
        );
        if ((int) $version->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }
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
            ->where('schedule.gacha_version_id', $version->id)
            ->orderByDesc('schedule.id')
            ->first($this->publishScheduleColumns());

        return [
            'data' => $schedule === null
                ? null
                : $this->mapPublishSchedule(
                    $schedule,
                    (int) $gacha->revision
                ),
        ];
    }

    /** @return list<string> */
    private function publishScheduleColumns(): array
    {
        return [
            'schedule.public_id',
            'schedule.status',
            'schedule.scheduled_for',
            'schedule.next_attempt_at',
            'schedule.attempts',
            'schedule.failure_code',
            'schedule.revision',
            'schedule.expected_gacha_revision',
            'schedule.expected_version_revision',
            'schedule.started_at',
            'schedule.completed_at',
            'schedule.cancelled_at',
            'schedule.failed_at',
            'schedule.request_id',
            'version.public_id as gacha_version_public_id',
            'version.revision as current_gacha_version_revision',
            'probability.public_id as probability_public_id',
            'probability.snapshot_sha256',
        ];
    }

    /** @return array<string, mixed> */
    private function mapPublishSchedule(
        object $schedule,
        int $currentGachaRevision
    ): array {
        return [
            'id' => $schedule->public_id,
            'status' => $schedule->status,
            'scheduled_for' => $schedule->scheduled_for,
            'next_attempt_at' => $schedule->next_attempt_at,
            'server_timezone' => 'UTC',
            'display_timezone' => (string) config('v2_catalog.timezone'),
            'gacha_version_id' => $schedule->gacha_version_public_id,
            'selected_probability' => [
                'id' => $schedule->probability_public_id,
                'snapshot_sha256' => $schedule->snapshot_sha256,
            ],
            'attempts' => (int) $schedule->attempts,
            'failure_code' => $schedule->failure_code,
            'revision' => (int) $schedule->revision,
            'gacha_revision' => $currentGachaRevision,
            'gacha_version_revision' =>
                (int) $schedule->current_gacha_version_revision,
            'started_at' => $schedule->started_at,
            'completed_at' => $schedule->completed_at,
            'cancelled_at' => $schedule->cancelled_at,
            'failed_at' => $schedule->failed_at,
            'request_id' => $schedule->request_id,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function prizes(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);
        $sort = $this->enum($filters, 'sort', ['name', 'code', 'rank'], 'name');
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'asc');
        $limit = $this->limit($filters);
        $columns = [
            'name' => 'prize.display_name',
            'code' => 'prize.code',
            'rank' => 'rank.sort_order',
        ];
        $query = DB::table('catalog_prizes as prize')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'prize.presentation_asset_id'
            )
            ->select([
                'prize.public_id',
                'prize.code',
                'prize.display_name',
                'prize.description',
                'prize.display_price',
                'prize.exchange_points',
                'prize.is_visible',
                'prize.revision',
                'prize.archived_at',
                'prize.created_at',
                'prize.updated_at',
                'rank.public_id as rank_public_id',
                'rank.code as rank_code',
                'rank.display_name as rank_name',
                'rank.sort_order as rank_sort_order',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_public_path',
                'asset.media_type as asset_media_type',
                'asset.mime_type as asset_mime_type',
                'asset.alt_text as asset_alt_text',
                'asset.is_public as asset_is_public',
            ]);
        $this->applySearch($query, $filters, [
            'prize.code',
            'prize.display_name',
            'prize.description',
            'rank.code',
            'rank.display_name',
        ]);
        $this->applyVisibility($query, $filters, 'prize.is_visible');
        $rankId = $this->optionalUuid($filters, 'rank_id');
        if ($rankId !== null) {
            $query->where('rank.public_id', $rankId);
        }

        return $this->paginate(
            'prizes',
            $query,
            $filters,
            $columns[$sort],
            'prize.public_id',
            $sort,
            $direction,
            $limit,
            fn (object $row): array => $this->mapPrize($row),
            $sort === 'rank' ? 'rank_sort_order' : null
        );
    }

    public function prize(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);
        $this->assertUuid($publicId);
        $row = DB::table('catalog_prizes as prize')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'prize.rank_id')
            ->leftJoin(
                'catalog_presentation_assets as asset',
                'asset.id',
                '=',
                'prize.presentation_asset_id'
            )
            ->where('prize.public_id', $publicId)
            ->select([
                'prize.public_id',
                'prize.code',
                'prize.display_name',
                'prize.description',
                'prize.display_price',
                'prize.exchange_points',
                'prize.is_visible',
                'prize.revision',
                'prize.archived_at',
                'prize.created_at',
                'prize.updated_at',
                'rank.public_id as rank_public_id',
                'rank.code as rank_code',
                'rank.display_name as rank_name',
                'rank.sort_order as rank_sort_order',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_public_path',
                'asset.media_type as asset_media_type',
                'asset.mime_type as asset_mime_type',
                'asset.alt_text as asset_alt_text',
                'asset.is_public as asset_is_public',
            ])
            ->first();
        if ($row === null) {
            throw $this->notFound();
        }

        return ['data' => $this->mapPrize($row)];
    }

    /** @param array<string, mixed> $filters */
    public function assets(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);
        $sort = $this->enum(
            $filters,
            'sort',
            ['created_at', 'media_type'],
            'created_at'
        );
        $direction = $this->enum(
            $filters,
            'direction',
            ['asc', 'desc'],
            'desc'
        );
        $limit = $this->limit($filters);
        $columns = [
            'created_at' => 'asset.created_at',
            'media_type' => 'asset.media_type',
        ];
        $query = DB::table('catalog_presentation_assets as asset')
            ->select([
                'asset.public_id',
                'asset.public_path',
                'asset.checksum_sha256',
                'asset.media_type',
                'asset.mime_type',
                'asset.byte_size',
                'asset.alt_text',
                'asset.is_public',
                'asset.revision',
                'asset.archived_at',
                'asset.created_at',
                'asset.updated_at',
            ]);
        $this->applySearch($query, $filters, [
            'asset.public_path',
            'asset.alt_text',
            'asset.mime_type',
        ]);
        $this->applyVisibility($query, $filters, 'asset.is_public');
        $mediaType = $this->enum(
            $filters,
            'media_type',
            ['all', 'image', 'video'],
            'all'
        );
        if ($mediaType !== 'all') {
            $query->where('asset.media_type', $mediaType);
        }

        return $this->paginate(
            'presentation-assets',
            $query,
            $filters,
            $columns[$sort],
            'asset.public_id',
            $sort,
            $direction,
            $limit,
            fn (object $row): array => $this->mapAsset($row)
        );
    }

    public function asset(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $this->authorize($context);

        return ['data' => $this->mapAsset(
            $this->find('catalog_presentation_assets', $publicId, [
                'public_id',
                'public_path',
                'checksum_sha256',
                'media_type',
                'mime_type',
                'byte_size',
                'alt_text',
                'is_public',
                'revision',
                'archived_at',
                'created_at',
                'updated_at',
            ])
        )];
    }

    private function authorize(V2AdminAuthorizationContext $context): void
    {
        $this->authorizer->authorizePermission(
            $context,
            V2Permission::ReadCatalog,
            false,
            'catalog.read'
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowedSorts
     * @param callable(object): array<string, mixed> $map
     * @param callable(object): array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function masterList(
        string $resource,
        string $table,
        array $filters,
        array $allowedSorts,
        string $defaultSort,
        callable $map
    ): array {
        $sort = $this->enum($filters, 'sort', $allowedSorts, $defaultSort);
        $direction = $this->enum($filters, 'direction', ['asc', 'desc'], 'asc');
        $columns = [
            'sort_order' => 'item.sort_order',
            'name' => 'item.display_name',
            'code' => 'item.code',
        ];
        $query = DB::table($table.' as item')->select('item.*');
        $searchColumns = [
            'item.code',
            'item.display_name',
        ];
        if ($table !== 'catalog_ranks') {
            $searchColumns[] = 'item.slug';
        }
        $this->applySearch($query, $filters, $searchColumns);
        $this->applyVisibility($query, $filters, 'item.is_visible');

        return $this->paginate(
            $resource,
            $query,
            $filters,
            $columns[$sort],
            'item.public_id',
            $sort,
            $direction,
            $this->limit($filters),
            $map
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param callable(object): array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function paginate(
        string $resource,
        Builder $query,
        array $filters,
        string $sortColumn,
        string $publicIdColumn,
        string $sort,
        string $direction,
        int $limit,
        callable $map,
        ?string $cursorValueColumn = null
    ): array {
        $cursor = $this->optionalString($filters, 'cursor', 4096);
        if ($cursor !== null) {
            $decoded = $this->decodeCursor($cursor, $resource, $sort, $direction);
            $operator = $direction === 'asc' ? '>' : '<';
            $query->where(function (Builder $nested) use (
                $sortColumn,
                $publicIdColumn,
                $operator,
                $decoded
            ): void {
                $nested->where($sortColumn, $operator, $decoded['value'])
                    ->orWhere(function (Builder $same) use (
                        $sortColumn,
                        $publicIdColumn,
                        $operator,
                        $decoded
                    ): void {
                        $same->where($sortColumn, '=', $decoded['value'])
                            ->where(
                                $publicIdColumn,
                                $operator,
                                $decoded['public_id']
                            );
                    });
            });
        }
        $rows = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy($publicIdColumn, $direction)
            ->limit($limit + 1)
            ->get();
        $hasNext = $rows->count() > $limit;
        $items = $rows->take($limit);
        $last = $items->last();

        return [
            'items' => $items->map($map)->values()->all(),
            'next_cursor' => $hasNext && $last !== null
                ? $this->encodeCursor(
                    $resource,
                    $sort,
                    $direction,
                    $last->{$cursorValueColumn
                        ?? (str_contains($sortColumn, '.')
                            ? substr($sortColumn, strrpos($sortColumn, '.') + 1)
                            : $sortColumn)},
                    $last->public_id
                )
                : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applySearch(
        Builder $query,
        array $filters,
        array $columns
    ): void {
        $search = $this->optionalString($filters, 'q', 100);
        if ($search === null) {
            return;
        }
        $query->where(function (Builder $nested) use ($columns, $search): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $nested->{$method}($column, 'ilike', '%'.$search.'%');
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyVisibility(
        Builder $query,
        array $filters,
        string $column
    ): void {
        $visibility = $this->enum(
            $filters,
            'visibility',
            ['all', 'visible', 'hidden'],
            'all'
        );
        if ($visibility !== 'all') {
            $query->where($column, $visibility === 'visible');
        }
    }

    /** @param array<string, mixed> $filters */
    private function limit(array $filters): int
    {
        $value = $filters['limit'] ?? self::DEFAULT_LIMIT;
        if (
            is_string($value)
            && preg_match('/^[0-9]+$/', $value) === 1
        ) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $allowed
     */
    private function enum(
        array $filters,
        string $key,
        array $allowed,
        string $default
    ): string {
        $value = $filters[$key] ?? $default;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    /** @param array<string, mixed> $filters */
    private function optionalString(
        array $filters,
        string $key,
        int $maxLength
    ): ?string {
        $value = $filters[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $maxLength) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    /** @param array<string, mixed> $filters */
    private function optionalUuid(array $filters, string $key): ?string
    {
        $value = $this->optionalString($filters, $key, 36);
        if ($value !== null && ! Str::isUuid($value)) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    private function find(
        string $table,
        string $publicId,
        array $columns = ['*']
    ): object {
        $this->assertUuid($publicId);
        $row = DB::table($table)
            ->where('public_id', $publicId)
            ->select($columns)
            ->first();
        if ($row === null) {
            throw $this->notFound();
        }

        return $row;
    }

    private function assertUuid(string $publicId): void
    {
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
    }

    private function encodeCursor(
        string $resource,
        string $sort,
        string $direction,
        mixed $value,
        string $publicId
    ): string {
        return Crypt::encryptString(json_encode([
            'resource' => $resource,
            'sort' => $sort,
            'direction' => $direction,
            'value' => $value,
            'public_id' => $publicId,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{value: mixed, public_id: string}
     */
    private function decodeCursor(
        string $cursor,
        string $resource,
        string $sort,
        string $direction
    ): array {
        try {
            $decoded = json_decode(
                Crypt::decryptString($cursor),
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            throw $this->invalidCursor();
        }
        if (
            ! is_array($decoded)
            || ($decoded['resource'] ?? null) !== $resource
            || ($decoded['sort'] ?? null) !== $sort
            || ($decoded['direction'] ?? null) !== $direction
            || ! array_key_exists('value', $decoded)
            || ! is_string($decoded['public_id'] ?? null)
            || ! Str::isUuid($decoded['public_id'])
            || (! is_string($decoded['value'])
                && ! is_int($decoded['value']))
        ) {
            throw $this->invalidCursor();
        }

        return [
            'value' => $decoded['value'],
            'public_id' => $decoded['public_id'],
        ];
    }

    /** @return array<string, mixed> */
    private function mapCategory(object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'slug' => $row->slug,
            'name' => $row->display_name,
            'description' => $row->description,
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
    private function mapTag(object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'slug' => $row->slug,
            'name' => $row->display_name,
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
    private function mapRank(object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'name' => $row->display_name,
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
        $currentVersion = $publishedVersion ?? DB::table('catalog_gacha_versions')
            ->where('gacha_id', $row->id)
            ->whereNull('archived_at')
            ->orderByDesc('version_number')
            ->first();
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
            'code' => $row->code,
            'slug' => $row->slug,
            'state' => $row->state,
            'sold_count' => (int) $row->sold_count,
            'category' => [
                'id' => $row->category_public_id,
                'code' => $row->category_code,
                'name' => $row->category_name,
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
    private function mapPrize(object $row): array
    {
        return [
            'id' => $row->public_id,
            'code' => $row->code,
            'name' => $row->display_name,
            'description' => $row->description,
            'display_price' => (int) $row->display_price,
            'exchange_points' => (int) $row->exchange_points,
            'is_visible' => (bool) $row->is_visible,
            'rank' => [
                'id' => $row->rank_public_id,
                'code' => $row->rank_code,
                'name' => $row->rank_name,
                'sort_order' => (int) $row->rank_sort_order,
            ],
            'presentation_asset' => $row->asset_public_id === null
                ? null
                : [
                    'id' => $row->asset_public_id,
                    'media_type' => $row->asset_media_type,
                    'mime_type' => $row->asset_mime_type,
                    'alt_text' => $row->asset_alt_text,
                    'public_path' => $row->asset_is_public
                        ? $row->asset_public_path
                        : null,
                    'is_public' => (bool) $row->asset_is_public,
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

    private function invalidQuery(): V2CatalogException
    {
        return new V2CatalogException(
            'INVALID_CATALOG_QUERY',
            422,
            'The catalog query is invalid.'
        );
    }

    private function invalidCursor(): V2CatalogException
    {
        return new V2CatalogException(
            'INVALID_CURSOR',
            422,
            'The catalog cursor is invalid.'
        );
    }

    private function notFound(): V2CatalogException
    {
        return new V2CatalogException(
            'CATALOG_RESOURCE_NOT_FOUND',
            404,
            'The catalog resource was not found.'
        );
    }
}
