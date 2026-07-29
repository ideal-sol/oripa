<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_published_probability_relation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE published_reference_exists boolean;
            BEGIN
                SELECT EXISTS (
                    SELECT 1
                    FROM catalog_probability_entries entry
                    JOIN catalog_probability_stages stage
                      ON stage.id = entry.probability_stage_id
                    JOIN catalog_probability_versions version
                      ON version.id = stage.probability_version_id
                    WHERE entry.gacha_version_prize_id = OLD.id
                      AND version.status::text = 'published'::text
                    UNION ALL
                    SELECT 1
                    FROM catalog_minimum_guarantees guarantee
                    JOIN catalog_probability_stages stage
                      ON stage.id = guarantee.probability_stage_id
                    JOIN catalog_probability_versions version
                      ON version.id = stage.probability_version_id
                    WHERE guarantee.gacha_version_prize_id = OLD.id
                      AND version.status::text = 'published'::text
                ) INTO published_reference_exists;

                IF published_reference_exists
                   AND (
                       TG_OP = 'DELETE'
                       OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                       OR NEW.prize_id IS DISTINCT FROM OLD.prize_id
                   ) THEN
                    RAISE EXCEPTION
                        'Published Probability Snapshot protects relation identity';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_protect_published_probability '.
            'BEFORE UPDATE OR DELETE ON catalog_gacha_version_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_published_probability_relation()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS '.
            'catalog_gacha_version_prizes_protect_published_probability '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS '.
            'v2_catalog_protect_published_probability_relation()'
        );
    }
};
