<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('email_display', 320);
            $table->string('email_normalized', 320);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password_hash');
            $table->string('state', 32);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state::text = ANY (ARRAY[".
            "'pending_verification'::text, 'active'::text, 'restricted'::text, ".
            "'suspended'::text, 'closed'::text, 'anonymized'::text]))"
        );
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_password_argon2id_check ".
            "CHECK (password_hash LIKE '\$argon2id\$%')"
        );
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_email_normalized_check '.
            'CHECK (email_normalized = lower(email_normalized) '.
            'AND email_normalized = btrim(email_normalized))'
        );
        DB::statement(
            'CREATE UNIQUE INDEX users_verified_email_unique '.
            'ON users (email_normalized) WHERE email_verified_at IS NOT NULL'
        );

        Schema::create('admins', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('email_display', 320);
            $table->string('email_normalized', 320)->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password_hash');
            $table->string('role', 16);
            $table->string('state', 16);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            "ALTER TABLE admins ADD CONSTRAINT admins_role_check ".
            "CHECK (role::text = ANY (ARRAY['owner'::text, 'admin'::text, 'operator'::text]))"
        );
        DB::statement(
            "ALTER TABLE admins ADD CONSTRAINT admins_state_check ".
            "CHECK (state::text = ANY (ARRAY[".
            "'invited'::text, 'active'::text, 'suspended'::text, 'disabled'::text]))"
        );
        DB::statement(
            "ALTER TABLE admins ADD CONSTRAINT admins_password_argon2id_check ".
            "CHECK (password_hash LIKE '\$argon2id\$%')"
        );
        DB::statement(
            'ALTER TABLE admins ADD CONSTRAINT admins_email_normalized_check '.
            'CHECK (email_normalized = lower(email_normalized) '.
            'AND email_normalized = btrim(email_normalized))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('users');
    }
};
