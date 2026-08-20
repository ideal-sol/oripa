<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CANONICAL_STAGE_CODE = '__canonical_inventory_v1';

    public function up(): void
    {
        $this->installScheduleGuard(true);
        $this->installScheduledDraftGuard(true);
    }

    public function down(): void
    {
        if (DB::table('catalog_gacha_publish_schedules as schedule')
            ->join(
                'catalog_gacha_versions as version',
                'version.id',
                '=',
                'schedule.gacha_version_id'
            )
            ->join(
                'catalog_probability_versions as probability',
                'probability.id',
                '=',
                'schedule.probability_version_id'
            )
            ->whereIn('schedule.status', ['scheduled', 'processing'])
            ->where(function ($query): void {
                $query->where('probability.status', '<>', 'published')
                    ->orWhereRaw(
                        'version.published_probability_version_id '.
                        'IS DISTINCT FROM schedule.probability_version_id'
                    );
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot roll back MIG-068 while a canonical Probability selection is pending.'
            );
        }

        $this->installScheduledDraftGuard(false);
        $this->installScheduleGuard(false);
    }

    private function installScheduleGuard(bool $allowCanonicalDraft): void
    {
        $probabilityRule = $allowCanonicalDraft
            ? sprintf(<<<'SQL'
                       OR probability_row.status::text NOT IN ('draft'::text, 'published'::text)
                       OR (
                           probability_row.status::text = 'draft'::text
                           AND NOT EXISTS (
                               SELECT 1 FROM catalog_probability_stages
                               WHERE probability_version_id = probability_row.id
                                 AND code = '%s'
                           )
                       )
                SQL, self::CANONICAL_STAGE_CODE)
            : <<<'SQL'
                       OR version_row.published_probability_version_id IS DISTINCT FROM NEW.probability_version_id
                       OR probability_row.status::text <> 'published'::text
                SQL;
        DB::unprepared(str_replace('__PROBABILITY_RULE__', $probabilityRule, <<<'SQL'
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
                       OR NEW.requested_by_admin_id IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.request_id IS DISTINCT FROM OLD.request_id
                       OR NEW.created_at IS DISTINCT FROM OLD.created_at
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule identity and revision are immutable';
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
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id IS DISTINCT FROM version_row.id
            __PROBABILITY_RULE__
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule requires its Draft and canonical Probability metadata';
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

    private function installScheduledDraftGuard(bool $allowInternalSelection): void
    {
        $probabilitySelectionRule = $allowInternalSelection
            ? 'NEW.published_probability_version_id IS NOT DISTINCT FROM active_schedule.probability_version_id'
            : 'NEW.published_probability_version_id IS NOT DISTINCT FROM OLD.published_probability_version_id';
        DB::unprepared(str_replace(
            '__PROBABILITY_SELECTION_RULE__',
            $probabilitySelectionRule,
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
                    IF active_schedule.status = 'scheduled' THEN
                        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                    END IF;
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
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable while processing';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id AND status IN ('scheduled', 'processing') LIMIT 1;
                    IF active_schedule.id IS NULL THEN RETURN NEW; END IF;
                    IF active_schedule.status = 'scheduled' THEN
                        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.status = 'draft' AND NEW.status = 'published'
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND __PROBABILITY_SELECTION_RULE__
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
                IF active_schedule.status = 'scheduled' THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                RAISE EXCEPTION 'Scheduled Gacha relations are immutable while processing';
            END;
            $$
            SQL
        ));
    }
};
