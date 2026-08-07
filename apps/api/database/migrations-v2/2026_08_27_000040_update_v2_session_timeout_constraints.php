<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceDurationConstraint('user_sessions', '12 hours', '24 hours');
        $this->replaceDurationConstraint('admin_sessions', '6 hours', '12 hours');
    }

    public function down(): void
    {
        DB::statement(
            "DELETE FROM admin_sessions WHERE last_activity_at >= created_at + INTERVAL '8 hours'"
        );
        DB::statement(
            "UPDATE admin_sessions SET ".
            "absolute_expires_at = LEAST(absolute_expires_at, created_at + INTERVAL '8 hours'), ".
            "idle_expires_at = LEAST(idle_expires_at, last_activity_at + INTERVAL '15 minutes', created_at + INTERVAL '8 hours')"
        );
        DB::statement(
            "UPDATE user_sessions SET idle_expires_at = LEAST(idle_expires_at, last_activity_at + INTERVAL '60 minutes', absolute_expires_at)"
        );

        $this->replaceDurationConstraint('user_sessions', '60 minutes', '24 hours');
        $this->replaceDurationConstraint('admin_sessions', '15 minutes', '8 hours');
    }

    private function replaceDurationConstraint(
        string $table,
        string $idleDuration,
        string $absoluteDuration
    ): void {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_duration_check");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_duration_check ".
            "CHECK (idle_expires_at <= last_activity_at + INTERVAL '{$idleDuration}' ".
            "AND absolute_expires_at <= created_at + INTERVAL '{$absoluteDuration}')"
        );
    }
};
