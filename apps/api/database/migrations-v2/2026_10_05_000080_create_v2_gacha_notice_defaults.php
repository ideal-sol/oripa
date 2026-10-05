<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_notice_defaults', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('scope', 16)->unique();
            $table->text('default_notices')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE gacha_notice_defaults ADD CONSTRAINT gacha_notice_defaults_scope_check CHECK (scope::text = ANY (ARRAY['standard'::text, 'login'::text]))");
        DB::statement('ALTER TABLE gacha_notice_defaults ADD CONSTRAINT gacha_notice_defaults_values_check CHECK (revision >= 1 AND (default_notices IS NULL OR char_length(default_notices) <= 10000))');
        foreach (['standard', 'login'] as $scope) {
            DB::table('gacha_notice_defaults')->insert(['public_id' => (string) Str::uuid7(), 'scope' => $scope]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Gacha notice defaults require a forward correction migration.');
    }
};
