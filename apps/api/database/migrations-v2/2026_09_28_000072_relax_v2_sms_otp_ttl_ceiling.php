<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE sms_verification_challenges '.
            'DROP CONSTRAINT sms_verification_challenges_ttl_check'
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges '.
            'ADD CONSTRAINT sms_verification_challenges_ttl_check CHECK ('.
            'expires_at > created_at AND '.
            "expires_at <= created_at + INTERVAL '24 hours')"
        );
    }

    public function down(): void
    {
        DB::statement(
            "UPDATE sms_verification_challenges SET expires_at = LEAST(".
            "expires_at, created_at + INTERVAL '5 minutes')"
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges '.
            'DROP CONSTRAINT sms_verification_challenges_ttl_check'
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges '.
            'ADD CONSTRAINT sms_verification_challenges_ttl_check CHECK ('.
            "expires_at <= created_at + INTERVAL '5 minutes')"
        );
    }
};
