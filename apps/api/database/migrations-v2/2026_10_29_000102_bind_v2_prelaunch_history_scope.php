<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE member_rank_prelaunch_authorizations
                ADD COLUMN history_scope jsonb,
                ADD COLUMN history_evidence_sha256 text,
                ADD COLUMN history_approval_digest text,
                ADD CONSTRAINT prelaunch_history_approval CHECK (
                    (history_scope IS NULL AND history_evidence_sha256 IS NULL AND history_approval_digest IS NULL)
                    OR (history_scope IS NOT NULL AND history_evidence_sha256 IS NOT NULL AND history_approval_digest IS NOT NULL
                        AND history_evidence_sha256 ~ '^[0-9a-f]{64}$'
                        AND history_evidence_sha256 <> authority_sha256
                        AND history_approval_digest = encode(sha256(convert_to(
                            history_scope::text || '|' || history_evidence_sha256, 'UTF8')), 'hex')));

            -- Only identifiers and hashes leave this function. Full rows and their
            -- ordered children bind references, state and immutable history.
            CREATE FUNCTION v2_prelaunch_history_scope() RETURNS jsonb LANGUAGE sql STABLE SET timezone='UTC' AS $$
                WITH records AS (
                    SELECT 'draws' AS kind, d.id, jsonb_build_array(to_jsonb(d),
                        (SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM draw_results r WHERE r.draw_request_id=d.id)) AS body
                    FROM draw_requests d
                    UNION ALL
                    SELECT 'operations', o.id, jsonb_build_array(to_jsonb(o),
                        (SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM point_ledger_entries l WHERE l.point_operation_id=o.id))
                    FROM point_operations o WHERE o.id NOT IN (SELECT point_operation_id FROM payment_point_grants)
                        AND o.id NOT IN (SELECT point_operation_id FROM pre_registration_grants)
                    UNION ALL
                    SELECT 'payments', p.id, jsonb_build_array(to_jsonb(p), to_jsonb(c))
                    FROM payments p LEFT JOIN payment_commercial_classifications c ON c.payment_id=p.id
                    WHERE p.status='processing'
                    UNION ALL
                    SELECT 'attempts', a.id, to_jsonb(a) FROM fincode_payment_attempts a WHERE a.status='pending'
                ), grouped AS (
                    SELECT kind, jsonb_agg(jsonb_build_object('id', id, 'sha256',
                        encode(sha256(convert_to(body::text, 'UTF8')), 'hex')) ORDER BY id) AS items
                    FROM records GROUP BY kind
                )
                SELECT jsonb_build_object('draws', '[]'::jsonb, 'operations', '[]'::jsonb,
                    'payments', '[]'::jsonb, 'attempts', '[]'::jsonb) || COALESCE(jsonb_object_agg(kind, items), '{}'::jsonb)
                FROM grouped;
            $$;

            CREATE FUNCTION v2_prelaunch_history_approved(authority_key uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM member_rank_prelaunch_authorizations a
                    JOIN member_rank_settings s ON s.public_id=a.setting_public_id AND s.id=1
                    WHERE a.public_id=authority_key AND a.expected_revision=s.revision
                        AND a.history_scope=v2_prelaunch_history_scope()
                        AND jsonb_array_length(a.history_scope->'draws')=12
                        AND jsonb_array_length(a.history_scope->'operations')=14
                        AND jsonb_array_length(a.history_scope->'payments')=6
                        AND jsonb_array_length(a.history_scope->'attempts')=6
                        AND a.history_evidence_sha256 IS NOT NULL
                        AND a.history_approval_digest=encode(sha256(convert_to(
                            a.history_scope::text || '|' || a.history_evidence_sha256, 'UTF8')), 'hex')
                        AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.status='processing'
                            AND NOT v2_prelaunch_legacy_test_origin(p.id))
                        AND NOT EXISTS (SELECT 1 FROM fincode_payment_attempts f JOIN payments p ON p.id=f.payment_id
                            WHERE f.status='pending' AND (p.status<>'processing' OR NOT v2_prelaunch_legacy_test_origin(p.id)))
                        AND (SELECT count(DISTINCT payment_id) FROM fincode_payment_attempts WHERE status='pending')=6);
            $$;

            CREATE FUNCTION v2_member_rank_prelaunch_manifest(history_approval text) RETURNS text LANGUAGE sql STABLE AS $$
                SELECT CASE WHEN history_approval IS NULL THEN v2_member_rank_prelaunch_manifest()
                    ELSE encode(sha256(convert_to(v2_member_rank_prelaunch_manifest() || '|' || history_approval, 'UTF8')), 'hex') END;
            $$;
            CREATE FUNCTION v2_prelaunch_test_attempt(attempt_key bigint, history_approved boolean) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT v2_prelaunch_test_attempt(attempt_key) OR
                    (history_approved AND EXISTS (
                        SELECT 1 FROM fincode_payment_attempts a JOIN payments p ON p.id=a.payment_id
                        WHERE a.id=attempt_key AND a.status='pending' AND p.status='processing'
                            AND v2_prelaunch_legacy_test_origin(p.id)));
            $$;
            CREATE FUNCTION v2_prelaunch_pending_payment_allowed(payment_key bigint, history_approved boolean) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM payments p WHERE p.id=payment_key AND v2_prelaunch_legacy_test_origin(p.id)
                    AND (p.status='requires_action' OR (p.status='processing' AND history_approved)));
            $$;
            CREATE OR REPLACE FUNCTION v2_prelaunch_publication_complete() RETURNS trigger AS $$
                        DECLARE run_row member_rank_prelaunch_runs%ROWTYPE; authority member_rank_prelaunch_authorizations%ROWTYPE; history_approved boolean;
                        BEGIN
                            SELECT * INTO STRICT run_row FROM member_rank_prelaunch_runs WHERE id = 1;
                            SELECT * INTO STRICT authority FROM member_rank_prelaunch_authorizations WHERE public_id = run_row.public_id;
                            history_approved := v2_prelaunch_history_approved(authority.public_id);
                            IF NOT EXISTS (SELECT 1 FROM member_rank_settings WHERE id = 1 AND enabled
                                    AND activated_at = run_row.activated_at AND revision = run_row.setting_revision
                                    AND public_id = authority.setting_public_id)
                                OR authority.expected_revision <> run_row.setting_revision
                                OR authority.origin_digest <> run_row.origin_digest
                                OR run_row.origin_digest <> v2_member_rank_prelaunch_manifest(authority.history_approval_digest)
                                OR (SELECT generation FROM financial_admission_generations WHERE id = 1) <> 1
                                OR run_row.excluded_origin_count <> (SELECT count(*) FROM payments)
                                OR run_row.excluded_origin_count <> (SELECT count(*) FROM member_rank_prelaunch_excluded_origins)
                                OR EXISTS (SELECT 1 FROM payments WHERE NOT v2_prelaunch_test_origin(id))
                                OR EXISTS (SELECT 1 FROM payments WHERE status NOT IN ('succeeded', 'failed', 'canceled', 'expired')
                                    AND NOT v2_prelaunch_pending_payment_allowed(id, history_approved))
                                OR EXISTS (SELECT 1 FROM member_rank_payment_adjustments WHERE NOT v2_prelaunch_zero_adjustment(id))
                                OR EXISTS (SELECT 1 FROM fincode_payment_attempts WHERE status NOT IN ('completed','failed')
                                    AND NOT v2_prelaunch_test_attempt(id, history_approved))
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
            IF EXISTS (SELECT 1 FROM draw_requests d JOIN users u ON u.id=d.user_id
                    WHERE u.pre_registered_at IS NOT NULL
                        OR u.id IN (SELECT user_id FROM pre_registration_targets)
                        OR u.id IN (SELECT user_id FROM pre_registration_grants))
                OR EXISTS (SELECT 1 FROM point_operations o JOIN users u ON u.id=o.user_id
                    WHERE o.id NOT IN (SELECT point_operation_id FROM pre_registration_grants)
                        AND (u.pre_registered_at IS NOT NULL
                            OR u.id IN (SELECT user_id FROM pre_registration_targets)
                            OR u.id IN (SELECT user_id FROM pre_registration_grants))) THEN
                IF NOT history_approved THEN
                    RAISE EXCEPTION 'prelaunch history scope is not approved';
                END IF;
            END IF;
            IF authority.history_scope IS NOT NULL AND NOT history_approved THEN
                RAISE EXCEPTION 'approved prelaunch history changed';
            END IF;
            RETURN NEW;
                        END;
                        $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Prelaunch history approval requires a forward-only migration.');
    }
};
