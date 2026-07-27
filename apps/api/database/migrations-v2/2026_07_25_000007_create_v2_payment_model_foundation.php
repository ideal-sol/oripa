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
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64);
            $table->unsignedInteger('version_no');
            $table->string('name', 191);
            $table->bigInteger('amount');
            $table->bigInteger('paid_point_amount');
            $table->bigInteger('free_point_amount');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->timestampTz('available_from')->nullable();
            $table->timestampTz('available_until')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['code', 'version_no']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('point_purchase_plan_id')->nullable()
                ->constrained('point_purchase_plans')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_payment_id', 191)->nullable();
            $table->string('status', 24);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('paid_point_amount');
            $table->bigInteger('free_point_amount');
            $table->string('plan_name_snapshot', 191);
            $table->string('plan_code_snapshot', 64);
            $table->foreignId('idempotency_record_id')->nullable()
                ->constrained('idempotency_records')->restrictOnDelete();
            $table->timestampTz('requires_action_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('points_granted_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->unique(['provider_code', 'provider_payment_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_adjustments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('parent_adjustment_id')->nullable()
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('status', 24);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('provider_adjustment_id', 191)->nullable();
            $table->string('provider_case_id', 191)->nullable();
            $table->unsignedBigInteger('source_provider_event_id')->nullable()->unique();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_text')->nullable();
            $table->foreignId('requested_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('manual_review_at')->nullable();
            $table->timestampsTz();
            $table->index(['payment_id', 'created_at']);
            $table->index(['type', 'status', 'created_at']);
        });

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider_code', 64);
            $table->string('external_event_id', 191);
            $table->string('event_type', 128);
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->restrictOnDelete();
            $table->foreignId('payment_adjustment_id')->nullable()
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->timestampTz('signature_verified_at');
            $table->timestampTz('provider_occurred_at')->nullable();
            $table->timestampTz('received_at');
            $table->char('payload_hash', 64);
            $table->text('payload_ciphertext')->nullable();
            $table->jsonb('headers_redacted')->default('{}');
            $table->string('processing_status', 16);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();
            $table->unique(['provider_code', 'external_event_id']);
            $table->index(['processing_status', 'next_retry_at']);
            $table->index(['payment_id', 'received_at']);
        });
        Schema::table('payment_adjustments', function (Blueprint $table): void {
            $table->foreign('source_provider_event_id')
                ->references('id')->on('payment_provider_events')->restrictOnDelete();
        });

        Schema::create('payment_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('transition_source', 32);
            $table->foreignId('provider_event_id')->nullable()
                ->constrained('payment_provider_events')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('request_id');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('payment_point_grants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_id')->unique()
                ->constrained('payments')->restrictOnDelete();
            $table->foreignId('point_operation_id')->unique()
                ->constrained('point_operations')->restrictOnDelete();
            $table->timestampTz('granted_at');
        });

        Schema::create('payment_provider_event_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_provider_event_id')
                ->constrained('payment_provider_events')->restrictOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('worker_id', 128);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('outcome', 16);
            $table->string('error_class_code', 64)->nullable();
            $table->text('error_message_redacted')->nullable();
            $table->uuid('request_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['payment_provider_event_id', 'attempt_no']);
        });

        Schema::create('payment_provider_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('operation_type', 64);
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('payment_adjustment_id')->nullable()
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->char('provider_idempotency_key_hash', 64);
            $table->char('request_hash', 64);
            $table->jsonb('request_redacted')->default('{}');
            $table->jsonb('response_redacted')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->boolean('timed_out')->default(false);
            $table->boolean('outcome_uncertain')->default(false);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['operation_type', 'provider_idempotency_key_hash']);
        });

        Schema::create('payment_adjustment_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_adjustment_id')
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('transition_source', 32);
            $table->foreignId('provider_event_id')->nullable()
                ->constrained('payment_provider_events')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('payment_adjustment_point_impacts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_adjustment_id')->unique()
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->bigInteger('required_paid_amount');
            $table->bigInteger('required_free_amount');
            $table->bigInteger('reversed_paid_from_paid')->default(0);
            $table->bigInteger('reversed_free_from_free')->default(0);
            $table->bigInteger('reversed_paid_shortage_from_free')->default(0);
            $table->bigInteger('shortfall_paid_amount')->default(0);
            $table->bigInteger('shortfall_free_amount')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('payment_adjustment_point_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_adjustment_id')
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->foreignId('point_operation_id')->unique()
                ->constrained('point_operations')->restrictOnDelete();
            $table->string('role', 24);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['payment_adjustment_id', 'role']);
        });

        Schema::create('point_lot_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('point_lot_id')->constrained('point_lots')->restrictOnDelete();
            $table->foreignId('payment_adjustment_id')
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('status', 16);
            $table->timestampTz('reserved_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->unique(['point_lot_id', 'payment_adjustment_id']);
            $table->index(['payment_adjustment_id', 'status']);
            $table->index(['point_lot_id', 'status']);
        });

        $this->constraints();
        $this->immutableGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_adjustments')) {
            Schema::table('payment_adjustments', function (Blueprint $table): void {
                $table->dropForeign(['source_provider_event_id']);
            });
        }
        foreach ([
            'point_lot_reservations',
            'payment_adjustment_point_operations',
            'payment_adjustment_point_impacts',
            'payment_adjustment_status_histories',
            'payment_provider_operations',
            'payment_provider_event_attempts',
            'payment_status_histories',
            'payment_provider_events',
            'payment_adjustments',
            'payment_point_grants',
            'payments',
            'point_purchase_plans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_payment_immutable_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS v2_protect_published_plan()');
        DB::statement('DROP FUNCTION IF EXISTS v2_validate_payment_adjustment_amount()');
    }

    private function constraints(): void
    {
        DB::statement("ALTER TABLE point_purchase_plans ADD CONSTRAINT purchase_plan_values_check CHECK (amount > 0 AND paid_point_amount = amount AND free_point_amount >= 0 AND currency = 'JPY' AND status::text = ANY (ARRAY['draft'::text,'published'::text,'retired'::text]) AND (available_until IS NULL OR available_from IS NULL OR available_until > available_from))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_values_check CHECK (amount > 0 AND paid_point_amount = amount AND free_point_amount >= 0 AND currency = 'JPY' AND status::text = ANY (ARRAY['created'::text,'requires_action'::text,'processing'::text,'succeeded'::text,'failed'::text,'canceled'::text,'expired'::text]) AND jsonb_typeof(metadata) = 'object')");
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT provider_event_values_check CHECK (processing_status::text = ANY (ARRAY['received'::text,'processing'::text,'processed'::text,'failed'::text,'ignored'::text]) AND jsonb_typeof(headers_redacted) = 'object' AND payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE payment_status_histories ADD CONSTRAINT payment_history_values_check CHECK (to_status::text = ANY (ARRAY['created'::text,'requires_action'::text,'processing'::text,'succeeded'::text,'failed'::text,'canceled'::text,'expired'::text]))");
        DB::statement("ALTER TABLE payment_adjustments ADD CONSTRAINT payment_adjustment_values_check CHECK (type::text = ANY (ARRAY['refund'::text,'chargeback'::text,'chargeback_reversal'::text]) AND status::text = ANY (ARRAY['requested'::text,'points_reserved'::text,'submitted'::text,'processing'::text,'succeeded'::text,'failed'::text,'canceled'::text,'manual_review'::text]) AND amount > 0 AND currency = 'JPY')");
        DB::statement("ALTER TABLE payment_provider_event_attempts ADD CONSTRAINT provider_attempt_values_check CHECK (attempt_no > 0 AND outcome::text = ANY (ARRAY['success'::text,'retry'::text,'failure'::text]))");
        DB::statement("ALTER TABLE payment_provider_operations ADD CONSTRAINT provider_operation_values_check CHECK (operation_type::text = 'refund'::text AND status::text = ANY (ARRAY['pending'::text,'succeeded'::text,'failed'::text,'uncertain'::text]) AND attempt_count >= 0 AND jsonb_typeof(request_redacted) = 'object' AND (response_redacted IS NULL OR jsonb_typeof(response_redacted) = 'object'))");
        DB::statement("ALTER TABLE payment_adjustment_status_histories ADD CONSTRAINT adjustment_history_values_check CHECK (to_status::text = ANY (ARRAY['requested'::text,'points_reserved'::text,'submitted'::text,'processing'::text,'succeeded'::text,'failed'::text,'canceled'::text,'manual_review'::text]))");
        DB::statement("ALTER TABLE payment_adjustment_point_impacts ADD CONSTRAINT payment_impact_nonnegative_check CHECK (required_paid_amount >= 0 AND required_free_amount >= 0 AND reversed_paid_from_paid >= 0 AND reversed_free_from_free >= 0 AND reversed_paid_shortage_from_free >= 0 AND shortfall_paid_amount >= 0 AND shortfall_free_amount >= 0)");
        DB::statement("ALTER TABLE payment_adjustment_point_operations ADD CONSTRAINT adjustment_operation_role_check CHECK (role::text = ANY (ARRAY['reserve_consume'::text,'reversal'::text]))");
        DB::statement("ALTER TABLE point_lot_reservations ADD CONSTRAINT lot_reservation_values_check CHECK (amount > 0 AND status::text = ANY (ARRAY['active'::text,'consumed'::text,'released'::text]) AND ((status = 'active' AND consumed_at IS NULL AND released_at IS NULL) OR (status = 'consumed' AND consumed_at IS NOT NULL AND released_at IS NULL) OR (status = 'released' AND released_at IS NOT NULL AND consumed_at IS NULL)))");
    }

    private function immutableGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_validate_payment_adjustment_amount()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE payment_amount bigint;
            BEGIN
                SELECT amount INTO payment_amount FROM payments WHERE id = NEW.payment_id;
                IF payment_amount IS NULL OR NEW.amount > payment_amount
                THEN RAISE EXCEPTION 'Payment adjustment exceeds payment amount';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER payment_adjustment_validate_amount BEFORE INSERT OR UPDATE OF payment_id, amount ON payment_adjustments FOR EACH ROW EXECUTE FUNCTION v2_validate_payment_adjustment_amount()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_payment_immutable_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'V2 payment history is append-only';
            END;
            $$
            SQL);
        foreach ([
            'payment_status_histories',
            'payment_provider_events',
            'payment_provider_event_attempts',
            'payment_adjustment_status_histories',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_reject_mutation BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_reject_payment_immutable_mutation()");
            DB::statement("CREATE TRIGGER {$table}_reject_truncate BEFORE TRUNCATE ON {$table} EXECUTE FUNCTION v2_reject_payment_immutable_mutation()");
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_protect_published_plan()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'published' AND
                   (NEW.amount, NEW.paid_point_amount, NEW.free_point_amount, NEW.currency)
                   IS DISTINCT FROM
                   (OLD.amount, OLD.paid_point_amount, OLD.free_point_amount, OLD.currency)
                THEN RAISE EXCEPTION 'Published purchase plan financial values are immutable';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER purchase_plan_protect_published BEFORE UPDATE ON point_purchase_plans FOR EACH ROW EXECUTE FUNCTION v2_protect_published_plan()');
    }
};
