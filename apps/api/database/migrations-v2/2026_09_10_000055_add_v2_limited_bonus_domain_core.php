<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_purchase_plan_limited_bonus_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('point_purchase_plan_id')
                ->constrained('point_purchase_plans')->restrictOnDelete();
            $table->boolean('is_enabled');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->bigInteger('bonus_point_amount');
            $table->timestampsTz();
            $table->index(
                ['point_purchase_plan_id', 'starts_at', 'ends_at'],
                'limited_bonus_campaign_plan_period_index'
            );
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('limited_bonus_point_amount')->default(0);
        });

        Schema::create('payment_limited_bonus_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->uuid('campaign_public_id');
            $table->boolean('is_enabled');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->bigInteger('bonus_point_amount');
            $table->timestampTz('snapshotted_at');
            $table->unique(['payment_id', 'campaign_public_id'], 'payment_limited_bonus_snapshot_unique');
            $table->index(
                ['payment_id', 'is_enabled', 'starts_at', 'ends_at'],
                'payment_limited_bonus_applicability_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE point_purchase_plan_limited_bonus_campaigns
            ADD CONSTRAINT limited_bonus_campaign_values_check CHECK (
                starts_at < ends_at
                AND bonus_point_amount > 0
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE payment_limited_bonus_snapshots
            ADD CONSTRAINT payment_limited_bonus_snapshot_values_check CHECK (
                starts_at < ends_at
                AND bonus_point_amount > 0
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_limited_bonus_values_check CHECK (
                limited_bonus_point_amount >= 0
                AND (status = 'succeeded' OR limited_bonus_point_amount = 0)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_limited_bonus_campaign_overlap() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.point_purchase_plan_id <> OLD.point_purchase_plan_id THEN
                    RAISE EXCEPTION 'limited bonus campaign plan version is immutable';
                END IF;

                PERFORM pg_advisory_xact_lock(
                    hashtextextended(
                        'v2_limited_bonus_campaign:' || NEW.point_purchase_plan_id::text,
                        0
                    )
                );

                IF EXISTS (
                    SELECT 1
                    FROM point_purchase_plan_limited_bonus_campaigns campaign
                    WHERE campaign.point_purchase_plan_id = NEW.point_purchase_plan_id
                      AND campaign.id <> COALESCE(NEW.id, 0)
                      AND tstzrange(campaign.starts_at, campaign.ends_at, '[)')
                          && tstzrange(NEW.starts_at, NEW.ends_at, '[)')
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23P01',
                        MESSAGE = 'limited bonus campaign periods must not overlap';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER limited_bonus_campaign_overlap_guard
            BEFORE INSERT OR UPDATE OF point_purchase_plan_id, starts_at, ends_at
            ON point_purchase_plan_limited_bonus_campaigns
            FOR EACH ROW EXECUTE FUNCTION v2_guard_limited_bonus_campaign_overlap()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_payment_limited_bonus_snapshot_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payment limited bonus snapshots are append-only';
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER payment_limited_bonus_snapshots_reject_mutation
            BEFORE UPDATE OR DELETE ON payment_limited_bonus_snapshots
            FOR EACH ROW EXECUTE FUNCTION v2_reject_payment_limited_bonus_snapshot_mutation()
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER payment_limited_bonus_snapshots_reject_truncate
            BEFORE TRUNCATE ON payment_limited_bonus_snapshots
            EXECUTE FUNCTION v2_reject_payment_limited_bonus_snapshot_mutation()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_protect_final_payment_limited_bonus() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'succeeded'
                    AND NEW.limited_bonus_point_amount IS DISTINCT FROM OLD.limited_bonus_point_amount THEN
                    RAISE EXCEPTION 'final payment limited bonus is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER payments_limited_bonus_final_guard
            BEFORE UPDATE OF limited_bonus_point_amount ON payments
            FOR EACH ROW EXECUTE FUNCTION v2_protect_final_payment_limited_bonus()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER payments_limited_bonus_final_guard ON payments');
        DB::statement('DROP FUNCTION v2_protect_final_payment_limited_bonus()');
        DB::statement('DROP TRIGGER payment_limited_bonus_snapshots_reject_truncate ON payment_limited_bonus_snapshots');
        DB::statement('DROP TRIGGER payment_limited_bonus_snapshots_reject_mutation ON payment_limited_bonus_snapshots');
        DB::statement('DROP FUNCTION v2_reject_payment_limited_bonus_snapshot_mutation()');
        DB::statement('DROP TRIGGER limited_bonus_campaign_overlap_guard ON point_purchase_plan_limited_bonus_campaigns');
        DB::statement('DROP FUNCTION v2_guard_limited_bonus_campaign_overlap()');
        Schema::dropIfExists('payment_limited_bonus_snapshots');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_limited_bonus_values_check');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('limited_bonus_point_amount');
        });
        Schema::dropIfExists('point_purchase_plan_limited_bonus_campaigns');
    }
};
