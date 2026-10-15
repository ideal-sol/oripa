<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_gacha_rank_video_revisions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('video_count')->default(1);
        });
        Schema::create('catalog_gacha_rank_video_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('video_revision_id')->constrained('catalog_gacha_rank_video_revisions')->restrictOnDelete();
            $table->foreignId('video_asset_id')->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->unsignedSmallInteger('weight_units');
            $table->unsignedSmallInteger('sort_order');
            $table->unique(['video_revision_id', 'video_asset_id'], 'rank_video_item_asset_unique');
            $table->unique(['video_revision_id', 'sort_order'], 'rank_video_item_order_unique');
        });
        DB::statement('ALTER TABLE catalog_gacha_rank_video_revisions ADD CONSTRAINT rank_video_count_check CHECK (video_count BETWEEN 1 AND 10)');
        DB::statement('ALTER TABLE catalog_gacha_rank_video_items ADD CONSTRAINT rank_video_item_values_check CHECK (weight_units >= 1 AND weight_units <= 10000 AND sort_order >= 1 AND sort_order <= 10)');
        DB::statement('INSERT INTO catalog_gacha_rank_video_items (video_revision_id, video_asset_id, weight_units, sort_order) SELECT id, video_asset_id, 10000, 1 FROM catalog_gacha_rank_video_revisions');
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->foreignId('gacha_rank_video_item_id')->nullable()->constrained('catalog_gacha_rank_video_items')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_rank_video_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE expected_count integer;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Rank video items are immutable';
                END IF;
                SELECT video_count INTO expected_count FROM catalog_gacha_rank_video_revisions
                    WHERE id = NEW.video_revision_id FOR UPDATE;
                IF expected_count IS NULL OR (SELECT count(*) FROM catalog_gacha_rank_video_items WHERE video_revision_id = NEW.video_revision_id) >= expected_count THEN
                    RAISE EXCEPTION 'Completed rank video sets are immutable';
                END IF;
                PERFORM asset.id FROM catalog_presentation_assets asset
                JOIN catalog_rank_effect_materials material ON material.presentation_asset_id = asset.id
                WHERE asset.id = NEW.video_asset_id AND asset.media_type = 'video'
                  AND asset.is_public AND asset.archived_at IS NULL FOR SHARE OF asset, material;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Rank video items require an available rank effect material';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER rank_video_item_guard BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_rank_video_items
                FOR EACH ROW EXECUTE FUNCTION v2_rank_video_item_guard();
            CREATE TRIGGER rank_video_item_truncate_guard BEFORE TRUNCATE ON catalog_gacha_rank_video_items
                FOR EACH STATEMENT EXECUTE FUNCTION v2_rank_video_item_guard();

            CREATE OR REPLACE FUNCTION v2_rank_video_seed_single() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.video_count = 1 THEN
                    INSERT INTO catalog_gacha_rank_video_items (video_revision_id, video_asset_id, weight_units, sort_order)
                    VALUES (NEW.id, NEW.video_asset_id, 10000, 1);
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE TRIGGER rank_video_seed_single AFTER INSERT ON catalog_gacha_rank_video_revisions
                FOR EACH ROW EXECUTE FUNCTION v2_rank_video_seed_single();

            CREATE OR REPLACE FUNCTION v2_rank_video_validate_set() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE revision_id bigint;
            DECLARE expected catalog_gacha_rank_video_revisions%ROWTYPE;
            DECLARE item_count integer;
            DECLARE total integer;
            DECLARE representative bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_rank_video_revisions' THEN
                    revision_id := NEW.id;
                ELSE
                    revision_id := NEW.video_revision_id;
                END IF;
                SELECT * INTO STRICT expected FROM catalog_gacha_rank_video_revisions WHERE id = revision_id;
                SELECT count(*), sum(weight_units) INTO item_count, total FROM catalog_gacha_rank_video_items WHERE video_revision_id = revision_id;
                SELECT video_asset_id INTO representative FROM catalog_gacha_rank_video_items WHERE video_revision_id = revision_id
                    ORDER BY weight_units DESC, sort_order LIMIT 1;
                IF item_count IS DISTINCT FROM expected.video_count OR total IS DISTINCT FROM 10000
                   OR representative IS DISTINCT FROM expected.video_asset_id THEN
                    RAISE EXCEPTION 'Rank video set requires 1-10 items totaling 10000 and its representative';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE CONSTRAINT TRIGGER rank_video_revision_set_guard AFTER INSERT ON catalog_gacha_rank_video_revisions
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_rank_video_validate_set();
            CREATE CONSTRAINT TRIGGER rank_video_item_set_guard AFTER INSERT ON catalog_gacha_rank_video_items
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_rank_video_validate_set();

            CREATE OR REPLACE FUNCTION v2_rank_video_asset_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (TG_OP = 'DELETE' OR NOT NEW.is_public OR NEW.archived_at IS NOT NULL)
                   AND EXISTS (SELECT 1 FROM catalog_gacha_rank_video_items WHERE video_asset_id = OLD.id) THEN
                    RAISE EXCEPTION 'Historical rank video materials must remain available';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;
            CREATE TRIGGER rank_video_asset_guard BEFORE UPDATE OR DELETE ON catalog_presentation_assets
                FOR EACH ROW EXECUTE FUNCTION v2_rank_video_asset_guard();

            CREATE OR REPLACE FUNCTION v2_draw_video_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.gacha_rank_video_item_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM catalog_gacha_rank_video_items item
                    JOIN catalog_presentation_assets asset ON asset.id = item.video_asset_id
                    WHERE item.id = NEW.gacha_rank_video_item_id AND item.video_revision_id = NEW.gacha_rank_video_revision_id
                      AND asset.public_id::text = NEW.display_snapshot->'video_snapshot'->>'id'
                ) THEN
                    RAISE EXCEPTION 'Draw video item must match its revision and snapshot';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER draw_video_item_guard BEFORE INSERT OR UPDATE ON draw_results
                FOR EACH ROW EXECUTE FUNCTION v2_draw_video_item_guard();
            CREATE OR REPLACE FUNCTION v2_login_guard_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_id bigint;
            DECLARE parent catalog_gachas%ROWTYPE;
            DECLARE row_data jsonb;
            BEGIN
                row_data := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                IF TG_TABLE_NAME = 'catalog_gachas' THEN parent_id := OLD.id;
                ELSIF TG_TABLE_NAME IN ('catalog_prizes', 'catalog_gacha_ranks', 'catalog_gacha_tags') THEN parent_id := (row_data->>'gacha_id')::bigint;
                ELSIF TG_TABLE_NAME = 'catalog_gacha_rank_video_revisions' THEN
                    SELECT gacha_id INTO parent_id FROM catalog_gacha_ranks WHERE id = (row_data->>'gacha_rank_id')::bigint;
                ELSIF TG_TABLE_NAME = 'prize_inventories' THEN
                    SELECT version.gacha_id INTO parent_id FROM catalog_gacha_version_prizes relation
                    JOIN catalog_gacha_versions version ON version.id = relation.gacha_version_id
                    WHERE relation.id = (row_data->>'gacha_version_prize_id')::bigint;
                ELSE
                    SELECT gacha_id INTO parent_id FROM catalog_gacha_versions WHERE id = (row_data->>'gacha_version_id')::bigint;
                END IF;
                SELECT * INTO parent FROM catalog_gachas WHERE id = parent_id FOR UPDATE;
                IF parent.gacha_type = 'standard' OR parent.id IS NULL THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_TABLE_NAME IN ('catalog_gacha_tags', 'catalog_gacha_version_tags') THEN
                    RAISE EXCEPTION 'Login Gacha has no Tags';
                END IF;
                IF TG_TABLE_NAME = 'catalog_prizes' AND TG_OP <> 'DELETE' THEN
                    IF NEW.shipping_only OR EXISTS (
                        SELECT 1 FROM catalog_gacha_versions version WHERE version.gacha_id = parent.id
                        AND version.archived_at IS NULL AND parent.gacha_type = 'login_daily'
                        AND NEW.exchange_points < version.minimum_exchange_points
                    ) THEN
                        RAISE EXCEPTION 'Login Prize cannot be shipping-only or below its minimum exchange value';
                    END IF;
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_ranks' AND TG_OP <> 'DELETE' THEN
                    IF NEW.preferred_rank_revision_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM catalog_rank_master_revisions WHERE id = NEW.preferred_rank_revision_id AND rank_master_id = NEW.rank_master_id) THEN
                        RAISE EXCEPTION 'Rank revision must belong to its Master';
                    END IF;
                END IF;
                IF parent.first_published_at IS NOT NULL THEN
                    IF TG_TABLE_NAME = 'catalog_gachas' THEN
                        IF TG_OP = 'DELETE' OR
                           (NEW.code, NEW.slug, NEW.category_id, NEW.current_title, NEW.current_description, NEW.current_notices,
                            NEW.current_presentation_asset_id, NEW.current_publish_start_at, NEW.current_publish_end_at)
                           IS DISTINCT FROM
                           (OLD.code, OLD.slug, OLD.category_id, OLD.current_title, OLD.current_description, OLD.current_notices,
                            OLD.current_presentation_asset_id, OLD.current_publish_start_at, OLD.current_publish_end_at) THEN
                            RAISE EXCEPTION 'Published login Gacha content is immutable';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'catalog_prizes' AND TG_OP = 'UPDATE' THEN
                        IF OLD.external_id IS NOT NULL OR NEW.external_id IS NULL
                           OR NEW.external_id !~ '^[A-Za-z0-9._-]{1,64}$'
                           OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                           OR (to_jsonb(NEW) - ARRAY['external_id', 'revision', 'updated_at'])
                              IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['external_id', 'revision', 'updated_at']) THEN
                            RAISE EXCEPTION 'Published login Gacha configuration is immutable';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'catalog_gacha_rank_video_revisions' AND TG_OP = 'INSERT' THEN
                        RETURN NEW;
                    ELSIF TG_TABLE_NAME = 'catalog_gacha_ranks' AND TG_OP = 'UPDATE' THEN
                        IF NEW.current_video_revision_id IS NULL
                           OR NEW.current_video_revision_id IS NOT DISTINCT FROM OLD.current_video_revision_id
                           OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                           OR (to_jsonb(NEW) - ARRAY['current_video_revision_id', 'revision', 'updated_at'])
                              IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['current_video_revision_id', 'revision', 'updated_at']) THEN
                            RAISE EXCEPTION 'Only the published login rank video may change';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'prize_inventories' THEN
                        IF parent.management_status <> 'published' OR TG_OP <> 'UPDATE' THEN
                            RAISE EXCEPTION 'Only Published login Gacha inventory may be adjusted';
                        END IF;
                    ELSE
                        RAISE EXCEPTION 'Published login Gacha configuration is immutable';
                    END IF;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Rank video sets require a forward correction migration.');
    }
};
