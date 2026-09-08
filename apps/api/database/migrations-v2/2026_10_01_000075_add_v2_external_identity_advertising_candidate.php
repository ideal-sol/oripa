<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('external_identity_transactions', function (Blueprint $table): void {
            $table->string('advertising_code_candidate', 8)->collation('C')->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE external_identity_transactions ADD CONSTRAINT external_identity_advertising_candidate_check
            CHECK (advertising_code_candidate IS NULL OR
                (purpose = 'login' AND advertising_code_candidate ~ '^[A-Za-z0-9]{8}$'))
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_external_identity_guard_advertising_candidate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.advertising_code_candidate IS DISTINCT FROM OLD.advertising_code_candidate THEN
                    RAISE EXCEPTION 'External identity advertising candidate is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER external_identity_advertising_candidate_immutable
            BEFORE UPDATE ON external_identity_transactions FOR EACH ROW
            EXECUTE FUNCTION v2_external_identity_guard_advertising_candidate();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('external_identity_transactions')->whereNotNull('advertising_code_candidate')->exists()) {
            throw new RuntimeException('External identity advertising history requires a forward migration.');
        }
        DB::statement('DROP TRIGGER external_identity_advertising_candidate_immutable ON external_identity_transactions');
        DB::statement('DROP FUNCTION v2_external_identity_guard_advertising_candidate()');
        Schema::table('external_identity_transactions', function (Blueprint $table): void {
            $table->dropColumn('advertising_code_candidate');
        });
    }
};
