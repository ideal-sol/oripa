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
        Schema::create('admin_authentication_policy', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->boolean('mfa_required')->default(false);
            $table->boolean('invitation_required')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->uuid('last_mutation_request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE admin_authentication_policy ADD CONSTRAINT '.
            'admin_authentication_policy_singleton_check CHECK (id = 1)'
        );
        DB::statement(
            'ALTER TABLE admin_authentication_policy ADD CONSTRAINT '.
            'admin_authentication_policy_revision_check CHECK (revision >= 1)'
        );
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_admin_authentication_policy()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'admin authentication policy cannot be deleted';
                END IF;
                IF NEW.id <> OLD.id
                    OR NEW.public_id <> OLD.public_id
                    OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION 'admin authentication policy identity is immutable';
                END IF;
                IF NEW.mfa_required = OLD.mfa_required
                    AND NEW.invitation_required = OLD.invitation_required THEN
                    RAISE EXCEPTION 'admin authentication policy update must change policy';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'admin authentication policy revision must advance by one';
                END IF;
                IF NEW.updated_by_admin_id IS NULL
                    OR NEW.last_mutation_request_id IS NULL THEN
                    RAISE EXCEPTION 'admin authentication policy mutation metadata is required';
                END IF;
                RETURN NEW;
            END;
            $function$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER admin_authentication_policy_guard
            BEFORE UPDATE OR DELETE ON admin_authentication_policy
            FOR EACH ROW EXECUTE FUNCTION guard_admin_authentication_policy()
        SQL);

        DB::table('admin_authentication_policy')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid7(),
            'mfa_required' => false,
            'invitation_required' => false,
            'revision' => 1,
            'created_at' => now()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_authentication_policy');
        DB::statement('DROP FUNCTION IF EXISTS guard_admin_authentication_policy()');
    }
};
