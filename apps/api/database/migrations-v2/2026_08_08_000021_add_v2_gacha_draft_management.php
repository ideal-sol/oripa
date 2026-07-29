<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->index('archived_at');
        });
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('cloned_from_version_id')->nullable()
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->index(['gacha_id', 'archived_at']);
        });

        DB::statement(
            'ALTER TABLE catalog_gachas ADD CONSTRAINT catalog_gachas_archive_check '.
            "CHECK (revision::numeric > 0::numeric AND ".
            "(archived_at IS NULL OR state::text = 'disabled'::text))"
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '.
            'ADD CONSTRAINT catalog_gacha_versions_archive_check '.
            "CHECK (revision::numeric > 0::numeric AND ".
            "(archived_at IS NULL OR status::text = 'draft'::text))"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_gacha_draft_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE has_published_version boolean := false;
            DECLARE has_draw_history boolean := false;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog Gacha records cannot be deleted';
                END IF;

                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    IF OLD.archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Archived Catalog Gacha records are immutable';
                    END IF;
                    IF NEW.code IS DISTINCT FROM OLD.code
                       OR NEW.slug IS DISTINCT FROM OLD.slug THEN
                        RAISE EXCEPTION 'Catalog Gacha code and slug are immutable';
                    END IF;
                    IF NEW.revision <> OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Catalog Gacha revision must increase by one';
                    END IF;

                    IF NEW.category_id IS DISTINCT FROM OLD.category_id
                       OR (
                           NEW.state IS DISTINCT FROM OLD.state
                           AND NEW.state = 'disabled'
                       )
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                        SELECT EXISTS (
                            SELECT 1
                            FROM catalog_gacha_versions
                            WHERE gacha_id = OLD.id
                              AND status = 'published'
                        ) INTO has_published_version;
                        SELECT EXISTS (
                            SELECT 1
                            FROM draw_requests AS draw
                            INNER JOIN gacha_draw_states AS state
                                ON state.id = draw.gacha_draw_state_id
                            WHERE state.gacha_id = OLD.id
                        ) INTO has_draw_history;
                        IF has_published_version OR has_draw_history THEN
                            RAISE EXCEPTION 'Published or drawn Gacha references protect this record';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published Gacha Version is immutable';
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Archived Gacha Draft Version is immutable';
                END IF;
                IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                   OR NEW.version_number IS DISTINCT FROM OLD.version_number THEN
                    RAISE EXCEPTION 'Gacha Draft Version identity is immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Gacha Draft Version revision must increase by one';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gachas_protect_draft_mutation '.
            'BEFORE UPDATE OR DELETE ON catalog_gachas FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_gacha_draft_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_versions_protect_draft_mutation '.
            'BEFORE UPDATE OR DELETE ON catalog_gacha_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_gacha_draft_mutation()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_gacha_draft_relation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_id bigint;
            DECLARE parent_archived_at timestamptz;
            DECLARE has_published_version boolean := false;
            DECLARE has_draw_history boolean := false;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_id := CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_id
                        ELSE NEW.gacha_id
                    END;
                    SELECT archived_at INTO parent_archived_at
                    FROM catalog_gachas
                    WHERE id = parent_id;
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_versions
                        WHERE gacha_id = parent_id
                          AND status = 'published'
                    ) INTO has_published_version;
                    SELECT EXISTS (
                        SELECT 1
                        FROM draw_requests AS draw
                        INNER JOIN gacha_draw_states AS state
                            ON state.id = draw.gacha_draw_state_id
                        WHERE state.gacha_id = parent_id
                    ) INTO has_draw_history;
                    IF parent_archived_at IS NOT NULL
                       OR has_published_version
                       OR has_draw_history THEN
                        RAISE EXCEPTION 'Published or drawn Gacha references protect this relation';
                    END IF;
                ELSE
                    parent_id := CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id
                        ELSE NEW.gacha_version_id
                    END;
                    SELECT archived_at INTO parent_archived_at
                    FROM catalog_gacha_versions
                    WHERE id = parent_id;
                    IF parent_archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Archived Gacha Draft Version relations are immutable';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_tags_protect_draft_relation '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_tags FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_gacha_draft_relation()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_protect_draft_relation '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_version_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_gacha_draft_relation()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_protect_draft_relation '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_tags_protect_draft_relation '.
            'ON catalog_gacha_tags'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_versions_protect_draft_mutation '.
            'ON catalog_gacha_versions'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gachas_protect_draft_mutation ON catalog_gachas'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_gacha_draft_relation()');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_gacha_draft_mutation()');
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '.
            'DROP CONSTRAINT IF EXISTS catalog_gacha_versions_archive_check'
        );
        DB::statement(
            'ALTER TABLE catalog_gachas DROP CONSTRAINT IF EXISTS catalog_gachas_archive_check'
        );

        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->dropIndex(['gacha_id', 'archived_at']);
            $table->dropConstrainedForeignId('cloned_from_version_id');
            $table->dropColumn(['revision', 'archived_at']);
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['revision', 'archived_at']);
        });
    }
};
