<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX payments_user_status_idx ON payments (user_id, status);
            ALTER TABLE catalog_gacha_versions
                ADD COLUMN purchase_count_mode varchar(4) NOT NULL DEFAULT 'none',
                ADD COLUMN purchase_count_value integer,
                ADD COLUMN purchase_amount_mode varchar(4) NOT NULL DEFAULT 'none',
                ADD COLUMN purchase_amount_value bigint;
            ALTER TABLE catalog_gacha_versions ADD CONSTRAINT catalog_gacha_versions_purchase_count_check CHECK (
                (purchase_count_mode = 'none' AND purchase_count_value IS NULL)
                OR (purchase_count_mode = 'eq' AND purchase_count_value IS NOT NULL AND purchase_count_value BETWEEN 0 AND 1000000)
                OR (purchase_count_mode IN ('gte', 'lt') AND purchase_count_value IS NOT NULL AND purchase_count_value BETWEEN 1 AND 1000000)
            );
            ALTER TABLE catalog_gacha_versions ADD CONSTRAINT catalog_gacha_versions_purchase_amount_check CHECK (
                (purchase_amount_mode = 'none' AND purchase_amount_value IS NULL)
                OR (purchase_amount_mode = 'eq' AND purchase_amount_value IS NOT NULL AND purchase_amount_value BETWEEN 0 AND 9007199254740991)
                OR (purchase_amount_mode IN ('gte', 'lt') AND purchase_amount_value IS NOT NULL AND purchase_amount_value BETWEEN 1 AND 9007199254740991)
            );
            ALTER TABLE catalog_gacha_versions ADD CONSTRAINT catalog_gacha_versions_purchase_consistency_check CHECK (
                NOT (((purchase_count_mode = 'eq' AND purchase_count_value = 0) OR (purchase_count_mode = 'lt' AND purchase_count_value = 1))
                    AND purchase_amount_mode IN ('eq', 'gte') AND purchase_amount_value >= 1)
                AND NOT (((purchase_amount_mode = 'eq' AND purchase_amount_value = 0) OR (purchase_amount_mode = 'lt' AND purchase_amount_value = 1))
                    AND purchase_count_mode IN ('eq', 'gte') AND purchase_count_value >= 1)
            );
            ALTER TABLE draw_requests
                ADD COLUMN purchase_count_snapshot bigint,
                ADD COLUMN purchase_amount_snapshot bigint,
                ADD CONSTRAINT draw_requests_purchase_snapshot_check CHECK (
                    (purchase_count_snapshot IS NULL AND purchase_amount_snapshot IS NULL)
                    OR (NOT is_qa_draw AND purchase_count_snapshot IS NOT NULL AND purchase_amount_snapshot IS NOT NULL
                        AND purchase_count_snapshot >= 0 AND purchase_amount_snapshot >= 0)
                );

            CREATE OR REPLACE FUNCTION v2_catalog_guard_purchase_condition() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE gacha catalog_gachas%ROWTYPE;
            DECLARE published catalog_gacha_versions%ROWTYPE;
            BEGIN
                SELECT * INTO STRICT gacha FROM catalog_gachas WHERE id = NEW.gacha_id;
                IF gacha.gacha_type <> 'standard' AND (NEW.purchase_count_mode <> 'none' OR NEW.purchase_amount_mode <> 'none') THEN
                    RAISE EXCEPTION 'Purchase conditions require a standard Gacha';
                END IF;
                IF gacha.first_published_at IS NOT NULL THEN
                    SELECT * INTO published FROM catalog_gacha_versions
                        WHERE gacha_id = gacha.id AND published_at IS NOT NULL ORDER BY version_number LIMIT 1;
                    IF published.id IS NULL OR ROW(NEW.purchase_count_mode, NEW.purchase_count_value, NEW.purchase_amount_mode, NEW.purchase_amount_value)
                        IS DISTINCT FROM ROW(published.purchase_count_mode, published.purchase_count_value, published.purchase_amount_mode, published.purchase_amount_value) THEN
                        RAISE EXCEPTION 'Published Gacha purchase conditions are immutable';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_gacha_versions_purchase_guard BEFORE INSERT OR UPDATE ON catalog_gacha_versions
                FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_purchase_condition();

            CREATE OR REPLACE FUNCTION v2_draw_guard_purchase_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'Draw purchase snapshots are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.purchase_count_snapshot IS NOT NULL THEN
                        RAISE EXCEPTION 'Draw purchase snapshots are immutable';
                    END IF;
                    RETURN OLD;
                END IF;
                IF ROW(NEW.purchase_count_snapshot, NEW.purchase_amount_snapshot)
                    IS DISTINCT FROM ROW(OLD.purchase_count_snapshot, OLD.purchase_amount_snapshot)
                    OR (OLD.purchase_count_snapshot IS NOT NULL AND (
                        NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                        OR NEW.is_qa_draw IS DISTINCT FROM OLD.is_qa_draw)) THEN
                    RAISE EXCEPTION 'Draw purchase snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER draw_requests_purchase_snapshot_guard BEFORE UPDATE OR DELETE ON draw_requests
                FOR EACH ROW EXECUTE FUNCTION v2_draw_guard_purchase_snapshot();
            CREATE TRIGGER draw_requests_purchase_snapshot_truncate_guard BEFORE TRUNCATE ON draw_requests
                FOR EACH STATEMENT EXECUTE FUNCTION v2_draw_guard_purchase_snapshot();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Gacha purchase conditions and Draw snapshots are forward-only.');
    }
};
