<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Draw\Services\V2DrawEligibilityService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class V2CatalogReadService
{
    public function __construct(
        private readonly V2DrawEligibilityService $eligibility
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return DB::table('catalog_categories')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('public_id')
            ->get(['public_id', 'slug', 'display_name', 'description'])
            ->map(fn (object $row): array => [
                'id' => $row->public_id,
                'slug' => $row->slug,
                'name' => $row->display_name,
                'description' => $row->description,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tags(): array
    {
        return DB::table('catalog_tags')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('public_id')
            ->get(['public_id', 'slug', 'display_name'])
            ->map(fn (object $row): array => [
                'id' => $row->public_id,
                'slug' => $row->slug,
                'name' => $row->display_name,
            ])->all();
    }

    /** @return array{content: string, mime_type: string, checksum: string} */
    public function presentationAssetContent(string $publicId): array
    {
        if (! Str::isUuid($publicId)) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }
        $asset = DB::table('catalog_presentation_assets as asset')
            ->where('asset.public_id', $publicId)
            ->where('asset.is_public', true)
            ->whereNull('asset.archived_at')
            ->where(function (Builder $query): void {
                $query->whereExists(function (Builder $revision): void {
                    $revision->selectRaw('1')
                        ->from('catalog_rank_master_revisions as rank_revision')
                        ->whereColumn('rank_revision.lineup_image_asset_id', 'asset.id')
                        ->orWhereColumn('rank_revision.result_image_asset_id', 'asset.id');
                })->orWhereExists(function (Builder $revision): void {
                    $revision->selectRaw('1')
                        ->from('catalog_gacha_rank_video_revisions as video_revision')
                        ->whereColumn('video_revision.video_asset_id', 'asset.id');
                });
            })
            ->first();
        if ($asset === null) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }
        $content = Storage::disk(config('filesystems.default'))
            ->get($asset->storage_identifier);
        if (! hash_equals($asset->checksum_sha256, hash('sha256', $content))) {
            throw new \RuntimeException('Canonical presentation Asset checksum mismatch.');
        }

        return [
            'content' => $content,
            'mime_type' => $asset->mime_type,
            'checksum' => $asset->checksum_sha256,
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(
        int $limit,
        ?string $cursor,
        ?string $categorySlug,
        ?string $tagSlug,
        ?User $user = null
    ): array {
        $maximum = (int) config('v2_catalog.maximum_page_size', 100);
        if ($limit < 1 || $limit > $maximum) {
            throw new V2CatalogException(
                'INVALID_PAGE_SIZE',
                422,
                'The requested page size is invalid.'
            );
        }
        $cursorId = $this->decodeCursor($cursor);
        $query = $this->publishedGachaQuery(false)
            ->when($cursorId !== null, fn (Builder $builder): Builder =>
                $builder->where('g.public_id', '>', $cursorId))
            ->when($categorySlug !== null, fn (Builder $builder): Builder =>
                $builder->where('c.slug', $categorySlug)
                    ->where('c.is_visible', true))
            ->when($tagSlug !== null, function (Builder $builder) use ($tagSlug): Builder {
                return $builder->whereExists(function (Builder $tags) use ($tagSlug): void {
                    $tags->selectRaw('1')
                        ->from('catalog_gacha_tags as filter_gt')
                        ->join('catalog_tags as filter_t', 'filter_t.id', '=', 'filter_gt.tag_id')
                        ->whereColumn('filter_gt.gacha_id', 'g.id')
                        ->where('filter_t.slug', $tagSlug)
                        ->where('filter_t.is_visible', true);
                });
            })
            ->orderBy('g.public_id')
            ->limit($limit + 1)
            ->get($this->summaryColumns());

        $hasMore = $query->count() > $limit;
        $rows = $query->take($limit);
        $tags = $this->tagsForGachas($rows->pluck('gacha_internal_id')->all());
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $data = $rows->map(
            fn (object $row): array => [
                ...$this->summary($row, $tags[$row->gacha_internal_id] ?? []),
                'drawn_count' => (int) $row->sold_count,
                'presentation' => $this->presentationForRow($row, $user, $now),
            ]
        )->values()->all();
        $last = $rows->last();

        return [
            'data' => $data,
            'meta' => [
                'page_size' => count($data),
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null
                    ? $this->encodeCursor($last->public_id)
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getByPublicId(string $publicId): array
    {
        $isPublicCode = preg_match('/\A[A-Za-z0-9]{11}\z/', $publicId) === 1;
        if (! $isPublicCode && ! Str::isUuid($publicId)) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }

        return $this->detail(
            $this->publishedGachaQuery(false)->where(
                $isPublicCode ? 'g.public_code' : 'g.public_id',
                $publicId
            )->first(
                $this->summaryColumns()
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getBySlug(string $slug): array
    {
        return $this->detail(
            $this->publishedGachaQuery(false)->where('g.slug', $slug)->first(
                $this->summaryColumns()
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function presentationState(string $publicId, ?User $user): array
    {
        $isPublicCode = preg_match('/\A[A-Za-z0-9]{11}\z/', $publicId) === 1;
        if (! $isPublicCode && ! Str::isUuid($publicId)) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }

        $row = $this->publishedGachaQuery(false)->where(
            $isPublicCode ? 'g.public_code' : 'g.public_id',
            $publicId
        )->first($this->summaryColumns());
        if ($row === null) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }

        return $this->presentationForRow(
            $row,
            $user,
            CarbonImmutable::now('UTC')->startOfSecond()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentationForRow(
        object $row,
        ?User $user,
        CarbonImmutable $now
    ): array {
        $saleState = $this->saleState($row, $now);
        $evaluation = $this->eligibility->evaluate(
            $user,
            (int) $row->gacha_internal_id,
            (string) $row->audience_code,
            (int) $row->first_time_eligible_days,
            (int) $row->daily_draw_limit,
            $now
        );
        $reason = $this->presentationReason($saleState, $evaluation);
        $remainingCount = (int) $row->remaining_count;
        $allowedCounts = [];
        if ($reason === null) {
            $dailyRemaining = $evaluation['daily']['remaining'];
            $platformSupported = config('v2_draw.allowed_counts', []);
            $gachaConfigured = $this->allowedDrawCounts(
                $row->allowed_draw_counts ?? null
            );
            $allowedCounts = array_values(array_filter(
                is_array($platformSupported) ? $platformSupported : [],
                static fn (mixed $count): bool => is_int($count)
                    && in_array($count, $gachaConfigured, true)
                    && ($dailyRemaining === null || $count <= $dailyRemaining)
            ));
            if ($allowedCounts === []) {
                $reason = $remainingCount === 0
                    ? 'sold_out'
                    : 'daily_limit_reached';
            }
        }

        return [
            'gacha_id' => $row->public_id,
            'sale_state' => $saleState,
            'user_state' => $evaluation['authenticated']
                ? 'authenticated'
                : 'unauthenticated',
            'audience' => $evaluation['audience_code'],
            'eligible' => $reason === null,
            'ineligible_reason' => $reason,
            'allowed_draw_counts' => $allowedCounts,
            'daily_limit' => $evaluation['daily'],
            'cta' => $this->cta($saleState, $reason),
            'display' => $this->catalogDisplay($saleState),
        ];
    }

    /**
     * @return array{show_price_points: bool, show_total_count: bool, show_drawn_count: bool}
     */
    private function catalogDisplay(string $saleState): array
    {
        $showSalesValues = ! in_array($saleState, ['sold_out', 'ended'], true);

        return [
            'show_price_points' => $showSalesValues,
            'show_total_count' => $showSalesValues,
            'show_drawn_count' => $showSalesValues,
        ];
    }

    private function publishedGachaQuery(bool $withinPublishedPeriod = true): Builder
    {
        $now = CarbonImmutable::now('UTC');

        return DB::table('catalog_gachas as g')
            ->join('catalog_gacha_versions as gv', 'gv.id', '=', 'g.published_version_id')
            ->join(
                'catalog_probability_versions as pv',
                'pv.id',
                '=',
                'gv.published_probability_version_id'
            )
            ->join('catalog_categories as c', 'c.id', '=', 'g.category_id')
            ->leftJoin(
                'gacha_draw_states as ds',
                'ds.id',
                '=',
                'g.active_draw_state_id'
            )
            ->leftJoinSub(
                DB::table('prize_inventories')
                    ->selectRaw(
                        'gacha_draw_state_id, '.
                        'SUM(total_quantity)::bigint AS total_count, '.
                        'SUM(available_quantity)::bigint AS remaining_count'
                    )
                    ->whereNotNull('gacha_draw_state_id')
                    ->groupBy('gacha_draw_state_id'),
                'inventory_totals',
                'inventory_totals.gacha_draw_state_id',
                '=',
                'ds.id'
            )
            ->leftJoin(
                'catalog_presentation_assets as a',
                'a.id',
                '=',
                'g.current_presentation_asset_id'
            )
            ->where('g.state', 'active')
            ->whereIn('g.management_status', [
                'scheduled', 'published', 'sales_paused',
            ])
            ->where('gv.status', 'published')
            ->where('pv.status', 'published')
            ->when($withinPublishedPeriod, function (Builder $query) use ($now): void {
                $query->whereRaw(
                    'COALESCE(g.current_publish_start_at, gv.publish_start_at) <= ?',
                    [$now]
                )
                    ->where(function (Builder $period) use ($now): void {
                        $period->whereNull('g.current_publish_end_at')
                            ->orWhere('g.current_publish_end_at', '>', $now);
                    });
            });
    }

    /**
     * @return list<string>
     */
    private function summaryColumns(): array
    {
        return [
            'g.id as gacha_internal_id',
            'g.public_id',
            'g.public_code',
            'g.slug',
            'g.sales_paused',
            DB::raw('ds.sold_count as sold_count'),
            'ds.status as draw_state_status',
            'gv.id as version_internal_id',
            'g.current_title as title',
            'g.current_description as description',
            'g.current_notices as notices',
            'gv.price_points',
            DB::raw('COALESCE(inventory_totals.total_count, 0) as total_count'),
            DB::raw('COALESCE(inventory_totals.remaining_count, 0) as remaining_count'),
            'gv.daily_draw_limit',
            'gv.audience_code',
            'gv.first_time_eligible_days',
            'gv.allowed_draw_counts',
            DB::raw('COALESCE(g.current_publish_start_at, gv.publish_start_at) as publish_start_at'),
            'g.current_publish_end_at as publish_end_at',
            'gv.published_probability_version_id',
            'c.public_id as category_public_id',
            'c.slug as category_slug',
            'c.display_name as category_name',
            'a.public_id as asset_public_id',
            'a.public_path as asset_public_path',
            'a.checksum_sha256 as asset_checksum_sha256',
            'a.media_type as asset_media_type',
            'a.mime_type as asset_mime_type',
            'a.alt_text as asset_alt_text',
            'a.is_public as asset_is_public',
        ];
    }

    /**
     * @param list<int> $gachaIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function tagsForGachas(array $gachaIds): array
    {
        if ($gachaIds === []) {
            return [];
        }
        $grouped = [];
        foreach (
            DB::table('catalog_gachas as g')
                ->join(
                'catalog_gacha_tags as gt',
                    'gt.gacha_id',
                    '=',
                    'g.id'
                )
                ->join('catalog_tags as t', 't.id', '=', 'gt.tag_id')
                ->whereIn('g.id', $gachaIds)
                ->where('t.is_visible', true)
                ->orderBy('t.sort_order')
                ->orderBy('t.public_id')
                ->get(['g.id as gacha_id', 't.public_id', 't.slug', 't.display_name'])
            as $tag
        ) {
            $grouped[$tag->gacha_id][] = [
                'id' => $tag->public_id,
                'slug' => $tag->slug,
                'name' => $tag->display_name,
            ];
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $tags
     * @return array<string, mixed>
     */
    private function summary(object $row, array $tags): array
    {
        return [
            'id' => $row->public_id,
            'slug' => $row->slug,
            'title' => $row->title,
            'price_points' => (int) $row->price_points,
            'total_count' => (int) $row->total_count,
            'remaining_count' => (int) $row->remaining_count,
            'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)->utc()
                ->toIso8601ZuluString(),
            'publish_end_at' => $row->publish_end_at === null
                ? null
                : CarbonImmutable::parse($row->publish_end_at)->utc()
                    ->toIso8601ZuluString(),
            'category' => [
                'id' => $row->category_public_id,
                'slug' => $row->category_slug,
                'name' => $row->category_name,
            ],
            'tags' => $tags,
            'presentation_asset' => $this->asset($row),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(?object $row): array
    {
        if ($row === null) {
            throw new V2CatalogException(
                'CATALOG_NOT_FOUND',
                404,
                'The requested catalog resource was not found.'
            );
        }

        $tags = $this->tagsForGachas([(int) $row->gacha_internal_id]);
        $result = $this->summary($row, $tags[$row->gacha_internal_id] ?? []);
        $result['sale_state'] = $this->saleState(
            $row,
            CarbonImmutable::now('UTC')->startOfSecond()
        );
        $result['description'] = $row->description;
        $result['notices'] = $row->notices;
        $result['ranks'] = $this->ranks((int) $row->version_internal_id);
        $result['probability_stages'] = $this->stages(
            (int) $row->published_probability_version_id,
            (int) $row->sold_count
        );

        return $result;
    }

    private function saleState(object $row, CarbonImmutable $now): string
    {
        $startsAt = CarbonImmutable::parse($row->publish_start_at)->utc();
        $endsAt = $row->publish_end_at === null
            ? null
            : CarbonImmutable::parse($row->publish_end_at)->utc();
        if ($now->lessThan($startsAt)) {
            return 'coming_soon';
        }
        if ($endsAt !== null && ! $now->lessThan($endsAt)) {
            return 'ended';
        }
        if ((int) $row->remaining_count === 0) {
            return 'sold_out';
        }
        if ((bool) $row->sales_paused) {
            return 'paused';
        }

        return in_array($row->draw_state_status, ['selling', 'sold_out'], true)
            ? 'on_sale'
            : 'ended';
    }

    /**
     * @param array<string, mixed> $evaluation
     */
    private function presentationReason(string $saleState, array $evaluation): ?string
    {
        if ($saleState !== 'on_sale') {
            return match ($saleState) {
                'coming_soon' => 'sale_not_started',
                'paused' => 'sales_paused',
                'sold_out' => 'sold_out',
                default => 'sale_ended',
            };
        }
        if (! $evaluation['authenticated']) {
            return 'authentication_required';
        }
        if (! $evaluation['audience_eligible']) {
            return 'audience_not_eligible';
        }
        if (
            ! $evaluation['daily']['unlimited']
            && $evaluation['daily']['remaining'] === 0
        ) {
            return 'daily_limit_reached';
        }

        return null;
    }

    /**
     * @return array{state: string, action: ?string, reason: ?string}
     */
    private function cta(string $saleState, ?string $reason): array
    {
        if (in_array($saleState, ['sold_out', 'ended'], true)) {
            return ['state' => 'hidden', 'action' => null, 'reason' => $reason];
        }
        if ($reason === 'authentication_required') {
            return ['state' => 'enabled', 'action' => 'login', 'reason' => $reason];
        }
        if ($reason !== null) {
            return ['state' => 'disabled', 'action' => 'draw', 'reason' => $reason];
        }

        return ['state' => 'enabled', 'action' => 'draw', 'reason' => null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ranks(int $gachaVersionId): array
    {
        return DB::table('catalog_gacha_version_prizes as gvp')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'gvp.prize_id')
            ->join(
                'catalog_gacha_ranks as gacha_rank',
                'gacha_rank.id',
                '=',
                'gvp.gacha_rank_id'
            )
            ->join(
                'catalog_rank_masters as master',
                'master.id',
                '=',
                'gacha_rank.rank_master_id'
            )
            ->join(
                'catalog_rank_master_revisions as revision',
                'revision.id',
                '=',
                'master.current_revision_id'
            )
            ->join(
                'catalog_presentation_assets as lineup_asset',
                'lineup_asset.id',
                '=',
                'revision.lineup_image_asset_id'
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
            ->join(
                'prize_inventories as inventory',
                'inventory.gacha_version_prize_id',
                '=',
                'gvp.id'
            )
            ->where('gvp.gacha_version_id', $gachaVersionId)
            ->where('master.status', 'active')
            ->groupBy([
                'master.public_id', 'revision.rank_name', 'revision.show_total_stock',
                'revision.display_order', 'lineup_asset.public_id',
                'lineup_asset.public_path', 'lineup_asset.checksum_sha256',
                'lineup_asset.media_type', 'lineup_asset.mime_type',
                'lineup_asset.alt_text', 'video_asset.public_id',
                'video_asset.public_path', 'video_asset.checksum_sha256',
                'video_asset.media_type', 'video_asset.mime_type',
                'video_asset.alt_text',
            ])
            ->orderBy('revision.display_order')
            ->orderBy('master.public_id')
            ->get([
                'master.public_id as rank_id', 'revision.rank_name',
                'revision.show_total_stock', 'revision.display_order',
                DB::raw('SUM(inventory.total_quantity)::bigint as total_stock'),
                'lineup_asset.public_id as lineup_public_id',
                'lineup_asset.public_path as lineup_path',
                'lineup_asset.checksum_sha256 as lineup_checksum',
                'lineup_asset.media_type as lineup_media_type',
                'lineup_asset.mime_type as lineup_mime_type',
                'lineup_asset.alt_text as lineup_alt_text',
                'video_asset.public_id as video_public_id',
                'video_asset.public_path as video_path',
                'video_asset.checksum_sha256 as video_checksum',
                'video_asset.media_type as video_media_type',
                'video_asset.mime_type as video_mime_type',
                'video_asset.alt_text as video_alt_text',
            ])->map(fn (object $rank): array => [
                'rank_id' => $rank->rank_id,
                'rank_name' => $rank->rank_name,
                'lineup_image' => $this->prefixedAsset($rank, 'lineup'),
                'show_total_stock' => (bool) $rank->show_total_stock,
                'total_stock' => $rank->show_total_stock
                    ? (int) $rank->total_stock
                    : null,
                'display_order' => (int) $rank->display_order,
                'current_video' => $this->prefixedAsset($rank, 'video'),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stages(int $probabilityVersionId, int $soldCount): array
    {
        $stages = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probabilityVersionId)
            ->orderBy('sort_order')
            ->get();
        $stageIds = $stages->pluck('id')->all();
        $rankTotals = DB::table('catalog_probability_entries as pe')
            ->join(
                'catalog_gacha_version_prizes as gvp',
                'gvp.id',
                '=',
                'pe.gacha_version_prize_id'
            )
            ->join(
                'catalog_gacha_ranks as gacha_rank',
                'gacha_rank.id',
                '=',
                'gvp.gacha_rank_id'
            )
            ->join(
                'catalog_rank_masters as master',
                'master.id',
                '=',
                'gacha_rank.rank_master_id'
            )
            ->join(
                'catalog_rank_master_revisions as revision',
                'revision.id',
                '=',
                'master.current_revision_id'
            )
            ->whereIn('pe.probability_stage_id', $stageIds)
            ->where('pe.result_type', 'prize')
            ->where('master.status', 'active')
            ->groupBy(
                'pe.probability_stage_id',
                'master.public_id',
                'revision.rank_name',
                'revision.display_order'
            )
            ->orderBy('revision.display_order')
            ->get([
                'pe.probability_stage_id',
                'master.public_id',
                'revision.rank_name as display_name',
                DB::raw('SUM(pe.probability_ppm)::bigint as total_ppm'),
            ])->groupBy('probability_stage_id');
        $pointBack = DB::table('catalog_probability_entries')
            ->whereIn('probability_stage_id', $stageIds)
            ->where('result_type', 'point_back')
            ->groupBy('probability_stage_id')
            ->get([
                'probability_stage_id',
                DB::raw('SUM(probability_ppm)::bigint as total_ppm'),
            ])->pluck('total_ppm', 'probability_stage_id');
        $guarantees = DB::table('catalog_minimum_guarantees as mg')
            ->leftJoin(
                'catalog_gacha_version_prizes as gvp',
                'gvp.id',
                '=',
                'mg.gacha_version_prize_id'
            )
            ->leftJoin(
                'catalog_gacha_ranks as gacha_rank',
                'gacha_rank.id',
                '=',
                'gvp.gacha_rank_id'
            )
            ->leftJoin(
                'catalog_rank_masters as master',
                'master.id',
                '=',
                'gacha_rank.rank_master_id'
            )
            ->leftJoin(
                'catalog_rank_master_revisions as revision',
                'revision.id',
                '=',
                'master.current_revision_id'
            )
            ->whereIn('mg.probability_stage_id', $stageIds)
            ->get([
                'mg.probability_stage_id',
                'mg.result_type',
                'mg.point_amount',
                'mg.probability_ppm',
                'master.public_id as rank_public_id',
                'revision.rank_name as rank_name',
            ])->keyBy('probability_stage_id');
        $nextDraw = $soldCount + 1;

        return $stages->map(function (object $stage) use (
            $rankTotals,
            $pointBack,
            $guarantees,
            $nextDraw
        ): array {
            $guarantee = $guarantees[$stage->id];
            $guaranteeTarget = $guarantee->result_type === 'prize'
                ? [
                    'rank' => [
                        'id' => $guarantee->rank_public_id,
                        'name' => $guarantee->rank_name,
                    ],
                ]
                : ['point_amount' => (int) $guarantee->point_amount];

            return [
                'id' => $stage->public_id,
                'code' => $stage->code,
                'name' => $stage->display_name,
                'condition' => [
                    'type' => $stage->condition_type,
                    'min_draw_number' => (int) $stage->min_draw_number,
                    'max_draw_number' => $stage->max_draw_number === null
                        ? null
                        : (int) $stage->max_draw_number,
                ],
                'is_current' => $stage->min_draw_number <= $nextDraw
                    && ($stage->max_draw_number === null
                        || $stage->max_draw_number >= $nextDraw),
                'rank_probabilities' => ($rankTotals[$stage->id] ?? collect())
                    ->map(fn (object $rank): array => [
                        'rank' => [
                            'id' => $rank->public_id,
                            'name' => $rank->display_name,
                        ],
                        'total_ppm' => (int) $rank->total_ppm,
                    ])->values()->all(),
                'point_back_total_ppm' => (int) ($pointBack[$stage->id] ?? 0),
                'minimum_guarantee' => [
                    'result_type' => $guarantee->result_type,
                    'total_ppm' => (int) $guarantee->probability_ppm,
                    ...$guaranteeTarget,
                ],
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function prefixedAsset(object $row, string $prefix): array
    {
        return [
            'id' => $row->{$prefix.'_public_id'},
            'path' => $row->{$prefix.'_path'},
            'checksum_sha256' => $row->{$prefix.'_checksum'},
            'media_type' => $row->{$prefix.'_media_type'},
            'mime_type' => $row->{$prefix.'_mime_type'},
            'alt_text' => $row->{$prefix.'_alt_text'},
        ];
    }

    /** @return array<string, mixed>|null */
    private function asset(object $row): ?array
    {
        if ($row->asset_public_id === null || ! (bool) $row->asset_is_public) {
            return null;
        }

        return [
            'id' => $row->asset_public_id,
            'path' => '/api/v2/content/assets/'.$row->asset_public_id,
            'checksum_sha256' => $row->asset_checksum_sha256,
            'media_type' => $row->asset_media_type,
            'mime_type' => $row->asset_mime_type,
            'alt_text' => $row->asset_alt_text,
        ];
    }

    private function encodeCursor(string $publicId): string
    {
        return rtrim(strtr(base64_encode($publicId), '+/', '-_'), '=');
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

    private function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $cursor)) {
            throw new V2CatalogException('INVALID_CURSOR', 422, 'The cursor is invalid.');
        }
        $decoded = base64_decode(
            strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4),
            true
        );
        if (! is_string($decoded) || ! Str::isUuid($decoded)) {
            throw new V2CatalogException('INVALID_CURSOR', 422, 'The cursor is invalid.');
        }

        return $decoded;
    }
}
