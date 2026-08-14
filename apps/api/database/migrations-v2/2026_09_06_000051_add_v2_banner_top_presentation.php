<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->boolean('show_on_top')->default(false)->after('is_important');
            $table->index(
                ['show_on_top', 'status', 'banner_id'],
                'content_versions_banner_top_public_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content_versions
            ADD CONSTRAINT content_versions_banner_top_only_check
            CHECK (banner_id IS NOT NULL OR show_on_top = false)
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE content_versions '.
            'DROP CONSTRAINT IF EXISTS content_versions_banner_top_only_check'
        );
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->dropIndex('content_versions_banner_top_public_index');
            $table->dropColumn('show_on_top');
        });
    }
};
