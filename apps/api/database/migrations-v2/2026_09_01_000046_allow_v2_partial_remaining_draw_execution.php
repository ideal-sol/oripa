<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE draw_requests DROP CONSTRAINT IF EXISTS draw_request_values_check'
        );
        DB::statement($this->constraint(false));
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE draw_requests DROP CONSTRAINT IF EXISTS draw_request_values_check'
        );
        DB::statement($this->constraint(true));
    }

    private function constraint(bool $requireFullExecution): string
    {
        $completedCountCheck = $requireFullExecution
            ? 'executed_count = requested_count'
            : 'executed_count > 0 AND executed_count <= requested_count';

        return "ALTER TABLE draw_requests ADD CONSTRAINT draw_request_values_check CHECK (".
            "requested_count = ANY (ARRAY[1,5,10,100,1000]) AND ".
            'executed_count <= requested_count AND point_cost_total > 0 AND '.
            'consumed_paid_points >= 0 AND consumed_free_points >= 0 AND '.
            'point_back_total >= 0 AND request_hash ~ \'^[0-9a-f]{64}$\' AND '.
            "catalog_snapshot_sha256 ~ '^[0-9a-f]{64}$' AND ".
            "status::text = ANY (ARRAY['processing'::text,'completed'::text]) AND ".
            "((status = 'completed' AND {$completedCountCheck} AND ".
            'wallet_paid_after IS NOT NULL AND wallet_free_after IS NOT NULL AND '.
            'processing_duration_ms IS NOT NULL AND response_data IS NOT NULL AND '.
            'completed_at IS NOT NULL) OR '.
            "(status = 'processing' AND executed_count = 0 AND completed_at IS NULL)))";
    }
};
