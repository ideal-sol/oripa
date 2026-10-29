-- RANKSCOPE-20261029: Ledger101 READ ONLY Shadow; no102/103 dependency.
-- NEW owner executes locally using existing READ ONLY access; OLD Codex never connects.
-- Output includes internal history identifiers/hashes: keep it in PRIVATE NEW Evidence.
-- Do not put real identifiers, Scope or row/child Evidence in Public GitHub/CI.
-- The additional2 Draw/2 Operation remain UNAPPROVED. Evidence receipt is not approval.
-- Prior12/14 approval must show the added rows in history_delta, never auto-approve them.
-- No Draw/Operation count is an approval rule. Full Scope, child hashes and Evidence bind approval.
-- Default NULL parameters intentionally report scope_and_evidence_match=false.
-- This is a shadow of Scope/Evidence predicates, NOT a complete Activation Preflight:
-- Owner/Fresh MFA/CSRF, site/revision/manifest, financial admission and deferred Commit still apply.
-- Preserve the snapshot, SQL file SHA256, global/protected counts, hashes and private delta.
-- NEW owner must verify the additional row states/references and preserved child hashes,
-- unchanged old Scope after removing the delta, processing/pending6 pairs, finance/reconciliation,
-- and send the Human-reviewed receipt MEMBERRANK_NEWPROD_SOURCE_SQL_SHADOW_PASS.
-- That marker is an operator receipt and is NEVER emitted automatically by this query.
-- Source Merge remains HOLD until the receipt and required Human confirmation arrive.
-- F2-B inflight-or-hold, pin and Recovery Marker are outside this Source Change.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL TIME ZONE 'UTC';
-- SHADOW_SELECT_BEGIN
WITH protected AS (
    SELECT u.id FROM users u WHERE u.pre_registered_at IS NOT NULL
        OR EXISTS (SELECT 1 FROM pre_registration_targets t WHERE t.user_id=u.id)
        OR EXISTS (SELECT 1 FROM pre_registration_grants g WHERE g.user_id=u.id)
), records AS (
                    SELECT 'draws' AS kind, d.id, jsonb_build_array(to_jsonb(d),
                        (SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM draw_results r WHERE r.draw_request_id=d.id),
                        (SELECT jsonb_agg(to_jsonb(p) ORDER BY p.id) FROM user_prizes p
                            JOIN draw_results r ON r.id=p.draw_result_id WHERE r.draw_request_id=d.id)) AS body
                    FROM draw_requests d WHERE EXISTS (SELECT 1 FROM protected u WHERE u.id=d.user_id)
                    UNION ALL
                    SELECT 'operations', o.id, jsonb_build_array(to_jsonb(o),
                        (SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM point_ledger_entries l WHERE l.point_operation_id=o.id))
                    FROM point_operations o WHERE EXISTS (SELECT 1 FROM protected u WHERE u.id=o.user_id)
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
                ), scope AS (
                SELECT jsonb_build_object('draws', '[]'::jsonb, 'operations', '[]'::jsonb,
                    'payments', '[]'::jsonb, 'attempts', '[]'::jsonb) || COALESCE(jsonb_object_agg(kind, items), '{}'::jsonb) AS body
                FROM grouped
), parameters AS (
    -- NEW owner may supply the PREVIOUS exact approved Scope for a private delta
    -- comparison. Leave Evidence/Digest NULL until a separate explicit Human GO.
    SELECT NULL::jsonb AS approved_scope, NULL::text AS evidence_sha256,
        NULL::text AS approved_history_digest
), pending_integrity AS (
    SELECT jsonb_array_length(scope.body->'payments')=6
        AND jsonb_array_length(scope.body->'attempts')=6
        AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.status='processing'
            AND NOT v2_prelaunch_legacy_test_origin(p.id))
        AND NOT EXISTS (SELECT 1 FROM fincode_payment_attempts f JOIN payments p ON p.id=f.payment_id
            WHERE f.status='pending' AND (p.status<>'processing' OR NOT v2_prelaunch_legacy_test_origin(p.id)))
        AND (SELECT count(DISTINCT payment_id) FROM fincode_payment_attempts WHERE status='pending')=6 AS valid
    FROM scope
), current_items AS (
    SELECT kind, item FROM scope, jsonb_each(scope.body) groups(kind, items),
        LATERAL jsonb_array_elements(items) entries(item)
), approved_items AS (
    SELECT kind, item FROM parameters,
        jsonb_each(COALESCE(approved_scope, '{}'::jsonb)) groups(kind, items),
        LATERAL jsonb_array_elements(items) entries(item)
), changed AS (
    SELECT COALESCE(c.kind,a.kind) AS kind, COALESCE(c.item,a.item)->'id' AS id,
        CASE WHEN a.item IS NULL THEN 'ADDED'
             WHEN c.item IS NULL THEN 'REMOVED' ELSE 'CHANGED' END AS change,
        a.item->'sha256' AS approved_sha256, c.item->'sha256' AS current_sha256
    FROM current_items c FULL JOIN approved_items a ON c.kind=a.kind AND c.item->'id'=a.item->'id'
    WHERE c.item IS DISTINCT FROM a.item
)
SELECT jsonb_build_object(
    'activation_authorized', false,
    'transaction_read_only', current_setting('transaction_read_only'),
    'snapshot_at', transaction_timestamp(),
    'global_draws', (SELECT count(*) FROM draw_requests),
    'global_operations', (SELECT count(*) FROM point_operations),
    'protected_users', (SELECT count(*) FROM protected),
    'protected_draws', jsonb_array_length(scope.body->'draws'),
    'protected_operations', jsonb_array_length(scope.body->'operations'),
    'processing_payments', jsonb_array_length(scope.body->'payments'),
    'pending_attempts', jsonb_array_length(scope.body->'attempts'),
    'processing_pending_integrity', pending_integrity.valid,
    'unapproved_origins', (SELECT count(*) FROM payments WHERE NOT v2_prelaunch_test_origin(id)),
    'unknown_attempts', (SELECT count(*) FROM fincode_payment_attempts WHERE status NOT IN ('completed','failed','pending')
        AND NOT v2_prelaunch_test_attempt(id)),
    'history_scope', scope.body,
    'history_scope_sha256', encode(sha256(convert_to(scope.body::text, 'UTF8')), 'hex'),
    'comparison_scope_supplied', parameters.approved_scope IS NOT NULL,
    'scope_and_evidence_match', COALESCE(parameters.approved_scope=scope.body
        AND parameters.evidence_sha256 ~ '^[0-9a-f]{64}$'
        AND parameters.evidence_sha256<>'e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248'
        AND parameters.approved_history_digest=encode(sha256(convert_to(
            parameters.approved_scope::text || '|' || parameters.evidence_sha256, 'UTF8')), 'hex')
        AND pending_integrity.valid, false),
    'history_delta', CASE WHEN parameters.approved_scope IS NULL THEN NULL ELSE
        (SELECT COALESCE(jsonb_agg(to_jsonb(changed) ORDER BY kind,id::text),'[]'::jsonb) FROM changed) END
) AS report
FROM scope CROSS JOIN parameters CROSS JOIN pending_integrity;
-- SHADOW_SELECT_END
COMMIT;
