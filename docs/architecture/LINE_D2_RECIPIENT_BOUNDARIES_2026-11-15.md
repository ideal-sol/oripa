# LINE D2 recipient boundaries

Change LINE-DELIVERY-20261109-D2; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Source implementation and Draft PR only.
Base:13cdc57f4d60e22836618034899e9f64a9885770 (D1 PR681 integrated).

## Authority and exclusions

The Human D2 prompt dated2026-11-15 overrides specification1.4:
no promotional preference table/API/UI and no environment/member allowlist.
Transactional and promotional are purposes with identical eligibility.
Recipients are linked members, friends without a current member association,
or their union. Unlinked does not assert that a person is unregistered.
No send, queue, worker, delivery table, segment SQL, preview-count, chat,
provider acquisition, Public/Admin API or Storefront change is included.

## Internal contract

`V2LineRecipientResolver::resolve(group, purpose, chunkSize)` traverses a union
of subject indexes in bounded keyset chunks (1–500). Groups are the fixed enum
`linked_members`, `unlinked_friends`, `all_friends`. Purpose is the fixed enum
`transactional` or `promotional`; no SQL/expression input is accepted.
Each chunk uses a fixed number of queries, with latest-event selection in SQL.
Output contains only BOT ID/authentication revision, source table/row ID, nullable
member ID and group. No subject, ciphertext, name or profile is returned.
These are internal candidate references, never an HTTP response or permission
to send. Cryptographic validity is established at the immediate send boundary.

Only the actual active, configured, connected BOT qualifies. The D1 legacy
reply fallback is not used. Both selection and recheck fence BOT authentication
revision, including a switch away and back. Membership is never copied to a
different BOT. Existing timestamps and equal-time negative precedence govern
the union of webhook, account observation and recovered follower observations.

Current LINE issuer/provider identity, active/restricted member state (matching
the existing vault), no account_closures row, a current matching friendship or
valid same-BOT recovered observation, friend state and encrypted subject are
required for linked members. Invalidated
member identities do not become an unlinked audience by fallback. Historical
superseded identities alone do not authorize delivery. Claimed terminal pending
rows are never reused as unlinked recipients.

`eligibleBeforeSend(reference, purpose)` requires a caller-owned transaction.
It retains the settings singleton lock, and linked-member user/identity row
locks, until that outer transaction ends. It resolves the current classification
again and verifies the encrypted subject against the existing HMAC keyring
inside the vault, returning only a boolean. Missing, damaged or mismatched
ciphertext fails closed, with no sensitive exception content.

## D7 storage and later linking

Migration110 adds `line_recovered_friends`: immutable BOT, canonical HMAC,
encrypted subject, and monotonic positive observation timestamp. There is no
invented followed_at, user-registration claim, reward record or point mutation.
The internal `V2LineRecoveredFriendRegistry::record` accepts one subject at a
time, validates the active BOT generation under the D1 singleton lock and
preserves existing ciphertext. D7 must supply the actual observation timestamp
and captured BOT authentication revision; it must not stamp delayed responses
with a later time. No real follower API is called by D2.

The existing vault now includes this table in canonical HMAC selection and
encrypted backfill. Consequently the existing LINE linking flow selects the
same subject through key rotation. Classification reads identity state afresh,
so pending→claimed/linked cannot produce two recipients. Recovery alone never
creates a reward or changes D1 terminal rows.

Human decision2026-11-15 explicitly permits a recovered-only subject to qualify
after later LINE linking even when friendship checking is OFF and no
line_friendships row exists. Identity association is derived from the same
canonical LINE HMAC and BOT scope on every resolution, rather than copying
membership or fabricating a follow date. The one recovered source reference is
reclassified from unlinked to linked; the old unlinked reference fails recheck.
No reward/reconciliation operation is invoked by resolution or recovery ingestion.
The actual existing linking reconciliation remains unchanged; recovery alone
cannot grant a benefit even when rewards are enabled.

A newer recorded unfollow/negative observation overrides the D7 snapshot;
equal-time negative wins. A later real follow can restore eligibility. Missing
or inconsistent subject binding, conflicting member ownership or a different
BOT fails closed. D7 records evidence of friendship at acquisition, not permanent
deliverability. Webhook delivery can be delayed or absent, so an unobserved block
cannot be detected locally. D3 must re-read the latest recorded state immediately
before sending and must handle the provider's actual delivery limitations; it
must never treat an old D7 snapshot as proof of current delivery. No real D7
acquisition or extra friend-check API call is introduced by D2.

## Follow-on boundaries

- D3 owns sending, retry identity, quota, pacing, decrypt-to-transport access and
  durable batches. Resolve at send time; recheck under the same outer transaction
  and retain fences through the first transport attempt. Do not retry provider
  side effects by retrying a DB transaction. Never substitute BOT credentials or
  a newly linked member for a saved reference. Retrying an attempted batch needs
  D3's immutable original expansion, independently of this candidate resolver.
- D5 can intersect typed business predicates with linked candidates; it must
  not bypass this resolver. Preview-count and segment SQL remain future.
- D6 can reuse unlinked references after its own authorization and retention
  decisions. Chat, media and unsend storage remain out of scope. The D1 Human
  retention decision and required pre-D6 official-guidance review remain open.
- D7 must use the registry only after its authenticated follower-list retrieval
  succeeds. Store unmatched IDs encrypted, never in logs, files or output.

## Migration and verification

110 was reserved under the shared allocation flock after auditing31 live heads,
20 open PRs,83 refs, worktrees, task policies and the coordination ledger.
107/108/109 are unchanged;108/109 remain permanently owned by D1. The new table
and event lookup index are additive. Existing ciphertext, terminal rows,
membership guards and reward uniqueness are unchanged. Deploy110 before this
source; do not activate against109. Rollback retains schema and uses a reviewed
forward correction; down/delete/truncate/rebinding are rejected.

Synthetic verification covers clean1→110,107→108→109→110 with old ciphertext and
terminal guards, forward-only rejection and backup/restore schema/ledger parity.
Local focused results and final-head Required5/self-review are recorded in the
PR. No operational migration, ENV edit, worker or Runtime activation is allowed.
Authenticated browser, Admin MFA and real LINE acceptance:
**NOT RUN — Human Browser Acceptance Pending**.

Official LINE guidance checked2026-11-15:
[receiving messages](https://developers.line.biz/en/docs/messaging-api/receiving-messages/)
confirms follow on add/unblock, unfollow on block, duplicate webhook events and
possible redelivery order inversion. D1 event IDs/timestamps and negative tie
precedence are preserved. No newly identified provider behavior changes D2.
