<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_sync_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('status', 16);
            $table->string('trigger', 16);
            $table->char('lease_hash', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('lease_expires_at')->nullable();
            foreach (['read', 'updated', 'unchanged', 'duplicate', 'invalid', 'missing', 'failed'] as $counter) {
                $table->unsignedInteger($counter.'_count')->default(0);
            }
            $table->unsignedInteger('unlinked_prize_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX notion_one_active_run ON notion_sync_runs ((true)) WHERE status IN ('queued', 'processing')");
        DB::statement("ALTER TABLE notion_sync_runs ADD CONSTRAINT notion_run_state CHECK (status IN ('queued','processing','completed','failed') AND trigger IN ('manual','periodic','business') AND (status <> 'processing' OR (lease_hash IS NOT NULL AND lease_expires_at IS NOT NULL)))");
        Schema::create('notion_sync_snapshot', function (Blueprint $table): void {
            $table->foreignId('run_id')->constrained('notion_sync_runs');
            $table->string('external_id', 64)->collation('C');
            $table->bigInteger('required_quantity');
            $table->bigInteger('allocation');
            $table->bigInteger('stored');
            $table->bigInteger('shipped');
            $table->primary(['run_id', 'external_id']);
        });
        Schema::create('notion_sync_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('run_id')->constrained('notion_sync_runs');
            $table->uuid('page_id');
            $table->string('external_id', 64)->nullable()->collation('C');
            $table->jsonb('values');
            $table->double('on_hand')->nullable();
            $table->double('available')->nullable();
            $table->timestampTz('read_at');
            $table->string('status', 16)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->unique(['run_id', 'page_id']);
            $table->index(['run_id', 'external_id']);
        });
        Schema::create('notion_inventory_cache', function (Blueprint $table): void {
            $table->string('external_id', 64)->collation('C')->primary();
            $table->uuid('page_id');
            $table->double('on_hand')->nullable();
            $table->double('available')->nullable();
            $table->timestampTz('read_at');
            $table->foreignId('run_id')->constrained('notion_sync_runs');
            $table->index('available');
        });
        Schema::create('notion_sync_state', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->timestampTz('next_request_at', 6)->nullable();
        });
        DB::table('notion_sync_state')->insert(['id' => 1]);
    }

    public function down(): void
    {
        throw new LogicException('Notion sync history requires a forward correction migration.');
    }
};
