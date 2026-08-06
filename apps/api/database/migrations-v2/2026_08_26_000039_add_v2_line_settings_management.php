<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('line_messaging_settings', function (Blueprint $table): void {
            $table->string('friend_add_url', 2048)
                ->nullable()
                ->after('login_relative_path');
        });
    }

    public function down(): void
    {
        Schema::table('line_messaging_settings', function (Blueprint $table): void {
            $table->dropColumn('friend_add_url');
        });
    }
};
