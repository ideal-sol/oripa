<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_rank_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gacha_rank_id')->constrained('gacha_ranks')->cascadeOnDelete();
            $table->foreignId('rank_asset_id')->constrained('rank_assets')->cascadeOnDelete();
            $table->string('usage_type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['gacha_rank_id', 'rank_asset_id', 'usage_type'], 'gacha_rank_assets_unique_usage');
            $table->index(['gacha_rank_id', 'usage_type']);
        });

        DB::statement("ALTER TABLE gacha_rank_assets ADD CONSTRAINT gacha_rank_assets_usage_type_check CHECK (usage_type IN ('image', 'video'))");

        DB::statement(<<<'SQL'
            INSERT INTO gacha_rank_assets (gacha_rank_id, rank_asset_id, usage_type, sort_order, created_at, updated_at)
            SELECT id, rank_image_asset_id, 'image', 0, NOW(), NOW()
            FROM gacha_ranks
            WHERE rank_image_asset_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO gacha_rank_assets (gacha_rank_id, rank_asset_id, usage_type, sort_order, created_at, updated_at)
            SELECT id, draw_video_asset_id, 'video', 0, NOW(), NOW()
            FROM gacha_ranks
            WHERE draw_video_asset_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        Schema::table('draw_results', function (Blueprint $table): void {
            $table->text('selected_rank_image_url')->nullable()->after('probability_version_stage_id');
            $table->text('selected_draw_video_url')->nullable()->after('selected_rank_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropColumn(['selected_rank_image_url', 'selected_draw_video_url']);
        });

        Schema::dropIfExists('gacha_rank_assets');
    }
};
