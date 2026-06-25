<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('normalized_phone_number', 32)->nullable()->after('phone_number');
        });

        DB::table('user_profiles')
            ->whereNotNull('phone_number')
            ->orderBy('id')
            ->select(['id', 'phone_number'])
            ->chunk(100, function ($profiles): void {
                foreach ($profiles as $profile) {
                    DB::table('user_profiles')
                        ->where('id', $profile->id)
                        ->update([
                            'normalized_phone_number' => preg_replace('/[^\d+]/', '', trim((string) $profile->phone_number)) ?: null,
                        ]);
                }
            });

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->index('normalized_phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropIndex(['normalized_phone_number']);
            $table->dropColumn('normalized_phone_number');
        });
    }
};
