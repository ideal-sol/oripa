<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->foreignId('target_user_tag_id')
                ->nullable()
                ->constrained('user_tags')
                ->restrictOnDelete();
            $table->index(
                ['target_user_tag_id', 'status'],
                'point_purchase_plans_target_tag_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->dropIndex('point_purchase_plans_target_tag_index');
            $table->dropConstrainedForeignId('target_user_tag_id');
        });
    }
};
