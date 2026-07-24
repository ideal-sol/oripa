<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_webauthn_credentials', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('credential_id')->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('label', 100);
            $table->json('transports')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['admin_id', 'revoked_at']);
        });

        Schema::create('admin_totp_methods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('secret_ciphertext');
            $table->string('encryption_key_version', 64);
            $table->bigInteger('last_used_time_step')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['admin_id', 'revoked_at']);
        });

        Schema::create('admin_recovery_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['admin_id', 'used_at', 'revoked_at']);
        });

        DB::statement(
            "ALTER TABLE admin_recovery_codes ADD CONSTRAINT admin_recovery_code_hash_check ".
            "CHECK (code_hash ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'ALTER TABLE admin_totp_methods ADD CONSTRAINT admin_totp_ciphertext_check '.
            "CHECK (length(secret_ciphertext) >= 32 AND secret_ciphertext NOT LIKE 'otpauth://%')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_recovery_codes');
        Schema::dropIfExists('admin_totp_methods');
        Schema::dropIfExists('admin_webauthn_credentials');
    }
};
