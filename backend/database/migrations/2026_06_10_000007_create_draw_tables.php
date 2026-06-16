<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gacha_id')->constrained()->restrictOnDelete();
            $table->integer('draw_count');
            $table->string('idempotency_key');
            $table->string('status')->default('processing');
            $table->integer('consumed_point_total');
            $table->timestamps();

            $table->unique(['user_id', 'gacha_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['gacha_id', 'created_at']);
        });

        Schema::create('draw_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gacha_id')->constrained()->restrictOnDelete();
            $table->integer('draw_sequence_number');
            $table->foreignId('rank_id')->nullable()->constrained('gacha_ranks')->restrictOnDelete();
            $table->foreignId('prize_id')->nullable()->constrained('gacha_prizes')->restrictOnDelete();
            $table->string('result_type');
            $table->integer('consumed_point');
            $table->integer('granted_point')->default(0);
            $table->integer('random_value');
            $table->foreignId('probability_version_id')->constrained('gacha_probability_versions')->restrictOnDelete();
            $table->foreignId('probability_version_stage_id')->constrained('gacha_probability_version_stages')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['gacha_id', 'draw_sequence_number']);
            $table->index(['draw_request_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE draw_requests ADD CONSTRAINT draw_requests_status_check CHECK (status IN ('processing', 'completed', 'failed'))");
        DB::statement('ALTER TABLE draw_requests ADD CONSTRAINT draw_requests_counts_check CHECK (draw_count >= 1 AND consumed_point_total >= 0)');
        DB::statement("ALTER TABLE draw_results ADD CONSTRAINT draw_results_type_check CHECK (result_type IN ('prize', 'point_back'))");
        DB::statement('ALTER TABLE draw_results ADD CONSTRAINT draw_results_amounts_check CHECK (draw_sequence_number >= 1 AND consumed_point >= 0 AND granted_point >= 0 AND random_value >= 0 AND random_value <= 999999)');
        DB::statement("ALTER TABLE draw_results ADD CONSTRAINT draw_results_prize_shape_check CHECK ((result_type = 'prize' AND rank_id IS NOT NULL AND prize_id IS NOT NULL AND granted_point = 0) OR (result_type = 'point_back' AND rank_id IS NULL AND prize_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_results');
        Schema::dropIfExists('draw_requests');
    }
};
