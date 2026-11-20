<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE line_quota_periods
                DROP CONSTRAINT line_quota_period_actor,
                DROP CONSTRAINT line_quota_periods_check,
                ADD CONSTRAINT line_quota_period_actor CHECK (
                    (baseline_origin = 'human' AND verified_by_admin_id IS NOT NULL)
                    OR (baseline_origin = 'system' AND verified_by_admin_id IS NULL
                        AND authentication_revision IS NOT NULL AND authentication_revision > 0 AND opening_used = 0)
                    OR (baseline_origin = 'system_initial' AND verified_by_admin_id IS NULL
                        AND authentication_revision IS NOT NULL AND authentication_revision > 0)
                ),
                ADD CONSTRAINT line_quota_period_opening_limit CHECK (
                    approved_limit >= 0 AND (baseline_origin = 'system_initial' OR approved_limit >= opening_used)
                );
            CREATE FUNCTION v2_line_quota_initial_generation_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.baseline_origin = 'system_initial' AND NOT EXISTS (
                    SELECT 1 FROM line_quota_accounts a JOIN line_bots b ON b.id = a.line_bot_id
                    WHERE a.line_bot_id = NEW.line_bot_id AND a.authentication_revision = NEW.authentication_revision
                        AND b.authentication_revision = NEW.authentication_revision
                ) THEN RAISE EXCEPTION 'LINE initial quota generation mismatch' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_quota_initial_generation_guard BEFORE INSERT ON line_quota_periods
                FOR EACH ROW EXECUTE FUNCTION v2_line_quota_initial_generation_guard();
            SQL);
    }

    public function down(): void { throw new RuntimeException('Forward-only LINE initial quota migration.'); }
};
