<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertCompatibleExistingData();
        $this->installGachaDraftGuard(true);
        $this->installLifecycleGuard(true);
        $this->installScheduleGuard(true);
        $this->installScheduledDraftGuard(true);
        $this->installInventoryCapacityGuards();
    }

    public function down(): void
    {
        if (DB::table('catalog_gacha_publish_schedules')
            ->whereIn('status', ['scheduled', 'processing'])->exists()) {
            throw new RuntimeException(
                'Cannot roll back MIG-067 while a pre-publication schedule is active.'
            );
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_versions_inventory_capacity_check '.
            'ON catalog_gacha_versions'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_inventory_capacity_check '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS prize_inventories_inventory_capacity_check '.
            'ON prize_inventories'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_inventory_capacity_lock '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS prize_inventories_inventory_capacity_lock '.
            'ON prize_inventories'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_gacha_inventory_capacity()');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_lock_gacha_inventory_capacity()');

        $this->installScheduledDraftGuard(false);
        $this->installScheduleGuard(false);
        $this->installLifecycleGuard(false);
        $this->installGachaDraftGuard(false);
    }

    private function assertCompatibleExistingData(): void
    {
        if (DB::table('catalog_gachas')->where('management_status', 'scheduled')->exists()) {
            throw new RuntimeException(
                'Legacy activated Gacha schedules require explicit reconciliation before MIG-067.'
            );
        }
        if (DB::query()->fromSub(
            DB::table('catalog_gacha_versions as version')
                ->select('version.id')
                ->join(
                    'catalog_gacha_version_prizes as relation',
                    'relation.gacha_version_id',
                    '=',
                    'version.id'
                )
                ->groupBy('version.id', 'version.total_count')
                ->havingRaw('SUM(relation.initial_inventory) > version.total_count'),
            'violations'
        )->exists()) {
            throw new RuntimeException(
                'Existing Gacha Prize snapshot inventory exceeds administrator total count.'
            );
        }
        if (DB::query()->fromSub(
            DB::table('catalog_gacha_versions as version')
                ->select('version.id')
                ->join(
                    'catalog_gacha_version_prizes as relation',
                    'relation.gacha_version_id',
                    '=',
                    'version.id'
                )
                ->join(
                    'prize_inventories as inventory',
                    'inventory.gacha_version_prize_id',
                    '=',
                    'relation.id'
                )
                ->groupBy('version.id', 'version.total_count')
                ->havingRaw('SUM(inventory.total_quantity) > version.total_count'),
            'violations'
        )->exists()) {
            throw new RuntimeException(
                'Existing operational Prize inventory exceeds administrator total count.'
            );
        }
    }

    private function installInventoryCapacityGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_lock_gacha_inventory_capacity()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE old_version_id bigint;
            DECLARE new_version_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_version_prizes' THEN
                    old_version_id := CASE WHEN TG_OP = 'UPDATE' THEN OLD.gacha_version_id ELSE NULL END;
                    new_version_id := NEW.gacha_version_id;
                ELSE
                    IF TG_OP = 'UPDATE' THEN
                        SELECT gacha_version_id INTO old_version_id
                        FROM catalog_gacha_version_prizes
                        WHERE id = OLD.gacha_version_prize_id;
                    END IF;
                    SELECT gacha_version_id INTO new_version_id
                    FROM catalog_gacha_version_prizes
                    WHERE id = NEW.gacha_version_prize_id;
                END IF;

                PERFORM id
                FROM catalog_gacha_versions
                WHERE id IN (old_version_id, new_version_id)
                ORDER BY id
                FOR UPDATE;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_gacha_inventory_capacity()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_id bigint;
            DECLARE capacity bigint;
            DECLARE snapshot_total numeric := 0;
            DECLARE operational_total numeric := 0;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    version_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'catalog_gacha_version_prizes' THEN
                    version_id := NEW.gacha_version_id;
                ELSE
                    SELECT gacha_version_id INTO version_id
                    FROM catalog_gacha_version_prizes
                    WHERE id = NEW.gacha_version_prize_id;
                END IF;
                IF version_id IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT total_count INTO capacity
                FROM catalog_gacha_versions
                WHERE id = version_id
                FOR UPDATE;
                SELECT COALESCE(SUM(initial_inventory), 0) INTO snapshot_total
                FROM catalog_gacha_version_prizes
                WHERE gacha_version_id = version_id;
                SELECT COALESCE(SUM(inventory.total_quantity), 0) INTO operational_total
                FROM prize_inventories AS inventory
                INNER JOIN catalog_gacha_version_prizes AS relation
                    ON relation.id = inventory.gacha_version_prize_id
                WHERE relation.gacha_version_id = version_id;

                IF snapshot_total > capacity OR operational_total > capacity THEN
                    RAISE EXCEPTION
                        'Aggregate Gacha Prize inventory cannot exceed total count';
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_inventory_capacity_lock '.
            'BEFORE INSERT OR UPDATE OF gacha_version_id, initial_inventory '.
            'ON catalog_gacha_version_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_lock_gacha_inventory_capacity()'
        );
        DB::statement(
            'CREATE TRIGGER prize_inventories_inventory_capacity_lock '.
            'BEFORE INSERT OR UPDATE OF gacha_version_prize_id, total_quantity '.
            'ON prize_inventories FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_lock_gacha_inventory_capacity()'
        );
        DB::statement(
            'CREATE CONSTRAINT TRIGGER catalog_gacha_versions_inventory_capacity_check '.
            'AFTER INSERT OR UPDATE ON catalog_gacha_versions '.
            'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_gacha_inventory_capacity()'
        );
        DB::statement(
            'CREATE CONSTRAINT TRIGGER catalog_gacha_version_prizes_inventory_capacity_check '.
            'AFTER INSERT OR UPDATE ON catalog_gacha_version_prizes '.
            'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_gacha_inventory_capacity()'
        );
        DB::statement(
            'CREATE CONSTRAINT TRIGGER prize_inventories_inventory_capacity_check '.
            'AFTER INSERT OR UPDATE ON prize_inventories '.
            'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_gacha_inventory_capacity()'
        );
    }

    private function installGachaDraftGuard(bool $allowPublishedCategory): void
    {
        $protectedFields = $allowPublishedCategory
            ? <<<'SQL'
                       (NEW.state IS DISTINCT FROM OLD.state AND NEW.state = 'disabled')
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL
            : <<<'SQL'
                       NEW.category_id IS DISTINCT FROM OLD.category_id
                       OR (NEW.state IS DISTINCT FROM OLD.state AND NEW.state = 'disabled')
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                SQL;
        DB::unprepared(str_replace('__PROTECTED_FIELDS__', $protectedFields, <<<'SQL'
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
                    IF NEW.code IS DISTINCT FROM OLD.code OR NEW.slug IS DISTINCT FROM OLD.slug THEN
                        RAISE EXCEPTION 'Catalog Gacha code and slug are immutable';
                    END IF;
                    IF NEW.revision <> OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Catalog Gacha revision must increase by one';
                    END IF;
                    IF __PROTECTED_FIELDS__ THEN
                        SELECT EXISTS (
                            SELECT 1 FROM catalog_gacha_versions
                            WHERE gacha_id = OLD.id AND status = 'published'
                        ) INTO has_published_version;
                        SELECT EXISTS (
                            SELECT 1 FROM draw_requests AS draw
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
            SQL));
    }

    private function installLifecycleGuard(bool $canonical): void
    {
        $sql = $canonical ? <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.management_status = 'unpublished'
                   AND NEW.management_status <> 'unpublished' THEN
                    RAISE EXCEPTION 'Unpublished Gacha lifecycle is terminal';
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at THEN
                    RAISE EXCEPTION 'First publication history is immutable';
                END IF;
                IF NEW.management_status = 'scheduled' AND (
                    NEW.first_published_at IS NOT NULL
                    OR NEW.scheduled_start_at IS NULL
                    OR NEW.scheduled_start_at <= clock_timestamp()
                    OR NEW.published_version_id IS NOT NULL
                    OR NEW.active_draw_state_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha must remain unpublished before start';
                END IF;
                IF NEW.management_status IN ('published', 'sales_paused') AND (
                    NEW.first_published_at IS NULL
                    OR NEW.first_published_at > clock_timestamp() + INTERVAL '5 seconds'
                    OR NEW.published_version_id IS NULL
                    OR NEW.active_draw_state_id IS NULL
                ) THEN
                    RAISE EXCEPTION 'Published Gacha requires actual publication history';
                END IF;
                IF NEW.management_status <> 'scheduled'
                   AND NEW.scheduled_start_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Only a scheduled Gacha may retain a scheduled start';
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND NEW.management_status = 'scheduled' THEN
                    RAISE EXCEPTION 'Published Gacha cannot be scheduled again';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL
            : <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.management_status = 'unpublished'
                   AND NEW.management_status <> 'unpublished' THEN
                    RAISE EXCEPTION 'Unpublished Gacha lifecycle is terminal';
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at <= CURRENT_TIMESTAMP
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at THEN
                    RAISE EXCEPTION 'First publication history is immutable';
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at > CURRENT_TIMESTAMP
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at
                   AND NOT (OLD.management_status = 'scheduled' AND (
                       NEW.management_status = 'scheduled' AND NEW.first_published_at > CURRENT_TIMESTAMP
                       OR NEW.management_status = 'draft' AND NEW.first_published_at IS NULL
                   )) THEN
                    RAISE EXCEPTION 'Scheduled first publication can only be changed before start';
                END IF;
                IF NEW.management_status = 'scheduled' AND (
                    NEW.first_published_at IS NULL
                    OR NEW.first_published_at <= CURRENT_TIMESTAMP
                    OR NEW.scheduled_start_at IS DISTINCT FROM NEW.first_published_at
                    OR NEW.published_version_id IS NULL
                    OR NEW.active_draw_state_id IS NULL
                ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha requires a future first publication';
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at <= CURRENT_TIMESTAMP
                   AND NEW.management_status = 'scheduled' THEN
                    RAISE EXCEPTION 'Published Gacha cannot be scheduled again';
                END IF;
                IF NEW.management_status <> 'scheduled'
                   AND NEW.scheduled_start_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Only a scheduled Gacha may retain a scheduled start';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL;
        DB::unprepared($sql);
    }

    private function installScheduleGuard(bool $editable): void
    {
        $updateIdentity = $editable
            ? <<<'SQL'
                    IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                       OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                       OR NEW.requested_by_admin_id IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule identity is immutable';
                    END IF;
                    IF OLD.status = 'scheduled' AND NEW.status = 'scheduled' THEN
                        IF NEW.scheduled_for <= clock_timestamp()
                           OR NEW.next_attempt_at IS DISTINCT FROM NEW.scheduled_for THEN
                            RAISE EXCEPTION 'Gacha Publish Schedule revision must remain future';
                        END IF;
                    ELSIF NOT (
                        (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                        OR (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                    END IF;
                SQL
            : <<<'SQL'
                    IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                       OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                       OR NEW.probability_version_id IS DISTINCT FROM OLD.probability_version_id
                       OR NEW.scheduled_for IS DISTINCT FROM OLD.scheduled_for
                       OR NEW.expected_gacha_revision IS DISTINCT FROM OLD.expected_gacha_revision
                       OR NEW.expected_version_revision IS DISTINCT FROM OLD.expected_version_revision
                       OR NEW.requested_by_admin_id IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule identity and revision are immutable';
                    END IF;
                    IF NOT (
                        (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                        OR (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                    END IF;
                SQL;
        DB::unprepared(str_replace('__UPDATE_RULE__', $updateIdentity, <<<'SQL'
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
            __UPDATE_RULE__
                ELSIF NEW.scheduled_for <= CURRENT_TIMESTAMP THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule must be in the future';
                END IF;
                IF NEW.status IN ('scheduled', 'processing') THEN
                    SELECT * INTO version_row FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    SELECT * INTO probability_row FROM catalog_probability_versions
                    WHERE id = NEW.probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'draft'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.revision IS DISTINCT FROM NEW.expected_version_revision
                       OR version_row.published_probability_version_id IS DISTINCT FROM NEW.probability_version_id
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id IS DISTINCT FROM version_row.id
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule requires its Draft and Published Probability';
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
            SQL));
    }

    private function installScheduledDraftGuard(bool $editable): void
    {
        $scheduledRules = $editable
            ? <<<'SQL'
                    IF active_schedule.status = 'scheduled' THEN
                        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                    END IF;
                SQL
            : '';
        $legacySalesPauseRule = $editable
            ? ''
            : <<<'SQL'
                    IF NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.published_version_id IS NOT DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id IS NOT DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.sold_count IS NOT DISTINCT FROM OLD.sold_count
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                SQL;
        $processingGachaRule = $editable
            ? <<<'SQL'
                    IF active_schedule.status = 'processing'
                       AND OLD.management_status = 'scheduled'
                       AND NEW.management_status = 'published'
                       AND OLD.first_published_at IS NULL
                       AND NEW.first_published_at IS NOT NULL
                       AND NEW.published_version_id IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state = 'active'
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                SQL
            : <<<'SQL'
                    IF active_schedule.status = 'processing'
                       AND NEW.published_version_id IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at
                       AND NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                       AND NEW.sales_paused_at IS NOT DISTINCT FROM OLD.sales_paused_at
                       AND NEW.sales_paused_by_admin_public_id IS NOT DISTINCT FROM OLD.sales_paused_by_admin_public_id
                       AND NEW.sales_pause_reason_code IS NOT DISTINCT FROM OLD.sales_pause_reason_code
                       AND NEW.sales_resumed_at IS NOT DISTINCT FROM OLD.sales_resumed_at
                       AND NEW.sales_last_mutation_request_id IS NOT DISTINCT FROM OLD.sales_last_mutation_request_id THEN
                        RETURN NEW;
                    END IF;
                SQL;
        DB::unprepared(str_replace(
            ['__SCHEDULED_RULES__', '__LEGACY_SALES_PAUSE_RULE__', '__PROCESSING_GACHA_RULE__'],
            [$scheduledRules, $legacySalesPauseRule, $processingGachaRule],
            <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_scheduled_draft_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_gacha_id bigint;
            DECLARE active_schedule catalog_gacha_publish_schedules%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = OLD.id AND status IN ('scheduled', 'processing') LIMIT 1;
                    IF active_schedule.id IS NULL THEN RETURN NEW; END IF;
            __SCHEDULED_RULES__
            __LEGACY_SALES_PAUSE_RULE__
            __PROCESSING_GACHA_RULE__
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable while processing';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id AND status IN ('scheduled', 'processing') LIMIT 1;
                    IF active_schedule.id IS NULL THEN RETURN NEW; END IF;
            __SCHEDULED_RULES__
                    IF active_schedule.status = 'processing'
                       AND OLD.status = 'draft' AND NEW.status = 'published'
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND NEW.published_probability_version_id IS NOT DISTINCT FROM OLD.published_probability_version_id
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Version is immutable while processing';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_gacha_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.gacha_id ELSE NEW.gacha_id END;
                ELSE
                    SELECT gacha_id INTO parent_gacha_id FROM catalog_gacha_versions
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id ELSE NEW.gacha_version_id END;
                END IF;
                SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                WHERE gacha_id = parent_gacha_id AND status IN ('scheduled', 'processing') LIMIT 1;
                IF active_schedule.id IS NULL THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
            __SCHEDULED_RULES__
                RAISE EXCEPTION 'Scheduled Gacha relations are immutable while processing';
            END;
            $$
            SQL
        ));
    }
};
