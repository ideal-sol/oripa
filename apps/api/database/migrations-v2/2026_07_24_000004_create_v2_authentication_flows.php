<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_email_verifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('redirect_path', 255)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'used_at', 'revoked_at']);
        });

        Schema::create('admin_invitations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['admin_id', 'used_at', 'revoked_at']);
        });

        Schema::table('admin_sessions', function (Blueprint $table): void {
            $table->boolean('requires_mfa_enrollment')->default(false);
        });

        foreach (['user_email_verifications', 'admin_invitations'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_token_hash_check ".
                "CHECK (token_hash ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_expiry_check ".
                'CHECK (expires_at > created_at)'
            );
        }
        DB::statement(
            'ALTER TABLE user_email_verifications ADD CONSTRAINT user_email_verifications_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '60 minutes')"
        );
        DB::statement(
            'ALTER TABLE admin_invitations ADD CONSTRAINT admin_invitations_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '30 minutes')"
        );
    }

    public function down(): void
    {
        Schema::table('admin_sessions', function (Blueprint $table): void {
            $table->dropColumn('requires_mfa_enrollment');
        });
        Schema::dropIfExists('admin_invitations');
        Schema::dropIfExists('user_email_verifications');
    }
};
