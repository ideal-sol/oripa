<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('pending');
            $table->text('reason')->nullable();
            $table->integer('payment_amount');
            $table->integer('paid_point_amount')->default(0);
            $table->integer('free_point_amount')->default(0);
            $table->integer('paid_reversed_amount')->default(0);
            $table->integer('free_reversed_amount')->default(0);
            $table->integer('shortfall_paid_amount')->default(0);
            $table->integer('shortfall_free_amount')->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'status', 'occurred_at']);
        });

        Schema::create('payment_reversal_point_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_reversal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('point_ledger_id')->nullable()->constrained()->nullOnDelete();
            $table->string('point_type', 16);
            $table->string('bucket', 64);
            $table->integer('amount')->default(0);
            $table->integer('shortfall_amount')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('payment_reversal_id');
            $table->index('point_lot_id');
            $table->index('point_ledger_id');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('payment_reversal_prize_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_reversal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_prize_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipping_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 32);
            $table->string('previous_user_prize_status')->nullable();
            $table->string('previous_shipping_item_status')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->text('mail_last_error')->nullable();
            $table->timestamp('mail_last_attempted_at')->nullable();
            $table->text('discord_last_error')->nullable();
            $table->timestamp('discord_last_attempted_at')->nullable();
            $table->timestamps();

            $table->index('payment_reversal_id');
            $table->index('user_prize_id');
            $table->index('shipping_item_id');
            $table->index(['action_type', 'status']);
        });

        DB::statement("ALTER TABLE payment_reversals ADD CONSTRAINT payment_reversals_type_check CHECK (type IN ('refund', 'chargeback'))");
        DB::statement("ALTER TABLE payment_reversals ADD CONSTRAINT payment_reversals_status_check CHECK (status IN ('pending', 'completed', 'failed', 'canceled', 'review_required'))");
        DB::statement('ALTER TABLE payment_reversals ADD CONSTRAINT payment_reversals_amounts_non_negative CHECK (payment_amount >= 0 AND paid_point_amount >= 0 AND free_point_amount >= 0 AND paid_reversed_amount >= 0 AND free_reversed_amount >= 0 AND shortfall_paid_amount >= 0 AND shortfall_free_amount >= 0)');

        DB::statement("ALTER TABLE payment_reversal_point_entries ADD CONSTRAINT payment_reversal_point_entries_point_type_check CHECK (point_type IN ('paid', 'free'))");
        DB::statement("ALTER TABLE payment_reversal_point_entries ADD CONSTRAINT payment_reversal_point_entries_bucket_check CHECK (bucket IN ('paid_purchase_from_paid', 'free_bonus_from_free', 'paid_purchase_shortfall_from_free', 'shortfall'))");
        DB::statement('ALTER TABLE payment_reversal_point_entries ADD CONSTRAINT payment_reversal_point_entries_amounts_non_negative CHECK (amount >= 0 AND shortfall_amount >= 0)');

        DB::statement("ALTER TABLE payment_reversal_prize_actions ADD CONSTRAINT payment_reversal_prize_actions_action_type_check CHECK (action_type IN ('hold', 'return_requested', 'hold_released', 'no_action'))");
        DB::statement("ALTER TABLE payment_reversal_prize_actions ADD CONSTRAINT payment_reversal_prize_actions_status_check CHECK (status IN ('pending', 'completed', 'released', 'canceled'))");

        DB::statement('ALTER TABLE user_prizes DROP CONSTRAINT IF EXISTS user_prizes_status_check');
        DB::statement("ALTER TABLE user_prizes ADD CONSTRAINT user_prizes_status_check CHECK (status IN ('stored', 'shipping_requested', 'shipped', 'converted', 'expired', 'held'))");

        DB::statement('ALTER TABLE shipping_items DROP CONSTRAINT IF EXISTS shipping_items_status_check');
        DB::statement("ALTER TABLE shipping_items ADD CONSTRAINT shipping_items_status_check CHECK (status IN ('requested', 'packing', 'shipped', 'delivered', 'returned', 'canceled', 'hold', 'return_requested'))");
    }

    public function down(): void
    {
        if (DB::table('user_prizes')->where('status', 'held')->exists()) {
            throw new \RuntimeException('Cannot rollback payment reversal migration while user_prizes.status=held rows exist.');
        }

        if (DB::table('shipping_items')->whereIn('status', ['hold', 'return_requested'])->exists()) {
            throw new \RuntimeException('Cannot rollback payment reversal migration while shipping_items hold/return_requested rows exist.');
        }

        DB::statement('ALTER TABLE shipping_items DROP CONSTRAINT IF EXISTS shipping_items_status_check');
        DB::statement("ALTER TABLE shipping_items ADD CONSTRAINT shipping_items_status_check CHECK (status IN ('requested', 'packing', 'shipped', 'delivered', 'returned', 'canceled'))");

        DB::statement('ALTER TABLE user_prizes DROP CONSTRAINT IF EXISTS user_prizes_status_check');
        DB::statement("ALTER TABLE user_prizes ADD CONSTRAINT user_prizes_status_check CHECK (status IN ('stored', 'shipping_requested', 'shipped', 'converted', 'expired'))");

        Schema::dropIfExists('payment_reversal_prize_actions');
        Schema::dropIfExists('payment_reversal_point_entries');
        Schema::dropIfExists('payment_reversals');
    }
};
