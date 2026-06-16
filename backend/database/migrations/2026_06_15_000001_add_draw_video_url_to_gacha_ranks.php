<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->text('draw_video_url')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->dropColumn('draw_video_url');
        });
    }
};
