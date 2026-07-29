<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_messaging_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->text('linked_follow_message');
            $table->text('pending_follow_message');
            $table->string('login_relative_path', 255);
            $table->bigInteger('reward_point_amount')->default(0);
            $table->unsignedInteger('reward_expiration_days')->default(180);
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('line_friendships', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->char('subject_hash', 64)->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->foreignId('point_operation_id')
                ->nullable()
                ->unique()
                ->constrained('point_operations')
                ->restrictOnDelete();
            $table->timestampTz('followed_at');
            $table->timestampTz('unfollowed_at')->nullable();
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'followed_at']);
        });

        Schema::create('line_pending_follows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->char('subject_hash', 64)->unique();
            $table->string('status', 16);
            $table->foreignId('claimed_by_user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('followed_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('unfollowed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'followed_at']);
        });

        Schema::create('line_webhook_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->char('event_id_hash', 64)->unique();
            $table->char('subject_hash', 64);
            $table->string('event_type', 16);
            $table->string('reply_status', 16);
            $table->string('reply_failure_code', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('reply_attempted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['event_type', 'occurred_at']);
        });

        DB::statement(
            'ALTER TABLE line_messaging_settings ADD CONSTRAINT '.
            'line_messaging_settings_singleton_check CHECK (id = 1)'
        );
        DB::statement(
            'ALTER TABLE line_messaging_settings ADD CONSTRAINT '.
            'line_messaging_settings_values_check CHECK ('.
            'char_length(linked_follow_message) BETWEEN 1 AND 1000 AND '.
            'char_length(pending_follow_message) BETWEEN 1 AND 1000 AND '.
            "linked_follow_message !~ '[<>]' AND pending_follow_message !~ '[<>]' AND ".
            "login_relative_path ~ '^/[A-Za-z0-9/_?&=.-]*$' AND ".
            'reward_point_amount >= 0 AND reward_expiration_days BETWEEN 1 AND 3650 AND '.
            'revision >= 1)'
        );
        DB::statement(
            'ALTER TABLE line_friendships ADD CONSTRAINT '.
            'line_friendships_values_check CHECK ('.
            "subject_hash ~ '^[0-9a-f]{64}$' AND ".
            "status::text = ANY (ARRAY['friend'::text, 'unfollowed'::text]) AND ".
            "((status = 'friend' AND unfollowed_at IS NULL) OR ".
            "(status = 'unfollowed' AND unfollowed_at IS NOT NULL)) AND ".
            '((point_operation_id IS NULL AND rewarded_at IS NULL) OR '.
            '(point_operation_id IS NOT NULL AND rewarded_at IS NOT NULL)))'
        );
        DB::statement(
            'ALTER TABLE line_pending_follows ADD CONSTRAINT '.
            'line_pending_follows_values_check CHECK ('.
            "subject_hash ~ '^[0-9a-f]{64}$' AND ".
            "status::text = ANY (ARRAY['pending'::text, 'claimed'::text, 'unfollowed'::text]) AND ".
            "(status = 'pending' AND claimed_by_user_id IS NULL AND claimed_at IS NULL ".
            'AND unfollowed_at IS NULL OR '.
            "status = 'claimed' AND claimed_by_user_id IS NOT NULL AND claimed_at IS NOT NULL ".
            'AND unfollowed_at IS NULL OR '.
            "status = 'unfollowed' AND claimed_by_user_id IS NULL AND claimed_at IS NULL ".
            'AND unfollowed_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE line_webhook_events ADD CONSTRAINT '.
            'line_webhook_events_values_check CHECK ('.
            "event_id_hash ~ '^[0-9a-f]{64}$' AND subject_hash ~ '^[0-9a-f]{64}$' AND ".
            "event_type::text = ANY (ARRAY['follow'::text, 'unfollow'::text]) AND ".
            "reply_status::text = ANY (ARRAY[".
            "'pending'::text, 'processing'::text, 'sent'::text, 'failed'::text, ".
            "'skipped'::text]) AND ".
            "((event_type = 'follow' AND reply_status <> 'skipped') OR ".
            "(event_type = 'unfollow' AND reply_status = 'skipped')) AND ".
            "((reply_status IN ('pending', 'skipped') AND reply_attempted_at IS NULL ".
            'AND reply_failure_code IS NULL) OR '.
            "(reply_status = 'processing' AND reply_attempted_at IS NOT NULL ".
            'AND reply_failure_code IS NULL) OR '.
            "(reply_status = 'sent' AND reply_attempted_at IS NOT NULL ".
            'AND reply_failure_code IS NULL) OR '.
            "(reply_status = 'failed' AND reply_attempted_at IS NOT NULL ".
            'AND reply_failure_code IS NOT NULL)))'
        );

        $this->installGuards();

        DB::table('line_messaging_settings')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid7(),
            'linked_follow_message' => '友だち追加が完了しました。',
            'pending_follow_message' => '{login_url} からLINEログインを完了してください。',
            'login_relative_path' => '/login',
            'reward_point_amount' => 0,
            'reward_expiration_days' => 180,
            'revision' => 1,
            'updated_by_admin_id' => null,
            'created_at' => now()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS v2_line_webhook_event_guard() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_line_pending_follow_guard() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_line_friendship_guard() CASCADE');
        Schema::dropIfExists('line_webhook_events');
        Schema::dropIfExists('line_pending_follows');
        Schema::dropIfExists('line_friendships');
        Schema::dropIfExists('line_messaging_settings');
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_friendship_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'LINE friendship deletion is forbidden';
                END IF;
                IF NEW.public_id <> OLD.public_id
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.user_id <> OLD.user_id
                    OR NEW.followed_at < OLD.followed_at
                    OR NEW.point_operation_id IS DISTINCT FROM OLD.point_operation_id
                        AND OLD.point_operation_id IS NOT NULL
                    OR NEW.rewarded_at IS DISTINCT FROM OLD.rewarded_at
                        AND OLD.rewarded_at IS NOT NULL
                THEN
                    RAISE EXCEPTION 'LINE friendship identity or reward is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER line_friendships_guard_update BEFORE UPDATE ON '.
            'line_friendships FOR EACH ROW EXECUTE FUNCTION v2_line_friendship_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_friendships_guard_delete BEFORE DELETE ON '.
            'line_friendships FOR EACH ROW EXECUTE FUNCTION v2_line_friendship_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_friendships_guard_truncate BEFORE TRUNCATE ON '.
            'line_friendships FOR EACH STATEMENT EXECUTE FUNCTION v2_line_friendship_guard()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_pending_follow_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'LINE pending follow deletion is forbidden';
                END IF;
                IF NEW.public_id <> OLD.public_id
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.followed_at < OLD.followed_at
                    OR OLD.status = 'claimed'
                    OR NOT (
                        OLD.status = 'pending' AND NEW.status IN ('pending', 'claimed', 'unfollowed')
                        OR OLD.status = 'unfollowed' AND NEW.status = 'pending'
                    )
                THEN
                    RAISE EXCEPTION 'LINE pending follow transition is invalid';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER line_pending_follows_guard_update BEFORE UPDATE ON '.
            'line_pending_follows FOR EACH ROW EXECUTE FUNCTION v2_line_pending_follow_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_pending_follows_guard_delete BEFORE DELETE ON '.
            'line_pending_follows FOR EACH ROW EXECUTE FUNCTION v2_line_pending_follow_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_pending_follows_guard_truncate BEFORE TRUNCATE ON '.
            'line_pending_follows FOR EACH STATEMENT EXECUTE FUNCTION v2_line_pending_follow_guard()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_webhook_event_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'LINE webhook event deletion is forbidden';
                END IF;
                IF NEW.public_id <> OLD.public_id
                    OR NEW.event_id_hash <> OLD.event_id_hash
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.event_type <> OLD.event_type
                    OR NEW.occurred_at <> OLD.occurred_at
                    OR NEW.created_at <> OLD.created_at
                    OR NOT (
                        OLD.reply_status = 'pending' AND NEW.reply_status = 'processing'
                        OR OLD.reply_status = 'processing'
                            AND NEW.reply_status IN ('sent', 'failed')
                    )
                THEN
                    RAISE EXCEPTION 'LINE webhook reply transition is invalid';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER line_webhook_events_guard_update BEFORE UPDATE ON '.
            'line_webhook_events FOR EACH ROW EXECUTE FUNCTION v2_line_webhook_event_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_webhook_events_guard_delete BEFORE DELETE ON '.
            'line_webhook_events FOR EACH ROW EXECUTE FUNCTION v2_line_webhook_event_guard()'
        );
        DB::statement(
            'CREATE TRIGGER line_webhook_events_guard_truncate BEFORE TRUNCATE ON '.
            'line_webhook_events FOR EACH STATEMENT EXECUTE FUNCTION v2_line_webhook_event_guard()'
        );
    }
};
