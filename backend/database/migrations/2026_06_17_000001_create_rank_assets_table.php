<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('asset_type');
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['asset_type', 'is_active']);
        });

        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->foreignId('rank_image_asset_id')->nullable()->after('image_url')->constrained('rank_assets')->nullOnDelete();
            $table->foreignId('draw_video_asset_id')->nullable()->after('draw_video_url')->constrained('rank_assets')->nullOnDelete();
        });

        DB::statement("ALTER TABLE rank_assets ADD CONSTRAINT rank_assets_asset_type_check CHECK (asset_type IN ('image', 'video'))");
    }

    public function down(): void
    {
        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draw_video_asset_id');
            $table->dropConstrainedForeignId('rank_image_asset_id');
        });

        Schema::dropIfExists('rank_assets');
    }
};
