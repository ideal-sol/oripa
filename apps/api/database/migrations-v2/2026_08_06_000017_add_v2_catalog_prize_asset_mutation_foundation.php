<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_prizes', 'catalog_presentation_assets'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('revision')->default(1);
                $blueprint->timestampTz('archived_at')->nullable();
                $blueprint->index('archived_at');
            });
        }
        DB::statement(
            'ALTER TABLE catalog_prizes ADD CONSTRAINT catalog_prizes_archive_check '.
            'CHECK (revision > 0 AND (archived_at IS NULL OR is_visible = false))'
        );
        DB::statement(
            'ALTER TABLE catalog_presentation_assets '.
            'ADD CONSTRAINT catalog_presentation_assets_archive_check '.
            'CHECK (revision > 0 AND (archived_at IS NULL OR is_public = false))'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_prize_asset_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
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
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_version_prizes gvp
                        JOIN catalog_gacha_versions gv ON gv.id = gvp.gacha_version_id
                        WHERE gvp.prize_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
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
                        FROM catalog_gacha_versions gv
                        WHERE gv.presentation_asset_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
                        UNION ALL
                        SELECT 1
                        FROM catalog_prizes p
                        JOIN catalog_gacha_version_prizes gvp ON gvp.prize_id = p.id
                        JOIN catalog_gacha_versions gv ON gv.id = gvp.gacha_version_id
                        WHERE p.presentation_asset_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
                        UNION ALL
                        SELECT 1
                        FROM catalog_rank_assets ra
                        JOIN catalog_prizes p ON p.rank_id = ra.rank_id
                        JOIN catalog_gacha_version_prizes gvp ON gvp.prize_id = p.id
                        JOIN catalog_gacha_versions gv ON gv.id = gvp.gacha_version_id
                        WHERE ra.presentation_asset_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
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
            $$
        SQL);

        foreach (['catalog_prizes', 'catalog_presentation_assets'] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_protect_mutation ".
                "BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_catalog_protect_prize_asset_mutation()'
            );
        }
    }

    public function down(): void
    {
        foreach (['catalog_prizes', 'catalog_presentation_assets'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_protect_mutation ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_prize_asset_mutation()');
        DB::statement(
            'ALTER TABLE catalog_prizes DROP CONSTRAINT IF EXISTS catalog_prizes_archive_check'
        );
        DB::statement(
            'ALTER TABLE catalog_presentation_assets '.
            'DROP CONSTRAINT IF EXISTS catalog_presentation_assets_archive_check'
        );
        foreach (['catalog_prizes', 'catalog_presentation_assets'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['archived_at']);
                $blueprint->dropColumn(['revision', 'archived_at']);
            });
        }
    }
};
