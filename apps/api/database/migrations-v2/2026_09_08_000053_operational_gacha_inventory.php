<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertBackfillIsUnambiguous();

        DB::statement('ALTER TABLE prize_inventories ALTER COLUMN gacha_draw_state_id DROP NOT NULL');
        $this->installPrizeInventoryActivationTrigger(true);
        Schema::table('prize_inventories', function (Blueprint $table): void {
            $table->renameColumn('initial_quantity', 'total_quantity');
            $table->renameColumn('won_count', 'awarded_count');
        });
        Schema::table('prize_inventories', function (Blueprint $table): void {
            $table->unsignedBigInteger('available_quantity')->nullable()
                ->after('awarded_count');
            $table->unsignedBigInteger('withdrawn_quantity')->nullable()
                ->after('available_quantity');
        });
        DB::statement(<<<'SQL'
            UPDATE prize_inventories
            SET available_quantity = total_quantity - awarded_count,
                withdrawn_quantity = 0
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO prize_inventories (
                gacha_draw_state_id,
                gacha_version_prize_id,
                total_quantity,
                awarded_count,
                available_quantity,
                withdrawn_quantity,
                lock_version,
                created_at,
                updated_at
            )
            SELECT NULL,
                relation.id,
                relation.initial_inventory,
                0,
                relation.initial_inventory,
                0,
                0,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM catalog_gacha_version_prizes AS relation
            LEFT JOIN prize_inventories AS inventory
              ON inventory.gacha_version_prize_id = relation.id
            WHERE inventory.id IS NULL
            SQL);
        DB::statement('ALTER TABLE prize_inventories ALTER COLUMN available_quantity SET NOT NULL');
        DB::statement('ALTER TABLE prize_inventories ALTER COLUMN withdrawn_quantity SET NOT NULL');
        DB::statement('ALTER TABLE prize_inventories DROP CONSTRAINT prize_inventory_values_check');
        DB::statement(<<<'SQL'
            ALTER TABLE prize_inventories
            ADD CONSTRAINT prize_inventory_values_check CHECK (
                total_quantity >= 0
                AND awarded_count >= 0
                AND available_quantity >= 0
                AND withdrawn_quantity >= 0
                AND total_quantity = awarded_count + available_quantity + withdrawn_quantity
            )
            SQL);

        Schema::create('prize_inventory_adjustments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('prize_inventory_id')
                ->constrained('prize_inventories')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->uuid('actor_public_id');
            $table->uuid('request_id');
            $table->string('idempotency_key', 255);
            $table->string('reason', 500);
            $table->unsignedBigInteger('before_total_quantity');
            $table->unsignedBigInteger('before_awarded_count');
            $table->unsignedBigInteger('before_available_quantity');
            $table->unsignedBigInteger('before_withdrawn_quantity');
            $table->unsignedBigInteger('before_lock_version');
            $table->unsignedBigInteger('after_total_quantity');
            $table->unsignedBigInteger('after_awarded_count');
            $table->unsignedBigInteger('after_available_quantity');
            $table->unsignedBigInteger('after_withdrawn_quantity');
            $table->unsignedBigInteger('after_lock_version');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['admin_id', 'idempotency_key'],
                'prize_inventory_adjustment_admin_idempotency_unique'
            );
            $table->index(['prize_inventory_id', 'created_at', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE prize_inventory_adjustments
            ADD CONSTRAINT prize_inventory_adjustment_values_check CHECK (
                before_total_quantity >= 0
                AND before_awarded_count >= 0
                AND before_available_quantity >= 0
                AND before_withdrawn_quantity >= 0
                AND before_lock_version >= 0
                AND after_total_quantity >= 0
                AND after_awarded_count >= 0
                AND after_available_quantity >= 0
                AND after_withdrawn_quantity >= 0
                AND after_lock_version >= 0
                AND before_total_quantity = before_awarded_count
                    + before_available_quantity + before_withdrawn_quantity
                AND after_total_quantity = after_awarded_count
                    + after_available_quantity + after_withdrawn_quantity
                AND before_awarded_count = after_awarded_count
                AND after_lock_version = before_lock_version + 1
                AND char_length(btrim(reason)) > 0
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_inventory_adjustment_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'Prize Inventory Adjustment history is append-only';
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER prize_inventory_adjustments_reject_mutation
            BEFORE UPDATE OR DELETE ON prize_inventory_adjustments
            FOR EACH ROW EXECUTE FUNCTION v2_reject_inventory_adjustment_mutation()
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER prize_inventory_adjustments_reject_truncate
            BEFORE TRUNCATE ON prize_inventory_adjustments
            FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_inventory_adjustment_mutation()
            SQL);

        $this->installDrawStateConstraint(true);
        $this->installSalesStateGuard(true);
    }

    public function down(): void
    {
        if (DB::table('prize_inventory_adjustments')->exists()) {
            throw new RuntimeException(
                'Operational Inventory adjustments must be reversed before rollback.'
            );
        }
        if (DB::table('gacha_draw_states')
            ->whereColumn('sold_count', '>', 'total_count')->exists()) {
            throw new RuntimeException(
                'Draw State history cannot be represented by the legacy inventory model.'
            );
        }

        $this->installSalesStateGuard(false);
        $this->installDrawStateConstraint(false);
        DB::statement('DROP TRIGGER IF EXISTS prize_inventory_adjustments_reject_mutation ON prize_inventory_adjustments');
        DB::statement('DROP TRIGGER IF EXISTS prize_inventory_adjustments_reject_truncate ON prize_inventory_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_inventory_adjustment_mutation()');
        Schema::dropIfExists('prize_inventory_adjustments');

        DB::table('prize_inventories')->whereNull('gacha_draw_state_id')->delete();
        DB::statement('ALTER TABLE prize_inventories DROP CONSTRAINT prize_inventory_values_check');
        Schema::table('prize_inventories', function (Blueprint $table): void {
            $table->dropColumn(['available_quantity', 'withdrawn_quantity']);
            $table->renameColumn('total_quantity', 'initial_quantity');
            $table->renameColumn('awarded_count', 'won_count');
        });
        DB::statement('ALTER TABLE prize_inventories ALTER COLUMN gacha_draw_state_id SET NOT NULL');
        $this->installPrizeInventoryActivationTrigger(false);
        DB::statement(<<<'SQL'
            ALTER TABLE prize_inventories
            ADD CONSTRAINT prize_inventory_values_check CHECK (
                won_count <= initial_quantity
            )
            SQL);
    }

    private function assertBackfillIsUnambiguous(): void
    {
        $checks = [
            'Prize Inventory exceeds its total quantity.' => <<<'SQL'
                SELECT 1 FROM prize_inventories
                WHERE won_count > initial_quantity
                LIMIT 1
                SQL,
            'Prize Inventory differs from its immutable Version snapshot.' => <<<'SQL'
                SELECT 1
                FROM prize_inventories AS inventory
                INNER JOIN catalog_gacha_version_prizes AS relation
                  ON relation.id = inventory.gacha_version_prize_id
                WHERE inventory.initial_quantity <> relation.initial_inventory
                LIMIT 1
                SQL,
            'Prize Inventory awarded count differs from successful Draw history.' => <<<'SQL'
                SELECT 1
                FROM prize_inventories AS inventory
                WHERE inventory.won_count <> (
                    SELECT COUNT(*)
                    FROM draw_results AS result
                    WHERE result.gacha_version_prize_id
                        = inventory.gacha_version_prize_id
                      AND result.result_type = 'prize'
                )
                LIMIT 1
                SQL,
            'Prize Inventory awarded count differs from User Prize history.' => <<<'SQL'
                SELECT 1
                FROM prize_inventories AS inventory
                WHERE inventory.won_count <> (
                    SELECT COUNT(*)
                    FROM user_prizes AS ownership
                    WHERE ownership.gacha_version_prize_id
                        = inventory.gacha_version_prize_id
                )
                LIMIT 1
                SQL,
            'Draw State sold count differs from completed Draw execution.' => <<<'SQL'
                SELECT 1
                FROM gacha_draw_states AS state
                WHERE state.sold_count <> (
                    SELECT COALESCE(SUM(request.executed_count), 0)
                    FROM draw_requests AS request
                    WHERE request.gacha_draw_state_id = state.id
                      AND request.status = 'completed'
                )
                OR state.sold_count <> (
                    SELECT COUNT(*)
                    FROM draw_results AS result
                    WHERE result.gacha_draw_state_id = state.id
                )
                LIMIT 1
                SQL,
            'Published Prize Inventory is missing or belongs to another Draw State.' => <<<'SQL'
                SELECT 1
                FROM gacha_draw_states AS state
                INNER JOIN catalog_gacha_version_prizes AS relation
                  ON relation.gacha_version_id = state.gacha_version_id
                LEFT JOIN prize_inventories AS inventory
                  ON inventory.gacha_version_prize_id = relation.id
                WHERE inventory.id IS NULL
                   OR inventory.gacha_draw_state_id <> state.id
                LIMIT 1
                SQL,
            'A missing Prize Inventory already has immutable Draw history.' => <<<'SQL'
                SELECT 1
                FROM catalog_gacha_version_prizes AS relation
                LEFT JOIN prize_inventories AS inventory
                  ON inventory.gacha_version_prize_id = relation.id
                WHERE inventory.id IS NULL
                  AND EXISTS (
                      SELECT 1 FROM draw_results AS result
                      WHERE result.gacha_version_prize_id = relation.id
                  )
                LIMIT 1
                SQL,
        ];
        foreach ($checks as $message => $sql) {
            if (DB::selectOne($sql) !== null) {
                throw new RuntimeException($message);
            }
        }
    }

    private function installDrawStateConstraint(bool $operational): void
    {
        DB::statement('ALTER TABLE gacha_draw_states DROP CONSTRAINT gacha_draw_state_values_check');
        $soldOutRule = $operational
            ? 'sold_out_at IS NOT NULL'
            : 'sold_count = total_count AND sold_out_at IS NOT NULL';
        $soldCountRule = $operational ? '' : 'AND sold_count <= total_count';
        DB::statement(<<<SQL
            ALTER TABLE gacha_draw_states
            ADD CONSTRAINT gacha_draw_state_values_check CHECK (
                total_count > 0
                {$soldCountRule}
                AND CASE status::text
                    WHEN 'sold_out' THEN
                        {$soldOutRule}
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    WHEN 'closed' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NOT NULL
                        AND close_reason::text = ANY (ARRAY[
                            'schedule_cancelled'::text,
                            'superseded'::text
                        ])
                    WHEN 'selling' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    WHEN 'paused' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    ELSE FALSE
                END
            )
            SQL);
    }

    private function installSalesStateGuard(bool $operational): void
    {
        $inventoryGuard = $operational
            ? <<<'SQL'
                    NOT EXISTS (
                        SELECT 1
                        FROM prize_inventories AS inventory
                        WHERE inventory.gacha_draw_state_id = state_row.id
                          AND inventory.available_quantity > 0
                    )
                SQL
            : 'state_row.sold_count >= state_row.total_count';
        $drawStateInvalid = $operational
            ? <<<'SQL'
                    NOT (
                        state_row.status::text = 'selling'::text
                        OR (
                            state_row.status::text = 'sold_out'::text
                            AND EXISTS (
                                SELECT 1
                                FROM prize_inventories AS inventory
                                WHERE inventory.gacha_draw_state_id = state_row.id
                                  AND inventory.available_quantity > 0
                            )
                        )
                    )
                SQL
            : "state_row.status::text <> 'selling'::text";
        DB::unprepared(str_replace(
            ['__INVENTORY_GUARD__', '__DRAW_STATE_INVALID__'],
            [$inventoryGuard, $drawStateInvalid],
            <<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_sales_state_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE state_row gacha_draw_states%ROWTYPE;
            DECLARE gacha_row catalog_gachas%ROWTYPE;
            DECLARE probability_row catalog_probability_versions%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'draw_requests' THEN
                    SELECT g.* INTO gacha_row
                    FROM catalog_gachas AS g
                    INNER JOIN gacha_draw_states AS state ON state.gacha_id = g.id
                    WHERE state.id = NEW.gacha_draw_state_id;
                    IF gacha_row.id IS NULL OR gacha_row.sales_paused = TRUE THEN
                        RAISE EXCEPTION 'Paused Gacha cannot accept a new Draw Request';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.sales_paused_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Gacha Sales operation history cannot be deleted';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                   OR NEW.sales_paused_at IS DISTINCT FROM OLD.sales_paused_at
                   OR NEW.sales_paused_by_admin_public_id IS DISTINCT FROM OLD.sales_paused_by_admin_public_id
                   OR NEW.sales_pause_reason_code IS DISTINCT FROM OLD.sales_pause_reason_code
                   OR NEW.sales_resumed_at IS DISTINCT FROM OLD.sales_resumed_at
                   OR NEW.sales_last_mutation_request_id IS DISTINCT FROM OLD.sales_last_mutation_request_id THEN
                    IF NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                       OR NEW.public_id IS DISTINCT FROM OLD.public_id
                       OR NEW.code IS DISTINCT FROM OLD.code
                       OR NEW.slug IS DISTINCT FROM OLD.slug
                       OR NEW.category_id IS DISTINCT FROM OLD.category_id
                       OR NEW.state IS DISTINCT FROM OLD.state
                       OR NEW.sold_count IS DISTINCT FROM OLD.sold_count
                       OR NEW.published_version_id IS DISTINCT FROM OLD.published_version_id
                       OR NEW.active_draw_state_id IS DISTINCT FROM OLD.active_draw_state_id
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                        RAISE EXCEPTION 'Gacha Sales state requires one Revision transition';
                    END IF;
                    IF NEW.published_version_id IS NULL
                       OR NEW.active_draw_state_id IS NULL
                       OR NEW.archived_at IS NOT NULL
                       OR NEW.state::text <> 'active'::text THEN
                        RAISE EXCEPTION 'Gacha Sales state requires an active Published Gacha';
                    END IF;
                    SELECT * INTO version_row FROM catalog_gacha_versions
                    WHERE id = NEW.published_version_id;
                    SELECT * INTO state_row FROM gacha_draw_states
                    WHERE id = NEW.active_draw_state_id;
                    SELECT * INTO probability_row FROM catalog_probability_versions
                    WHERE id = version_row.published_probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.id
                       OR version_row.status::text <> 'published'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.published_at IS NULL
                       OR state_row.id IS NULL
                       OR state_row.gacha_id IS DISTINCT FROM NEW.id
                       OR state_row.gacha_version_id IS DISTINCT FROM version_row.id
                       OR state_row.probability_version_id IS DISTINCT FROM probability_row.id
                       OR __DRAW_STATE_INVALID__
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'Gacha Sales state requires matching active Draw references';
                    END IF;
                    IF NEW.sales_paused = FALSE THEN
                        IF __INVENTORY_GUARD__
                           OR COALESCE(NEW.current_publish_start_at, version_row.publish_start_at) > CURRENT_TIMESTAMP
                           OR (NEW.current_publish_end_at IS NOT NULL AND NEW.current_publish_end_at <= CURRENT_TIMESTAMP)
                           OR EXISTS (
                               SELECT 1 FROM catalog_gacha_publish_schedules
                               WHERE gacha_id = NEW.id AND status::text = 'processing'::text
                           ) THEN
                            RAISE EXCEPTION 'Gacha Sales Resume preflight failed';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL
        ));
    }

    private function installPrizeInventoryActivationTrigger(bool $operational): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS prize_inventories_validate_activation ON prize_inventories'
        );
        $when = $operational
            ? ' WHEN (NEW.gacha_draw_state_id IS NOT NULL)'
            : '';
        DB::statement(
            'CREATE TRIGGER prize_inventories_validate_activation '.
            'BEFORE INSERT OR UPDATE OF gacha_draw_state_id, '.
            'gacha_version_prize_id ON prize_inventories FOR EACH ROW'.
            $when.' EXECUTE FUNCTION v2_validate_gacha_activation()'
        );
    }
};
