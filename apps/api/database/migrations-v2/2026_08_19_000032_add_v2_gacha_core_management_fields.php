<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->unsignedInteger('daily_draw_limit')->default(0)->after('total_count');
            $table->string('audience_code', 32)
                ->default('all_users')->after('daily_draw_limit');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gacha_versions
            ADD CONSTRAINT catalog_gacha_versions_core_management_check
            CHECK (
                daily_draw_limit >= 0
                AND audience_code::text = ANY (
                    ARRAY[
                        'all_users'::text,
                        'first_time_users'::text,
                        'line_users'::text
                    ]
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'DROP CONSTRAINT IF EXISTS catalog_gacha_versions_core_management_check'
        );
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->dropColumn(['daily_draw_limit', 'audience_code']);
        });
    }
};
