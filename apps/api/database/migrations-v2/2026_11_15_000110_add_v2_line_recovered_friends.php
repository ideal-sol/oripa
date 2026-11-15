<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A follower-list observation is not a follow event and confers no reward.
        Schema::create('line_recovered_friends', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('line_bot_id')->constrained('line_bots')->restrictOnDelete();
            $table->string('subject_hash', 64);
            $table->text('subject_ciphertext');
            $table->bigInteger('observed_at_ms');
            $table->unique(['line_bot_id', 'subject_hash'], 'line_recovered_bot_subject_unique');
        });
        DB::statement("ALTER TABLE line_recovered_friends ADD CONSTRAINT line_recovered_values CHECK (subject_hash ~ '^[0-9a-f]{64}$' AND length(subject_ciphertext) > 0 AND observed_at_ms >= 0)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_recovered_friend_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'LINE recovered identity history cannot be deleted';
                END IF;
                IF NEW.id <> OLD.id OR NEW.line_bot_id <> OLD.line_bot_id
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.subject_ciphertext <> OLD.subject_ciphertext
                    OR NEW.observed_at_ms < OLD.observed_at_ms THEN
                    RAISE EXCEPTION 'LINE recovered identity binding is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_recovered_friend_guard BEFORE UPDATE OR DELETE ON line_recovered_friends
                FOR EACH ROW EXECUTE FUNCTION v2_line_recovered_friend_guard();
            CREATE TRIGGER line_recovered_friend_truncate_guard BEFORE TRUNCATE ON line_recovered_friends
                FOR EACH STATEMENT EXECUTE FUNCTION v2_line_recovered_friend_guard();
            CREATE INDEX line_webhook_recipient_observation ON line_webhook_events
                (line_bot_id, subject_hash, event_timestamp_ms DESC, event_type DESC);
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only LINE recovered friend migration');
    }
};
