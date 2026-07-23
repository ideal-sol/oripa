<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->text('result_image_url')->nullable()->after('draw_video_url');
        });
    }

    public function down(): void
    {
        Schema::table('gacha_ranks', function (Blueprint $table): void {
            $table->dropColumn('result_image_url');
        });
    }
};
