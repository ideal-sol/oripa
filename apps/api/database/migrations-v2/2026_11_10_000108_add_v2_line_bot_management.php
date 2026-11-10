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
        Schema::create('line_login_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('channel_id', 32)->nullable();
            $table->text('channel_secret_ciphertext')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE line_login_settings ADD CONSTRAINT line_login_setting_values CHECK (id = 1 AND revision >= 1 AND ((channel_id IS NULL AND channel_secret_ciphertext IS NULL) OR (channel_id IS NOT NULL AND channel_id ~ '^[0-9]{1,32}$' AND channel_secret_ciphertext IS NOT NULL)))");
        DB::table('line_login_settings')->insert(['id' => 1, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('line_bots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('channel_id', 32)->nullable()->unique();
            $table->text('channel_secret_ciphertext')->nullable();
            $table->string('basic_id')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('legacy')->default(false);
            $table->string('connection_status', 16)->default('unconfigured');
            $table->timestampTz('connected_at')->nullable();
            $table->jsonb('quota_snapshot')->nullable();
            $table->timestampTz('quota_fetched_at')->nullable();
            $table->jsonb('followers_snapshot')->nullable();
            $table->timestampTz('followers_fetched_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX line_bots_one_active ON line_bots (is_active) WHERE is_active');
        DB::statement('CREATE UNIQUE INDEX line_bots_one_legacy ON line_bots (legacy) WHERE legacy');
        DB::statement("ALTER TABLE line_bots ADD CONSTRAINT line_bots_values CHECK (revision >= 1 AND char_length(name) > 0 AND (channel_id IS NULL OR channel_id ~ '^[0-9]{1,32}$') AND connection_status::text IN ('unconfigured','pending','succeeded','failed') AND (NOT is_active OR (channel_id IS NOT NULL AND channel_secret_ciphertext IS NOT NULL AND connection_status = 'succeeded' AND connected_at IS NOT NULL)))");
        // No provider call, guessed Channel ID or token import in schema migration.
        $legacyId = DB::table('line_bots')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'name' => '既存BOT（接続設定待ち）',
            'legacy' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['line_friendships', 'line_pending_follows', 'line_webhook_events'] as $name) {
            // A constant column default attaches existing rows without UPDATE:
            // terminal pending/event guards and encrypted bytes stay untouched.
            Schema::table($name, function (Blueprint $table) use ($legacyId): void {
                $table->foreignId('line_bot_id')->default($legacyId)->constrained('line_bots')->restrictOnDelete();
            });
        }
        DB::statement('DROP INDEX line_friendship_current_subject_unique');
        DB::statement('ALTER TABLE line_friendships DROP CONSTRAINT line_friendships_user_id_unique');
        DB::statement('CREATE UNIQUE INDEX line_friendship_current_subject_unique ON line_friendships (line_bot_id, subject_hash) WHERE superseded_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX line_friendship_bot_user_unique ON line_friendships (line_bot_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX line_friendship_member_reward_once ON line_friendships (user_id) WHERE point_operation_id IS NOT NULL');
        DB::statement('ALTER TABLE line_pending_follows DROP CONSTRAINT line_pending_follows_subject_hash_unique');
        DB::statement('ALTER TABLE line_pending_follows DROP CONSTRAINT line_pending_follows_claimed_by_user_id_unique');
        DB::statement('CREATE UNIQUE INDEX line_pending_bot_subject_unique ON line_pending_follows (line_bot_id, subject_hash)');
        DB::statement('CREATE UNIQUE INDEX line_pending_bot_claimed_user_unique ON line_pending_follows (line_bot_id, claimed_by_user_id)');
        DB::statement('ALTER TABLE line_webhook_events DROP CONSTRAINT line_webhook_events_event_id_hash_unique');
        DB::statement('CREATE UNIQUE INDEX line_webhook_bot_event_unique ON line_webhook_events (line_bot_id, event_id_hash)');
        Schema::create('line_friend_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('line_bot_id')->constrained('line_bots')->restrictOnDelete();
            $table->foreignId('external_identity_account_id')->constrained('external_identity_accounts')->restrictOnDelete();
            $table->bigInteger('observed_at_ms');
            $table->boolean('friend');
            $table->unique(['line_bot_id', 'external_identity_account_id'], 'line_observation_bot_account_unique');
        });
        DB::statement('ALTER TABLE line_friend_observations ADD CONSTRAINT line_observation_time_check CHECK (observed_at_ms >= 0)');
        DB::statement('INSERT INTO line_friend_observations (line_bot_id, external_identity_account_id, observed_at_ms, friend) SELECT ?, id, line_observed_at_ms, line_observed_friend FROM external_identity_accounts WHERE provider = ? AND line_observed_at_ms IS NOT NULL', [$legacyId, 'line']);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_line_bot_membership_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.line_bot_id IS DISTINCT FROM OLD.line_bot_id THEN
                    RAISE EXCEPTION 'LINE bot membership is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        foreach (['line_friendships', 'line_pending_follows', 'line_webhook_events'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_bot_guard BEFORE UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION v2_line_bot_membership_guard()");
        }
        Schema::table('external_identity_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('line_login_revision')->nullable();
            $table->unsignedBigInteger('line_bot_revision')->nullable();
            $table->foreignId('line_bot_id')->nullable()->constrained('line_bots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only LINE bot management migration');
    }
};
