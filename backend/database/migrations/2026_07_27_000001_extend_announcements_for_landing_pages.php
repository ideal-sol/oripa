<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('category')->default('notice')->after('body');
            $table->timestamp('published_until')->nullable()->after('published_at');
            $table->index(
                ['category', 'status', 'published_at', 'published_until'],
                'announcements_public_window_index',
            );
        });

        DB::statement("UPDATE announcements SET category = 'notice' WHERE category IS NULL");
        DB::statement("ALTER TABLE announcements ALTER COLUMN category SET NOT NULL");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_category_check CHECK (category IN ('notice', 'lp'))");
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_publication_window_check CHECK (published_until IS NULL OR (published_at IS NOT NULL AND published_until > published_at))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE announcements DROP CONSTRAINT IF EXISTS announcements_publication_window_check');
        DB::statement('ALTER TABLE announcements DROP CONSTRAINT IF EXISTS announcements_category_check');

        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropIndex('announcements_public_window_index');
            $table->dropColumn(['category', 'published_until']);
        });
    }
};
