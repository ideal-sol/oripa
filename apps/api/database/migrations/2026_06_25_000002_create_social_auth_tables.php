<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('last_login_at')->nullable();
            $table->json('raw_profile')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
            $table->index('email');
        });

        Schema::create('social_login_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('raw_profile')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_user_id', 'status']);
            $table->index(['email', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_provider_check CHECK (provider IN ('google', 'apple'))");
        DB::statement("ALTER TABLE social_login_sessions ADD CONSTRAINT social_login_sessions_provider_check CHECK (provider IN ('google', 'apple'))");
        DB::statement("ALTER TABLE social_login_sessions ADD CONSTRAINT social_login_sessions_status_check CHECK (status IN ('pending', 'completed', 'expired', 'canceled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_login_sessions');
        Schema::dropIfExists('social_accounts');
    }
};
