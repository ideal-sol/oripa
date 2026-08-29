<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fincode_card_registration_intents DROP CONSTRAINT fincode_card_intent_values_check');
        DB::statement('ALTER TABLE fincode_cards DROP CONSTRAINT fincode_card_values_check');

        Schema::table('fincode_card_registration_intents', function (Blueprint $table): void {
            $table->string('flow_type', 32)->default('legacy')->after('idempotency_key_hash');
            $table->uuid('provider_idempotency_key')->nullable()->after('flow_type');
            $table->string('provider_payment_method_id', 64)->nullable()->after('provider_idempotency_key');
            $table->string('provider_access_id', 128)->nullable()->after('provider_payment_method_id');
            $table->string('provider_card_id', 64)->nullable()->after('provider_access_id');
            $table->string('provider_transaction_id', 128)->nullable()->after('provider_card_id');
            $table->string('provider_status', 64)->nullable()->after('status');
            $table->string('provider_tds2_status', 64)->nullable()->after('provider_status');
            $table->text('redirect_url_ciphertext')->nullable()->after('provider_tds2_status');
            $table->unsignedInteger('attempt_count')->default(0)->after('redirect_url_ciphertext');
            $table->string('last_error_code', 64)->nullable()->after('attempt_count');
            $table->timestampTz('last_attempted_at')->nullable()->after('last_error_code');
            $table->timestampTz('webhook_received_at')->nullable()->after('last_attempted_at');
            $table->timestampTz('provider_reconciled_at')->nullable()->after('webhook_received_at');
            $table->timestampTz('failed_at')->nullable()->after('completed_at');
            $table->timestampTz('canceled_at')->nullable()->after('failed_at');
            $table->unique('provider_idempotency_key', 'fincode_card_intents_provider_idempotency_unique');
            $table->unique('provider_payment_method_id', 'fincode_card_intents_payment_method_unique');
            $table->unique('provider_access_id', 'fincode_card_intents_access_id_unique');
            $table->index(
                ['flow_type', 'status', 'expires_at'],
                'fincode_card_intents_flow_state_expiry_idx'
            );
        });

        Schema::table('fincode_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('registration_intent_id')->nullable()->after('fincode_customer_id');
            $table->string('provider_payment_method_id', 64)->nullable()->after('provider_card_id');
            $table->string('registration_assurance', 32)->nullable()->after('provider_payment_method_id');
            $table->timestampTz('registration_verified_at')->nullable()->after('registration_assurance');
            $table->foreign('registration_intent_id', 'fincode_cards_registration_intent_fk')
                ->references('id')
                ->on('fincode_card_registration_intents')
                ->restrictOnDelete();
            $table->unique('registration_intent_id', 'fincode_cards_registration_intent_unique');
            $table->unique('provider_payment_method_id', 'fincode_cards_payment_method_unique');
            $table->index(
                ['user_id', 'registration_verified_at', 'deleted_at'],
                'fincode_cards_user_verified_active_idx'
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE fincode_card_registration_intents
ADD CONSTRAINT fincode_card_intent_values_check CHECK (
    idempotency_key_hash ~ '^[0-9a-f]{64}$'
    AND flow_type::text = ANY (ARRAY['legacy'::text,'three_d_secure_2'::text])
    AND status::text = ANY (ARRAY[
        'reserved'::text,
        'starting'::text,
        'requires_action'::text,
        'pending'::text,
        'completed'::text,
        'failed'::text,
        'expired'::text,
        'canceled'::text
    ])
    AND attempt_count >= 0
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fincode_cards
ADD CONSTRAINT fincode_card_values_check CHECK (
    last4 ~ '^[0-9]{4}$'
    AND expire_month BETWEEN 1 AND 12
    AND expire_year BETWEEN 2000 AND 9999
    AND (
        (
            registration_intent_id IS NULL
            AND provider_payment_method_id IS NULL
            AND registration_assurance IS NULL
            AND registration_verified_at IS NULL
        )
        OR
        (
            registration_intent_id IS NOT NULL
            AND provider_payment_method_id IS NOT NULL
            AND registration_assurance = 'three_d_secure_2'
            AND registration_verified_at IS NOT NULL
        )
    )
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fincode_cards DROP CONSTRAINT fincode_card_values_check');
        DB::statement('ALTER TABLE fincode_card_registration_intents DROP CONSTRAINT fincode_card_intent_values_check');

        Schema::table('fincode_cards', function (Blueprint $table): void {
            $table->dropIndex('fincode_cards_user_verified_active_idx');
            $table->dropUnique('fincode_cards_payment_method_unique');
            $table->dropUnique('fincode_cards_registration_intent_unique');
            $table->dropForeign('fincode_cards_registration_intent_fk');
            $table->dropColumn([
                'registration_intent_id',
                'provider_payment_method_id',
                'registration_assurance',
                'registration_verified_at',
            ]);
        });

        Schema::table('fincode_card_registration_intents', function (Blueprint $table): void {
            $table->dropIndex('fincode_card_intents_flow_state_expiry_idx');
            $table->dropUnique('fincode_card_intents_access_id_unique');
            $table->dropUnique('fincode_card_intents_payment_method_unique');
            $table->dropUnique('fincode_card_intents_provider_idempotency_unique');
            $table->dropColumn([
                'flow_type',
                'provider_idempotency_key',
                'provider_payment_method_id',
                'provider_access_id',
                'provider_card_id',
                'provider_transaction_id',
                'provider_status',
                'provider_tds2_status',
                'redirect_url_ciphertext',
                'attempt_count',
                'last_error_code',
                'last_attempted_at',
                'webhook_received_at',
                'provider_reconciled_at',
                'failed_at',
                'canceled_at',
            ]);
        });

        DB::statement("ALTER TABLE fincode_card_registration_intents ADD CONSTRAINT fincode_card_intent_values_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$' AND status::text = ANY (ARRAY['reserved'::text,'completed'::text,'expired'::text,'canceled'::text]))");
        DB::statement("ALTER TABLE fincode_cards ADD CONSTRAINT fincode_card_values_check CHECK (last4 ~ '^[0-9]{4}$' AND expire_month BETWEEN 1 AND 12 AND expire_year BETWEEN 2000 AND 9999)");
    }
};
