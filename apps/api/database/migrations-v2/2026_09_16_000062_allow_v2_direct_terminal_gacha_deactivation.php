<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->installGuard(false);
    }

    public function down(): void
    {
        $this->installGuard(true);
    }

    private function installGuard(bool $requiresPausedSource): void
    {
        $pausedRequirement = $requiresPausedSource
            ? 'NEW.sales_paused IS NOT TRUE OR'
            : '';

        DB::unprepared(str_replace(
            '__PAUSED_REQUIREMENT__',
            $pausedRequirement,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_public_deactivation_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE state_row gacha_draw_states%ROWTYPE;
            DECLARE deactivating boolean := FALSE;
            DECLARE metadata_changed boolean := FALSE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.public_deactivated_at IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Gacha Public deactivation history cannot be deleted';
                    END IF;
                    RETURN OLD;
                END IF;

                deactivating :=
                    OLD.published_version_id IS NOT NULL
                    AND OLD.active_draw_state_id IS NOT NULL
                    AND NEW.published_version_id IS NULL
                    AND NEW.active_draw_state_id IS NULL;
                metadata_changed :=
                    NEW.public_deactivated_at
                        IS DISTINCT FROM OLD.public_deactivated_at
                    OR NEW.public_deactivated_by_admin_public_id
                        IS DISTINCT FROM OLD.public_deactivated_by_admin_public_id
                    OR NEW.public_deactivation_request_id
                        IS DISTINCT FROM OLD.public_deactivation_request_id;

                IF NOT deactivating AND metadata_changed THEN
                    RAISE EXCEPTION
                        'Gacha Public deactivation metadata is transition controlled';
                END IF;
                IF NOT deactivating THEN
                    RETURN NEW;
                END IF;

                IF __PAUSED_REQUIREMENT__
                   NEW.public_deactivated_at IS NULL
                   OR NEW.public_deactivated_by_admin_public_id IS NULL
                   OR NEW.public_deactivation_request_id IS NULL
                   OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                   OR NEW.public_id IS DISTINCT FROM OLD.public_id
                   OR NEW.code IS DISTINCT FROM OLD.code
                   OR NEW.slug IS DISTINCT FROM OLD.slug
                   OR NEW.category_id IS DISTINCT FROM OLD.category_id
                   OR NEW.state IS DISTINCT FROM OLD.state
                   OR NEW.sold_count IS DISTINCT FROM OLD.sold_count
                   OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                   OR NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                   OR NEW.sales_paused_at IS DISTINCT FROM OLD.sales_paused_at
                   OR NEW.sales_paused_by_admin_public_id
                        IS DISTINCT FROM OLD.sales_paused_by_admin_public_id
                   OR NEW.sales_pause_reason_code
                        IS DISTINCT FROM OLD.sales_pause_reason_code
                   OR NEW.sales_resumed_at IS DISTINCT FROM OLD.sales_resumed_at
                   OR NEW.sales_last_mutation_request_id
                        IS DISTINCT FROM OLD.sales_last_mutation_request_id THEN
                    RAISE EXCEPTION
                        'Gacha Public deactivation requires one Revision transition';
                END IF;
                IF NEW.state::text <> 'active'::text
                   OR NEW.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Gacha Public deactivation requires an active Gacha Master';
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = NEW.id
                      AND status::text = ANY (
                          ARRAY['scheduled'::text, 'processing'::text]
                      )
                ) THEN
                    RAISE EXCEPTION
                        'Gacha Public deactivation conflicts with an active Publish Schedule';
                END IF;

                SELECT * INTO version_row
                FROM catalog_gacha_versions
                WHERE id = OLD.published_version_id;
                SELECT * INTO state_row
                FROM gacha_draw_states
                WHERE id = OLD.active_draw_state_id;
                IF version_row.id IS NULL
                   OR version_row.gacha_id IS DISTINCT FROM OLD.id
                   OR version_row.status::text <> 'published'::text
                   OR version_row.archived_at IS NOT NULL
                   OR version_row.published_at IS NULL
                   OR state_row.id IS NULL
                   OR state_row.gacha_id IS DISTINCT FROM OLD.id
                   OR state_row.gacha_version_id IS DISTINCT FROM version_row.id
                   OR state_row.probability_version_id
                        IS DISTINCT FROM version_row.published_probability_version_id THEN
                    RAISE EXCEPTION
                        'Gacha Public deactivation requires matching active references';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL
        ));
    }
};
