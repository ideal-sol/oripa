<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE line_quota_periods ADD COLUMN baseline_cut_at timestamptz;
            ALTER TABLE line_deliveries ADD COLUMN segment jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(segment) = 'object');
            ALTER TABLE line_deliveries ADD COLUMN planned_count integer NOT NULL DEFAULT 0 CHECK (planned_count >= 0);
            ALTER TABLE line_deliveries ADD COLUMN create_request_key uuid UNIQUE;
            ALTER TABLE line_deliveries ADD COLUMN create_request_hash char(64);
            ALTER TABLE line_deliveries ADD CONSTRAINT line_delivery_creation_identity CHECK ((create_request_key IS NULL) = (create_request_hash IS NULL));
            CREATE TABLE line_media_intents (
                id uuid PRIMARY KEY, line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                authentication_revision bigint NOT NULL, created_by_admin_id bigint NOT NULL REFERENCES admins(id),
                kind text NOT NULL CHECK (kind IN ('image','video','imagemap','carousel')),
                files jsonb NOT NULL CHECK (jsonb_typeof(files) = 'object'),
                expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), finalized_at timestamptz
            );
            CREATE TABLE line_media_assets (
                id uuid PRIMARY KEY REFERENCES line_media_intents(id), line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                authentication_revision bigint NOT NULL, kind text NOT NULL,
                manifest jsonb NOT NULL CHECK (jsonb_typeof(manifest) = 'object'),
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE OR REPLACE FUNCTION v2_line_management_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'LINE management history is retained'; END IF;
                IF TG_TABLE_NAME = 'line_media_assets' THEN RAISE EXCEPTION 'LINE verified assets are immutable'; END IF;
                IF TG_TABLE_NAME = 'line_media_intents' THEN
                    IF OLD.finalized_at IS NOT NULL OR NEW.finalized_at IS NULL
                        OR (to_jsonb(NEW) - 'finalized_at') IS DISTINCT FROM (to_jsonb(OLD) - 'finalized_at')
                        THEN RAISE EXCEPTION 'LINE upload identity is immutable'; END IF;
                ELSIF TG_TABLE_NAME = 'line_quota_periods' THEN
                    IF NEW.baseline_cut_at IS DISTINCT FROM OLD.baseline_cut_at
                        THEN RAISE EXCEPTION 'LINE baseline evidence is immutable'; END IF;
                ELSE
                    IF ROW(NEW.create_request_key,NEW.create_request_hash) IS DISTINCT FROM ROW(OLD.create_request_key,OLD.create_request_hash)
                        OR (OLD.status NOT IN ('draft','scheduled') AND ROW(NEW.segment,NEW.planned_count) IS DISTINCT FROM ROW(OLD.segment,OLD.planned_count))
                        THEN RAISE EXCEPTION 'LINE delivery selection is immutable'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_deliveries_management_guard BEFORE UPDATE ON line_deliveries FOR EACH ROW EXECUTE FUNCTION v2_line_management_guard();
            CREATE TRIGGER line_quota_periods_evidence_guard BEFORE UPDATE ON line_quota_periods FOR EACH ROW EXECUTE FUNCTION v2_line_management_guard();
        SQL);
        foreach (['line_media_intents', 'line_media_assets'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_history_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_line_management_guard(); CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION v2_line_management_guard();");
        }
    }

    public function down(): void { throw new RuntimeException('Forward-only LINE management migration'); }
};
