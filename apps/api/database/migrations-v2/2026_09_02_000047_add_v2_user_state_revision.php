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
            $table->unsignedBigInteger('state_revision')->default(1);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_state_revision_check CHECK (state_revision >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_state_revision_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('state_revision');
        });
    }
};
