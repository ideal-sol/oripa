<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('full_name', 255)->nullable();
            $table->date('birth_date')->nullable();
            $table->timestampTz('first_sms_verified_at')->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_v2_first_sms_verified_at() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.first_sms_verified_at IS NOT NULL
        AND NEW.first_sms_verified_at IS DISTINCT FROM OLD.first_sms_verified_at THEN
        RAISE EXCEPTION 'first_sms_verified_at is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER users_first_sms_verified_at_immutable
BEFORE UPDATE OF first_sms_verified_at ON users
FOR EACH ROW EXECUTE FUNCTION protect_v2_first_sms_verified_at();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Signup SMS qualification requires a forward correction migration.');
    }
};
