<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE line_quota_accounts ADD COLUMN last_checked_at timestamptz;
            ALTER TABLE line_quota_periods
                ALTER COLUMN verified_by_admin_id DROP NOT NULL,
                ADD COLUMN baseline_origin text NOT NULL DEFAULT 'human',
                ADD COLUMN authentication_revision bigint,
                ADD CONSTRAINT line_quota_period_actor CHECK (
                    (baseline_origin = 'human' AND verified_by_admin_id IS NOT NULL)
                    OR (baseline_origin = 'system' AND verified_by_admin_id IS NULL
                        AND authentication_revision IS NOT NULL AND authentication_revision > 0 AND opening_used = 0)
                );
            SQL);
    }

    public function down(): void { throw new RuntimeException('Forward-only LINE quota actor migration.'); }
};
