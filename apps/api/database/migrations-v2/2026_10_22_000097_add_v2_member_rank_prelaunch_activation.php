<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE financial_admission_generations (
                id bigint PRIMARY KEY CHECK (id = 1),
                generation bigint NOT NULL CHECK (generation IN (0, 1))
            );
            INSERT INTO financial_admission_generations VALUES (1, 0);
            CREATE TABLE member_rank_prelaunch_authorizations (
                public_id uuid PRIMARY KEY,
                site_code text NOT NULL CHECK (site_code = 'oripoke-prelaunch'),
                setting_public_id uuid NOT NULL UNIQUE REFERENCES member_rank_settings(public_id),
                authority_sha256 text NOT NULL CHECK (authority_sha256 = 'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248'),
                expected_revision bigint NOT NULL CHECK (expected_revision > 0),
                origin_digest text NOT NULL CHECK (origin_digest ~ '^[0-9a-f]{64}$'),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            CREATE TABLE member_rank_prelaunch_runs (
                id bigint PRIMARY KEY CHECK (id = 1),
                public_id uuid NOT NULL UNIQUE REFERENCES member_rank_prelaunch_authorizations(public_id),
                activated_at timestamptz NOT NULL,
                setting_revision bigint NOT NULL CHECK (setting_revision > 0),
                excluded_origin_count bigint NOT NULL CHECK (excluded_origin_count >= 0),
                origin_digest text NOT NULL CHECK (origin_digest ~ '^[0-9a-f]{64}$'),
                admin_id bigint NOT NULL REFERENCES admins(id)
            );
            CREATE TABLE member_rank_prelaunch_excluded_origins (
                payment_id bigint PRIMARY KEY REFERENCES payments(id),
                run_id bigint NOT NULL REFERENCES member_rank_prelaunch_runs(id) DEFERRABLE INITIALLY DEFERRED CHECK (run_id = 1),
                grant_id bigint UNIQUE REFERENCES payment_point_grants(id),
                point_operation_id bigint UNIQUE REFERENCES point_operations(id),
                granted_at timestamptz,
                CHECK ((grant_id IS NULL AND point_operation_id IS NULL AND granted_at IS NULL)
                    OR (grant_id IS NOT NULL AND point_operation_id IS NOT NULL AND granted_at IS NOT NULL))
            );
            CREATE OR REPLACE FUNCTION v2_financial_share() RETURNS void AS $$
            BEGIN
                IF NOT pg_try_advisory_xact_lock_shared(740022, 1) THEN
                    RAISE EXCEPTION 'financial admission is closed' USING ERRCODE = '40001';
                END IF;
                PERFORM generation FROM financial_admission_generations WHERE id = 1 FOR SHARE NOWAIT;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'financial admission generation is missing';
                END IF;
            EXCEPTION WHEN lock_not_available THEN
                RAISE EXCEPTION 'financial admission generation is busy' USING ERRCODE = '40001';
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION v2_financial_admit() RETURNS void AS $$
            BEGIN
                PERFORM v2_financial_share();
                PERFORM pg_advisory_xact_lock_shared(740022, 2);
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION v2_financial_write_guard() RETURNS trigger AS $$
            DECLARE admitted boolean; current_generation bigint;
            BEGIN
                SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid()
                    AND locktype = 'advisory' AND classid = 740022 AND objsubid = 2 AND granted
                    AND ((objid = 2 AND mode = 'ShareLock') OR (objid = 1 AND mode = 'ExclusiveLock'))) INTO admitted;
                PERFORM v2_financial_share();
                SELECT generation INTO STRICT current_generation FROM financial_admission_generations WHERE id = 1;
                IF current_generation = 1 AND TG_ARGV[0] = 'strict' AND NOT admitted THEN
                    RAISE EXCEPTION 'financial writer requires outer admission' USING ERRCODE = '40001';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION v2_prelaunch_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'prelaunch authority and origins are immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION v2_financial_generation_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' OR NEW.id IS DISTINCT FROM OLD.id
                    OR OLD.generation <> 0 OR NEW.generation <> 1
                    OR NOT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid()
                        AND locktype = 'advisory' AND classid = 740022 AND objid = 1 AND objsubid = 2
                        AND granted AND mode = 'ExclusiveLock') THEN
                    RAISE EXCEPTION 'financial generation requires irreversible exclusive publication';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_financial_generation_guard BEFORE UPDATE OR DELETE ON financial_admission_generations
                FOR EACH ROW EXECUTE FUNCTION v2_financial_generation_guard();
            CREATE TRIGGER v2_financial_generation_truncate BEFORE TRUNCATE ON financial_admission_generations
                EXECUTE FUNCTION v2_prelaunch_immutable();
            CREATE OR REPLACE FUNCTION v2_financial_user_state_guard() RETURNS trigger AS $$
            BEGIN
                PERFORM v2_financial_admit();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_financial_user_state_guard BEFORE UPDATE OF state ON users
                FOR EACH ROW WHEN (OLD.state IS DISTINCT FROM NEW.state
                    AND (OLD.state IN ('closed', 'anonymized') OR NEW.state IN ('closed', 'anonymized')))
                EXECUTE FUNCTION v2_financial_user_state_guard();
            CREATE OR REPLACE FUNCTION v2_member_rank_prelaunch_manifest() RETURNS text AS $$
                SELECT encode(sha256(convert_to(COALESCE(string_agg(
                    payment.id::text || '|' || payment.public_id::text || '|' || payment.user_id::text
                    || '|' || payment.amount::text || '|' || payment.paid_point_amount::text || '|' || payment.status
                    || '|' || COALESCE(grant_row.id::text, '') || '|' || COALESCE(grant_row.point_operation_id::text, '')
                    || '|' || COALESCE(extract(epoch FROM grant_row.granted_at)::bigint::text, ''),
                    E'\n' ORDER BY payment.id), ''), 'UTF8')), 'hex')
                FROM payments payment LEFT JOIN payment_point_grants grant_row ON grant_row.payment_id = payment.id;
            $$ LANGUAGE sql STABLE;
            CREATE OR REPLACE FUNCTION v2_prelaunch_publication_complete() RETURNS trigger AS $$
            DECLARE run_row member_rank_prelaunch_runs%ROWTYPE; authority member_rank_prelaunch_authorizations%ROWTYPE;
            BEGIN
                SELECT * INTO STRICT run_row FROM member_rank_prelaunch_runs WHERE id = 1;
                SELECT * INTO STRICT authority FROM member_rank_prelaunch_authorizations WHERE public_id = run_row.public_id;
                IF NOT EXISTS (SELECT 1 FROM member_rank_settings WHERE id = 1 AND enabled
                        AND activated_at = run_row.activated_at AND revision = run_row.setting_revision
                        AND public_id = authority.setting_public_id)
                    OR authority.expected_revision <> run_row.setting_revision
                    OR authority.origin_digest <> run_row.origin_digest
                    OR run_row.origin_digest <> v2_member_rank_prelaunch_manifest()
                    OR (SELECT generation FROM financial_admission_generations WHERE id = 1) <> 1
                    OR run_row.excluded_origin_count <> (SELECT count(*) FROM payments)
                    OR run_row.excluded_origin_count <> (SELECT count(*) FROM member_rank_prelaunch_excluded_origins)
                    OR EXISTS (SELECT 1 FROM payments WHERE status NOT IN ('succeeded', 'failed', 'canceled', 'expired'))
                    OR EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins excluded
                        JOIN payments payment ON payment.id = excluded.payment_id
                        LEFT JOIN payment_point_grants grant_row ON grant_row.payment_id = payment.id
                        WHERE excluded.grant_id IS DISTINCT FROM grant_row.id
                            OR excluded.point_operation_id IS DISTINCT FROM grant_row.point_operation_id
                            OR excluded.granted_at IS DISTINCT FROM grant_row.granted_at
                            OR (payment.status = 'succeeded' AND grant_row.id IS NULL)) THEN
                    RAISE EXCEPTION 'prelaunch publication is incomplete';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER v2_prelaunch_run_complete AFTER INSERT ON member_rank_prelaunch_runs
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_publication_complete();
            CREATE CONSTRAINT TRIGGER v2_prelaunch_generation_complete AFTER UPDATE ON financial_admission_generations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_publication_complete();
            CREATE OR REPLACE FUNCTION v2_prelaunch_origin_insert_guard() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM member_rank_settings WHERE id = 1 AND enabled)
                    OR NOT EXISTS (SELECT 1 FROM pg_locks WHERE pid = pg_backend_pid()
                        AND locktype = 'advisory' AND classid = 740022 AND objid = 1 AND objsubid = 2
                        AND granted AND mode = 'ExclusiveLock') THEN
                    RAISE EXCEPTION 'prelaunch origins require exclusive unpublished cutover';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_prelaunch_origin_insert_guard BEFORE INSERT ON member_rank_prelaunch_excluded_origins
                FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_origin_insert_guard();
            CREATE OR REPLACE FUNCTION v2_prelaunch_grant_complete() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM member_rank_prelaunch_runs) THEN RETURN NEW; END IF;
                IF EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id = NEW.payment_id) THEN
                    RAISE EXCEPTION 'excluded prelaunch payment cannot acquire a new grant';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM member_rank_point_entries entry
                        JOIN payments payment ON payment.id = NEW.payment_id
                        WHERE entry.payment_id = NEW.payment_id AND entry.reason = 'payment'
                            AND entry.point_operation_id = NEW.point_operation_id AND entry.user_id = payment.user_id
                            AND entry.applied_delta = payment.amount AND entry.granted_at = NEW.granted_at)
                    OR NOT EXISTS (SELECT 1 FROM member_rank_purchase_bonuses WHERE payment_id = NEW.payment_id) THEN
                    RAISE EXCEPTION 'post-cutover paid grant requires atomic M4 and M2 origins';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER v2_prelaunch_grant_complete AFTER INSERT ON payment_point_grants
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_grant_complete();
            CREATE OR REPLACE FUNCTION v2_member_rank_prelaunch_eligible(payment_key bigint) RETURNS boolean AS $$
                SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM member_rank_prelaunch_runs WHERE id = 1) THEN NULL
                    ELSE NOT EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id = payment_key) END;
            $$ LANGUAGE sql STABLE;
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
                        AND COALESCE(v2_member_rank_prelaunch_eligible(NEW.payment_id), settings.activated_at <= NEW.granted_at)
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
            CREATE OR REPLACE FUNCTION v2_prelaunch_excluded_rank_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.payment_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id = NEW.payment_id
                ) THEN RAISE EXCEPTION 'prelaunch payment is excluded from rank and purchase bonus'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_prelaunch_excluded_rank_guard BEFORE INSERT ON member_rank_point_entries
                FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_excluded_rank_guard();
            CREATE TRIGGER v2_prelaunch_excluded_bonus_guard BEFORE INSERT ON member_rank_purchase_bonuses
                FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_excluded_rank_guard();
            SQL);
        foreach (['member_rank_prelaunch_authorizations', 'member_rank_prelaunch_runs', 'member_rank_prelaunch_excluded_origins'] as $table) {
            DB::unprepared("CREATE TRIGGER v2_prelaunch_immutable BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION v2_prelaunch_immutable();
                CREATE TRIGGER v2_prelaunch_truncate BEFORE TRUNCATE ON {$table} EXECUTE FUNCTION v2_prelaunch_immutable();");
        }
        foreach (['payments', 'payment_point_grants', 'payment_adjustments', 'payment_adjustment_point_operations',
            'point_operations', 'wallets', 'point_lots', 'point_ledger_entries', 'point_adjustments',
            'point_lot_reservations', 'point_reconciliation_runs', 'point_reconciliation_discrepancies',
            'point_balance_snapshots', 'draw_requests', 'draw_results',
            'member_rank_settings', 'member_rank_setting_versions', 'member_rank_setting_values',
            'member_rank_states', 'member_rank_point_entries', 'member_rank_month_totals', 'member_rank_changes',
            'member_rank_achievements', 'member_rank_entitlements', 'member_rank_purchase_bonuses',
            'member_rank_bonus_recoveries', 'member_rank_manual_changes', 'member_rank_payment_adjustments',
            'member_rank_monthly_jobs'] as $table) {
            DB::unprepared("CREATE TRIGGER v2_financial_admission BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION v2_financial_write_guard('strict');");
        }
        foreach (['payment_provider_events', 'payment_provider_event_attempts', 'payment_provider_operations',
            'fincode_payment_attempts'] as $table) {
            DB::unprepared("CREATE TRIGGER v2_financial_admission BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION v2_financial_write_guard('receipt');");
        }
    }

    public function down(): void
    {
        throw new LogicException('Prelaunch activation authority requires a forward-only migration.');
    }
};
