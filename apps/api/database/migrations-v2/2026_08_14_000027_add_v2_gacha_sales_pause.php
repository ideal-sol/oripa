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
            $table->boolean('sales_paused')->default(false)->after('active_draw_state_id');
            $table->timestampTz('sales_paused_at')->nullable()->after('sales_paused');
            $table->uuid('sales_paused_by_admin_public_id')
                ->nullable()->after('sales_paused_at');
            $table->string('sales_pause_reason_code', 32)
                ->nullable()->after('sales_paused_by_admin_public_id');
            $table->timestampTz('sales_resumed_at')
                ->nullable()->after('sales_pause_reason_code');
            $table->uuid('sales_last_mutation_request_id')
                ->nullable()->after('sales_resumed_at');
            $table->foreign('sales_paused_by_admin_public_id')
                ->references('public_id')->on('admins')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gachas
            ADD CONSTRAINT catalog_gacha_sales_state_check
            CHECK (
                (
                    sales_paused = TRUE
                    AND sales_paused_at IS NOT NULL
                    AND sales_paused_by_admin_public_id IS NOT NULL
                    AND sales_pause_reason_code::text = ANY (
                        ARRAY[
                            'operations_review'::text,
                            'inventory_review'::text,
                            'incident_response'::text
                        ]
                    )
                    AND sales_resumed_at IS NULL
                    AND sales_last_mutation_request_id IS NOT NULL
                )
                OR
                (
                    sales_paused = FALSE
                    AND (
                        (
                            sales_paused_at IS NULL
                            AND sales_paused_by_admin_public_id IS NULL
                            AND sales_pause_reason_code IS NULL
                            AND sales_resumed_at IS NULL
                            AND sales_last_mutation_request_id IS NULL
                        )
                        OR
                        (
                            sales_paused_at IS NOT NULL
                            AND sales_paused_by_admin_public_id IS NOT NULL
                            AND sales_pause_reason_code::text = ANY (
                                ARRAY[
                                    'operations_review'::text,
                                    'inventory_review'::text,
                                    'incident_response'::text
                                ]
                            )
                            AND sales_resumed_at IS NOT NULL
                            AND sales_resumed_at >= sales_paused_at
                            AND sales_last_mutation_request_id IS NOT NULL
                        )
                    )
                )
            )
        SQL);

        $this->installSalesGuards();
        $this->installScheduleCompatibilityGuards();
    }

    public function down(): void
    {
        $this->restoreScheduleGuards();
        DB::statement('DROP TRIGGER IF EXISTS draw_requests_sales_pause_guard ON draw_requests');
        DB::statement('DROP TRIGGER IF EXISTS catalog_gachas_sales_state_guard ON catalog_gachas');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_gacha_sales_state_guard()');
        DB::statement('ALTER TABLE catalog_gachas DROP CONSTRAINT IF EXISTS catalog_gacha_sales_state_check');
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropForeign(['sales_paused_by_admin_public_id']);
            $table->dropColumn([
                'sales_paused',
                'sales_paused_at',
                'sales_paused_by_admin_public_id',
                'sales_pause_reason_code',
                'sales_resumed_at',
                'sales_last_mutation_request_id',
            ]);
        });
    }

    private function installSalesGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_sales_state_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE state_row gacha_draw_states%ROWTYPE;
            DECLARE gacha_row catalog_gachas%ROWTYPE;
            DECLARE probability_row catalog_probability_versions%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'draw_requests' THEN
                    SELECT g.* INTO gacha_row
                    FROM catalog_gachas AS g
                    INNER JOIN gacha_draw_states AS state
                        ON state.gacha_id = g.id
                    WHERE state.id = NEW.gacha_draw_state_id;
                    IF gacha_row.id IS NULL OR gacha_row.sales_paused = TRUE THEN
                        RAISE EXCEPTION 'Paused Gacha cannot accept a new Draw Request';
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF OLD.sales_paused_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Gacha Sales operation history cannot be deleted';
                    END IF;
                    RETURN OLD;
                END IF;

                IF (
                    NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                    OR NEW.sales_paused_at IS DISTINCT FROM OLD.sales_paused_at
                    OR NEW.sales_paused_by_admin_public_id
                        IS DISTINCT FROM OLD.sales_paused_by_admin_public_id
                    OR NEW.sales_pause_reason_code
                        IS DISTINCT FROM OLD.sales_pause_reason_code
                    OR NEW.sales_resumed_at IS DISTINCT FROM OLD.sales_resumed_at
                    OR NEW.sales_last_mutation_request_id
                        IS DISTINCT FROM OLD.sales_last_mutation_request_id
                ) THEN
                    IF NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                       OR NEW.public_id IS DISTINCT FROM OLD.public_id
                       OR NEW.code IS DISTINCT FROM OLD.code
                       OR NEW.slug IS DISTINCT FROM OLD.slug
                       OR NEW.category_id IS DISTINCT FROM OLD.category_id
                       OR NEW.state IS DISTINCT FROM OLD.state
                       OR NEW.sold_count IS DISTINCT FROM OLD.sold_count
                       OR NEW.published_version_id
                            IS DISTINCT FROM OLD.published_version_id
                       OR NEW.active_draw_state_id
                            IS DISTINCT FROM OLD.active_draw_state_id
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                        RAISE EXCEPTION
                            'Gacha Sales state requires one Revision transition';
                    END IF;
                    IF NEW.published_version_id IS NULL
                       OR NEW.active_draw_state_id IS NULL
                       OR NEW.archived_at IS NOT NULL
                       OR NEW.state::text <> 'active'::text THEN
                        RAISE EXCEPTION
                            'Gacha Sales state requires an active Published Gacha';
                    END IF;

                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = NEW.published_version_id;
                    SELECT * INTO state_row
                    FROM gacha_draw_states
                    WHERE id = NEW.active_draw_state_id;
                    SELECT * INTO probability_row
                    FROM catalog_probability_versions
                    WHERE id = version_row.published_probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.id
                       OR version_row.status::text <> 'published'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.published_at IS NULL
                       OR state_row.id IS NULL
                       OR state_row.gacha_id IS DISTINCT FROM NEW.id
                       OR state_row.gacha_version_id IS DISTINCT FROM version_row.id
                       OR state_row.probability_version_id
                            IS DISTINCT FROM probability_row.id
                       OR state_row.status::text <> 'selling'::text
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION
                            'Gacha Sales state requires matching active Draw references';
                    END IF;
                    IF NEW.sales_paused = FALSE THEN
                        IF state_row.sold_count >= state_row.total_count
                           OR version_row.publish_start_at > CURRENT_TIMESTAMP
                           OR (
                               version_row.publish_end_at IS NOT NULL
                               AND version_row.publish_end_at <= CURRENT_TIMESTAMP
                           )
                           OR EXISTS (
                               SELECT 1
                               FROM catalog_gacha_publish_schedules
                               WHERE gacha_id = NEW.id
                                 AND status::text = 'processing'::text
                           ) THEN
                            RAISE EXCEPTION 'Gacha Sales Resume preflight failed';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gachas_sales_state_guard '.
            'BEFORE UPDATE OF sales_paused, sales_paused_at, '.
            'sales_paused_by_admin_public_id, sales_pause_reason_code, '.
            'sales_resumed_at, sales_last_mutation_request_id OR DELETE '.
            'ON catalog_gachas FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_gacha_sales_state_guard()'
        );
        DB::statement(
            'CREATE TRIGGER draw_requests_sales_pause_guard '.
            'BEFORE INSERT ON draw_requests FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_gacha_sales_state_guard()'
        );
    }

    private function installScheduleCompatibilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_publish_schedule_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE probability_row catalog_probability_versions%ROWTYPE;
            DECLARE revision_rebase boolean := FALSE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule history cannot be deleted';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    revision_rebase :=
                        OLD.status IN ('scheduled', 'processing')
                        AND NEW.status IS NOT DISTINCT FROM OLD.status
                        AND NEW.expected_gacha_revision
                            IS DISTINCT FROM OLD.expected_gacha_revision
                        AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                        AND NEW.gacha_version_id IS NOT DISTINCT FROM OLD.gacha_version_id
                        AND NEW.probability_version_id
                            IS NOT DISTINCT FROM OLD.probability_version_id
                        AND NEW.scheduled_for IS NOT DISTINCT FROM OLD.scheduled_for
                        AND NEW.expected_version_revision
                            IS NOT DISTINCT FROM OLD.expected_version_revision
                        AND NEW.requested_by_admin_id
                            IS NOT DISTINCT FROM OLD.requested_by_admin_id
                        AND NEW.attempts IS NOT DISTINCT FROM OLD.attempts
                        AND NEW.locked_at IS NOT DISTINCT FROM OLD.locked_at
                        AND NEW.locked_by_hash IS NOT DISTINCT FROM OLD.locked_by_hash
                        AND NEW.lease_expires_at IS NOT DISTINCT FROM OLD.lease_expires_at
                        AND NEW.started_at IS NOT DISTINCT FROM OLD.started_at
                        AND NEW.completed_at IS NOT DISTINCT FROM OLD.completed_at
                        AND NEW.cancelled_at IS NOT DISTINCT FROM OLD.cancelled_at
                        AND NEW.failed_at IS NOT DISTINCT FROM OLD.failed_at
                        AND NEW.failure_code IS NOT DISTINCT FROM OLD.failure_code
                        AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1;
                    IF NOT revision_rebase THEN
                        IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                           OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                           OR NEW.probability_version_id
                                IS DISTINCT FROM OLD.probability_version_id
                           OR NEW.scheduled_for IS DISTINCT FROM OLD.scheduled_for
                           OR NEW.expected_gacha_revision
                                IS DISTINCT FROM OLD.expected_gacha_revision
                           OR NEW.expected_version_revision
                                IS DISTINCT FROM OLD.expected_version_revision
                           OR NEW.requested_by_admin_id
                                IS DISTINCT FROM OLD.requested_by_admin_id
                           OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                            RAISE EXCEPTION
                                'Gacha Publish Schedule identity and revision are immutable';
                        END IF;
                        IF NOT (
                            (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                            OR
                            (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                        ) THEN
                            RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                        END IF;
                    END IF;
                ELSIF NEW.scheduled_for <= CURRENT_TIMESTAMP THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule must be in the future';
                END IF;

                IF NEW.status IN ('scheduled', 'processing') THEN
                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    SELECT * INTO probability_row
                    FROM catalog_probability_versions
                    WHERE id = NEW.probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'draft'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.revision
                            IS DISTINCT FROM NEW.expected_version_revision
                       OR version_row.published_probability_version_id
                            IS DISTINCT FROM NEW.probability_version_id
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id
                            IS DISTINCT FROM version_row.id
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256
                            !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule requires its Draft and Published Probability';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM catalog_gachas
                        WHERE id = NEW.gacha_id
                          AND revision = NEW.expected_gacha_revision
                          AND archived_at IS NULL
                    ) THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule revision is stale';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_scheduled_draft_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_gacha_id bigint;
            DECLARE active_schedule catalog_gacha_publish_schedules%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.published_version_id
                            IS NOT DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id
                            IS NOT DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.sold_count IS NOT DISTINCT FROM OLD.sold_count
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND NEW.published_version_id
                            IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id
                            IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at
                       AND NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                       AND NEW.sales_paused_at IS NOT DISTINCT FROM OLD.sales_paused_at
                       AND NEW.sales_paused_by_admin_public_id
                            IS NOT DISTINCT FROM OLD.sales_paused_by_admin_public_id
                       AND NEW.sales_pause_reason_code
                            IS NOT DISTINCT FROM OLD.sales_pause_reason_code
                       AND NEW.sales_resumed_at IS NOT DISTINCT FROM OLD.sales_resumed_at
                       AND NEW.sales_last_mutation_request_id
                            IS NOT DISTINCT FROM OLD.sales_last_mutation_request_id THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable';
                END IF;

                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.status::text = 'draft'::text
                       AND NEW.status::text = 'published'::text
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND NEW.published_probability_version_id
                            IS NOT DISTINCT FROM OLD.published_probability_version_id
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Version is immutable';
                END IF;

                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_gacha_id := CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_id
                        ELSE NEW.gacha_id
                    END;
                ELSE
                    SELECT gacha_id INTO parent_gacha_id
                    FROM catalog_gacha_versions
                    WHERE id = CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id
                        ELSE NEW.gacha_version_id
                    END;
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = parent_gacha_id
                      AND status IN ('scheduled', 'processing')
                ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha relations are immutable';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
        SQL);
    }

    private function restoreScheduleGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_publish_schedule_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE probability_row catalog_probability_versions%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule history cannot be deleted';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                       OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                       OR NEW.probability_version_id
                            IS DISTINCT FROM OLD.probability_version_id
                       OR NEW.scheduled_for IS DISTINCT FROM OLD.scheduled_for
                       OR NEW.expected_gacha_revision
                            IS DISTINCT FROM OLD.expected_gacha_revision
                       OR NEW.expected_version_revision
                            IS DISTINCT FROM OLD.expected_version_revision
                       OR NEW.requested_by_admin_id
                            IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule identity and revision are immutable';
                    END IF;
                    IF NOT (
                        (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                        OR
                        (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                    END IF;
                ELSIF NEW.scheduled_for <= CURRENT_TIMESTAMP THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule must be in the future';
                END IF;
                IF NEW.status IN ('scheduled', 'processing') THEN
                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    SELECT * INTO probability_row
                    FROM catalog_probability_versions
                    WHERE id = NEW.probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'draft'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.revision
                            IS DISTINCT FROM NEW.expected_version_revision
                       OR version_row.published_probability_version_id
                            IS DISTINCT FROM NEW.probability_version_id
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id
                            IS DISTINCT FROM version_row.id
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256
                            !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule requires its Draft and Published Probability';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM catalog_gachas
                        WHERE id = NEW.gacha_id
                          AND revision = NEW.expected_gacha_revision
                          AND archived_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule revision is stale';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_scheduled_draft_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_gacha_id bigint;
            DECLARE active_schedule catalog_gacha_publish_schedules%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND NEW.published_version_id
                            IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id
                            IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.status::text = 'draft'::text
                       AND NEW.status::text = 'published'::text
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND NEW.published_probability_version_id
                            IS NOT DISTINCT FROM OLD.published_probability_version_id
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Version is immutable';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_gacha_id := CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_id
                        ELSE NEW.gacha_id
                    END;
                ELSE
                    SELECT gacha_id INTO parent_gacha_id
                    FROM catalog_gacha_versions
                    WHERE id = CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id
                        ELSE NEW.gacha_version_id
                    END;
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = parent_gacha_id
                      AND status IN ('scheduled', 'processing')
                ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha relations are immutable';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
        SQL);
    }
};
