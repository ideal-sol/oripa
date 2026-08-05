<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_banner_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->timestampsTz();
        });

        Schema::table('content_versions', function (Blueprint $table): void {
            $table->foreignId('banner_category_id')->nullable()
                ->after('banner_id')
                ->constrained('content_banner_categories')->restrictOnDelete();
            $table->index(['banner_category_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_content_guard_banner_category_relation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.banner_id IS NULL AND NEW.banner_category_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Only Banner Versions may reference Banner Categories';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER content_versions_banner_category_guard '.
            'BEFORE INSERT OR UPDATE OF banner_id, banner_category_id ON content_versions '.
            'FOR EACH ROW EXECUTE FUNCTION v2_content_guard_banner_category_relation()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS content_versions_banner_category_guard ON content_versions'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_content_guard_banner_category_relation()');
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->dropForeign(['banner_category_id']);
            $table->dropIndex(['banner_category_id', 'id']);
            $table->dropColumn('banner_category_id');
        });
        Schema::dropIfExists('content_banner_categories');
    }
};
