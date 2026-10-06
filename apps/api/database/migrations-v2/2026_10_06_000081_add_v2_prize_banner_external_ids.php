<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_banners', function (Blueprint $table): void {
            $table->string('external_id', 64)->collation('C')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX content_banners_external_id_active_unique ON content_banners (external_id) WHERE status <> 'archived'");
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->string('external_id', 64)->collation('C')->nullable()->index();
        });
        DB::unprepared(<<<'SQL'
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
        throw new LogicException('External IDs require a forward correction migration.');
    }
};
