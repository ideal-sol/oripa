<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
        });

        Schema::table('content_versions', function (Blueprint $table): void {
            $table->foreignId('page_category_id')->nullable()
                ->after('banner_category_id')
                ->constrained('content_page_categories')->restrictOnDelete();
            $table->index(['page_category_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_content_guard_page_category_relation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.static_page_id IS NULL AND NEW.page_category_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Only Static Page Versions may reference Page Categories';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER content_versions_page_category_guard '.
            'BEFORE INSERT OR UPDATE OF static_page_id, page_category_id ON content_versions '.
            'FOR EACH ROW EXECUTE FUNCTION v2_content_guard_page_category_relation()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS content_versions_page_category_guard ON content_versions');
        DB::statement('DROP FUNCTION IF EXISTS v2_content_guard_page_category_relation()');
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->dropForeign(['page_category_id']);
            $table->dropIndex(['page_category_id', 'id']);
            $table->dropColumn('page_category_id');
        });
        Schema::dropIfExists('content_page_categories');
    }
};
