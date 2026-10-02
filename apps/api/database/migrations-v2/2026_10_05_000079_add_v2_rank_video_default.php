<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_presentation_assets', function (Blueprint $table): void {
            $table->boolean('is_default_rank_video')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX catalog_rank_video_default_unique ON catalog_presentation_assets (is_default_rank_video) WHERE is_default_rank_video');
        DB::statement("ALTER TABLE catalog_presentation_assets ADD CONSTRAINT catalog_rank_video_default_valid CHECK (NOT is_default_rank_video OR (media_type = 'video' AND is_public AND archived_at IS NULL))");
    }

    public function down(): void
    {
        throw new LogicException('Rank video defaults require a forward correction migration.');
    }
};
