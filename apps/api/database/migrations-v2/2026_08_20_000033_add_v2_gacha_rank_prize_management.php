<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_ranks', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('display_name');
        });
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_price')->default(0)->after('exchange_points');
        });
        Schema::create('catalog_gacha_version_ranks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('rank_id')
                ->constrained('catalog_ranks')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['gacha_version_id', 'rank_id']);
            $table->unique(['gacha_version_id', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO catalog_gacha_version_ranks (
                gacha_version_id, rank_id, sort_order, created_at, updated_at
            )
            SELECT
                relation.gacha_version_id,
                prize.rank_id,
                ROW_NUMBER() OVER (
                    PARTITION BY relation.gacha_version_id
                    ORDER BY rank.sort_order, rank.id
                ) - 1,
                MIN(relation.created_at),
                MAX(relation.updated_at)
            FROM catalog_gacha_version_prizes relation
            JOIN catalog_prizes prize ON prize.id = relation.prize_id
            JOIN catalog_ranks rank ON rank.id = prize.rank_id
            GROUP BY relation.gacha_version_id, prize.rank_id, rank.sort_order, rank.id
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_gacha_version_rank()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_id bigint;
            DECLARE parent_status text;
            DECLARE parent_archived_at timestamptz;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE'
                    THEN OLD.gacha_version_id ELSE NEW.gacha_version_id END;
                SELECT status, archived_at
                INTO parent_status, parent_archived_at
                FROM catalog_gacha_versions
                WHERE id = parent_id;
                IF parent_status IS DISTINCT FROM 'draft'::text
                   OR parent_archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Only active Draft Gacha Versions accept rank changes';
                END IF;
                IF TG_OP = 'UPDATE'
                   AND NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id THEN
                    RAISE EXCEPTION 'Gacha Version rank parent is immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_ranks_guard '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_version_ranks '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_gacha_version_rank()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_rank_prize_management()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE rank_key bigint;
            DECLARE has_published_reference boolean := false;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_prizes' THEN
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_version_prizes relation
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE relation.prize_id = OLD.id
                          AND version.status::text = 'published'::text
                    ) INTO has_published_reference;
                    IF has_published_reference
                       AND NEW.cost_price IS DISTINCT FROM OLD.cost_price THEN
                        RAISE EXCEPTION 'Published Catalog references protect this record';
                    END IF;
                ELSE
                    rank_key := CASE WHEN TG_TABLE_NAME = 'catalog_rank_assets'
                        THEN CASE WHEN TG_OP = 'DELETE' THEN OLD.rank_id ELSE NEW.rank_id END
                        ELSE OLD.id
                    END;
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_version_ranks relation
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE relation.rank_id = rank_key
                          AND version.status::text = 'published'::text
                        UNION ALL
                        SELECT 1
                        FROM catalog_gacha_version_prizes relation
                        JOIN catalog_prizes prize ON prize.id = relation.prize_id
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        WHERE prize.rank_id = rank_key
                          AND version.status::text = 'published'::text
                    ) INTO has_published_reference;
                    IF TG_TABLE_NAME = 'catalog_rank_assets' THEN
                        IF has_published_reference THEN
                            RAISE EXCEPTION 'Published Catalog references protect this record';
                        END IF;
                    ELSIF has_published_reference
                          AND NEW.description IS DISTINCT FROM OLD.description THEN
                        RAISE EXCEPTION 'Published Catalog references protect this record';
                    END IF;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_ranks_guard_management_fields '.
            'BEFORE UPDATE OF description ON catalog_ranks FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_prize_management()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_prizes_guard_management_fields '.
            'BEFORE UPDATE OF cost_price ON catalog_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_prize_management()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_rank_assets_guard_published '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_rank_assets FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_prize_management()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_rank_assets_guard_published ON catalog_rank_assets'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_prizes_guard_management_fields ON catalog_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_ranks_guard_management_fields ON catalog_ranks'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_ranks_guard '.
            'ON catalog_gacha_version_ranks'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_rank_prize_management()');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_gacha_version_rank()');
        Schema::dropIfExists('catalog_gacha_version_ranks');
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });
        Schema::table('catalog_ranks', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
