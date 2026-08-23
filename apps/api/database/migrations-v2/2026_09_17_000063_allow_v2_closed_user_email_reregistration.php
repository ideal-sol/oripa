<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX users_verified_email_unique');
        DB::statement(
            "CREATE UNIQUE INDEX users_verified_email_unique ".
            "ON users (email_normalized) ".
            "WHERE email_verified_at IS NOT NULL AND state <> 'closed'"
        );
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM users
                    WHERE email_verified_at IS NOT NULL
                    GROUP BY email_normalized
                    HAVING COUNT(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Cannot restore verified email uniqueness while reused closed email records exist';
                END IF;
            END
            $$
            SQL);
        DB::statement('DROP INDEX users_verified_email_unique');
        DB::statement(
            'CREATE UNIQUE INDEX users_verified_email_unique '.
            'ON users (email_normalized) WHERE email_verified_at IS NOT NULL'
        );
    }
};
