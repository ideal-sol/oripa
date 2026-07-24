<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->bigInteger('paid_balance')->default(0);
            $table->bigInteger('free_balance')->default(0);
            $table->bigInteger('paid_reserved_balance')->default(0);
            $table->bigInteger('free_reserved_balance')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('point_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_type', 32);
            $table->string('business_key', 191)->unique();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->boolean('is_qa')->default(false);
            $table->unsignedBigInteger('qa_draw_execution_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'occurred_at', 'id']);
        });

        Schema::create('point_lots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('grant_operation_id')
                ->constrained('point_operations')->restrictOnDelete();
            $table->string('point_type', 8);
            $table->bigInteger('granted_amount');
            $table->bigInteger('remaining_amount');
            $table->bigInteger('reserved_amount')->default(0);
            $table->timestampTz('granted_at');
            $table->timestampTz('expire_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index('grant_operation_id');
        });
        DB::statement(
            'CREATE INDEX point_lots_consumption_order ON point_lots '.
            '(user_id, point_type, expire_at, granted_at, id) '.
            'WHERE remaining_amount > reserved_amount'
        );

        Schema::create('point_ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('point_operation_id')
                ->constrained('point_operations')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('point_lot_id')
                ->nullable()->constrained('point_lots')->restrictOnDelete();
            $table->string('point_type', 8);
            $table->string('entry_type', 16);
            $table->bigInteger('amount_delta');
            $table->bigInteger('wallet_balance_after');
            $table->bigInteger('lot_remaining_after')->nullable();
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['point_operation_id', 'sequence_no']);
            $table->index(['user_id', 'occurred_at', 'id']);
            $table->index(['point_lot_id', 'id']);
        });

        Schema::create('point_adjustments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('direction', 8);
            $table->string('point_type', 8);
            $table->bigInteger('amount');
            $table->timestampTz('expire_at')->nullable();
            $table->string('reason_code', 64);
            $table->text('reason_text');
            $table->string('status', 16);
            $table->foreignId('requested_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->foreignId('approved_by_admin_id')
                ->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('point_operation_id')
                ->nullable()->unique()->constrained('point_operations')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('point_balance_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('snapshot_date')->unique();
            $table->timestampTz('source_cutoff_at');
            $table->string('calculation_method', 32);
            $table->bigInteger('opening_paid_balance');
            $table->bigInteger('opening_free_balance');
            $table->bigInteger('granted_paid_amount');
            $table->bigInteger('granted_free_amount');
            $table->bigInteger('consumed_paid_amount');
            $table->bigInteger('consumed_free_amount');
            $table->bigInteger('expired_free_amount');
            $table->bigInteger('reversed_paid_amount');
            $table->bigInteger('reversed_free_amount');
            $table->bigInteger('closing_paid_balance');
            $table->bigInteger('closing_free_balance');
            $table->bigInteger('paid_reserved_balance');
            $table->bigInteger('free_reserved_balance');
            $table->unsignedBigInteger('user_count');
            $table->unsignedBigInteger('open_lot_count');
            $table->boolean('is_base_date');
            $table->timestampTz('generated_at');
            $table->uuid('generation_run_id');
            $table->char('checksum', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('point_reconciliation_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->date('target_date');
            $table->string('status', 16);
            $table->unsignedBigInteger('checked_wallet_count')->default(0);
            $table->unsignedBigInteger('discrepancy_count')->default(0);
            $table->string('initiated_by', 32);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['target_date', 'started_at']);
        });

        Schema::create('point_reconciliation_discrepancies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('reconciliation_run_id')
                ->constrained('point_reconciliation_runs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('point_type', 8);
            $table->string('discrepancy_type', 32);
            $table->bigInteger('expected_amount');
            $table->bigInteger('actual_amount');
            $table->jsonb('source_ids')->default('[]');
            $table->boolean('resolved')->default(false);
            $table->foreignId('resolution_operation_id')
                ->nullable()->constrained('point_operations')->restrictOnDelete();
            $table->text('admin_memo')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['reconciliation_run_id', 'user_id']);
        });

        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('scope', 64);
            $table->string('actor_type', 16);
            $table->uuid('actor_public_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->string('resource_type', 64)->nullable();
            $table->uuid('resource_public_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->unique(
                ['scope', 'actor_type', 'actor_public_id', 'key_hash'],
                'idempotency_actor_key_unique'
            );
            $table->index(['status', 'expires_at']);
        });

        $this->addConstraints();
        $this->addImmutableGuards();
    }

    public function down(): void
    {
        foreach (
            ['point_operations', 'point_ledger_entries', 'point_reconciliation_discrepancies']
            as $table
        ) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_mutation ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_truncate ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_point_immutable_mutation()');

        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('point_reconciliation_discrepancies');
        Schema::dropIfExists('point_reconciliation_runs');
        Schema::dropIfExists('point_balance_snapshots');
        Schema::dropIfExists('point_adjustments');
        Schema::dropIfExists('point_ledger_entries');
        Schema::dropIfExists('point_lots');
        Schema::dropIfExists('point_operations');
        Schema::dropIfExists('wallets');
    }

    private function addConstraints(): void
    {
        DB::statement(
            'ALTER TABLE wallets ADD CONSTRAINT wallets_balance_check CHECK ('.
            'paid_balance >= 0 AND free_balance >= 0 AND '.
            'paid_reserved_balance >= 0 AND free_reserved_balance >= 0 AND '.
            'paid_reserved_balance <= paid_balance AND free_reserved_balance <= free_balance)'
        );
        DB::statement(
            "ALTER TABLE point_operations ADD CONSTRAINT point_operations_actor_check ".
            "CHECK (actor_type::text = ANY (ARRAY[".
            "'system'::text, 'user'::text, 'admin'::text, 'webhook'::text]))"
        );
        DB::statement(
            "ALTER TABLE point_operations ADD CONSTRAINT point_operations_metadata_check ".
            "CHECK (jsonb_typeof(metadata) = 'object')"
        );
        DB::statement(
            "ALTER TABLE point_lots ADD CONSTRAINT point_lots_type_amount_expiry_check CHECK (".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND ".
            'granted_amount > 0 AND remaining_amount >= 0 AND reserved_amount >= 0 AND '.
            'reserved_amount <= remaining_amount AND remaining_amount <= granted_amount AND '.
            "((point_type = 'paid' AND expire_at IS NULL) OR ".
            "(point_type = 'free' AND expire_at IS NOT NULL)))"
        );
        DB::statement(
            "ALTER TABLE point_ledger_entries ADD CONSTRAINT point_ledger_type_entry_check CHECK (".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND ".
            "entry_type::text = ANY (ARRAY[".
            "'grant'::text, 'spend'::text, 'expire'::text, ".
            "'reverse'::text, 'restore'::text]) AND ".
            'amount_delta <> 0 AND wallet_balance_after >= 0 AND '.
            '(lot_remaining_after IS NULL OR lot_remaining_after >= 0) AND '.
            "((entry_type::text = ANY (ARRAY['grant'::text, 'restore'::text]) ".
            "AND amount_delta > 0) OR ".
            "(entry_type::text = ANY (ARRAY[".
            "'spend'::text, 'expire'::text, 'reverse'::text]) AND amount_delta < 0)))"
        );
        DB::statement(
            "ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_values_check CHECK (".
            "direction::text = ANY (ARRAY['grant'::text, 'deduct'::text]) AND ".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND amount > 0 AND ".
            "status::text = ANY (ARRAY[".
            "'requested'::text, 'approved'::text, 'executed'::text, 'rejected'::text]) AND ".
            "((point_type = 'paid' AND expire_at IS NULL) OR ".
            "(point_type = 'free' AND direction = 'grant' AND expire_at IS NOT NULL) OR ".
            "(point_type = 'free' AND direction = 'deduct')) AND ".
            "((status = 'executed' AND point_operation_id IS NOT NULL AND executed_at IS NOT NULL) OR ".
            "(status <> 'executed' AND point_operation_id IS NULL AND executed_at IS NULL)))"
        );
        DB::statement(
            "ALTER TABLE point_balance_snapshots ADD CONSTRAINT point_snapshots_values_check CHECK (".
            "calculation_method = 'ledger_cutoff' AND ".
            'opening_paid_balance >= 0 AND opening_free_balance >= 0 AND '.
            'granted_paid_amount >= 0 AND granted_free_amount >= 0 AND '.
            'consumed_paid_amount >= 0 AND consumed_free_amount >= 0 AND '.
            'expired_free_amount >= 0 AND reversed_paid_amount >= 0 AND '.
            'reversed_free_amount >= 0 AND closing_paid_balance >= 0 AND '.
            'closing_free_balance >= 0 AND paid_reserved_balance >= 0 AND '.
            'free_reserved_balance >= 0 AND '.
            "checksum ~ '^[0-9a-f]{64}$' AND ".
            "(is_base_date = ((EXTRACT(MONTH FROM snapshot_date) = 3 AND ".
            "EXTRACT(DAY FROM snapshot_date) = 31) OR ".
            "(EXTRACT(MONTH FROM snapshot_date) = 9 AND ".
            'EXTRACT(DAY FROM snapshot_date) = 30))))'
        );
        DB::statement(
            "ALTER TABLE point_reconciliation_runs ADD CONSTRAINT point_reconciliation_status_check ".
            "CHECK (status::text = ANY (ARRAY[".
            "'running'::text, 'completed'::text, 'failed'::text]))"
        );
        DB::statement(
            "ALTER TABLE point_reconciliation_discrepancies ".
            "ADD CONSTRAINT point_discrepancy_values_check CHECK (".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND ".
            "jsonb_typeof(source_ids) = 'array' AND ".
            "(resolved = false OR resolution_operation_id IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_values_check CHECK (".
            "actor_type::text = ANY (ARRAY[".
            "'system'::text, 'user'::text, 'admin'::text, 'webhook'::text]) AND ".
            "key_hash ~ '^[0-9a-f]{64}$' AND request_hash ~ '^[0-9a-f]{64}$' AND ".
            "status::text = ANY (ARRAY[".
            "'processing'::text, 'completed'::text, 'failed'::text]) AND ".
            'expires_at > created_at AND '.
            "((status = 'completed' AND completed_at IS NOT NULL AND ".
            'resource_type IS NOT NULL AND resource_public_id IS NOT NULL) OR '.
            "(status <> 'completed' AND completed_at IS NULL)))"
        );
    }

    private function addImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_point_immutable_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 point financial record is append-only';
            END;
            $$
        SQL);

        foreach (
            ['point_operations', 'point_ledger_entries', 'point_reconciliation_discrepancies']
            as $table
        ) {
            DB::statement(
                "CREATE TRIGGER {$table}_reject_mutation ".
                "BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_reject_point_immutable_mutation()'
            );
            DB::statement(
                "CREATE TRIGGER {$table}_reject_truncate ".
                "BEFORE TRUNCATE ON {$table} ".
                'FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_point_immutable_mutation()'
            );
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM PUBLIC");
        }
    }
};
