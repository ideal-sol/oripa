<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- This predicate is shared by Inspection, Scope and the deferred Commit guard.
            CREATE OR REPLACE FUNCTION v2_prelaunch_protected_user(user_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM users u WHERE u.id=user_key AND (
                    u.pre_registered_at IS NOT NULL
                    OR EXISTS (SELECT 1 FROM pre_registration_targets t WHERE t.user_id=u.id)
                    OR EXISTS (SELECT 1 FROM pre_registration_grants g WHERE g.user_id=u.id)));
            $$;

            -- No Draw/Operation count grants approval: only exact immutable Scope/Evidence equality does.
            -- Only identifiers and hashes leave this function. Full rows and their
            -- ordered children bind references, state and immutable history.
            CREATE OR REPLACE FUNCTION v2_prelaunch_history_scope() RETURNS jsonb LANGUAGE sql STABLE SET timezone='UTC' AS $$
                WITH records AS (
                    SELECT 'draws' AS kind, d.id, jsonb_build_array(to_jsonb(d),
                        (SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM draw_results r WHERE r.draw_request_id=d.id),
                        (SELECT jsonb_agg(to_jsonb(p) ORDER BY p.id) FROM user_prizes p
                            JOIN draw_results r ON r.id=p.draw_result_id WHERE r.draw_request_id=d.id)) AS body
                    FROM draw_requests d WHERE v2_prelaunch_protected_user(d.user_id)
                    UNION ALL
                    SELECT 'operations', o.id, jsonb_build_array(to_jsonb(o),
                        (SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM point_ledger_entries l WHERE l.point_operation_id=o.id))
                    FROM point_operations o WHERE v2_prelaunch_protected_user(o.user_id)
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

            CREATE OR REPLACE FUNCTION v2_prelaunch_history_approved(authority_key uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM member_rank_prelaunch_authorizations a
                    JOIN member_rank_settings s ON s.public_id=a.setting_public_id AND s.id=1
                    WHERE a.public_id=authority_key AND a.expected_revision=s.revision
                        AND a.history_scope=v2_prelaunch_history_scope()
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

            -- Migration102's deferred publication trigger is deliberately retained.
            -- It calls the replaced approval function and still rejects unapproved
            -- protected history, digest drift and every original financial HOLD.
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Protected prelaunch history scope requires a forward-only migration.');
    }
};
