<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE line_bots ADD COLUMN authentication_revision bigint NOT NULL DEFAULT 1');
        // Preserve valid 108-era OAuth snapshots without rewriting immutable transactions.
        DB::statement('UPDATE line_bots SET authentication_revision = revision');
        DB::statement('ALTER TABLE line_bots ADD CONSTRAINT line_bots_authentication_revision_check CHECK (authentication_revision >= 1 AND authentication_revision <= revision)');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only LINE bot authentication revision migration');
    }
};
