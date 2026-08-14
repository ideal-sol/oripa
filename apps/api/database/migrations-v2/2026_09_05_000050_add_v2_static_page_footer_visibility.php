<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_static_pages', function (Blueprint $table): void {
            $table->boolean('show_in_footer')->default(false)->after('is_legal');
            $table->index(
                ['show_in_footer', 'status', 'id'],
                'content_static_pages_footer_public_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_static_pages', function (Blueprint $table): void {
            $table->dropIndex('content_static_pages_footer_public_index');
            $table->dropColumn('show_in_footer');
        });
    }
};
