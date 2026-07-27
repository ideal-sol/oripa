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
        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->timestampTz('idempotency_expires_at')->nullable()->after('request_hash');
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('consumed_point_total');
        });

        DB::table('draw_requests')
            ->orderBy('id')
            ->eachById(function (object $drawRequest): void {
                DB::table('draw_requests')
                    ->where('id', $drawRequest->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        DB::statement('ALTER TABLE draw_requests ALTER COLUMN public_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX draw_requests_public_id_unique ON draw_requests (public_id)');
        DB::statement(
            'ALTER TABLE draw_requests ADD CONSTRAINT draw_requests_processing_duration_check '.
            'CHECK (processing_duration_ms IS NULL OR processing_duration_ms >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE draw_requests DROP CONSTRAINT IF EXISTS draw_requests_processing_duration_check');
        DB::statement('DROP INDEX IF EXISTS draw_requests_public_id_unique');

        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'public_id',
                'request_hash',
                'idempotency_expires_at',
                'processing_duration_ms',
            ]);
        });
    }
};
