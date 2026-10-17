<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE member_rank_changes
                DROP CONSTRAINT member_rank_changes_reason_check,
                DROP CONSTRAINT member_rank_changes_check,
                ALTER COLUMN point_entry_id DROP NOT NULL,
                ADD COLUMN judgment_month date,
                ADD CONSTRAINT member_rank_changes_reason_shape CHECK (
                    (reason = 'promotion' AND to_level > from_level AND point_entry_id IS NOT NULL AND judgment_month IS NULL)
                    OR (reason = 'demotion' AND to_level = from_level - 1 AND point_entry_id IS NULL
                        AND judgment_month IS NOT NULL AND judgment_month = (month + INTERVAL '1 month')::date)
                );
            CREATE UNIQUE INDEX member_rank_changes_month_origin ON member_rank_changes (user_id, judgment_month)
                WHERE reason = 'demotion';
            CREATE INDEX member_rank_states_pending_month ON member_rank_states (baseline_month, user_id) WHERE level > 1;
            ALTER TABLE member_rank_states ADD CONSTRAINT member_rank_states_cursor CHECK (
                last_judged_month IS NULL OR last_judged_month = baseline_month
            );
            CREATE OR REPLACE FUNCTION v2_member_rank_cursor_forward() RETURNS trigger AS $$
            BEGIN
                IF NEW.baseline_month < OLD.baseline_month OR (OLD.last_judged_month IS NOT NULL
                    AND (NEW.last_judged_month IS NULL OR NEW.last_judged_month < OLD.last_judged_month)) THEN
                    RAISE EXCEPTION 'member rank judgment cursor must move forward';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_cursor_forward BEFORE UPDATE ON member_rank_states
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_cursor_forward();
            CREATE OR REPLACE FUNCTION v2_member_rank_entry_open_month() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM member_rank_states WHERE user_id = NEW.user_id AND baseline_month > NEW.month) THEN
                    RAISE EXCEPTION 'member rank paid grant cannot follow a later monthly judgment';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_entry_open_month BEFORE INSERT ON member_rank_point_entries
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_entry_open_month();
            CREATE TABLE member_rank_monthly_jobs (
                month date PRIMARY KEY CHECK (EXTRACT(DAY FROM month) = 1),
                status varchar(16) NOT NULL DEFAULT 'pending'
                    CHECK (status::text = ANY (ARRAY['pending', 'processing', 'completed', 'failed']::text[])),
                cursor_user_id bigint NOT NULL DEFAULT 0 CHECK (cursor_user_id >= 0),
                processed_count bigint NOT NULL DEFAULT 0 CHECK (processed_count >= 0),
                failures smallint NOT NULL DEFAULT 0 CHECK (failures BETWEEN 0 AND 3),
                lease_owner varchar(64),
                lease_expires_at timestamptz,
                heartbeat_at timestamptz,
                next_attempt_at timestamptz NOT NULL,
                last_error varchar(32),
                started_at timestamptz,
                completed_at timestamptz,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((status = 'processing' AND lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL AND heartbeat_at IS NOT NULL)
                    OR (status <> 'processing' AND lease_owner IS NULL AND lease_expires_at IS NULL)),
                CHECK ((status = 'completed') = (completed_at IS NOT NULL)),
                CHECK (status <> 'failed' OR failures = 3)
            );
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Member rank monthly judgment requires a forward-only migration.');
    }
};
