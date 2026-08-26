<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_state_check');
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state::text = ANY (ARRAY[".
            "'pending_verification'::text, 'verification_failed'::text, 'active'::text, ".
            "'restricted'::text, 'suspended'::text, 'closed'::text, 'anonymized'::text]))"
        );
    }

    public function down(): void
    {
        if (DB::table('users')->where('state', 'verification_failed')->exists()) {
            throw new \RuntimeException(
                'Cannot remove verification_failed while User history uses the state.'
            );
        }
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_state_check');
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state::text = ANY (ARRAY[".
            "'pending_verification'::text, 'active'::text, 'restricted'::text, ".
            "'suspended'::text, 'closed'::text, 'anonymized'::text]))"
        );
    }
};
