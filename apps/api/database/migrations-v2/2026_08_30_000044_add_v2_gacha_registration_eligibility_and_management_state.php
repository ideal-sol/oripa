<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('catalog_gacha_publish_schedules')
            ->whereIn('status', ['scheduled', 'processing'])->exists()) {
            throw new \RuntimeException(
                'Active legacy Gacha Publish Schedules must be cancelled and recreated before MIG-062H.'
            );
        }

        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->unsignedInteger('first_time_eligible_days')
                ->default(7)->after('audience_code');
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->string('management_status', 24)
                ->default('draft')->after('state');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gacha_versions
            ADD CONSTRAINT catalog_gacha_versions_first_time_days_check
            CHECK (first_time_eligible_days >= 1)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gachas
            ADD CONSTRAINT catalog_gachas_management_status_check
            CHECK (management_status::text = ANY (ARRAY[
                'draft'::text,
                'scheduled'::text,
                'published'::text,
                'sales_paused'::text,
                'unpublished'::text
            ]))
            SQL);

        DB::statement(<<<'SQL'
            UPDATE catalog_gachas AS g
            SET management_status = CASE
                    WHEN g.public_deactivated_at IS NOT NULL THEN 'unpublished'
                    WHEN g.sales_paused THEN 'sales_paused'
                    WHEN g.published_version_id IS NOT NULL THEN 'published'
                    ELSE 'draft'
                END,
                revision = g.revision + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE g.archived_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE catalog_gachas DROP CONSTRAINT IF EXISTS catalog_gachas_management_status_check'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions DROP CONSTRAINT IF EXISTS catalog_gacha_versions_first_time_days_check'
        );
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropColumn('management_status');
        });
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->dropColumn('first_time_eligible_days');
        });
    }
};
