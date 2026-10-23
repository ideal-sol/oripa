<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipping_minimum_point_settings (
                id bigint PRIMARY KEY CHECK (id = 1),
                public_id uuid NOT NULL UNIQUE,
                minimum_points bigint NOT NULL DEFAULT 0 CHECK (minimum_points BETWEEN 0 AND 9007199254740991),
                revision bigint NOT NULL DEFAULT 1 CHECK (revision BETWEEN 1 AND 9007199254740991),
                updated_by_admin_id bigint REFERENCES admins(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            ALTER TABLE shipping_requests
                ADD COLUMN selected_point_total_snapshot bigint,
                ADD COLUMN applied_minimum_points_snapshot bigint,
                ADD COLUMN applied_setting_revision bigint,
                ADD CONSTRAINT shipping_requests_minimum_snapshot_check CHECK (
                    (selected_point_total_snapshot IS NULL AND applied_minimum_points_snapshot IS NULL AND applied_setting_revision IS NULL)
                    OR (selected_point_total_snapshot IS NOT NULL AND applied_minimum_points_snapshot IS NOT NULL
                        AND applied_setting_revision IS NOT NULL
                        AND selected_point_total_snapshot BETWEEN 1 AND 900719925474099100
                        AND applied_minimum_points_snapshot BETWEEN 0 AND 9007199254740991
                        AND selected_point_total_snapshot >= applied_minimum_points_snapshot
                        AND applied_setting_revision BETWEEN 1 AND 9007199254740991)
                );
            CREATE OR REPLACE FUNCTION v2_shipping_minimum_snapshot_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.selected_point_total_snapshot IS DISTINCT FROM OLD.selected_point_total_snapshot
                    OR NEW.applied_minimum_points_snapshot IS DISTINCT FROM OLD.applied_minimum_points_snapshot
                    OR NEW.applied_setting_revision IS DISTINCT FROM OLD.applied_setting_revision THEN
                    RAISE EXCEPTION 'Shipping minimum snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER shipping_requests_minimum_snapshot_guard BEFORE UPDATE ON shipping_requests
                FOR EACH ROW EXECUTE FUNCTION v2_shipping_minimum_snapshot_immutable();
            SQL);
        DB::table('shipping_minimum_point_settings')->insert([
            'id' => 1, 'public_id' => (string) Str::uuid7(), 'minimum_points' => 0, 'revision' => 1,
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Shipping minimum settings and snapshots require a forward-only migration.');
    }
};
