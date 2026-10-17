<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE member_rank_purchase_bonuses (
                payment_id bigint PRIMARY KEY REFERENCES payment_point_grants(payment_id) ON DELETE RESTRICT,
                user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                rank_level smallint NOT NULL CHECK (rank_level BETWEEN 1 AND 7),
                rank_code varchar(16) NOT NULL,
                rate_basis_points integer NOT NULL CHECK (rate_basis_points BETWEEN 0 AND 10000),
                paid_amount bigint NOT NULL CHECK (paid_amount > 0),
                bonus_amount bigint NOT NULL CHECK (bonus_amount >= 0),
                month date NOT NULL,
                setting_revision bigint NOT NULL,
                point_operation_id bigint UNIQUE REFERENCES point_operations(id) ON DELETE RESTRICT,
                granted_at timestamptz NOT NULL,
                FOREIGN KEY (setting_revision, rank_level) REFERENCES member_rank_setting_values(version_revision, level) ON DELETE RESTRICT,
                CHECK (rank_code = (ARRAY['beginner','bronze','silver','gold','platinum','diamond','vip'])[rank_level]),
                CHECK (bonus_amount = floor(paid_amount::numeric * rate_basis_points / 10000)),
                CHECK ((bonus_amount = 0 AND point_operation_id IS NULL) OR (bonus_amount > 0 AND point_operation_id IS NOT NULL)),
                CHECK (month = date_trunc('month', granted_at AT TIME ZONE 'Asia/Tokyo')::date)
            );
            CREATE INDEX member_rank_purchase_bonuses_user_month ON member_rank_purchase_bonuses (user_id, month, payment_id);
            CREATE UNIQUE INDEX member_rank_purchase_operation_payment ON point_operations (source_id)
                WHERE source_type = 'member_rank_purchase';
            CREATE TRIGGER v2_member_rank_purchase_immutable BEFORE UPDATE OR DELETE ON member_rank_purchase_bonuses
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_accounting_immutable();
            CREATE TRIGGER v2_member_rank_purchase_no_truncate BEFORE TRUNCATE ON member_rank_purchase_bonuses
                FOR EACH STATEMENT EXECUTE FUNCTION v2_member_rank_accounting_immutable();
            CREATE OR REPLACE FUNCTION v2_member_rank_purchase_origin() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM payments payment
                    JOIN payment_point_grants grant_record ON grant_record.payment_id = payment.id
                    JOIN member_rank_setting_values setting_value ON setting_value.version_revision = NEW.setting_revision
                        AND setting_value.level = NEW.rank_level
                    WHERE payment.id = NEW.payment_id AND payment.user_id = NEW.user_id
                        AND payment.status = 'succeeded' AND payment.amount = NEW.paid_amount
                        AND payment.paid_point_amount = NEW.paid_amount AND grant_record.granted_at = NEW.granted_at
                        AND setting_value.purchase_bonus_rate_bp = NEW.rate_basis_points
                ) OR NOT EXISTS (
                    SELECT 1 FROM member_rank_settings settings WHERE settings.id = 1 AND settings.enabled
                        AND settings.activated_at <= NEW.granted_at
                        AND NEW.setting_revision = (
                            SELECT version.revision FROM member_rank_setting_versions version
                            WHERE version.revision <= settings.revision AND version.effective_at <= NEW.granted_at
                            ORDER BY version.effective_at DESC, version.revision DESC LIMIT 1
                        )
                ) THEN
                    RAISE EXCEPTION 'member rank purchase requires matching payment grant and effective settings';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_purchase_origin BEFORE INSERT ON member_rank_purchase_bonuses
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_origin();
            CREATE OR REPLACE FUNCTION v2_member_rank_purchase_consistent() RETURNS trigger AS $$
            DECLARE purchase member_rank_purchase_bonuses%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'point_operations' THEN
                    IF NEW.source_type <> 'member_rank_purchase' THEN RETURN NEW; END IF;
                    SELECT * INTO purchase FROM member_rank_purchase_bonuses WHERE point_operation_id = NEW.id;
                    IF NOT FOUND THEN RAISE EXCEPTION 'member rank purchase operation requires immutable payment origin'; END IF;
                ELSIF TG_TABLE_NAME = 'point_lots' THEN
                    IF NOT EXISTS (SELECT 1 FROM point_operations WHERE id = NEW.grant_operation_id AND source_type = 'member_rank_purchase') THEN RETURN NEW; END IF;
                    SELECT * INTO purchase FROM member_rank_purchase_bonuses WHERE point_operation_id = NEW.grant_operation_id;
                    IF NOT FOUND THEN RAISE EXCEPTION 'member rank purchase lot requires immutable payment origin'; END IF;
                ELSIF TG_TABLE_NAME = 'point_ledger_entries' THEN
                    IF NOT EXISTS (SELECT 1 FROM point_operations WHERE id = NEW.point_operation_id AND source_type = 'member_rank_purchase') THEN RETURN NEW; END IF;
                    SELECT * INTO purchase FROM member_rank_purchase_bonuses WHERE point_operation_id = NEW.point_operation_id;
                    IF NOT FOUND THEN RAISE EXCEPTION 'member rank purchase ledger requires immutable payment origin'; END IF;
                ELSE
                    purchase := NEW;
                END IF;
                IF purchase.bonus_amount = 0 THEN RETURN NEW; END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM point_operations operation
                    WHERE operation.id = purchase.point_operation_id AND operation.user_id = purchase.user_id
                        AND operation.source_type = 'member_rank_purchase' AND operation.operation_type = 'free_grant'
                        AND operation.source_id = purchase.payment_id AND NOT operation.is_qa
                        AND operation.business_key = 'member_rank.purchase:' || purchase.payment_id::text
                        AND operation.occurred_at = purchase.granted_at
                ) OR (SELECT count(*) FROM point_lots WHERE grant_operation_id = purchase.point_operation_id) <> 1
                  OR NOT EXISTS (
                    SELECT 1 FROM point_lots lot WHERE lot.grant_operation_id = purchase.point_operation_id
                        AND lot.user_id = purchase.user_id AND lot.point_type = 'free'
                        AND lot.granted_amount = purchase.bonus_amount AND lot.granted_at = purchase.granted_at
                        AND lot.expire_at = purchase.granted_at + interval '180 days'
                  ) OR (SELECT count(*) FROM point_ledger_entries WHERE point_operation_id = purchase.point_operation_id) <> 1
                  OR NOT EXISTS (
                    SELECT 1 FROM point_ledger_entries entry
                    JOIN point_lots lot ON lot.id = entry.point_lot_id
                    JOIN wallets wallet ON wallet.id = entry.wallet_id
                    WHERE entry.point_operation_id = purchase.point_operation_id AND entry.user_id = purchase.user_id
                        AND wallet.user_id = purchase.user_id AND lot.grant_operation_id = purchase.point_operation_id
                        AND entry.point_type = 'free' AND entry.entry_type = 'grant'
                        AND entry.amount_delta = purchase.bonus_amount AND entry.lot_remaining_after = purchase.bonus_amount
                        AND entry.occurred_at = purchase.granted_at
                  ) THEN
                    RAISE EXCEPTION 'member rank purchase operation lot and ledger must match immutable origin';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER v2_member_rank_purchase_consistent AFTER INSERT ON member_rank_purchase_bonuses
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_consistent();
            CREATE CONSTRAINT TRIGGER v2_member_rank_purchase_operation_consistent AFTER INSERT ON point_operations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_consistent();
            CREATE CONSTRAINT TRIGGER v2_member_rank_purchase_lot_consistent AFTER INSERT ON point_lots
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_consistent();
            CREATE CONSTRAINT TRIGGER v2_member_rank_purchase_ledger_consistent AFTER INSERT ON point_ledger_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_consistent();
            CREATE OR REPLACE FUNCTION v2_member_rank_purchase_lot_immutable() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM point_operations WHERE id IN (OLD.grant_operation_id, NEW.grant_operation_id)
                    AND source_type = 'member_rank_purchase')
                    AND ROW(OLD.user_id, OLD.grant_operation_id, OLD.point_type, OLD.granted_amount, OLD.granted_at, OLD.expire_at)
                        IS DISTINCT FROM ROW(NEW.user_id, NEW.grant_operation_id, NEW.point_type, NEW.granted_amount, NEW.granted_at, NEW.expire_at) THEN
                    RAISE EXCEPTION 'member rank purchase lot origin is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_purchase_lot_immutable BEFORE UPDATE ON point_lots
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_purchase_lot_immutable();
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Member rank purchase history requires a forward-only migration.');
    }
};
