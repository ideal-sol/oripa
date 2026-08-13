<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->foreignId('gacha_id')->nullable()->after('code')
                ->constrained('catalog_gachas')->restrictOnDelete();
        });
        Schema::table('catalog_gacha_version_prizes', function (Blueprint $table): void {
            $table->foreignId('rank_id')->nullable()->after('prize_id')
                ->constrained('catalog_ranks')->restrictOnDelete();
            $table->string('rank_code', 32)->nullable()->after('rank_id');
            $table->string('rank_display_name', 128)->nullable()->after('rank_code');
            $table->unsignedInteger('rank_sort_order')->nullable()->after('rank_display_name');
            $table->foreignId('presentation_asset_id')->nullable()->after('rank_sort_order')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->string('display_name', 191)->nullable()->after('presentation_asset_id');
            $table->text('description')->nullable()->after('display_name');
            $table->bigInteger('display_price')->nullable()->after('description');
            $table->bigInteger('exchange_points')->nullable()->after('display_price');
            $table->bigInteger('cost_price')->nullable()->after('exchange_points');
            $table->boolean('is_visible')->nullable()->after('cost_price');
        });

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM catalog_prizes prize
                    LEFT JOIN catalog_gacha_version_prizes relation
                      ON relation.prize_id = prize.id
                    LEFT JOIN catalog_gacha_versions version
                      ON version.id = relation.gacha_version_id
                    GROUP BY prize.id
                    HAVING COUNT(DISTINCT version.gacha_id) <> 1
                ) THEN
                    RAISE EXCEPTION
                        'Every Catalog Prize must resolve to exactly one Gacha before ownership backfill';
                END IF;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            UPDATE catalog_prizes prize
            SET gacha_id = owner.gacha_id,
                revision = prize.revision + 1,
                updated_at = CURRENT_TIMESTAMP
            FROM (
                SELECT relation.prize_id, MIN(version.gacha_id) AS gacha_id
                FROM catalog_gacha_version_prizes relation
                JOIN catalog_gacha_versions version
                  ON version.id = relation.gacha_version_id
                GROUP BY relation.prize_id
            ) owner
            WHERE owner.prize_id = prize.id
        SQL);
        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes '.
            'DISABLE TRIGGER catalog_gacha_version_prizes_protect_published'
        );
        DB::statement(<<<'SQL'
            UPDATE catalog_gacha_version_prizes relation
            SET rank_id = prize.rank_id,
                rank_code = rank.code,
                rank_display_name = rank.display_name,
                rank_sort_order = rank.sort_order,
                presentation_asset_id = prize.presentation_asset_id,
                display_name = prize.display_name,
                description = prize.description,
                display_price = prize.display_price,
                exchange_points = prize.exchange_points,
                cost_price = prize.cost_price,
                is_visible = prize.is_visible
            FROM catalog_prizes prize
            JOIN catalog_ranks rank ON rank.id = prize.rank_id
            WHERE prize.id = relation.prize_id
        SQL);
        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes '.
            'ENABLE TRIGGER catalog_gacha_version_prizes_protect_published'
        );

        DB::statement('ALTER TABLE catalog_prizes ALTER COLUMN gacha_id SET NOT NULL');
        foreach ([
            'rank_id',
            'rank_code',
            'rank_display_name',
            'rank_sort_order',
            'display_name',
            'display_price',
            'exchange_points',
            'cost_price',
            'is_visible',
        ] as $column) {
            DB::statement(
                "ALTER TABLE catalog_gacha_version_prizes ALTER COLUMN {$column} SET NOT NULL"
            );
        }
        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes ADD CONSTRAINT '.
            'catalog_gacha_version_prize_snapshot_values_check CHECK ('.
            'display_price >= 0 AND exchange_points >= 0 AND cost_price >= 0)'
        );
        DB::statement(
            'CREATE INDEX catalog_prizes_gacha_id_id_index ON catalog_prizes (gacha_id, id)'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_prize_gacha_ownership()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_gacha_id bigint;
            DECLARE prize_gacha_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_prizes' THEN
                    IF TG_OP = 'UPDATE' AND NEW.gacha_id IS DISTINCT FROM OLD.gacha_id THEN
                        RAISE EXCEPTION 'Catalog Prize Gacha ownership is immutable';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT gacha_id INTO version_gacha_id
                FROM catalog_gacha_versions
                WHERE id = NEW.gacha_version_id;
                SELECT gacha_id INTO prize_gacha_id
                FROM catalog_prizes
                WHERE id = NEW.prize_id;
                IF version_gacha_id IS NULL
                   OR prize_gacha_id IS NULL
                   OR version_gacha_id IS DISTINCT FROM prize_gacha_id THEN
                    RAISE EXCEPTION 'Cross-Gacha Prize association is not allowed';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_prizes_gacha_ownership_guard '.
            'BEFORE UPDATE OF gacha_id ON catalog_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_prize_gacha_ownership()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_gacha_ownership_guard '.
            'BEFORE INSERT OR UPDATE OF gacha_version_id, prize_id '.
            'ON catalog_gacha_version_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_prize_gacha_ownership()'
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_prizes_guard_management_fields ON catalog_prizes'
        );
        $this->replacePrizeAssetMutationGuard(true);
    }

    public function down(): void
    {
        $this->replacePrizeAssetMutationGuard(false);
        DB::statement(
            'CREATE TRIGGER catalog_prizes_guard_management_fields '.
            'BEFORE UPDATE OF cost_price ON catalog_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_prize_management()'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_gacha_ownership_guard '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_prizes_gacha_ownership_guard ON catalog_prizes'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_prize_gacha_ownership()');
        DB::statement(
            'DROP INDEX IF EXISTS catalog_prizes_gacha_id_id_index'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes DROP CONSTRAINT IF EXISTS '.
            'catalog_gacha_version_prize_snapshot_values_check'
        );
        $snapshotColumns = array_values(array_filter([
            'display_name',
            'description',
            'display_price',
            'exchange_points',
            'cost_price',
            'is_visible',
            'rank_code',
            'rank_display_name',
            'rank_sort_order',
        ], static fn (string $column): bool => Schema::hasColumn(
            'catalog_gacha_version_prizes',
            $column
        )));
        Schema::table('catalog_gacha_version_prizes', function (Blueprint $table) use (
            $snapshotColumns
        ): void {
            $table->dropConstrainedForeignId('presentation_asset_id');
            $table->dropConstrainedForeignId('rank_id');
            $table->dropColumn($snapshotColumns);
        });
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gacha_id');
        });
    }

    private function replacePrizeAssetMutationGuard(bool $snapshotsOwnPresentation): void
    {
        $publishedPrizeAssetReference = $snapshotsOwnPresentation
            ? <<<'SQL'
                        SELECT 1
                        FROM catalog_gacha_version_prizes relation
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE relation.presentation_asset_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                SQL
            : <<<'SQL'
                        SELECT 1
                        FROM catalog_prizes prize
                        JOIN catalog_gacha_version_prizes relation
                          ON relation.prize_id = prize.id
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE prize.presentation_asset_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                SQL;
        $publishedPrizeMutation = $snapshotsOwnPresentation
            ? ''
            : <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_version_prizes relation
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE relation.prize_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                    ) INTO has_published_reference;
                    IF has_published_reference AND (
                        NEW.rank_id IS DISTINCT FROM OLD.rank_id
                        OR NEW.presentation_asset_id IS DISTINCT FROM OLD.presentation_asset_id
                        OR NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR COALESCE(NEW.description, '') IS DISTINCT FROM
                           COALESCE(OLD.description, '')
                        OR NEW.display_price IS DISTINCT FROM OLD.display_price
                        OR NEW.exchange_points IS DISTINCT FROM OLD.exchange_points
                        OR NEW.is_visible IS DISTINCT FROM OLD.is_visible
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                    ) THEN
                        RAISE EXCEPTION 'Published Catalog references protect this master record';
                    END IF;
                SQL;
        $publishedRankAssetReference = $snapshotsOwnPresentation
            ? <<<'SQL'
                        SELECT 1
                        FROM catalog_rank_assets rank_asset
                        JOIN catalog_gacha_version_prizes relation
                          ON relation.rank_id = rank_asset.rank_id
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE rank_asset.presentation_asset_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                SQL
            : <<<'SQL'
                        SELECT 1
                        FROM catalog_rank_assets rank_asset
                        JOIN catalog_prizes prize ON prize.rank_id = rank_asset.rank_id
                        JOIN catalog_gacha_version_prizes relation
                          ON relation.prize_id = prize.id
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE rank_asset.presentation_asset_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                SQL;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION v2_catalog_protect_prize_asset_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE has_published_reference boolean := false;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog Prize and Asset records cannot be deleted';
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Archived Catalog Prize and Asset records are immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Catalog Prize and Asset revision must increase by one';
                END IF;

                IF TG_TABLE_NAME = 'catalog_prizes' THEN
                    IF NEW.code IS DISTINCT FROM OLD.code THEN
                        RAISE EXCEPTION 'Catalog master code is immutable';
                    END IF;
                    {$publishedPrizeMutation}
                ELSE
                    IF NEW.storage_identifier IS DISTINCT FROM OLD.storage_identifier
                       OR NEW.public_path IS DISTINCT FROM OLD.public_path
                       OR NEW.checksum_sha256 IS DISTINCT FROM OLD.checksum_sha256
                       OR NEW.media_type IS DISTINCT FROM OLD.media_type
                       OR NEW.mime_type IS DISTINCT FROM OLD.mime_type
                       OR NEW.byte_size IS DISTINCT FROM OLD.byte_size THEN
                        RAISE EXCEPTION 'Presentation Asset object identity is immutable';
                    END IF;
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_versions version
                        WHERE version.presentation_asset_id = OLD.id
                          AND version.status = 'published'
                          AND (version.publish_end_at IS NULL
                               OR version.publish_end_at > CURRENT_TIMESTAMP)
                        UNION ALL
            {$publishedPrizeAssetReference}
                        UNION ALL
            {$publishedRankAssetReference}
                    ) INTO has_published_reference;
                    IF has_published_reference AND (
                        COALESCE(NEW.alt_text, '') IS DISTINCT FROM COALESCE(OLD.alt_text, '')
                        OR NEW.is_public IS DISTINCT FROM OLD.is_public
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                    ) THEN
                        RAISE EXCEPTION 'Published Catalog references protect this master record';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$
        SQL);
    }
};
