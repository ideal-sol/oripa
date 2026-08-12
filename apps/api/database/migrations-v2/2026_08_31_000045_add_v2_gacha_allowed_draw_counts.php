<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gacha_versions
            ADD COLUMN allowed_draw_counts jsonb NOT NULL
            DEFAULT '[1,5,10]'::jsonb
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gacha_versions
            ADD CONSTRAINT catalog_gacha_versions_allowed_draw_counts_check
            CHECK (allowed_draw_counts IN (
                '[1]'::jsonb,
                '[1,5]'::jsonb,
                '[1,10]'::jsonb,
                '[1,100]'::jsonb,
                '[1,1000]'::jsonb,
                '[1,5,10]'::jsonb,
                '[1,5,100]'::jsonb,
                '[1,5,1000]'::jsonb,
                '[1,10,100]'::jsonb,
                '[1,10,1000]'::jsonb,
                '[1,100,1000]'::jsonb,
                '[1,5,10,100]'::jsonb,
                '[1,5,10,1000]'::jsonb,
                '[1,5,100,1000]'::jsonb,
                '[1,10,100,1000]'::jsonb,
                '[1,5,10,100,1000]'::jsonb
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE catalog_gacha_versions DROP CONSTRAINT IF EXISTS catalog_gacha_versions_allowed_draw_counts_check'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions DROP COLUMN allowed_draw_counts'
        );
    }
};
