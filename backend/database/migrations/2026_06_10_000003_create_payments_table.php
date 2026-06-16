<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_payment_id')->nullable();
            $table->string('webhook_event_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->integer('amount');
            $table->integer('paid_point_amount')->default(0);
            $table->integer('free_point_amount')->default(0);
            $table->string('currency', 3)->default('JPY');
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('chargeback_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'canceled', 'refunded', 'chargeback'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amounts_non_negative CHECK (amount >= 0 AND paid_point_amount >= 0 AND free_point_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
