<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gachas', function (Blueprint $table): void {
            $table->unsignedInteger('daily_draw_limit')->nullable()->after('total_count');
        });

        DB::statement('ALTER TABLE gachas ADD CONSTRAINT gachas_daily_draw_limit_positive CHECK (daily_draw_limit IS NULL OR daily_draw_limit >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gachas DROP CONSTRAINT IF EXISTS gachas_daily_draw_limit_positive');

        Schema::table('gachas', function (Blueprint $table): void {
            $table->dropColumn('daily_draw_limit');
        });
    }
};
