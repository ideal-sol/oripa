<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE external_identity_accounts '.
            'DROP CONSTRAINT external_identity_accounts_provider_check'
        );
        DB::statement(
            'ALTER TABLE external_identity_accounts '.
            'DROP CONSTRAINT external_identity_accounts_issuer_check'
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_provider_check ".
            "CHECK (provider = 'google' OR provider = 'line')"
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_issuer_check CHECK (".
            "(provider = 'google' AND issuer = 'https://accounts.google.com') OR ".
            "(provider = 'line' AND issuer = 'https://access.line.me'))"
        );

        DB::statement(
            'ALTER TABLE external_identity_transactions '.
            'DROP CONSTRAINT external_identity_transactions_provider_check'
        );
        DB::statement(
            "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
            "external_identity_transactions_provider_check ".
            "CHECK (provider = 'google' OR provider = 'line')"
        );
    }

    public function down(): void
    {
        $lineAccounts = DB::table('external_identity_accounts')
            ->where('provider', 'line')
            ->exists();
        $lineTransactions = DB::table('external_identity_transactions')
            ->where('provider', 'line')
            ->exists();
        if ($lineAccounts || $lineTransactions) {
            throw new \RuntimeException(
                'LINE external identity records must be retained; rollback refused.'
            );
        }

        DB::statement(
            'ALTER TABLE external_identity_accounts '.
            'DROP CONSTRAINT external_identity_accounts_provider_check'
        );
        DB::statement(
            'ALTER TABLE external_identity_accounts '.
            'DROP CONSTRAINT external_identity_accounts_issuer_check'
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_provider_check CHECK (provider = 'google')"
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_issuer_check ".
            "CHECK (issuer = 'https://accounts.google.com')"
        );

        DB::statement(
            'ALTER TABLE external_identity_transactions '.
            'DROP CONSTRAINT external_identity_transactions_provider_check'
        );
        DB::statement(
            "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
            "external_identity_transactions_provider_check CHECK (provider = 'google')"
        );
    }
};
