<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class V2CardInventoryService
{
    public function __construct(private readonly V2AdminFreshMfaAuthorizer $authorizer)
    {
    }

    public function cards(V2AdminAuthorizationContext $context, array $filters = []): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);

        return $this->cardPage($filters);
    }

    public function card(V2AdminAuthorizationContext $context, string $externalId, array $filters = []): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $externalId) !== 1) {
            throw $this->invalid();
        }
        $limit = $this->limit($filters);
        $scope = ['card', $externalId, $limit];
        $after = $this->after($filters, $scope);
        $sql = $this->commonSql().<<<'SQL'
            , breakdown AS (
                SELECT version_key AS cursor_key, gacha_id, gacha_name, management_status,
                    gacha_archived, version_id, version_number, bool_or(selected) AS is_selected_version,
                    sum(allocation)::bigint AS allocation, sum(allocation)::bigint AS unwon,
                    sum(won)::bigint AS won, sum(stored)::bigint AS stored,
                    sum(shipped)::bigint AS shipped, sum(returned)::bigint AS returned
                FROM relations WHERE external_id = ? AND (selected OR won > 0)
                GROUP BY version_key, gacha_id, gacha_name, management_status,
                    gacha_archived, version_id, version_number
            ), page AS (
                SELECT * FROM breakdown WHERE cursor_key > ? ORDER BY cursor_key LIMIT ?
            ), statuses AS (
                SELECT ownership.status, count(*)::bigint AS quantity
                FROM ownership JOIN catalog_gacha_version_prizes relation ON relation.id = ownership.gacha_version_prize_id
                JOIN catalog_prizes prize ON prize.id = relation.prize_id
                WHERE prize.external_id = ? GROUP BY ownership.status
            )
            SELECT (SELECT row_to_json(card) FROM cards card WHERE external_id = ?) AS card,
                COALESCE((SELECT jsonb_agg(to_jsonb(page) ORDER BY cursor_key) FROM page), '[]') AS items,
                COALESCE((SELECT jsonb_object_agg(status, quantity) FROM statuses), '{}') AS status_counts,
                (SELECT invalid FROM integrity) AS invalid
            SQL;
        $row = DB::selectOne($sql, [$externalId, $after ?? 0, $limit + 1, $externalId, $externalId]);
        $this->assertIntegrity($row);
        if ($row->card === null) {
            throw new V2CatalogException('CATALOG_RESOURCE_NOT_FOUND', 404, 'The card was not found.');
        }
        [$items, $cursor] = $this->page($row, $limit, $scope);

        return [
            'data' => $this->mapCard(json_decode($row->card, true, 512, JSON_THROW_ON_ERROR)),
            'breakdown' => $items,
            'next_cursor' => $cursor,
            'status_counts' => json_decode($row->status_counts, false, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function unlinkedPrizes(V2AdminAuthorizationContext $context, array $filters = []): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);
        $limit = $this->limit($filters);
        $scope = ['unlinked', $limit];
        $after = $this->after($filters, $scope);
        $row = DB::selectOne($this->commonSql().<<<'SQL'
            , page AS (
                SELECT relation_key AS cursor_key, version_id || '.' || prize_id AS id,
                    prize_id, prize_name, gacha_id, gacha_name, management_status,
                    gacha_archived, version_id, version_number, allocation, stored,
                    allocation + stored AS quantity
                FROM unlinked WHERE relation_key > ? ORDER BY relation_key LIMIT ?
            )
            SELECT COALESCE((SELECT jsonb_agg(to_jsonb(page) ORDER BY cursor_key) FROM page), '[]') AS items,
                (SELECT count(*) FROM unlinked) AS total_count,
                (SELECT invalid FROM integrity) AS invalid
            SQL, [$after ?? 0, $limit + 1]);
        $this->assertIntegrity($row);
        [$items, $cursor] = $this->page($row, $limit, $scope);

        return ['items' => $items, 'next_cursor' => $cursor, 'total_count' => (int) $row->total_count];
    }

    public function snapshotExport(): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Inventory export requires a top-level read-only snapshot.');
        }
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Inventory snapshot storage is unavailable.');
        }
        try {
            $unlinkedCount = DB::transaction(function () use ($stream): int {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $cursor = null;
                do {
                    $page = $this->cardPage(['limit' => 100, 'cursor' => $cursor]);
                    foreach ($page['items'] as $card) {
                        $line = json_encode($card, JSON_THROW_ON_ERROR)."\n";
                        if (fwrite($stream, $line) !== strlen($line)) {
                            throw new RuntimeException('Inventory snapshot storage is unavailable.');
                        }
                    }
                    $cursor = $page['next_cursor'];
                } while ($cursor !== null);

                return $page['unlinked_prize_count'];
            });
            if (! rewind($stream)) {
                throw new RuntimeException('Inventory snapshot storage is unavailable.');
            }

            return ['stream' => $stream, 'unlinked_prize_count' => $unlinkedCount];
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private function cardPage(array $filters): array
    {
        $limit = $this->limit($filters);
        $externalId = $this->text($filters, 'external_id', 64) ?? '';
        $name = $this->text($filters, 'name', 200) ?? '';
        $reserved = $this->boolean($filters, 'reserved_only');
        $negative = $this->boolean($filters, 'negative_only');
        $scope = ['cards', $externalId, $name, $reserved, $negative, $limit];
        $after = $this->after($filters, $scope);
        $row = DB::selectOne($this->commonSql().<<<'SQL'
            , page AS (
                SELECT *, external_id AS cursor_key FROM cards
                WHERE external_id COLLATE "C" > ? COLLATE "C"
                    AND strpos(external_id, ?) > 0 AND strpos(lower(COALESCE(name, '')), lower(?)) > 0
                    AND (? = 0 OR allocation + stored > 0) AND ? = 0
                ORDER BY external_id COLLATE "C" LIMIT ?
            )
            SELECT COALESCE((SELECT jsonb_agg(to_jsonb(page) ORDER BY external_id COLLATE "C") FROM page), '[]') AS items,
                (SELECT count(*) FROM unlinked) AS total_count,
                (SELECT invalid FROM integrity) AS invalid
            SQL, [$after ?? '', $externalId, $name, (int) $reserved, (int) $negative, $limit + 1]);
        $this->assertIntegrity($row);
        [$items, $cursor] = $this->page($row, $limit, $scope);

        return [
            'items' => array_map($this->mapCard(...), $items),
            'next_cursor' => $cursor,
            'unlinked_prize_count' => (int) $row->total_count,
        ];
    }

    private function commonSql(): string
    {
        return <<<'SQL'
            WITH draft_versions AS (
                SELECT DISTINCT ON (gacha_id) id, gacha_id
                FROM catalog_gacha_versions WHERE status = 'draft' AND archived_at IS NULL
                ORDER BY gacha_id, version_number DESC
            ), selected_versions AS (
                SELECT gacha.id AS gacha_id, gacha.management_status,
                    CASE WHEN gacha.management_status IN ('draft', 'scheduled') THEN draft.id
                        ELSE gacha.published_version_id END AS version_id
                FROM catalog_gachas gacha LEFT JOIN draft_versions draft ON draft.gacha_id = gacha.id
                WHERE gacha.archived_at IS NULL
                    AND gacha.management_status IN ('draft', 'scheduled', 'published', 'sales_paused')
            ), allocation AS (
                SELECT relation.id, CASE WHEN inventory.id IS NOT NULL THEN inventory.available_quantity
                    WHEN selected.management_status IN ('draft', 'scheduled') THEN relation.initial_inventory
                    ELSE NULL END AS quantity
                FROM selected_versions selected
                JOIN catalog_gacha_versions version ON version.id = selected.version_id
                    AND version.gacha_id = selected.gacha_id AND version.archived_at IS NULL
                JOIN catalog_gacha_version_prizes relation ON relation.gacha_version_id = version.id
                JOIN catalog_prizes prize ON prize.id = relation.prize_id AND prize.archived_at IS NULL
                LEFT JOIN prize_inventories inventory ON inventory.gacha_version_prize_id = relation.id
            ), integrity AS (
                SELECT EXISTS(SELECT 1 FROM allocation WHERE quantity IS NULL OR quantity < 0)
                    OR EXISTS(SELECT 1 FROM selected_versions selected
                        LEFT JOIN catalog_gacha_versions version ON version.id = selected.version_id
                            AND version.gacha_id = selected.gacha_id
                        WHERE selected.management_status IN ('published', 'sales_paused')
                            AND (version.id IS NULL OR version.archived_at IS NOT NULL)) AS invalid
            ), ownership AS (
                SELECT owned.gacha_version_prize_id, owned.status
                FROM user_prizes owned JOIN draw_results result ON result.id = owned.draw_result_id
                WHERE result.is_qa_draw = false
            ), ownership_counts AS (
                SELECT gacha_version_prize_id,
                    count(*) FILTER (WHERE status IN ('stored', 'exchange_processing', 'shipping_requested', 'packing', 'hold')) AS stored,
                    count(*) FILTER (WHERE status IN ('shipped', 'delivered', 'return_requested')) AS shipped,
                    count(*) FILTER (WHERE status = 'returned') AS returned, count(*) AS won
                FROM ownership GROUP BY gacha_version_prize_id
            ), relations AS (
                SELECT relation.id AS relation_key, version.id AS version_key, prize.external_id,
                    prize.public_id AS prize_id, prize.display_name AS prize_name,
                    gacha.public_id AS gacha_id, version.title AS gacha_name, gacha.management_status,
                    gacha.archived_at IS NOT NULL AS gacha_archived,
                    version.public_id AS version_id, version.version_number,
                    allocation.id IS NOT NULL AS selected, COALESCE(allocation.quantity, 0)::bigint AS allocation,
                    COALESCE(owned.stored, 0)::bigint AS stored, COALESCE(owned.shipped, 0)::bigint AS shipped,
                    COALESCE(owned.returned, 0)::bigint AS returned, COALESCE(owned.won, 0)::bigint AS won
                FROM catalog_gacha_version_prizes relation
                JOIN catalog_gacha_versions version ON version.id = relation.gacha_version_id
                JOIN catalog_gachas gacha ON gacha.id = version.gacha_id
                JOIN catalog_prizes prize ON prize.id = relation.prize_id
                LEFT JOIN allocation ON allocation.id = relation.id
                LEFT JOIN ownership_counts owned ON owned.gacha_version_prize_id = relation.id
            ), unlinked AS (
                SELECT * FROM relations WHERE external_id IS NULL AND (selected OR stored > 0)
            ), totals AS (
                SELECT external_id, sum(allocation)::bigint AS allocation, sum(stored)::bigint AS stored,
                    sum(shipped)::bigint AS shipped, sum(returned)::bigint AS returned
                FROM relations WHERE external_id IS NOT NULL GROUP BY external_id
            ), library AS (
                SELECT banner.external_id, version.title AS name, asset.public_id AS asset_id,
                    asset.mime_type, asset.alt_text
                FROM content_banners banner
                LEFT JOIN LATERAL (
                    SELECT id, title FROM content_versions WHERE banner_id = banner.id ORDER BY version_number DESC LIMIT 1
                ) version ON true
                LEFT JOIN LATERAL (
                    SELECT presentation_asset_id FROM content_version_assets
                    WHERE content_version_id = version.id AND usage_type = 'image' ORDER BY presentation_asset_id LIMIT 1
                ) link ON true
                LEFT JOIN catalog_presentation_assets asset ON asset.id = link.presentation_asset_id
                    AND asset.archived_at IS NULL AND asset.is_public = true AND asset.media_type = 'image'
                WHERE banner.external_id IS NOT NULL AND banner.status <> 'archived'
            ), identities AS (
                SELECT external_id FROM catalog_prizes WHERE external_id IS NOT NULL
                UNION SELECT external_id FROM library
            ), cards AS (
                SELECT identities.external_id, library.name, library.asset_id, library.mime_type, library.alt_text,
                    COALESCE(totals.allocation, 0)::bigint AS allocation, COALESCE(totals.stored, 0)::bigint AS stored,
                    COALESCE(totals.shipped, 0)::bigint AS shipped, COALESCE(totals.returned, 0)::bigint AS returned
                FROM identities LEFT JOIN totals ON totals.external_id = identities.external_id
                LEFT JOIN library ON library.external_id = identities.external_id
            )
            SQL;
    }

    private function mapCard(array $row): array
    {
        return [
            'external_id' => $row['external_id'], 'name' => $row['name'],
            'asset' => $row['asset_id'] === null ? null : [
                'id' => $row['asset_id'], 'media_type' => 'image', 'mime_type' => $row['mime_type'],
                'alt_text' => $row['alt_text'], 'is_public' => true,
                'public_path' => '/api/v2/content/assets/'.$row['asset_id'],
            ],
            'allocation' => (int) $row['allocation'], 'stored' => (int) $row['stored'],
            'required_quantity' => (int) $row['allocation'] + (int) $row['stored'],
            'shipped' => (int) $row['shipped'], 'returned' => (int) $row['returned'],
            'notion_on_hand' => null, 'notion_available' => null, 'notion_read_at' => null,
        ];
    }

    private function assertIntegrity(object $row): void
    {
        if ($row->invalid) {
            throw new V2CatalogException('PRIZE_INVENTORY_UNAVAILABLE', 409, 'The selected inventory is inconsistent.');
        }
    }

    private function page(object $row, int $limit, array $scope): array
    {
        $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $last = $items === [] ? null : $items[array_key_last($items)]['cursor_key'];
        foreach ($items as &$item) {
            unset($item['cursor_key']);
        }
        unset($item);

        return [$items, $hasMore ? Crypt::encryptString(json_encode([
            'scope' => $scope, 'after' => $last,
        ], JSON_THROW_ON_ERROR)) : null];
    }

    private function after(array $filters, array $scope): string|int|null
    {
        $cursor = $this->text($filters, 'cursor', 4096);
        if ($cursor === null) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 32, JSON_THROW_ON_ERROR);
            if (($decoded['scope'] ?? null) !== $scope
                || (! is_string($decoded['after'] ?? null) && ! is_int($decoded['after'] ?? null))) {
                throw new RuntimeException('Invalid cursor.');
            }

            return $decoded['after'];
        } catch (Throwable) {
            throw new V2CatalogException('CATALOG_CURSOR_INVALID', 422, 'The inventory cursor is invalid.');
        }
    }

    private function text(array $filters, string $key, int $maximum): ?string
    {
        $value = $filters[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $maximum) {
            throw $this->invalid();
        }

        return trim($value);
    }

    private function boolean(array $filters, string $key): bool
    {
        $value = $filters[$key] ?? false;
        if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            throw $this->invalid();
        }

        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private function limit(array $filters): int
    {
        $value = $filters['limit'] ?? 20;
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value)))
            || (int) $value < 1 || (int) $value > 100) {
            throw $this->invalid();
        }

        return (int) $value;
    }

    private function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_INPUT_INVALID', 422, 'The inventory input is invalid.');
    }
}
