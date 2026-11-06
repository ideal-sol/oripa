<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['external_identity_accounts', 'line_friendships', 'line_pending_follows'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('subject_ciphertext')->nullable();
            });
        }
        Schema::table('external_identity_accounts', function (Blueprint $table): void {
            $table->bigInteger('line_observed_at_ms')->nullable();
            $table->boolean('line_observed_friend')->nullable();
        });
        DB::statement("ALTER TABLE external_identity_accounts ADD CONSTRAINT external_identity_line_private_check CHECK ((provider = 'line' OR (subject_ciphertext IS NULL AND line_observed_at_ms IS NULL AND line_observed_friend IS NULL)) AND ((line_observed_at_ms IS NULL AND line_observed_friend IS NULL) OR (line_observed_at_ms IS NOT NULL AND line_observed_at_ms >= 0 AND line_observed_friend IS NOT NULL)))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_subject_ciphertext_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.subject_ciphertext IS NOT NULL AND NEW.subject_ciphertext IS DISTINCT FROM OLD.subject_ciphertext THEN
                    RAISE EXCEPTION 'LINE encrypted subject is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        foreach (['external_identity_accounts', 'line_friendships', 'line_pending_follows'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_ciphertext_guard BEFORE UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION v2_line_subject_ciphertext_guard()");
        }
        // Keep the installed guard, including its deletion and transition checks.
        // A ciphertext-only backfill must also work for a terminal pending row.
        $definition = DB::selectOne("SELECT pg_get_functiondef('v2_line_pending_follow_guard()'::regprocedure) AS definition")->definition;
        $needle = "IF NEW.public_id <> OLD.public_id";
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected LINE pending guard definition');
        }
        $exception = <<<'SQL'
            IF OLD.subject_ciphertext IS NULL AND NEW.subject_ciphertext IS NOT NULL
                AND (to_jsonb(NEW) - 'subject_ciphertext') = (to_jsonb(OLD) - 'subject_ciphertext') THEN
                RETURN NEW;
            END IF;
            SQL;
        DB::unprepared(str_replace($needle, $exception."\n".$needle, $definition));
        // A delayed follow is retained for deduplication but must not send a reply.
        Schema::table('line_webhook_events', function (Blueprint $table): void {
            $table->boolean('stale_observation')->default(false);
            $table->bigInteger('event_timestamp_ms')->nullable();
        });
        DB::statement('ALTER TABLE line_webhook_events DROP CONSTRAINT line_webhook_events_values_check');
        // Explicit text inputs keep pg_dump/pg_restore CHECK expressions stable.
        DB::statement(<<<'SQL'
            ALTER TABLE line_webhook_events ADD CONSTRAINT line_webhook_events_values_check CHECK (
                subject_hash ~ '^[0-9a-f]{64}$' AND event_id_hash ~ '^[0-9a-f]{64}$'
                AND (event_timestamp_ms IS NULL OR event_timestamp_ms >= 0)
                AND event_type::text IN ('follow', 'unfollow')
                AND reply_status::text IN ('pending', 'processing', 'sent', 'failed', 'skipped')
                AND ((event_type = 'follow' AND ((stale_observation AND reply_status = 'skipped') OR (NOT stale_observation AND reply_status <> 'skipped')))
                    OR (event_type = 'unfollow' AND reply_status = 'skipped'))
                AND ((reply_status::text IN ('pending', 'skipped') AND reply_attempted_at IS NULL AND reply_failure_code IS NULL)
                    OR (reply_status::text IN ('processing', 'sent') AND reply_attempted_at IS NOT NULL AND reply_failure_code IS NULL)
                    OR (reply_status = 'failed' AND reply_attempted_at IS NOT NULL AND reply_failure_code IS NOT NULL))
            )
        SQL);
        $eventGuard = DB::selectOne("SELECT pg_get_functiondef('v2_line_webhook_event_guard()'::regprocedure) AS definition")->definition;
        if (substr_count($eventGuard, $needle) !== 1) throw new RuntimeException('Unexpected LINE event guard definition');
        DB::unprepared(str_replace($needle, 'IF NEW.event_timestamp_ms IS DISTINCT FROM OLD.event_timestamp_ms OR NEW.stale_observation IS DISTINCT FROM OLD.stale_observation OR NEW.public_id <> OLD.public_id', $eventGuard));
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only LINE encrypted identity migration');
    }
};
