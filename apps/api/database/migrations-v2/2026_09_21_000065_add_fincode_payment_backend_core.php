<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_method', 32)->nullable()->after('provider_code');
            $table->string('provider_status', 64)->nullable()->after('status');
            $table->timestampTz('expires_at')->nullable()->after('provider_status');
            $table->timestampTz('provider_confirmed_at')->nullable()->after('expires_at');
            $table->index(['user_id', 'payment_method', 'status', 'expires_at'], 'payments_user_method_state_idx');
            $table->index(['payment_method', 'status', 'created_at'], 'payments_method_state_created_idx');
        });

        Schema::create('fincode_customers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('provider_customer_id', 64)->unique();
            $table->uuid('provider_idempotency_key')->unique();
            $table->string('status', 32)->default('prepared');
            $table->timestampsTz();
        });

        Schema::create('fincode_card_registration_intents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('fincode_customer_id')->constrained('fincode_customers')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->string('status', 32)->default('reserved');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'expires_at'], 'fincode_card_intents_user_state_idx');
        });

        Schema::create('fincode_cards', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('fincode_customer_id')->constrained('fincode_customers')->restrictOnDelete();
            $table->string('provider_card_id', 64);
            $table->string('brand', 32)->nullable();
            $table->char('last4', 4);
            $table->unsignedSmallInteger('expire_month');
            $table->unsignedSmallInteger('expire_year');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['fincode_customer_id', 'provider_card_id']);
            $table->index(['user_id', 'deleted_at', 'last_used_at'], 'fincode_cards_user_active_idx');
        });

        Schema::create('fincode_payment_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('fincode_card_id')->nullable()->constrained('fincode_cards')->restrictOnDelete();
            $table->uuid('provider_idempotency_key')->unique();
            $table->uuid('provider_execute_idempotency_key')->nullable()->unique();
            $table->string('provider_access_id', 128)->nullable();
            $table->string('provider_session_id', 128)->nullable()->unique();
            $table->text('redirect_url_ciphertext')->nullable();
            $table->string('status', 32)->default('prepared');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_fincode_method_check CHECK (payment_method IS NULL OR payment_method::text = ANY (ARRAY['credit_card'::text,'paypay'::text,'konbini'::text,'virtual_account'::text]))");
        DB::statement("ALTER TABLE fincode_customers ADD CONSTRAINT fincode_customer_status_check CHECK (status::text = ANY (ARRAY['prepared'::text,'calling'::text,'active'::text,'failed'::text,'uncertain'::text]))");
        DB::statement("ALTER TABLE fincode_card_registration_intents ADD CONSTRAINT fincode_card_intent_values_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$' AND status::text = ANY (ARRAY['reserved'::text,'completed'::text,'expired'::text,'canceled'::text]))");
        DB::statement("ALTER TABLE fincode_cards ADD CONSTRAINT fincode_card_values_check CHECK (last4 ~ '^[0-9]{4}$' AND expire_month BETWEEN 1 AND 12 AND expire_year BETWEEN 2000 AND 9999)");
        DB::statement("ALTER TABLE fincode_payment_attempts ADD CONSTRAINT fincode_payment_attempt_values_check CHECK (status::text = ANY (ARRAY['prepared'::text,'calling'::text,'requires_action'::text,'pending'::text,'failed'::text,'uncertain'::text,'completed'::text]) AND attempt_count >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('fincode_payment_attempts');
        Schema::dropIfExists('fincode_cards');
        Schema::dropIfExists('fincode_card_registration_intents');
        Schema::dropIfExists('fincode_customers');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_user_method_state_idx');
            $table->dropIndex('payments_method_state_created_idx');
            $table->dropColumn([
                'payment_method',
                'provider_status',
                'expires_at',
                'provider_confirmed_at',
            ]);
        });
    }
};
