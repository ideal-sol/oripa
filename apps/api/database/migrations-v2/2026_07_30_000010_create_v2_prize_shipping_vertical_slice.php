<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS user_prizes_reject_mutation ON user_prizes');
        DB::statement('DROP TRIGGER IF EXISTS user_prizes_reject_truncate ON user_prizes');
        DB::statement('ALTER TABLE user_prizes DROP CONSTRAINT user_prize_status_check');
        DB::statement('ALTER TABLE user_prizes ALTER COLUMN status TYPE varchar(32)');

        Schema::table('user_prizes', function (Blueprint $table): void {
            $table->bigInteger('exchange_point_snapshot')->nullable()->after('status');
            $table->bigInteger('exchanged_point_amount')->nullable()
                ->after('exchange_point_snapshot');
            $table->timestampTz('storage_expires_at')->nullable()->after('acquired_at');
            $table->timestampTz('terminal_at')->nullable()->after('storage_expires_at');
        });
        DB::statement(<<<'SQL'
            UPDATE user_prizes AS user_prize
            SET exchange_point_snapshot = prize.exchange_points,
                storage_expires_at = user_prize.acquired_at + INTERVAL '60 days'
            FROM catalog_gacha_version_prizes AS relation
            JOIN catalog_prizes AS prize ON prize.id = relation.prize_id
            WHERE relation.id = user_prize.gacha_version_prize_id
            SQL);
        DB::statement('ALTER TABLE user_prizes ALTER COLUMN exchange_point_snapshot SET NOT NULL');
        DB::statement('ALTER TABLE user_prizes ALTER COLUMN storage_expires_at SET NOT NULL');

        Schema::create('user_prize_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_prize_id')
                ->constrained('user_prizes')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 16);
            $table->uuid('actor_public_id')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('reason_code', 64);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_prize_id', 'occurred_at', 'id']);
        });

        Schema::create('prize_exchange_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('idempotency_record_id')->unique()
                ->constrained('idempotency_records')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->unsignedInteger('requested_count');
            $table->bigInteger('exchange_point_total')->default(0);
            $table->foreignId('point_operation_id')->nullable()->unique()
                ->constrained('point_operations')->restrictOnDelete();
            $table->jsonb('response_data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->index(['user_id', 'created_at', 'id']);
        });

        Schema::create('prize_exchange_request_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('prize_exchange_request_id')
                ->constrained('prize_exchange_requests')->restrictOnDelete();
            $table->foreignId('user_prize_id')->unique()
                ->constrained('user_prizes')->restrictOnDelete();
            $table->bigInteger('exchange_point_snapshot');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['prize_exchange_request_id', 'user_prize_id'],
                'prize_exchange_request_item_unique'
            );
        });

        Schema::create('shipping_addresses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('recipient_name_ciphertext');
            $table->text('postal_code_ciphertext');
            $table->text('prefecture_ciphertext');
            $table->text('city_ciphertext');
            $table->text('street_ciphertext');
            $table->text('building_ciphertext')->nullable();
            $table->text('phone_number_ciphertext');
            $table->char('correlation_hash', 64);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'deleted_at', 'id']);
        });

        Schema::create('shipping_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shipping_address_id')->nullable()
                ->constrained('shipping_addresses')->nullOnDelete();
            $table->foreignId('idempotency_record_id')->unique()
                ->constrained('idempotency_records')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->string('status', 32)->default('requested');
            $table->text('recipient_name_ciphertext');
            $table->text('postal_code_ciphertext');
            $table->text('prefecture_ciphertext');
            $table->text('city_ciphertext');
            $table->text('street_ciphertext');
            $table->text('building_ciphertext')->nullable();
            $table->text('phone_number_ciphertext');
            $table->char('address_snapshot_hash', 64);
            $table->string('carrier_code', 64)->nullable();
            $table->text('tracking_number_ciphertext')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->jsonb('response_data')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'requested_at', 'id']);
            $table->index(['status', 'requested_at', 'id']);
        });

        Schema::create('shipping_request_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('shipping_request_id')
                ->constrained('shipping_requests')->restrictOnDelete();
            $table->foreignId('user_prize_id')->unique()
                ->constrained('user_prizes')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['shipping_request_id', 'user_prize_id'],
                'shipping_request_item_unique'
            );
        });

        Schema::create('shipping_request_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('shipping_request_id')
                ->constrained('shipping_requests')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 16);
            $table->uuid('actor_public_id')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('reason_code', 64);
            $table->string('carrier_code', 64)->nullable();
            $table->char('tracking_correlation_hash', 64)->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['shipping_request_id', 'occurred_at', 'id']);
        });

        $this->constraints();
        $this->guards();
    }

    public function down(): void
    {
        foreach ([
            'shipping_request_status_histories',
            'user_prize_status_histories',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_mutation ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_truncate ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS user_prizes_protect_ownership ON user_prizes');
        DB::statement('DROP TRIGGER IF EXISTS user_prizes_reject_truncate ON user_prizes');
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_prize_shipping_history_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS v2_protect_user_prize_ownership()');

        Schema::dropIfExists('shipping_request_status_histories');
        Schema::dropIfExists('shipping_request_items');
        Schema::dropIfExists('shipping_requests');
        Schema::dropIfExists('shipping_addresses');
        Schema::dropIfExists('prize_exchange_request_items');
        Schema::dropIfExists('prize_exchange_requests');
        Schema::dropIfExists('user_prize_status_histories');

        if (DB::table('user_prizes')->where('status', '<>', 'stored')->exists()) {
            throw new RuntimeException(
                'MIG-052 rollback requires all user prizes to remain in stored status.'
            );
        }
        Schema::table('user_prizes', function (Blueprint $table): void {
            $table->dropColumn([
                'exchange_point_snapshot',
                'exchanged_point_amount',
                'storage_expires_at',
                'terminal_at',
            ]);
        });
        DB::statement('ALTER TABLE user_prizes ALTER COLUMN status TYPE varchar(16)');
        DB::statement(
            "ALTER TABLE user_prizes ADD CONSTRAINT user_prize_status_check CHECK (status = 'stored')"
        );
        DB::statement(
            'CREATE TRIGGER user_prizes_reject_mutation BEFORE UPDATE OR DELETE ON user_prizes '.
            'FOR EACH ROW EXECUTE FUNCTION v2_reject_draw_history_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER user_prizes_reject_truncate BEFORE TRUNCATE ON user_prizes '.
            'FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_draw_history_mutation()'
        );
    }

    private function constraints(): void
    {
        DB::statement(
            "ALTER TABLE user_prizes ADD CONSTRAINT user_prize_status_check CHECK (".
            "status::text = ANY (ARRAY[".
            "'stored'::text,'exchange_processing'::text,'converted'::text,".
            "'shipping_requested'::text,'packing'::text,'shipped'::text,".
            "'delivered'::text,'hold'::text,'return_requested'::text,".
            "'returned'::text,'expired'::text,'canceled'::text]) AND ".
            'exchange_point_snapshot >= 0 AND '.
            '(exchanged_point_amount IS NULL OR exchanged_point_amount >= 0) AND '.
            'storage_expires_at > acquired_at AND '.
            "((status = 'converted' AND exchanged_point_amount IS NOT NULL) OR ".
            "(status <> 'converted')))"
        );
        DB::statement(
            "ALTER TABLE prize_exchange_requests ADD CONSTRAINT prize_exchange_request_values_check CHECK (".
            "status::text = ANY (ARRAY['processing'::text,'completed'::text]) AND ".
            'requested_count > 0 AND exchange_point_total >= 0 AND '.
            "request_hash ~ '^[0-9a-f]{64}$' AND ".
            "((status = 'completed' AND point_operation_id IS NOT NULL AND ".
            'response_data IS NOT NULL AND completed_at IS NOT NULL) OR '.
            "(status = 'processing' AND point_operation_id IS NULL AND completed_at IS NULL)))"
        );
        DB::statement(
            'ALTER TABLE prize_exchange_request_items ADD CONSTRAINT '.
            'prize_exchange_item_points_check CHECK (exchange_point_snapshot >= 0)'
        );
        DB::statement(
            "ALTER TABLE shipping_requests ADD CONSTRAINT shipping_request_values_check CHECK (".
            "status::text = ANY (ARRAY[".
            "'requested'::text,'packing'::text,'shipped'::text,'delivered'::text,".
            "'hold'::text,'return_requested'::text,'returned'::text,'canceled'::text]) AND ".
            "request_hash ~ '^[0-9a-f]{64}$' AND address_snapshot_hash ~ '^[0-9a-f]{64}$' AND ".
            "((status::text = ANY (ARRAY['shipped'::text,'delivered'::text,'returned'::text]) ".
            'AND carrier_code IS NOT NULL '.
            'AND tracking_number_ciphertext IS NOT NULL AND shipped_at IS NOT NULL) OR '.
            "status::text <> ALL (ARRAY['shipped'::text,'delivered'::text,'returned'::text])) AND ".
            "((status::text = ANY (ARRAY['delivered'::text,'returned'::text,'canceled'::text]) ".
            'AND terminal_at IS NOT NULL) OR '.
            "status::text <> ALL (ARRAY['delivered'::text,'returned'::text,'canceled'::text])))"
        );
        DB::statement(
            "ALTER TABLE shipping_addresses ADD CONSTRAINT shipping_address_hash_check ".
            "CHECK (correlation_hash ~ '^[0-9a-f]{64}$')"
        );
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_protect_user_prize_ownership()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR
                   (NEW.public_id, NEW.user_id, NEW.draw_result_id, NEW.gacha_version_prize_id,
                    NEW.acquired_at, NEW.exchange_point_snapshot, NEW.storage_expires_at)
                   IS DISTINCT FROM
                   (OLD.public_id, OLD.user_id, OLD.draw_result_id, OLD.gacha_version_prize_id,
                    OLD.acquired_at, OLD.exchange_point_snapshot, OLD.storage_expires_at)
                THEN
                    RAISE EXCEPTION 'V2 user prize ownership and snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER user_prizes_protect_ownership BEFORE UPDATE OR DELETE ON user_prizes '.
            'FOR EACH ROW EXECUTE FUNCTION v2_protect_user_prize_ownership()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_prize_shipping_history_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 prize and shipping history is append-only';
            END;
            $$
        SQL);
        foreach ([
            'user_prize_status_histories',
            'shipping_request_status_histories',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_reject_mutation BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_reject_prize_shipping_history_mutation()'
            );
            DB::statement(
                "CREATE TRIGGER {$table}_reject_truncate BEFORE TRUNCATE ON {$table} ".
                'FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_prize_shipping_history_mutation()'
            );
        }
        DB::statement(
            'CREATE TRIGGER user_prizes_reject_truncate BEFORE TRUNCATE ON user_prizes '.
            'FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_prize_shipping_history_mutation()'
        );
    }
};
