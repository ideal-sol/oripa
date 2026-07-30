<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_gacha_probability_selection()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE selected_status text;
            DECLARE selected_parent_id bigint;
            DECLARE selected_archived_at timestamptz;
            DECLARE selected_snapshot_sha256 text;
            BEGIN
                IF NEW.published_probability_version_id
                    IS NOT DISTINCT FROM OLD.published_probability_version_id THEN
                    RETURN NEW;
                END IF;

                IF OLD.status::text <> 'draft'::text
                   OR OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Only an active Draft Gacha Version can select Probability';
                END IF;
                IF NEW.published_probability_version_id IS NULL THEN
                    RAISE EXCEPTION
                        'Selected Published Probability cannot be cleared';
                END IF;

                SELECT status, gacha_version_id, archived_at, snapshot_sha256
                  INTO selected_status,
                       selected_parent_id,
                       selected_archived_at,
                       selected_snapshot_sha256
                FROM catalog_probability_versions
                WHERE id = NEW.published_probability_version_id;

                IF selected_status IS DISTINCT FROM 'published'::text
                   OR selected_parent_id IS DISTINCT FROM NEW.id
                   OR selected_archived_at IS NOT NULL
                   OR selected_snapshot_sha256 IS NULL
                   OR selected_snapshot_sha256
                        !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION
                        'Gacha Version requires its immutable Published Probability Snapshot';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_versions_validate_probability_selection '.
            'BEFORE UPDATE OF published_probability_version_id '.
            'ON catalog_gacha_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_gacha_probability_selection()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS '.
            'catalog_gacha_versions_validate_probability_selection '.
            'ON catalog_gacha_versions'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS '.
            'v2_catalog_validate_gacha_probability_selection()'
        );
    }
};
