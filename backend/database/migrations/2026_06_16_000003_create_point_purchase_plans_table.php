<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_purchase_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_point_amount');
            $table->unsignedInteger('free_point_amount')->default(0);
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        DB::table('point_purchase_plans')->insert([
            [
                'name' => 'ライト',
                'amount' => 1000,
                'paid_point_amount' => 1000,
                'free_point_amount' => 0,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'スタンダード',
                'amount' => 3000,
                'paid_point_amount' => 3000,
                'free_point_amount' => 300,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'プレミアム',
                'amount' => 10000,
                'paid_point_amount' => 10000,
                'free_point_amount' => 1500,
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('point_purchase_plans');
    }
};
