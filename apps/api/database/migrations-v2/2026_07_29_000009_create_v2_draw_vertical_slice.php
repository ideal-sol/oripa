<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_draw_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('gacha_id')->unique()
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('gacha_version_id')->unique()
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('probability_version_id')->unique()
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->string('status', 16)->default('selling');
            $table->unsignedBigInteger('total_count');
            $table->unsignedBigInteger('sold_count')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('sold_out_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('prize_inventories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('gacha_draw_state_id')
                ->constrained('gacha_draw_states')->restrictOnDelete();
            $table->foreignId('gacha_version_prize_id')->unique()
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->unsignedBigInteger('initial_quantity');
            $table->unsignedBigInteger('won_count')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestampsTz();
            $table->index(['gacha_draw_state_id', 'id']);
        });

        Schema::create('draw_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gacha_draw_state_id')
                ->constrained('gacha_draw_states')->restrictOnDelete();
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('probability_version_id')
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->foreignId('idempotency_record_id')->unique()
                ->constrained('idempotency_records')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->char('catalog_snapshot_sha256', 64);
            $table->unsignedSmallInteger('requested_count');
            $table->unsignedSmallInteger('executed_count')->default(0);
            $table->bigInteger('point_cost_total');
            $table->bigInteger('consumed_paid_points')->default(0);
            $table->bigInteger('consumed_free_points')->default(0);
            $table->bigInteger('wallet_paid_after')->nullable();
            $table->bigInteger('wallet_free_after')->nullable();
            $table->bigInteger('point_back_total')->default(0);
            $table->string('status', 16)->default('processing');
            $table->unsignedInteger('processing_duration_ms')->nullable();
            $table->jsonb('response_data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['gacha_draw_state_id', 'created_at', 'id']);
        });

        Schema::create('draw_results', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('draw_request_id')
                ->constrained('draw_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gacha_draw_state_id')
                ->constrained('gacha_draw_states')->restrictOnDelete();
            $table->foreignId('probability_version_id')
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->foreignId('probability_stage_id')
                ->constrained('catalog_probability_stages')->restrictOnDelete();
            $table->unsignedSmallInteger('request_sequence');
            $table->unsignedBigInteger('draw_sequence_number');
            $table->string('result_type', 24);
            $table->foreignId('gacha_version_prize_id')->nullable()
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->foreignId('rank_id')->nullable()
                ->constrained('catalog_ranks')->restrictOnDelete();
            $table->bigInteger('consumed_points');
            $table->bigInteger('point_back_amount')->default(0);
            $table->unsignedInteger('random_value');
            $table->jsonb('display_snapshot');
            $table->char('display_snapshot_sha256', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['draw_request_id', 'request_sequence']);
            $table->unique(
                ['gacha_draw_state_id', 'draw_sequence_number'],
                'draw_results_gacha_sequence_unique'
            );
            $table->index(['user_id', 'occurred_at', 'id']);
        });

        Schema::create('user_prizes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('draw_result_id')->unique()
                ->constrained('draw_results')->restrictOnDelete();
            $table->foreignId('gacha_version_prize_id')
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->string('status', 16)->default('stored');
            $table->timestampTz('acquired_at');
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'acquired_at', 'id']);
        });

        Schema::create('payment_adjustment_prize_actions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_adjustment_id')
                ->constrained('payment_adjustments')->restrictOnDelete();
            $table->foreignId('user_prize_id')
                ->constrained('user_prizes')->restrictOnDelete();
            $table->string('action_type', 32);
            $table->string('status', 24);
            $table->string('previous_shipping_status', 32)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('hold_released_at')->nullable();
            $table->string('mail_status', 24)->default('not_requested');
            $table->string('failure_reason', 191)->nullable();
            $table->timestampsTz();
            $table->unique(['payment_adjustment_id', 'user_prize_id', 'action_type']);
        });

        $this->constraints();
        $this->immutableHistory();
    }

    public function down(): void
    {
        foreach (['draw_results', 'user_prizes'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_mutation ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_truncate ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_draw_history_mutation()');
        Schema::dropIfExists('payment_adjustment_prize_actions');
        Schema::dropIfExists('user_prizes');
        Schema::dropIfExists('draw_results');
        Schema::dropIfExists('draw_requests');
        Schema::dropIfExists('prize_inventories');
        Schema::dropIfExists('gacha_draw_states');
    }

    private function constraints(): void
    {
        DB::statement(
            "ALTER TABLE gacha_draw_states ADD CONSTRAINT gacha_draw_state_values_check CHECK (".
            "status::text = ANY (ARRAY['selling'::text,'paused'::text,'sold_out'::text]) AND ".
            'total_count > 0 AND sold_count <= total_count AND '.
            "((status = 'sold_out' AND sold_count = total_count AND sold_out_at IS NOT NULL) OR ".
            "(status <> 'sold_out' AND sold_out_at IS NULL)))"
        );
        DB::statement(
            'ALTER TABLE prize_inventories ADD CONSTRAINT prize_inventory_values_check CHECK ('.
            'won_count <= initial_quantity)'
        );
        DB::statement(
            "ALTER TABLE draw_requests ADD CONSTRAINT draw_request_values_check CHECK (".
            "requested_count = ANY (ARRAY[1,5,10,100,1000]) AND ".
            'executed_count <= requested_count AND point_cost_total > 0 AND '.
            'consumed_paid_points >= 0 AND consumed_free_points >= 0 AND '.
            'point_back_total >= 0 AND request_hash ~ \'^[0-9a-f]{64}$\' AND '.
            "catalog_snapshot_sha256 ~ '^[0-9a-f]{64}$' AND ".
            "status::text = ANY (ARRAY['processing'::text,'completed'::text]) AND ".
            "((status = 'completed' AND executed_count = requested_count AND ".
            'wallet_paid_after IS NOT NULL AND wallet_free_after IS NOT NULL AND '.
            'processing_duration_ms IS NOT NULL AND response_data IS NOT NULL AND '.
            'completed_at IS NOT NULL) OR '.
            "(status = 'processing' AND executed_count = 0 AND completed_at IS NULL)))"
        );
        DB::statement(
            "ALTER TABLE draw_results ADD CONSTRAINT draw_result_values_check CHECK (".
            'request_sequence > 0 AND draw_sequence_number > 0 AND consumed_points > 0 AND '.
            'point_back_amount >= 0 AND random_value <= 999999 AND '.
            "display_snapshot_sha256 ~ '^[0-9a-f]{64}$' AND ".
            "jsonb_typeof(display_snapshot) = 'object' AND ".
            "((result_type = 'prize' AND gacha_version_prize_id IS NOT NULL AND ".
            'rank_id IS NOT NULL AND point_back_amount = 0) OR '.
            "(result_type = 'point_back' AND gacha_version_prize_id IS NULL AND ".
            'rank_id IS NULL AND point_back_amount >= 0)))'
        );
        DB::statement(
            "ALTER TABLE user_prizes ADD CONSTRAINT user_prize_status_check ".
            "CHECK (status = 'stored')"
        );
        DB::statement(
            "ALTER TABLE payment_adjustment_prize_actions ".
            "ADD CONSTRAINT payment_adjustment_prize_action_values_check CHECK (".
            "action_type::text = ANY (ARRAY[".
            "'hold'::text,'return_request'::text,'release_hold'::text,'mark_returned'::text]) AND ".
            "status::text = ANY (ARRAY[".
            "'pending'::text,'completed'::text,'failed'::text,'manual_review'::text]) AND ".
            "mail_status::text = ANY (ARRAY[".
            "'not_requested'::text,'pending'::text,'sent'::text,'failed'::text]))"
        );
    }

    private function immutableHistory(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_draw_history_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 draw ownership history is append-only';
            END;
            $$
        SQL);
        foreach (['draw_results', 'user_prizes'] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_reject_mutation ".
                "BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_reject_draw_history_mutation()'
            );
            DB::statement(
                "CREATE TRIGGER {$table}_reject_truncate ".
                "BEFORE TRUNCATE ON {$table} ".
                'FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_draw_history_mutation()'
            );
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM PUBLIC");
        }
    }
};
