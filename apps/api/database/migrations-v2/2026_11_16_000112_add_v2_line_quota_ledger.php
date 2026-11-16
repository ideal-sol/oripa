<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE line_quota_accounts (
                line_bot_id bigint PRIMARY KEY REFERENCES line_bots(id),
                authentication_revision bigint NOT NULL CHECK (authentication_revision > 0),
                usable_from date NOT NULL CHECK (extract(day FROM usable_from) = 1),
                halted boolean NOT NULL DEFAULT true, halt_reason varchar(64), halted_at timestamptz,
                updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TABLE line_quota_periods (
                line_bot_id bigint NOT NULL REFERENCES line_quota_accounts(line_bot_id),
                month date NOT NULL CHECK (extract(day FROM month) = 1),
                opening_used bigint NOT NULL CHECK (opening_used >= 0),
                approved_limit bigint NOT NULL CHECK (approved_limit >= opening_used),
                effective_limit bigint NOT NULL CHECK (effective_limit >= 0 AND effective_limit <= approved_limit),
                evidence_id uuid NOT NULL, verified_by_admin_id bigint NOT NULL REFERENCES admins(id),
                verified_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (line_bot_id, month)
            );
            CREATE TABLE line_quota_charges (
                line_bot_id bigint NOT NULL, month date NOT NULL,
                batch_id bigint NOT NULL REFERENCES line_delivery_batches(id),
                units integer NOT NULL CHECK (units BETWEEN 1 AND 500),
                state text NOT NULL CHECK (state IN ('reserved','unknown','accepted')),
                created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (line_bot_id, month, batch_id),
                FOREIGN KEY (line_bot_id, month) REFERENCES line_quota_periods(line_bot_id, month)
            );
            CREATE INDEX line_quota_charges_batch ON line_quota_charges(batch_id);
            CREATE OR REPLACE FUNCTION v2_line_quota_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN RAISE EXCEPTION 'LINE quota history is retained'; END IF;
                IF TG_TABLE_NAME = 'line_quota_periods' AND TG_OP = 'UPDATE' THEN
                    IF (to_jsonb(NEW) - 'effective_limit') IS DISTINCT FROM (to_jsonb(OLD) - 'effective_limit')
                        OR NEW.effective_limit > OLD.effective_limit THEN
                        RAISE EXCEPTION 'LINE quota baseline is immutable';
                    END IF;
                ELSIF TG_TABLE_NAME = 'line_quota_charges' THEN
                    IF NOT EXISTS (SELECT 1 FROM line_delivery_batches WHERE id = NEW.batch_id AND line_bot_id = NEW.line_bot_id) THEN
                        RAISE EXCEPTION 'LINE quota BOT mismatch';
                    END IF;
                    IF TG_OP = 'UPDATE' AND ((to_jsonb(NEW) - ARRAY['state','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['state','updated_at'])
                        OR (NEW.state <> OLD.state AND NOT (OLD.state = 'reserved' AND NEW.state = 'unknown' OR OLD.state = 'unknown' AND NEW.state = 'accepted'))) THEN
                        RAISE EXCEPTION 'LINE quota exposure cannot be released';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_quota_periods_guard BEFORE UPDATE OR DELETE ON line_quota_periods
                FOR EACH ROW EXECUTE FUNCTION v2_line_quota_history_guard();
            CREATE TRIGGER line_quota_charges_guard BEFORE INSERT OR UPDATE OR DELETE ON line_quota_charges
                FOR EACH ROW EXECUTE FUNCTION v2_line_quota_history_guard();
            CREATE TRIGGER line_quota_accounts_delete_guard BEFORE DELETE ON line_quota_accounts
                FOR EACH ROW EXECUTE FUNCTION v2_line_quota_history_guard();
            SQL);
        foreach (['line_quota_accounts', 'line_quota_periods', 'line_quota_charges'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION v2_line_quota_history_guard();");
        }
    }

    public function down(): void { throw new RuntimeException('Forward-only LINE quota migration.'); }
};
