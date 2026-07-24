<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->char('session_id_hash', 64)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->char('session_id_hash', 64)->primary();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['admin_id', 'revoked_at']);
        });

        Schema::create('user_remember_devices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('selector', 32)->unique();
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('rotation_counter')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('replay_detected_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['user_id', 'revoked_at']);
        });

        foreach (['user_sessions', 'admin_sessions'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_hash_check ".
                "CHECK (session_id_hash ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_expiry_check ".
                'CHECK (last_activity_at >= created_at AND idle_expires_at > last_activity_at '.
                'AND absolute_expires_at > created_at AND idle_expires_at <= absolute_expires_at)'
            );
        }
        DB::statement(
            'ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_duration_check '.
            "CHECK (idle_expires_at <= last_activity_at + INTERVAL '60 minutes' ".
            "AND absolute_expires_at <= created_at + INTERVAL '24 hours')"
        );
        DB::statement(
            'ALTER TABLE admin_sessions ADD CONSTRAINT admin_sessions_duration_check '.
            "CHECK (idle_expires_at <= last_activity_at + INTERVAL '15 minutes' ".
            "AND absolute_expires_at <= created_at + INTERVAL '8 hours')"
        );

        DB::statement(
            "ALTER TABLE user_remember_devices ADD CONSTRAINT user_remember_token_hash_check ".
            "CHECK (token_hash ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'ALTER TABLE user_remember_devices ADD CONSTRAINT user_remember_max_age_check '.
            "CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL '30 days')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_remember_devices');
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('user_sessions');
    }
};
