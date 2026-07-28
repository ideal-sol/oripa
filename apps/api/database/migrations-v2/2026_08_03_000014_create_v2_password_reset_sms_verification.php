<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table): void {
            $table->timestampTz('reauthenticated_at')->nullable();
        });
        DB::statement('UPDATE user_sessions SET reauthenticated_at = created_at');
        DB::statement('ALTER TABLE user_sessions ALTER COLUMN reauthenticated_at SET NOT NULL');
        DB::statement(
            'ALTER TABLE user_sessions ALTER COLUMN reauthenticated_at SET DEFAULT CURRENT_TIMESTAMP'
        );
        DB::statement(
            'ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_reauthenticated_at_check '.
            'CHECK (reauthenticated_at >= created_at AND reauthenticated_at <= absolute_expires_at)'
        );

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('redirect_path', 255);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'used_at', 'revoked_at']);
        });

        Schema::create('user_phone_numbers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('phone_ciphertext');
            $table->char('phone_hmac', 64);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('sms_verification_challenges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('phone_ciphertext');
            $table->char('phone_hmac', 64);
            $table->char('code_hash', 64);
            $table->string('purpose', 32);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['user_id', 'used_at', 'revoked_at']);
            $table->index(['phone_hmac', 'used_at', 'revoked_at']);
        });

        foreach (['password_reset_tokens', 'sms_verification_challenges'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_secret_hash_check ".
                "CHECK (".($table === 'password_reset_tokens' ? 'token_hash' : 'code_hash').
                " ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_attempts_check ".
                'CHECK (failed_attempts BETWEEN 0 AND 5)'
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_expiry_check ".
                'CHECK (expires_at > created_at)'
            );
        }
        DB::statement(
            'ALTER TABLE password_reset_tokens ADD CONSTRAINT password_reset_tokens_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '30 minutes')"
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges ADD CONSTRAINT sms_verification_challenges_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '5 minutes')"
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges ADD CONSTRAINT sms_verification_challenges_purpose_check '.
            "CHECK (purpose::text = ANY (ARRAY['registration'::text, 'phone_change'::text]))"
        );
        DB::statement(
            "ALTER TABLE user_phone_numbers ADD CONSTRAINT user_phone_numbers_hmac_check ".
            "CHECK (phone_hmac ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            "ALTER TABLE sms_verification_challenges ADD CONSTRAINT sms_verification_challenges_hmac_check ".
            "CHECK (phone_hmac ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX user_phone_numbers_verified_unique '.
            'ON user_phone_numbers (phone_hmac) '.
            'WHERE verified_at IS NOT NULL AND revoked_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX sms_verification_challenges_active_user_unique '.
            'ON sms_verification_challenges (user_id) '.
            'WHERE used_at IS NULL AND revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_verification_challenges');
        Schema::dropIfExists('user_phone_numbers');
        Schema::dropIfExists('password_reset_tokens');
        Schema::table('user_sessions', function (Blueprint $table): void {
            $table->dropColumn('reauthenticated_at');
        });
    }
};
