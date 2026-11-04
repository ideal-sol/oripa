<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_banners', function (Blueprint $table): void {
            $table->string('model_number', 64)->nullable();
        });
        Schema::table('content_banner_import_rows', function (Blueprint $table): void {
            $table->string('model_number', 64)->nullable();
            $table->boolean('model_number_provided')->default(false);
        });
    }

    public function down(): void
    {
        throw new LogicException('Banner model numbers require a forward correction migration.');
    }
};
