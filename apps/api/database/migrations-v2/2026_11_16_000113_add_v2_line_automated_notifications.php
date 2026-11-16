<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE line_automated_templates (
                event varchar(32) PRIMARY KEY CHECK (event::text IN ('shipping','payment','first_purchase','rank_promotion')),
                revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0)
            );
            INSERT INTO line_automated_templates(event) VALUES ('shipping'),('payment'),('first_purchase'),('rank_promotion');
            CREATE TABLE line_automated_template_versions (
                event varchar(32) NOT NULL REFERENCES line_automated_templates(event),
                revision bigint NOT NULL CHECK (revision > 0),
                enabled boolean NOT NULL DEFAULT false,
                category varchar(32) NOT NULL CHECK (category::text IN ('transactional','promotional')),
                messages_ciphertext text NOT NULL,
                effective_at timestamptz NOT NULL,
                created_by_admin_id bigint NOT NULL REFERENCES admins(id),
                PRIMARY KEY (event,revision),
                CHECK (event::text NOT IN ('shipping','payment') OR category = 'transactional')
            );
            CREATE TABLE line_automated_outcomes (
                source_key uuid PRIMARY KEY,
                outbox_id bigint NOT NULL REFERENCES outbox_messages(id) ON DELETE RESTRICT,
                event varchar(32) NOT NULL REFERENCES line_automated_templates(event),
                template_revision bigint,
                status varchar(16) NOT NULL CHECK (status::text IN ('queued','skipped')),
                reason_code varchar(64) NOT NULL,
                batch_id bigint UNIQUE REFERENCES line_delivery_batches(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(outbox_id,event),
                FOREIGN KEY(event,template_revision) REFERENCES line_automated_template_versions(event,revision),
                CHECK ((status = 'queued') = (batch_id IS NOT NULL))
            );
            CREATE FUNCTION v2_line_automated_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'LINE automated history is immutable'; END;
            $$;
            CREATE TRIGGER v2_line_template_version_immutable BEFORE UPDATE OR DELETE ON line_automated_template_versions
                FOR EACH ROW EXECUTE FUNCTION v2_line_automated_history_guard();
            CREATE TRIGGER v2_line_automated_outcome_immutable BEFORE UPDATE OR DELETE ON line_automated_outcomes
                FOR EACH ROW EXECUTE FUNCTION v2_line_automated_history_guard();
            CREATE TRIGGER v2_line_template_version_truncate BEFORE TRUNCATE ON line_automated_template_versions
                FOR EACH STATEMENT EXECUTE FUNCTION v2_line_automated_history_guard();
            CREATE TRIGGER v2_line_automated_outcome_truncate BEFORE TRUNCATE ON line_automated_outcomes
                FOR EACH STATEMENT EXECUTE FUNCTION v2_line_automated_history_guard();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration; retain automated notification history.');
    }
};
