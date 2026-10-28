<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_prelaunch_test_origin(payment_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM payments p JOIN payment_commercial_classifications c ON c.payment_id=p.id
                    WHERE p.id=payment_key AND c.classification='test'
                        AND (c.reason='provider_test_mode' OR (c.reason='pay1_existing_fincode' AND p.provider_code='fincode')));
            $$;
            CREATE OR REPLACE FUNCTION v2_prelaunch_legacy_test_origin(payment_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM payments p JOIN payment_commercial_classifications c ON c.payment_id=p.id
                    WHERE p.id=payment_key AND p.provider_code='fincode' AND c.classification='test' AND c.reason='pay1_existing_fincode');
            $$;
            CREATE OR REPLACE FUNCTION v2_prelaunch_test_event(event_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM payment_provider_events e JOIN payments p ON p.id=e.payment_id
                    WHERE e.id=event_key AND v2_prelaunch_legacy_test_origin(p.id)
                        AND e.provider_code=p.provider_code AND e.signature_verified_at IS NOT NULL
                        AND e.payment_adjustment_id IS NULL
                        AND e.event_type IN ('payment.succeeded','payment.status_changed','payment.canceled')
                        AND NOT EXISTS (SELECT 1 FROM payment_provider_event_attempts a WHERE a.payment_provider_event_id=e.id
                            AND (a.completed_at IS NULL OR a.outcome <> 'success')));
            $$;
            CREATE OR REPLACE FUNCTION v2_prelaunch_test_attempt(attempt_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM fincode_payment_attempts a JOIN payments p ON p.id=a.payment_id
                    WHERE a.id=attempt_key AND v2_prelaunch_legacy_test_origin(p.id)
                        AND a.status='requires_action' AND p.status='requires_action');
            $$;
            CREATE OR REPLACE FUNCTION v2_prelaunch_zero_adjustment(record_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM member_rank_payment_adjustments r
                    JOIN payments p ON p.id=r.payment_id
                    JOIN payment_adjustments a ON a.id=r.adjustment_id AND a.payment_id=p.id
                    JOIN payment_point_grants g ON g.payment_id=p.id
                    JOIN point_operations o ON o.id=r.point_operation_id
                    JOIN payment_adjustment_point_impacts i ON i.payment_adjustment_id=a.id
                    WHERE r.id=record_key AND v2_prelaunch_test_origin(p.id)
                        AND r.effect='none' AND r.requested_points=0 AND r.applied_points=0
                        AND r.point_entry_id IS NULL AND r.before_level IS NULL AND r.after_level IS NULL
                        AND r.purchase_bonus_required=0 AND r.purchase_bonus_reversed=0 AND r.purchase_bonus_shortfall=0
                        AND p.status='succeeded' AND r.user_id=p.user_id AND a.status='succeeded'
                        AND a.type IN ('refund','chargeback') AND a.amount=p.amount
                        AND r.occurred_at=a.succeeded_at AND i.completed_at=r.occurred_at
                        AND r.month=date_trunc('month',g.granted_at AT TIME ZONE 'Asia/Tokyo')::date
                        AND o.user_id=p.user_id AND o.source_type='payment_adjustment' AND o.source_id=p.id
                        AND o.operation_type=CASE WHEN a.type='refund' THEN 'refund_reversal' ELSE 'chargeback_reversal' END
                        AND EXISTS (SELECT 1 FROM payment_adjustment_point_operations l WHERE l.payment_adjustment_id=a.id
                            AND l.point_operation_id=o.id AND l.role=CASE WHEN a.type='refund' THEN 'reserve_consume' ELSE 'reversal' END)
                        AND (SELECT count(*) FROM payment_adjustment_point_operations WHERE payment_adjustment_id=a.id)=1
                        AND NOT EXISTS (SELECT 1 FROM point_ledger_entries l WHERE l.point_operation_id=o.id
                            AND (l.entry_type <> 'reverse' OR l.amount_delta>=0 OR l.user_id<>p.user_id))
                        AND i.required_paid_amount=p.paid_point_amount
                        AND i.required_free_amount::numeric=p.free_point_amount::numeric+COALESCE(p.limited_bonus_point_amount,0)
                        AND i.required_paid_amount::numeric=i.reversed_paid_from_paid::numeric+i.reversed_paid_shortage_from_free+i.shortfall_paid_amount
                        AND i.required_free_amount::numeric=i.reversed_free_from_free::numeric+i.shortfall_free_amount
                        AND i.reversed_paid_from_paid::numeric=(SELECT COALESCE(-sum(amount_delta),0) FROM point_ledger_entries WHERE point_operation_id=o.id AND point_type='paid')
                        AND i.reversed_free_from_free::numeric+i.reversed_paid_shortage_from_free=(SELECT COALESCE(-sum(amount_delta),0) FROM point_ledger_entries WHERE point_operation_id=o.id AND point_type='free')
                        AND NOT EXISTS (SELECT 1 FROM member_rank_point_entries WHERE payment_id=p.id OR origin_payment_id=p.id OR adjustment_id=a.id)
                        AND NOT EXISTS (SELECT 1 FROM member_rank_purchase_bonuses WHERE payment_id=p.id));
            $$;
            CREATE OR REPLACE FUNCTION v2_payment_commercial(payment_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM payment_commercial_classifications WHERE payment_id=payment_key
                    AND classification='commercial' AND reason='provider_live_mode')
                    AND NOT EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id=payment_key);
            $$;
            CREATE TRIGGER v2_financial_admission BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON payment_commercial_classifications
                FOR EACH STATEMENT EXECUTE FUNCTION v2_financial_write_guard('strict');
            CREATE OR REPLACE FUNCTION v2_payment_classification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT ((NEW.classification='commercial' AND NEW.reason='provider_live_mode')
                    OR (NEW.classification='test' AND NEW.reason='provider_test_mode')) THEN
                    RAISE EXCEPTION 'new payment classification requires explicit provider mode';
                END IF;
                -- Idempotent adapter INSERT ON CONFLICT may revisit a classified payment.
                IF NOT EXISTS (SELECT 1 FROM payment_commercial_classifications WHERE payment_id=NEW.payment_id)
                    AND (EXISTS (SELECT 1 FROM payment_point_grants WHERE payment_id=NEW.payment_id)
                        OR EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id=NEW.payment_id)) THEN
                    RAISE EXCEPTION 'payment classification must precede grant and cutover';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER payment_classification_insert_guard BEFORE INSERT ON payment_commercial_classifications
                FOR EACH ROW EXECUTE FUNCTION v2_payment_classification_guard();

            CREATE OR REPLACE FUNCTION v2_member_rank_prelaunch_manifest() RETURNS text AS $$
                            SELECT encode(sha256(convert_to(COALESCE(string_agg(
                                payment.id::text || '|' || payment.public_id::text || '|' || payment.user_id::text
                                || '|' || COALESCE(classification.classification, 'unknown') || '|' || COALESCE(classification.reason, '')
                                || '|' || payment.amount::text || '|' || payment.paid_point_amount::text || '|' || payment.status
                                || '|' || COALESCE(grant_row.id::text, '') || '|' || COALESCE(grant_row.point_operation_id::text, '')
                                || '|' || COALESCE(extract(epoch FROM grant_row.granted_at)::bigint::text, ''),
                                E'\n' ORDER BY payment.id), ''), 'UTF8')), 'hex')
                            FROM payments payment LEFT JOIN payment_point_grants grant_row ON grant_row.payment_id = payment.id
                            LEFT JOIN payment_commercial_classifications classification ON classification.payment_id = payment.id;
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
                                OR EXISTS (SELECT 1 FROM payments WHERE NOT v2_prelaunch_test_origin(id))
                                OR EXISTS (SELECT 1 FROM payments WHERE status NOT IN ('succeeded', 'failed', 'canceled', 'expired')
                                    AND NOT (v2_prelaunch_legacy_test_origin(id) AND status='requires_action'))
                                OR EXISTS (SELECT 1 FROM member_rank_payment_adjustments WHERE NOT v2_prelaunch_zero_adjustment(id))
                                OR EXISTS (SELECT 1 FROM fincode_payment_attempts WHERE status NOT IN ('completed','failed')
                                    AND NOT v2_prelaunch_test_attempt(id))
                                OR EXISTS (SELECT 1 FROM payment_provider_events e WHERE e.payment_id IS NULL
                                    OR (e.processing_status NOT IN ('processed','ignored') AND NOT v2_prelaunch_test_event(e.id)
                                        AND NOT EXISTS (SELECT 1 FROM payment_provider_event_attempts a
                                            WHERE a.payment_provider_event_id=e.id AND a.outcome='success' AND a.completed_at IS NOT NULL
                                                AND NOT EXISTS (SELECT 1 FROM payment_provider_event_attempts later
                                                    WHERE later.payment_provider_event_id=e.id AND later.attempt_no>a.attempt_no))))
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
            CREATE OR REPLACE FUNCTION v2_prelaunch_grant_complete() RETURNS trigger AS $$
                        BEGIN
                            IF NOT EXISTS (SELECT 1 FROM member_rank_prelaunch_runs) THEN RETURN NEW; END IF;
                            IF EXISTS (SELECT 1 FROM member_rank_prelaunch_excluded_origins WHERE payment_id = NEW.payment_id) THEN
                                RAISE EXCEPTION 'excluded prelaunch payment cannot acquire a new grant';
                            END IF;
                            IF NOT EXISTS (SELECT 1 FROM payment_commercial_classifications WHERE payment_id=NEW.payment_id
                                AND ((classification='commercial' AND reason='provider_live_mode') OR (classification='test' AND reason='provider_test_mode'))) THEN
                                RAISE EXCEPTION 'post-cutover paid grant requires explicit classification';
                            END IF;
                            IF NOT v2_payment_commercial(NEW.payment_id) THEN RETURN NEW; END IF;
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
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Prelaunch classification authority requires a forward-only migration.');
    }
};
