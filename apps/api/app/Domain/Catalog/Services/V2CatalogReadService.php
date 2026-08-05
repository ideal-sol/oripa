<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2CatalogReadService
{
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

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(
        int $limit,
        ?string $cursor,
        ?string $categorySlug,
        ?string $tagSlug
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
        $query = $this->publishedGachaQuery()
            ->when($cursorId !== null, fn (Builder $builder): Builder =>
                $builder->where('g.public_id', '>', $cursorId))
            ->when($categorySlug !== null, fn (Builder $builder): Builder =>
                $builder->where('c.slug', $categorySlug))
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
        $data = $rows->map(
            fn (object $row): array => $this->summary($row, $tags[$row->gacha_internal_id] ?? [])
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
            $this->publishedGachaQuery()->where(
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
            $this->publishedGachaQuery()->where('g.slug', $slug)->first(
                $this->summaryColumns()
            )
        );
    }

    private function publishedGachaQuery(): Builder
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
            ->join('catalog_categories as c', function ($join): void {
                $join->on(
                    'c.id',
                    '=',
                    DB::raw('COALESCE(gv.category_id, g.category_id)')
                );
            })
            ->leftJoin(
                'gacha_draw_states as ds',
                'ds.id',
                '=',
                'g.active_draw_state_id'
            )
            ->leftJoin(
                'catalog_presentation_assets as a',
                'a.id',
                '=',
                'gv.presentation_asset_id'
            )
            ->where('g.state', 'active')
            ->where('gv.status', 'published')
            ->where('pv.status', 'published')
            ->where('c.is_visible', true)
            ->where('gv.publish_start_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('gv.publish_end_at')
                    ->orWhere('gv.publish_end_at', '>', $now);
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
            DB::raw('COALESCE(ds.sold_count, g.sold_count) as sold_count'),
            'gv.id as version_internal_id',
            'gv.title',
            'gv.description',
            'gv.notices',
            'gv.price_points',
            'gv.total_count',
            'gv.publish_start_at',
            'gv.publish_end_at',
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
                    'catalog_gacha_version_tags as gt',
                    'gt.gacha_version_id',
                    '=',
                    'g.published_version_id'
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
            'remaining_count' => (bool) $row->sales_paused
                ? 0
                : max(0, (int) $row->total_count - (int) $row->sold_count),
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
        $result['description'] = $row->description;
        $result['notices'] = $row->notices;
        $result['ranks'] = $this->ranks((int) $row->version_internal_id);
        $result['probability_stages'] = $this->stages(
            (int) $row->published_probability_version_id,
            (int) $row->sold_count
        );

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ranks(int $gachaVersionId): array
    {
        $rankRows = DB::table('catalog_gacha_version_prizes as gvp')
            ->join('catalog_prizes as p', 'p.id', '=', 'gvp.prize_id')
            ->join('catalog_ranks as r', 'r.id', '=', 'p.rank_id')
            ->where('gvp.gacha_version_id', $gachaVersionId)
            ->where('p.is_visible', true)
            ->where('r.is_visible', true)
            ->select(
                'r.id as rank_internal_id',
                'r.public_id as rank_public_id',
                'r.code as rank_code',
                'r.display_name as rank_name',
                'r.sort_order as rank_sort_order'
            )
            ->distinct()
            ->orderBy('r.sort_order')
            ->orderBy('r.public_id')
            ->get();
        $prizeRows = DB::table('catalog_gacha_version_prizes as gvp')
            ->join('catalog_prizes as p', 'p.id', '=', 'gvp.prize_id')
            ->leftJoin(
                'catalog_presentation_assets as a',
                'a.id',
                '=',
                'p.presentation_asset_id'
            )
            ->where('gvp.gacha_version_id', $gachaVersionId)
            ->where('p.is_visible', true)
            ->orderBy('gvp.sort_order')
            ->orderBy('p.public_id')
            ->get([
                'p.rank_id',
                'p.public_id',
                'p.display_name',
                'p.description',
                'p.display_price',
                'p.exchange_points',
                'a.public_id as asset_public_id',
                'a.public_path as asset_public_path',
                'a.checksum_sha256 as asset_checksum_sha256',
                'a.media_type as asset_media_type',
                'a.mime_type as asset_mime_type',
                'a.alt_text as asset_alt_text',
                'a.is_public as asset_is_public',
            ])->groupBy('rank_id');

        return $rankRows->map(function (object $rank) use ($prizeRows): array {
            return [
                'id' => $rank->rank_public_id,
                'code' => $rank->rank_code,
                'name' => $rank->rank_name,
                'presentation_assets' => $this->rankAssets((int) $rank->rank_internal_id),
                'prizes' => ($prizeRows[$rank->rank_internal_id] ?? collect())
                    ->map(fn (object $prize): array => [
                        'id' => $prize->public_id,
                        'name' => $prize->display_name,
                        'description' => $prize->description,
                        'display_price' => (int) $prize->display_price,
                        'exchange_points' => (int) $prize->exchange_points,
                        'presentation_asset' => $this->asset($prize),
                    ])->values()->all(),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rankAssets(int $rankId): array
    {
        return DB::table('catalog_rank_assets as ra')
            ->join('catalog_presentation_assets as a', 'a.id', '=', 'ra.presentation_asset_id')
            ->where('ra.rank_id', $rankId)
            ->where('a.is_public', true)
            ->orderBy('ra.sort_order')
            ->orderBy('a.public_id')
            ->get([
                'ra.usage_type',
                'a.public_id as asset_public_id',
                'a.public_path as asset_public_path',
                'a.checksum_sha256 as asset_checksum_sha256',
                'a.media_type as asset_media_type',
                'a.mime_type as asset_mime_type',
                'a.alt_text as asset_alt_text',
                'a.is_public as asset_is_public',
            ])
            ->map(fn (object $asset): array => [
                'usage_type' => $asset->usage_type,
                ...$this->asset($asset),
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
            ->join('catalog_prizes as p', 'p.id', '=', 'gvp.prize_id')
            ->join('catalog_ranks as r', 'r.id', '=', 'p.rank_id')
            ->whereIn('pe.probability_stage_id', $stageIds)
            ->where('pe.result_type', 'prize')
            ->where('r.is_visible', true)
            ->groupBy(
                'pe.probability_stage_id',
                'r.public_id',
                'r.code',
                'r.display_name',
                'r.sort_order'
            )
            ->orderBy('r.sort_order')
            ->get([
                'pe.probability_stage_id',
                'r.public_id',
                'r.code',
                'r.display_name',
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
            ->leftJoin('catalog_prizes as p', 'p.id', '=', 'gvp.prize_id')
            ->leftJoin('catalog_ranks as r', 'r.id', '=', 'p.rank_id')
            ->whereIn('mg.probability_stage_id', $stageIds)
            ->get([
                'mg.probability_stage_id',
                'mg.result_type',
                'mg.point_amount',
                'mg.probability_ppm',
                'r.public_id as rank_public_id',
                'r.code as rank_code',
                'r.display_name as rank_name',
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
                        'code' => $guarantee->rank_code,
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
                            'code' => $rank->code,
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

    /**
     * @return array<string, mixed>|null
     */
    private function asset(object $row): ?array
    {
        if ($row->asset_public_id === null || ! (bool) $row->asset_is_public) {
            return null;
        }

        return [
            'id' => $row->asset_public_id,
            'path' => $row->asset_public_path,
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
