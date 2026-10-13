<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_banner_asset_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->uuid('job_public_id')->index();
            $table->uuid('row_public_id')->unique();
            $table->uuid('asset_public_id')->index();
            $table->uuid('actor_public_id');
            $table->uuid('request_id');
            $table->string('owner', 32)->default('banner_import');
            $table->string('operation_type', 32)->default('create');
            $table->string('storage_disk', 64);
            $table->char('storage_generation', 64);
            $table->string('object_key', 255);
            $table->char('checksum_sha256', 64);
            $table->string('mime_type', 32);
            $table->unsignedInteger('byte_size');
            $table->string('status', 32)->default('prepared');
            $table->unsignedInteger('lease_generation');
            $table->unsignedSmallInteger('put_attempts')->default(0);
            $table->unsignedInteger('reconcile_attempts')->default(0);
            $table->string('last_result', 64)->nullable();
            $table->string('manual_review_reason', 64)->nullable();
            $table->timestampTz('last_reconciled_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX banner_asset_operation_created_key ON content_banner_asset_operations (storage_generation, object_key) WHERE operation_type = 'create'");
        DB::statement("CREATE UNIQUE INDEX banner_asset_operation_created_uuid ON content_banner_asset_operations (asset_public_id) WHERE operation_type = 'create'");
        DB::statement("ALTER TABLE content_banner_asset_operations ADD CONSTRAINT banner_asset_operation_values CHECK (owner::text = 'banner_import' AND operation_type::text IN ('create','reuse') AND byte_size BETWEEN 1 AND 5242880 AND checksum_sha256 ~ '^[a-f0-9]{64}$' AND storage_generation ~ '^[a-f0-9]{64}$' AND mime_type::text IN ('image/gif','image/jpeg','image/png','image/webp') AND status::text IN ('prepared','writing','registered','attached','manual_review') AND lease_generation > 0 AND put_attempts BETWEEN 0 AND 1 AND (operation_type::text = 'create' OR (put_attempts = 0 AND status::text IN ('registered','attached','manual_review'))))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_banner_asset_operation_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
        RAISE EXCEPTION 'Banner asset operations require an approved retention migration.';
    END IF;
    IF ROW(NEW.public_id, NEW.job_public_id, NEW.row_public_id, NEW.asset_public_id, NEW.actor_public_id, NEW.request_id, NEW.owner, NEW.operation_type, NEW.storage_disk, NEW.storage_generation, NEW.object_key, NEW.checksum_sha256, NEW.mime_type, NEW.byte_size)
        IS DISTINCT FROM ROW(OLD.public_id, OLD.job_public_id, OLD.row_public_id, OLD.asset_public_id, OLD.actor_public_id, OLD.request_id, OLD.owner, OLD.operation_type, OLD.storage_disk, OLD.storage_generation, OLD.object_key, OLD.checksum_sha256, OLD.mime_type, OLD.byte_size)
        OR NEW.lease_generation < OLD.lease_generation OR NEW.put_attempts < OLD.put_attempts THEN
        RAISE EXCEPTION 'Banner asset operation identity is immutable.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER banner_asset_operation_identity BEFORE UPDATE OR DELETE ON content_banner_asset_operations FOR EACH ROW EXECUTE FUNCTION guard_banner_asset_operation_identity();
CREATE TRIGGER banner_asset_operation_retention BEFORE TRUNCATE ON content_banner_asset_operations FOR EACH STATEMENT EXECUTE FUNCTION guard_banner_asset_operation_identity();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Banner asset operations require a forward correction migration.');
    }
};
