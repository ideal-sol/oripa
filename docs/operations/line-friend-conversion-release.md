# LINE friend conversion release

Source change LINE-FRIEND-CV-20261120, Strict Change, Runtime Activation deferred.

The new table is additive. Apply migration 000119 before starting an approved
API release. Source rollback retains the table and its evidence. The migration
does not ingest or backfill historical users, change LINE eligibility, or
modify rewards.

The Human sets `V2_LINE_FRIEND_CONVERSION_STARTED_AT` to a future UTC instant in
the separately approved API runtime configuration, using
`YYYY-MM-DDTHH:MM:SSZ` or `YYYY-MM-DDTHH:MM:SS+00:00`. An absent or invalid value
prevents new conversion records. A supported first transition is eligible
only at or after that instant. A pre-start pending follow claimed during
post-start linking uses link completion time. Revocation, blocking and member
replacement retain history, including superseded friendship rows.

The existing LINE settings-row lock orders callback, webhook and observation
transactions. The callback snapshots before completing External Identity and
records after reconciling pending follow. Webhook follow uses the event
timestamp, verified observation uses observation time and pending follow
reconciliation uses completion time. The conversion insert runs in a
savepoint with no identifying values in failure logs. D7 acquisition and its
recipient resolver update delivery evidence only, and have no canonical
friendship writer. Existing history recovered during pending reads is always
suppressed.

Pending, claim and delivered require the current authenticated SMS-verified
member. Mutations require the existing CSRF boundary. The read has a separate
60-per-minute member bucket. Claim and delivered share the existing mutation
budget of 20 per minute, scoped to conversion events. The 409 codes are
`CONVERSION_CLAIM_HELD`, `CONVERSION_ALREADY_DELIVERED`,
`CONVERSION_NOT_DELIVERABLE` and `CONVERSION_TOKEN_MISMATCH`.

Paidy alpha.53 is the released immutable predecessor. The LINE candidate is
additive bundle/Client/Testkit alpha.54 with Public alpha.50 and 97 operations,
retaining all 94 predecessor operations. Site Schema alpha.23 and Admin/Webhook
alpha.47 retain their existing versions. The candidate requires separate
Human Source Merge and canonical Contract publication approval. Its eventual
Artifact identity is recorded by the subsequent Release Metadata task.
Storefront adoption requires the approved immutable Artifact, manifest and
package checksums.

For OLD Test acceptance, keep GTM OFF and verify the migration, API owner/SMS
boundary, both first-time orderings, pre-start exclusions, and claim expiry and
recovery with synthetic members. For browser GTM acceptance after explicit
Human authorization, check the start boundary, simultaneous devices, failed
push recovery, stale-claim recovery without a second push, and the exact
`line_friend_added` payload. Production activation and the start setting
require separate approval.
