<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_prizes', 'catalog_gacha_version_prizes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('shipping_only')->default(false);
            });
        }
        Schema::table('user_prizes', function (Blueprint $table): void {
            $table->boolean('shipping_only_snapshot')->default(false);
        });
        DB::statement(
            "ALTER TABLE user_prizes ADD CONSTRAINT user_prize_shipping_only_exchange_check ".
            "CHECK (NOT shipping_only_snapshot OR ".
            "(status::text NOT IN ('exchange_processing', 'converted') AND exchanged_point_amount IS NULL))"
        );
        DB::statement(
            'CREATE INDEX user_prizes_shipping_only_expiration_index '.
            'ON user_prizes (storage_expires_at, id) '.
            "WHERE shipping_only_snapshot AND status = 'stored'"
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_protect_user_prize_ownership()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR
                   (NEW.public_id, NEW.user_id, NEW.draw_result_id, NEW.gacha_version_prize_id,
                    NEW.acquired_at, NEW.exchange_point_snapshot, NEW.storage_expires_at,
                    NEW.shipping_only_snapshot)
                   IS DISTINCT FROM
                   (OLD.public_id, OLD.user_id, OLD.draw_result_id, OLD.gacha_version_prize_id,
                    OLD.acquired_at, OLD.exchange_point_snapshot, OLD.storage_expires_at,
                    OLD.shipping_only_snapshot)
                THEN
                    RAISE EXCEPTION 'V2 user prize ownership and snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Shipping-only prize rights require a forward correction migration.');
    }
};
