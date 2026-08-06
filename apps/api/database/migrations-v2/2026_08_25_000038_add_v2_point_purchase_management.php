<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('audience_code', 32)->default('all_users');
            $table->unsignedBigInteger('revision')->default(1);
            $table->index(['sort_order', 'id'], 'point_purchase_plans_sort_cursor_index');
            $table->index(['audience_code', 'status'], 'point_purchase_plans_audience_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE point_purchase_plans
            ADD CONSTRAINT point_purchase_plan_management_check CHECK (
                sort_order >= 0
                AND audience_code::text = ANY (
                    ARRAY['all_users'::text, 'first_purchase_users'::text]
                )
                AND revision >= 1
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE point_purchase_plans '
            .'DROP CONSTRAINT IF EXISTS point_purchase_plan_management_check'
        );
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->dropIndex('point_purchase_plans_sort_cursor_index');
            $table->dropIndex('point_purchase_plans_audience_index');
            $table->dropColumn(['sort_order', 'audience_code', 'revision']);
        });
    }
};
