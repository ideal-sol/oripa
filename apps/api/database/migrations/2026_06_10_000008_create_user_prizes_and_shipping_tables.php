<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gacha_id')->constrained()->restrictOnDelete();
            $table->foreignId('gacha_prize_id')->constrained('gacha_prizes')->restrictOnDelete();
            $table->foreignId('draw_result_id')->unique()->constrained('draw_results')->restrictOnDelete();
            $table->string('status')->default('stored');
            $table->timestamp('acquired_at');
            $table->timestamp('storage_expire_at');
            $table->integer('converted_point')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('storage_expire_at');
        });

        Schema::create('shipping_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('requested');
            $table->string('recipient_name');
            $table->string('postal_code', 16);
            $table->string('prefecture');
            $table->string('city');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('phone_number', 32);
            $table->string('tracking_number')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('shipping_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_prize_id')->unique()->constrained()->restrictOnDelete();
        });

        DB::statement("ALTER TABLE user_prizes ADD CONSTRAINT user_prizes_status_check CHECK (status IN ('stored', 'shipping_requested', 'shipped', 'converted', 'expired'))");
        DB::statement('ALTER TABLE user_prizes ADD CONSTRAINT user_prizes_converted_point_non_negative CHECK (converted_point IS NULL OR converted_point >= 0)');
        DB::statement("ALTER TABLE shipping_requests ADD CONSTRAINT shipping_requests_status_check CHECK (status IN ('requested', 'packing', 'shipped', 'delivered', 'returned', 'canceled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_items');
        Schema::dropIfExists('shipping_requests');
        Schema::dropIfExists('user_prizes');
    }
};
