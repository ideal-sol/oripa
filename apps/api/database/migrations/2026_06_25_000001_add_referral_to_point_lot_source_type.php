<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE point_lots DROP CONSTRAINT point_lots_source_type_check');
        DB::statement("ALTER TABLE point_lots ADD CONSTRAINT point_lots_source_type_check CHECK (source_type IN ('purchase', 'campaign', 'minimum_guarantee', 'compensation', 'exchange', 'referral'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE point_lots DROP CONSTRAINT point_lots_source_type_check');
        DB::statement("ALTER TABLE point_lots ADD CONSTRAINT point_lots_source_type_check CHECK (source_type IN ('purchase', 'campaign', 'minimum_guarantee', 'compensation', 'exchange'))");
    }
};
