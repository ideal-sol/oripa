<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_banner_import_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('requested_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->foreignId('idempotency_record_id')->unique()->constrained('idempotency_records')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('file_sha256', 64);
            $table->boolean('refresh_existing_images')->default(false);
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('total');
            $table->char('lease_token_hash', 64)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('lease_generation')->default(0);
            $table->timestampTz('secret_expires_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'lease_expires_at']);
        });
        Schema::create('content_banner_import_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('job_id')->constrained('content_banner_import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('external_id', 64)->collation('C');
            $table->string('title', 191);
            $table->foreignId('category_id')->constrained('content_banner_categories')->restrictOnDelete();
            $table->string('category_name', 100);
            $table->boolean('fetch_image');
            $table->text('url_ciphertext')->nullable();
            $table->timestampTz('url_deleted_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('result', 16)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->uuid('asset_public_id')->nullable();
            $table->boolean('asset_created')->default(false);
            $table->uuid('banner_public_id')->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->string('compensation_status', 32)->nullable();
            $table->timestampsTz();
            $table->unique(['job_id', 'row_number']);
            $table->unique(['job_id', 'external_id']);
        });
        DB::statement("ALTER TABLE content_banner_import_jobs ADD CONSTRAINT banner_import_job_values CHECK (status IN ('queued','processing','completed','failed') AND total BETWEEN 1 AND 1000 AND ((status = 'processing' AND lease_token_hash IS NOT NULL AND lease_expires_at IS NOT NULL) OR (status <> 'processing' AND lease_token_hash IS NULL AND lease_expires_at IS NULL)))");
        DB::statement("ALTER TABLE content_banner_import_rows ADD CONSTRAINT banner_import_row_values CHECK (row_number >= 2 AND external_id ~ '^[A-Za-z0-9._-]{1,64}$' AND status IN ('pending','processing','asset_registered','completed','failed') AND (result IS NULL OR result IN ('created','updated','unchanged','failed')) AND (status NOT IN ('completed','failed') OR (url_ciphertext IS NULL AND result IS NOT NULL)))");
    }

    public function down(): void
    {
        throw new LogicException('Banner import history requires a forward correction migration.');
    }
};
