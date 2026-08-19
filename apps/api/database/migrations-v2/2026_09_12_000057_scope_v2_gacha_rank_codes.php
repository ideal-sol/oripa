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
            $table->foreignId('gacha_id')->nullable()->after('public_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    WITH rank_owners AS (
                        SELECT relation.rank_id, version.gacha_id
                        FROM catalog_gacha_version_ranks relation
                        JOIN catalog_gacha_versions version
                          ON version.id = relation.gacha_version_id
                        UNION
                        SELECT prize.rank_id, prize.gacha_id
                        FROM catalog_prizes prize
                    )
                    SELECT 1
                    FROM rank_owners
                    GROUP BY rank_id
                    HAVING COUNT(DISTINCT gacha_id) > 1
                ) THEN
                    RAISE EXCEPTION
                        'A Catalog Rank must resolve to at most one Gacha before ownership backfill';
                END IF;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            WITH rank_owners AS (
                SELECT relation.rank_id, version.gacha_id
                FROM catalog_gacha_version_ranks relation
                JOIN catalog_gacha_versions version
                  ON version.id = relation.gacha_version_id
                UNION
                SELECT prize.rank_id, prize.gacha_id
                FROM catalog_prizes prize
            ), resolved AS (
                SELECT rank_id, MIN(gacha_id) AS gacha_id
                FROM rank_owners
                GROUP BY rank_id
            )
            UPDATE catalog_ranks rank
            SET gacha_id = resolved.gacha_id,
                revision = rank.revision + 1
            FROM resolved
            WHERE resolved.rank_id = rank.id
        SQL);
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM catalog_ranks
                    WHERE gacha_id IS NOT NULL
                    GROUP BY gacha_id, code
                    HAVING COUNT(*) > 1
                ) THEN
                    RAISE EXCEPTION
                        'Duplicate Catalog Rank codes exist inside one Gacha';
                END IF;
            END;
            $$
        SQL);

        DB::statement(
            'ALTER TABLE catalog_ranks ADD CONSTRAINT '.
            'catalog_ranks_gacha_id_code_unique UNIQUE (gacha_id, code)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX catalog_ranks_unowned_code_unique '.
            'ON catalog_ranks (code) WHERE gacha_id IS NULL'
        );
        DB::statement(
            'ALTER TABLE catalog_ranks DROP CONSTRAINT catalog_ranks_code_unique'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_rank_gacha_scope()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_gacha_id bigint;
            DECLARE rank_gacha_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_ranks' THEN
                    IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id THEN
                        RAISE EXCEPTION 'Catalog Rank Gacha ownership is immutable';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT gacha_id INTO parent_gacha_id
                FROM catalog_gacha_versions
                WHERE id = NEW.gacha_version_id;
                SELECT gacha_id INTO rank_gacha_id
                FROM catalog_ranks
                WHERE id = NEW.rank_id;
                IF parent_gacha_id IS NULL
                   OR rank_gacha_id IS NULL
                   OR parent_gacha_id IS DISTINCT FROM rank_gacha_id THEN
                    RAISE EXCEPTION 'Cross-Gacha Rank association is not allowed';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_ranks_gacha_scope_guard '.
            'BEFORE UPDATE OF gacha_id ON catalog_ranks FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_gacha_scope()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_ranks_gacha_scope_guard '.
            'BEFORE INSERT OR UPDATE OF gacha_version_id, rank_id '.
            'ON catalog_gacha_version_ranks FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_gacha_scope()'
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM catalog_ranks
                    GROUP BY code
                    HAVING COUNT(*) > 1
                ) THEN
                    RAISE EXCEPTION
                        'Cannot restore global Catalog Rank code uniqueness while duplicate codes exist';
                END IF;
            END;
            $$
        SQL);

        DB::statement(
            'ALTER TABLE catalog_ranks ADD CONSTRAINT '.
            'catalog_ranks_code_unique UNIQUE (code)'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_ranks_gacha_scope_guard '.
            'ON catalog_gacha_version_ranks'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_ranks_gacha_scope_guard ON catalog_ranks'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_rank_gacha_scope()');
        DB::statement('DROP INDEX IF EXISTS catalog_ranks_unowned_code_unique');
        DB::statement(
            'ALTER TABLE catalog_ranks DROP CONSTRAINT IF EXISTS '.
            'catalog_ranks_gacha_id_code_unique'
        );
        Schema::table('catalog_ranks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gacha_id');
        });
    }
};
