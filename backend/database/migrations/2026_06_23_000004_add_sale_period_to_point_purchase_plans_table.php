<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->dateTime('starts_at')->nullable()->after('is_active');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            $table->index(['is_active', 'starts_at', 'ends_at', 'sort_order'], 'point_purchase_plans_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('point_purchase_plans', function (Blueprint $table): void {
            $table->dropIndex('point_purchase_plans_period_index');
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
