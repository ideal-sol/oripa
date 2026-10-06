<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_banners', function (Blueprint $table): void {
            $table->string('external_id', 64)->collation('C')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX content_banners_external_id_active_unique ON content_banners (external_id) WHERE status <> 'archived'");
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->string('external_id', 64)->collation('C')->nullable()->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('External IDs require a forward correction migration.');
    }
};
