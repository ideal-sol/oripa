<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->installLifecycleGuard(true);
    }

    public function down(): void
    {
        if (DB::table('catalog_gachas')
            ->whereNotNull('public_deactivated_at')
            ->where('management_status', '<>', 'unpublished')
            ->exists()) {
            throw new RuntimeException(
                'Cannot roll back MIG-072 after an unpublished Gacha was restored.'
            );
        }

        $this->installLifecycleGuard(false);
    }

    private function installLifecycleGuard(bool $allowsDraftRestore): void
    {
        $terminalCondition = $allowsDraftRestore
            ? <<<'SQL'
                IF OLD.management_status = 'unpublished'
                   AND NEW.management_status NOT IN ('unpublished', 'draft') THEN
                    RAISE EXCEPTION 'Unpublished Gacha may only return to Draft';
                END IF;
                SQL
            : <<<'SQL'
                IF OLD.management_status = 'unpublished'
                   AND NEW.management_status <> 'unpublished' THEN
                    RAISE EXCEPTION 'Unpublished Gacha lifecycle is terminal';
                END IF;
                SQL;

        DB::unprepared(str_replace('__TERMINAL_CONDITION__', $terminalCondition, <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                __TERMINAL_CONDITION__
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
            SQL));
    }
};
