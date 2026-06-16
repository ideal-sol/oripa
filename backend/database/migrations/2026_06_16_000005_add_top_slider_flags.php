<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gachas', function (Blueprint $table): void {
            $table->boolean('show_on_top_slider')->default(false)->after('main_image_url');
        });

        Schema::table('announcements', function (Blueprint $table): void {
            $table->boolean('show_on_top_slider')->default(false)->after('thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('show_on_top_slider');
        });

        Schema::table('gachas', function (Blueprint $table): void {
            $table->dropColumn('show_on_top_slider');
        });
    }
};
