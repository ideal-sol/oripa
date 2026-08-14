<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class V2ContentReadService
{
    public function __construct(
        private readonly V2ContentCursor $cursor,
        private readonly V2ContentHtmlSanitizer $sanitizer
    ) {
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function banners(): array
    {
        $rows = $this->publishedQuery('content_banners', 'banner_id')
            ->leftJoin('content_version_assets as cva', function ($join): void {
                $join->on('cva.content_version_id', '=', 'cv.id')
                    ->where('cva.usage_type', 'image');
            })
            ->leftJoin('catalog_presentation_assets as asset', 'asset.id', '=', 'cva.presentation_asset_id')
            ->where('asset.is_public', true)
            ->where('asset.media_type', 'image')
            ->orderBy('cv.sort_order')
            ->orderBy('p.id')
            ->get([
                'p.public_id',
                'cv.title',
                'cv.link_url',
                'cv.publish_start_at',
                'cv.publish_end_at',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.alt_text',
            ])
            ->map(fn (object $row): array => [
                'id' => $row->public_id,
                'title' => $row->title,
                'link_url' => $row->link_url,
                'asset' => [
                    'id' => $row->asset_public_id,
                    'path' => $this->assetPublicPath($row->asset_public_id),
                    'checksum_sha256' => $row->asset_checksum,
                    'alt_text' => $row->alt_text,
                ],
                'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)
                    ->toIso8601String(),
                'publish_end_at' => $row->publish_end_at === null
                    ? null
                    : CarbonImmutable::parse($row->publish_end_at)->toIso8601String(),
            ])
            ->all();

        return ['items' => $rows];
    }

    /** @return array{content: string, mime_type: string} */
    public function assetContent(string $publicId): array
    {
        $asset = DB::table('catalog_presentation_assets')
            ->where('public_id', $publicId)
            ->where('media_type', 'image')
            ->where('is_public', true)
            ->whereNull('archived_at')
            ->first(['storage_identifier', 'mime_type']);
        if ($asset === null) {
            throw $this->notFound();
        }
        $disk = Storage::disk(config('filesystems.default'));
        if (! $disk->exists($asset->storage_identifier)) {
            throw $this->notFound();
        }

        return [
            'content' => $disk->get($asset->storage_identifier),
            'mime_type' => $asset->mime_type,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function notices(?string $cursor, int $limit): array
    {
        $after = $this->cursor->decode($cursor);
        $limit = $this->limit($limit);
        $rows = $this->publishedQuery('content_notices', 'notice_id')
            ->leftJoin('content_version_assets as cva', function ($join): void {
                $join->on('cva.content_version_id', '=', 'cv.id')
                    ->where('cva.usage_type', 'thumbnail');
            })
            ->leftJoin('catalog_presentation_assets as asset', function ($join): void {
                $join->on('asset.id', '=', 'cva.presentation_asset_id')
                    ->where('asset.is_public', true)
                    ->where('asset.media_type', 'image');
            })
            ->when($after !== null, fn (Builder $query) => $query->where('cv.id', '<', $after))
            ->orderByDesc('cv.id')
            ->limit($limit + 1)
            ->get([
                'cv.id as version_id',
                'p.public_id',
                'p.slug',
                'cv.title',
                'cv.summary',
                'cv.is_important',
                'cv.publish_start_at',
                'cv.publish_end_at',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.alt_text',
            ]);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => [
            'id' => $row->public_id,
            'slug' => $row->slug,
            'title' => $row->title,
            'summary' => $row->summary,
            'is_important' => (bool) $row->is_important,
            'asset' => $this->asset($row),
            'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)->toIso8601String(),
            'publish_end_at' => $row->publish_end_at === null
                ? null
                : CarbonImmutable::parse($row->publish_end_at)->toIso8601String(),
        ])->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore
                ? $this->cursor->encode((int) $rows->get($limit - 1)->version_id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function notice(string $publicId): array
    {
        $row = $this->publishedQuery('content_notices', 'notice_id')
            ->leftJoin('content_version_assets as cva', function ($join): void {
                $join->on('cva.content_version_id', '=', 'cv.id')
                    ->where('cva.usage_type', 'thumbnail');
            })
            ->leftJoin('catalog_presentation_assets as asset', function ($join): void {
                $join->on('asset.id', '=', 'cva.presentation_asset_id')
                    ->where('asset.is_public', true)
                    ->where('asset.media_type', 'image');
            })
            ->where('p.public_id', $publicId)
            ->first([
                'p.public_id',
                'p.slug',
                'cv.title',
                'cv.summary',
                'cv.body_html',
                'cv.is_important',
                'cv.content_checksum',
                'cv.publish_start_at',
                'cv.publish_end_at',
                'asset.public_id as asset_public_id',
                'asset.public_path as asset_path',
                'asset.checksum_sha256 as asset_checksum',
                'asset.alt_text',
            ]);
        if ($row === null) {
            throw $this->notFound();
        }

        return $this->document($row, includeSummary: true);
    }

    /** @return array<string, mixed> */
    public function staticPage(string $slug): array
    {
        $row = $this->publishedQuery('content_static_pages', 'static_page_id')
            ->where('p.slug', $slug)
            ->first([
                'p.public_id',
                'p.slug',
                'p.is_legal',
                'cv.title',
                'cv.body_html',
                'cv.content_checksum',
                'cv.publish_start_at',
                'cv.publish_end_at',
            ]);
        if ($row === null) {
            throw $this->notFound();
        }

        return [
            ...$this->document($row),
            'is_legal' => (bool) $row->is_legal,
        ];
    }

    /** @return array{items: list<array{id: string, slug: string, title: string}>} */
    public function footerPages(): array
    {
        $items = $this->publishedQuery('content_static_pages', 'static_page_id')
            ->where('p.show_in_footer', true)
            ->orderBy('cv.sort_order')
            ->orderBy('p.id')
            ->get(['p.public_id', 'p.slug', 'cv.title'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->public_id,
                'slug' => (string) $row->slug,
                'title' => (string) $row->title,
            ])->all();

        return ['items' => $items];
    }

    private function publishedQuery(string $table, string $ownerColumn): Builder
    {
        $now = now();

        return DB::table($table.' as p')
            ->join('content_versions as cv', function ($join) use ($ownerColumn): void {
                $join->on('cv.id', '=', 'p.published_version_id')
                    ->on('cv.'.$ownerColumn, '=', 'p.id');
            })
            ->where('p.status', 'published')
            ->where('cv.status', 'published')
            ->where('cv.publish_start_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('cv.publish_end_at')
                    ->orWhere('cv.publish_end_at', '>', $now);
            });
    }

    /** @return array<string, mixed> */
    private function document(object $row, bool $includeSummary = false): array
    {
        $result = [
            'id' => $row->public_id,
            'slug' => $row->slug,
            'title' => $row->title,
            'body_html' => $this->sanitizer->sanitize($row->body_html),
            'checksum_sha256' => $row->content_checksum,
            'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)
                ->toIso8601String(),
            'publish_end_at' => $row->publish_end_at === null
                ? null
                : CarbonImmutable::parse($row->publish_end_at)->toIso8601String(),
        ];
        if ($includeSummary) {
            $result['summary'] = $row->summary;
            $result['is_important'] = (bool) $row->is_important;
            $result['asset'] = $this->asset($row);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function asset(object $row): ?array
    {
        if (! property_exists($row, 'asset_public_id') || $row->asset_public_id === null) {
            return null;
        }

        return [
            'id' => $row->asset_public_id,
            'path' => $row->asset_path,
            'checksum_sha256' => $row->asset_checksum,
            'alt_text' => $row->alt_text,
        ];
    }

    private function assetPublicPath(string $publicId): string
    {
        return '/api/v2/content/assets/'.$publicId;
    }

    private function limit(int $limit): int
    {
        $maximum = (int) config('v2_content_contact.cursor_maximum', 100);
        if ($limit < 1 || $limit > $maximum) {
            throw new V2ContentContactException(
                'CONTENT_PAGE_SIZE_INVALID',
                422,
                'The Content page size is invalid.'
            );
        }

        return $limit;
    }

    private function notFound(): V2ContentContactException
    {
        return new V2ContentContactException(
            'CONTENT_NOT_FOUND',
            404,
            'The Content resource was not found.'
        );
    }
}
