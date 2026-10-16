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
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('pre_registered_at')->nullable()->index();
        });
        Schema::create('pre_registration_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->uuid('public_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->timestampTz('cutoff_at')->nullable();
            $table->unsignedInteger('point_amount')->default(500);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedInteger('snapshot_amount')->nullable();
            $table->unsignedBigInteger('snapshot_revision')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
        Schema::create('pre_registration_targets', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'user_id']);
        });
        Schema::create('pre_registration_grants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('phone_hmac', 64)->unique();
            $table->foreignId('point_operation_id')->unique()->constrained('point_operations')->restrictOnDelete();
            $table->unsignedInteger('point_amount');
            $table->unsignedBigInteger('setting_revision');
            $table->timestampTz('activated_at');
            $table->timestampTz('expires_at');
        });
        Schema::create('pre_registration_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->unsignedBigInteger('expected_revision');
            $table->jsonb('user_ids');
            $table->jsonb('response')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->unique(['admin_id', 'key_hash']);
        });
        DB::statement("ALTER TABLE pre_registration_settings ADD CONSTRAINT pre_registration_setting_values CHECK (id = 1 AND revision > 0 AND point_amount BETWEEN 1 AND 1000000 AND (NOT is_enabled OR cutoff_at IS NOT NULL) AND ((started_at IS NULL AND snapshot_amount IS NULL AND snapshot_revision IS NULL AND completed_at IS NULL) OR (started_at IS NOT NULL AND cutoff_at IS NOT NULL AND started_at >= cutoff_at AND snapshot_amount BETWEEN 1 AND 1000000 AND snapshot_revision > 0)))");
        DB::statement("ALTER TABLE pre_registration_targets ADD CONSTRAINT pre_registration_target_status CHECK (status::text = ANY (ARRAY['pending'::text, 'activated'::text, 'failed'::text, 'excluded'::text]) AND attempts >= 0)");
        DB::statement("ALTER TABLE pre_registration_grants ADD CONSTRAINT pre_registration_grant_values CHECK (point_amount >= 1 AND point_amount <= 1000000 AND setting_revision > 0 AND phone_hmac ~ '^[0-9a-f]{64}$' AND expires_at = activated_at + INTERVAL '180 days')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_pre_registration_user_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.pre_registered_at IS NOT NULL AND NEW.pre_registered_at IS DISTINCT FROM NEW.created_at THEN
                        RAISE EXCEPTION 'pre_registration timestamp must equal registration timestamp';
                    END IF;
                ELSE
                    IF NEW.pre_registered_at IS DISTINCT FROM OLD.pre_registered_at THEN
                        RAISE EXCEPTION 'pre_registration timestamp is immutable';
                    END IF;
                    IF NEW.created_at IS DISTINCT FROM OLD.created_at AND EXISTS (SELECT 1 FROM pre_registration_grants WHERE user_id = OLD.id) THEN
                        RAISE EXCEPTION 'activated registration timestamp is immutable';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_pre_registration_user_guard BEFORE INSERT OR UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION v2_pre_registration_user_guard();
            CREATE OR REPLACE FUNCTION v2_pre_registration_grant_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pre_registration grants are immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_pre_registration_grant_guard BEFORE UPDATE OR DELETE ON pre_registration_grants
                FOR EACH ROW EXECUTE FUNCTION v2_pre_registration_grant_guard();
            CREATE OR REPLACE FUNCTION v2_pre_registration_snapshot_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.started_at IS NOT NULL AND (
                    NEW.snapshot_amount IS DISTINCT FROM OLD.snapshot_amount OR
                    NEW.snapshot_revision IS DISTINCT FROM OLD.snapshot_revision OR
                    NEW.started_at IS DISTINCT FROM OLD.started_at OR
                    NEW.cutoff_at IS DISTINCT FROM OLD.cutoff_at OR
                    (OLD.completed_at IS NULL AND NEW.point_amount IS DISTINCT FROM OLD.point_amount)
                ) THEN
                    RAISE EXCEPTION 'pre_registration campaign snapshot is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_pre_registration_snapshot_guard BEFORE UPDATE ON pre_registration_settings
                FOR EACH ROW EXECUTE FUNCTION v2_pre_registration_snapshot_guard();
            SQL);
        DB::table('pre_registration_settings')->insert(['id' => 1, 'public_id' => (string) Str::uuid7()]);
    }

    public function down(): void
    {
        throw new LogicException('Pre-registration history requires a forward-only migration.');
    }
};
