<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_lots', function (Blueprint $table): void {
            $table->boolean('legacy_no_expiry')->default(false);
        });
        Schema::table('point_adjustments', function (Blueprint $table): void {
            $table->boolean('legacy_no_expiry')->default(false);
        });
        Schema::table('point_balance_snapshots', function (Blueprint $table): void {
            $table->bigInteger('expired_paid_amount')->default(0);
        });

        DB::table('point_lots')
            ->where('point_type', 'paid')
            ->whereNull('expire_at')
            ->update(['legacy_no_expiry' => true]);
        DB::table('point_adjustments')
            ->where('direction', 'grant')
            ->where('point_type', 'paid')
            ->whereNull('expire_at')
            ->update(['legacy_no_expiry' => true]);

        DB::statement('ALTER TABLE point_lots DROP CONSTRAINT point_lots_type_amount_expiry_check');
        DB::statement(
            "ALTER TABLE point_lots ADD CONSTRAINT point_lots_type_amount_expiry_check CHECK (".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND ".
            'granted_amount > 0 AND remaining_amount >= 0 AND reserved_amount >= 0 AND '.
            'reserved_amount <= remaining_amount AND remaining_amount <= granted_amount AND '.
            '((expire_at IS NOT NULL AND legacy_no_expiry = false) OR '.
            "(point_type = 'paid' AND expire_at IS NULL AND legacy_no_expiry = true)))"
        );
        DB::statement('ALTER TABLE point_adjustments DROP CONSTRAINT point_adjustments_values_check');
        DB::statement(
            "ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_values_check CHECK (".
            "direction::text = ANY (ARRAY['grant'::text, 'deduct'::text]) AND ".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND amount > 0 AND ".
            "status::text = ANY (ARRAY['requested'::text, 'approved'::text, 'executed'::text, 'rejected'::text]) AND ".
            "((direction = 'grant' AND ((expire_at IS NOT NULL AND legacy_no_expiry = false) OR ".
            "(point_type = 'paid' AND expire_at IS NULL AND legacy_no_expiry = true))) OR ".
            "(direction = 'deduct' AND expire_at IS NULL AND legacy_no_expiry = false)) AND ".
            "((status = 'executed' AND point_operation_id IS NOT NULL AND executed_at IS NOT NULL) OR ".
            "(status <> 'executed' AND point_operation_id IS NULL AND executed_at IS NULL)))"
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_new_null_point_expiry() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.expire_at IS NULL OR NEW.legacy_no_expiry THEN
                    RAISE EXCEPTION 'new point lots require a finite expiry';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER point_lots_new_expiry_guard
            BEFORE INSERT ON point_lots
            FOR EACH ROW EXECUTE FUNCTION v2_reject_new_null_point_expiry()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_point_expiry_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.expire_at IS DISTINCT FROM OLD.expire_at
                    OR NEW.legacy_no_expiry IS DISTINCT FROM OLD.legacy_no_expiry THEN
                    RAISE EXCEPTION 'point lot expiry is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER point_lots_expiry_immutable_guard
            BEFORE UPDATE OF expire_at, legacy_no_expiry ON point_lots
            FOR EACH ROW EXECUTE FUNCTION v2_reject_point_expiry_mutation()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_new_null_adjustment_grant_expiry() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.legacy_no_expiry
                    OR (NEW.direction = 'grant' AND NEW.expire_at IS NULL)
                    OR (NEW.direction = 'deduct' AND NEW.expire_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'new point adjustment expiry is invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER point_adjustments_new_expiry_guard
            BEFORE INSERT ON point_adjustments
            FOR EACH ROW EXECUTE FUNCTION v2_reject_new_null_adjustment_grant_expiry()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_adjustment_expiry_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.expire_at IS DISTINCT FROM OLD.expire_at
                    OR NEW.legacy_no_expiry IS DISTINCT FROM OLD.legacy_no_expiry THEN
                    RAISE EXCEPTION 'point adjustment expiry is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER point_adjustments_expiry_immutable_guard
            BEFORE UPDATE OF expire_at, legacy_no_expiry ON point_adjustments
            FOR EACH ROW EXECUTE FUNCTION v2_reject_adjustment_expiry_mutation()
        SQL);

        DB::statement('DROP INDEX point_lots_consumption_order');
        DB::statement(
            'CREATE INDEX point_lots_fefo_consumption_order ON point_lots '.
            '(user_id, expire_at ASC NULLS LAST, granted_at ASC, id ASC) '.
            'WHERE remaining_amount > reserved_amount'
        );
        DB::statement(
            'CREATE INDEX point_lots_expiration_candidates ON point_lots '.
            '(expire_at ASC, user_id ASC, id ASC) '.
            'WHERE expire_at IS NOT NULL AND remaining_amount > 0'
        );

        DB::statement('ALTER TABLE point_balance_snapshots DROP CONSTRAINT point_snapshots_values_check');
        DB::statement(
            "ALTER TABLE point_balance_snapshots ADD CONSTRAINT point_snapshots_values_check CHECK (".
            "calculation_method = 'ledger_cutoff' AND ".
            'opening_paid_balance >= 0 AND opening_free_balance >= 0 AND '.
            'granted_paid_amount >= 0 AND granted_free_amount >= 0 AND '.
            'consumed_paid_amount >= 0 AND consumed_free_amount >= 0 AND '.
            'expired_paid_amount >= 0 AND expired_free_amount >= 0 AND '.
            'reversed_paid_amount >= 0 AND reversed_free_amount >= 0 AND '.
            'closing_paid_balance >= 0 AND closing_free_balance >= 0 AND '.
            'paid_reserved_balance >= 0 AND free_reserved_balance >= 0 AND '.
            "checksum ~ '^[0-9a-f]{64}$' AND ".
            '(is_base_date = ((EXTRACT(MONTH FROM snapshot_date) = 3 AND '.
            'EXTRACT(DAY FROM snapshot_date) = 31) OR '.
            '(EXTRACT(MONTH FROM snapshot_date) = 9 AND '.
            'EXTRACT(DAY FROM snapshot_date) = 30))))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE point_balance_snapshots DROP CONSTRAINT point_snapshots_values_check');
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
            '(is_base_date = ((EXTRACT(MONTH FROM snapshot_date) = 3 AND '.
            'EXTRACT(DAY FROM snapshot_date) = 31) OR '.
            '(EXTRACT(MONTH FROM snapshot_date) = 9 AND '.
            'EXTRACT(DAY FROM snapshot_date) = 30))))'
        );
        Schema::table('point_balance_snapshots', function (Blueprint $table): void {
            $table->dropColumn('expired_paid_amount');
        });

        DB::statement('DROP INDEX point_lots_expiration_candidates');
        DB::statement('DROP INDEX point_lots_fefo_consumption_order');
        DB::statement(
            'CREATE INDEX point_lots_consumption_order ON point_lots '.
            '(user_id, point_type, expire_at, granted_at, id) '.
            'WHERE remaining_amount > reserved_amount'
        );

        DB::statement('DROP TRIGGER point_adjustments_expiry_immutable_guard ON point_adjustments');
        DB::statement('DROP FUNCTION v2_reject_adjustment_expiry_mutation()');
        DB::statement('DROP TRIGGER point_adjustments_new_expiry_guard ON point_adjustments');
        DB::statement('DROP FUNCTION v2_reject_new_null_adjustment_grant_expiry()');
        DB::statement('DROP TRIGGER point_lots_expiry_immutable_guard ON point_lots');
        DB::statement('DROP FUNCTION v2_reject_point_expiry_mutation()');
        DB::statement('DROP TRIGGER point_lots_new_expiry_guard ON point_lots');
        DB::statement('DROP FUNCTION v2_reject_new_null_point_expiry()');

        DB::statement('ALTER TABLE point_adjustments DROP CONSTRAINT point_adjustments_values_check');
        DB::statement(
            "ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_values_check CHECK (".
            "direction::text = ANY (ARRAY['grant'::text, 'deduct'::text]) AND ".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND amount > 0 AND ".
            "status::text = ANY (ARRAY['requested'::text, 'approved'::text, 'executed'::text, 'rejected'::text]) AND ".
            "((point_type = 'paid' AND expire_at IS NULL) OR ".
            "(point_type = 'free' AND direction = 'grant' AND expire_at IS NOT NULL) OR ".
            "(point_type = 'free' AND direction = 'deduct')) AND ".
            "((status = 'executed' AND point_operation_id IS NOT NULL AND executed_at IS NOT NULL) OR ".
            "(status <> 'executed' AND point_operation_id IS NULL AND executed_at IS NULL)))"
        );
        DB::statement('ALTER TABLE point_lots DROP CONSTRAINT point_lots_type_amount_expiry_check');
        DB::statement(
            "ALTER TABLE point_lots ADD CONSTRAINT point_lots_type_amount_expiry_check CHECK (".
            "point_type::text = ANY (ARRAY['paid'::text, 'free'::text]) AND ".
            'granted_amount > 0 AND remaining_amount >= 0 AND reserved_amount >= 0 AND '.
            'reserved_amount <= remaining_amount AND remaining_amount <= granted_amount AND '.
            "((point_type = 'paid' AND expire_at IS NULL) OR ".
            "(point_type = 'free' AND expire_at IS NOT NULL)))"
        );
        Schema::table('point_adjustments', function (Blueprint $table): void {
            $table->dropColumn('legacy_no_expiry');
        });
        Schema::table('point_lots', function (Blueprint $table): void {
            $table->dropColumn('legacy_no_expiry');
        });
    }
};
