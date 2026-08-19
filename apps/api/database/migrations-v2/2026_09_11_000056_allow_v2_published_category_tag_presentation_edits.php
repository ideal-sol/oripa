<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->functionSql(false));
    }

    public function down(): void
    {
        DB::unprepared($this->functionSql(true));
    }

    private function functionSql(bool $protectPresentation): string
    {
        $categoryFields = $protectPresentation
            ? <<<'SQL'
                        NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.slug IS DISTINCT FROM OLD.slug
                        OR COALESCE(NEW.description, '') IS DISTINCT FROM
                           COALESCE(OLD.description, '')
                        OR NEW.sort_order IS DISTINCT FROM OLD.sort_order
                        OR NEW.is_visible IS DISTINCT FROM OLD.is_visible
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL
            : <<<'SQL'
                        NEW.slug IS DISTINCT FROM OLD.slug
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL;
        $tagFields = $protectPresentation
            ? <<<'SQL'
                        NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.slug IS DISTINCT FROM OLD.slug
                        OR NEW.sort_order IS DISTINCT FROM OLD.sort_order
                        OR NEW.is_visible IS DISTINCT FROM OLD.is_visible
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL
            : <<<'SQL'
                        NEW.slug IS DISTINCT FROM OLD.slug
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL;

        return <<<SQL
            CREATE OR REPLACE FUNCTION v2_catalog_protect_master_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE has_published_reference boolean := false;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog master records cannot be deleted';
                END IF;
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'Catalog master code is immutable';
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Archived Catalog master records are immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Catalog master revision must increase by one';
                END IF;

                IF TG_TABLE_NAME = 'catalog_categories' THEN
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gachas g
                        JOIN catalog_gacha_versions gv
                          ON gv.id = g.published_version_id
                        WHERE g.category_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
                    ) INTO has_published_reference;
                    IF has_published_reference AND (
                        {$categoryFields}
                    ) THEN
                        RAISE EXCEPTION 'Published Catalog references protect this master record';
                    END IF;
                ELSIF TG_TABLE_NAME = 'catalog_tags' THEN
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_tags gt
                        JOIN catalog_gachas g ON g.id = gt.gacha_id
                        JOIN catalog_gacha_versions gv
                          ON gv.id = g.published_version_id
                        WHERE gt.tag_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
                    ) INTO has_published_reference;
                    IF has_published_reference AND (
                        {$tagFields}
                    ) THEN
                        RAISE EXCEPTION 'Published Catalog references protect this master record';
                    END IF;
                ELSE
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_prizes p
                        JOIN catalog_gacha_version_prizes gvp ON gvp.prize_id = p.id
                        JOIN catalog_gacha_versions gv ON gv.id = gvp.gacha_version_id
                        WHERE p.rank_id = OLD.id
                          AND gv.status = 'published'
                          AND (gv.publish_end_at IS NULL OR gv.publish_end_at > CURRENT_TIMESTAMP)
                    ) INTO has_published_reference;
                    IF has_published_reference AND (
                        NEW.display_name IS DISTINCT FROM OLD.display_name
                        OR NEW.sort_order IS DISTINCT FROM OLD.sort_order
                        OR NEW.is_visible IS DISTINCT FROM OLD.is_visible
                        OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                    ) THEN
                        RAISE EXCEPTION 'Published Catalog references protect this master record';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$
        SQL;
    }
};
