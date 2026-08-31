<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE catalog_rank_masters DROP CONSTRAINT catalog_rank_masters_status_check'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_rank_masters
            ADD CONSTRAINT catalog_rank_masters_status_check CHECK (
                status::text = ANY (ARRAY['active'::text, 'inactive'::text])
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE catalog_rank_masters DROP CONSTRAINT catalog_rank_masters_status_check'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_rank_masters
            ADD CONSTRAINT catalog_rank_masters_status_check CHECK (
                status IN ('active', 'inactive')
            )
            SQL);
    }
};
