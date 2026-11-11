# LINE D1 — credentials and BOT boundaries

- Change: LINE-DELIVERY-20261109-D1; R3; Strict Change; Application Runtime Activation: deferred.
- Authority: approved D1 prompt v1.1 (2026-11-10), specification v1.4 and the latest explicit Human decisions. Scope ends at one Draft PR.
- Base: `b120b8c7bd3f2a7e538546e17da37bded5b11e0f`. Public API, Storefront Client/Testkit and Storefront source are unchanged.

## Credential authority

LINE Login credentials belong to the site singleton, independently of Messaging BOTs.
Owner changes require a valid Admin MFA session plus the existing five-minute fresh MFA boundary.
Admin can read configuration status; Operator retains the pre-existing LINE settings page
without access to the new credential/BOT section. The UI reuses TOTP/WebAuthn reauthentication,
clears credential inputs after each attempt and requires the Human to retry the mutation explicitly.
Secrets are encrypted with Laravel Crypt, omitted from every presentation, and never returned
from the idempotency record. Only the request fingerprint, safe result and credential-replaced
boolean enter idempotency/audit records.

An unset login singleton continues reading the existing ENV credentials.
`v2:line:import-environment` is an explicit, unscheduled migration command: it imports
only unset login credentials and the initial BOT secret, never overwrites Admin configuration,
never imports an access token and never activates a BOT. It is implemented and tested
with synthetic values only; operating it in OLD Test or Production requires separate Human GO.
Migrations108 and109 must precede activation of this source. A deployment with missing schema is
not an authorized activation; do not point the existing application at an unprepared DB.
The original login redirect URI, bot_prompt and friendship feature settings stay in ENV.

Each BOT has a stable Channel ID once configured. Rotating its secret or changing its name is
supported; another official account must be added as another BOT, preserving historical membership.
The initial placeholder has no guessed Channel ID. Human supplies its correct ID and performs a
connection check before activation. This is a data-identity boundary, not a new send allowlist.

## Provider calls and failure behavior

Official reference checked at implementation:
[Messaging API reference](https://developers.line.biz/en/reference/messaging-api/).
Stateless token issuance uses POST `https://api.line.me/oauth2/v3/token`,
client_credentials, Channel ID and secret. Tokens expire after fifteen minutes and are cached
encrypted for at most thirteen minutes, keyed by BOT UUID/revision/credential ciphertext digest.
There is no long-lived token input or DB token column. One401 can refresh the token once.
Requests have bounded timeouts, do not follow redirects and use only fixed api.line.me resources.
Provider bodies and HTTP exceptions are replaced with generic failures.

A connection check requests BOT info. Failed checks of inactive BOTs are persisted as failed and
prevent activation. Activation checks connectivity again, so a historical success cannot activate
a BOT with rejected credentials. A failed secret replacement on an active BOT rolls back the change.
Transient recheck failures do not silently deactivate or select another BOT.
Quota/follower snapshots are fetched only by explicit Owner actions, with timestamps and
allowlisted fields. Follower data uses the preceding Asia/Tokyo calendar day.
The legacy initial BOT retains the existing ENV reply transport until a DB-configured BOT is active.

## Switching, webhooks and OAuth

The partial unique index permits at most one active BOT. Configuration mutations, OAuth
completion, friendship/reward handling and the actual reply call serialize on the existing
`line_messaging_settings` singleton. Reply claims are committed before the external side effect;
the provider call is never retried by a DB transaction. A switched-out claim fails without sending
through the new BOT. HTTP/token retries are bounded separately.

Each BOT URL verifies its own secret before any event handling. Unknown/malformed UUIDs and missing
secrets return401. Inactive, correctly signed BOT URLs return200 without event processing.
The original `/webhooks/v2/line` remains bound to the initial BOT only. During the unconfigured
migration period this legacy route and the ENV reply token continue to work. Once another BOT is
active, its requests are acknowledged without applying old-BOT events.

Admin displays/copies the complete HTTPS `webhook_url`, composed only from the existing
`V2_PUBLIC_ORIGIN` (`v2_identity.origins.user`) and BOT webhook path. Missing or invalid origins
(including HTTP, credentials, query, fragment or non-root path) return null and disable copying.
Request Host/Forwarded headers, Admin origin and APP_URL are never fallbacks. The additive
nullable Admin contract retains `webhook_path` for compatibility. Route reachability remains
an activation/Human acceptance check.

Login starts record the login revision and selected BOT identity/authentication revision. Callback checks them
before token exchange and again inside completion; a changed configuration requires a new login
attempt. Transactions created before108 have no BOT binding and must also restart.
Rejected callbacks create no identity binding/session. Post-callback friendship observations carry
the captured BOT ID and both configuration revisions and are ignored after a switch or credential change.
Existing PKCE, nonce, browser/session binding,
SMS requirements, account closure, HMAC keyring and vault invariants remain authoritative.
The management `revision` continues to advance on every write for OCC. The separate
`authentication_revision` advances only on credential changes (including initial ENV import)
and actual selection changes. Quota/follower retrieval, connection checks, renaming and
rechecking activation of the already active BOT do not cancel login/link. Both switched-out
and newly activated BOTs advance authentication revision, rejecting switch-away-and-back
callbacks as well as stale post-callback observations.

Friendships, pending follows and webhook deduplication are BOT-scoped.
Observations are ordered per BOT/account; equal-time unfollow wins.
Reward eligibility and the additional partial unique reward index remain global per member.
Switching BOTs neither grants a second reward nor copies confirmed friendship.
Encrypted unlinked pending identities remain available for later linking and D7;
the delivery eligibility and bulk recovery implementations are deferred.

## Migration108 allocation and compatibility

File: `2026_11_10_000108_add_v2_line_bot_management.php`.
The shared Migration Allocation flock was acquired before reservation/creation.
Main107,81 local/fetched refs, live remote branches, open PRs, existing worktrees,
active task policies and the shared reservation ledger were checked for collisions.
The logical108 reservation remains assigned to D1 through its Draft handoff/Change closeout.
The exact physical lock handoff state is recorded in the PR and shared coordination ledger.

| Object | Change |
| --- | --- |
| line_login_settings | Site singleton, encrypted secret, revision, Admin reference |
| line_bots | Stable UUID/Channel ID, encrypted secret, status, snapshots, revisions, Admin references; at most one active and one legacy BOT |
| line_friendships | Initial BOT FK/default, BOT+subject/user uniqueness, global member reward uniqueness |
| line_pending_follows | Initial BOT FK/default, BOT+subject/claimed-user uniqueness |
| line_webhook_events | Initial BOT FK/default, BOT+event deduplication |
| line_friend_observations | BOT/account observation ordering, initial snapshot copied from legacy identity observations |
| external_identity_transactions | Nullable login revision, BOT FK and BOT revision |

Constant FK defaults associate existing rows without updating terminal rows or encrypted bytes.
Independent BOT membership triggers prevent reassignment. The installed ciphertext, pending-state
and event-state guard functions remain unchanged. Migration107 SHA256:
`d24dcf68dacf78e16aa96d8c7e1f9515d56b1e26749ba5db20569afae61dc2df`.
All older migration files are unchanged. The source is forward-only; rollback must retain schema
and history and use a separately reviewed source correction. Do not run down migrations.

Synthetic verification covers a clean1..108 database and a transaction-local clean1..107 schema
with claimed/unfollowed pending rows, terminal sent/failed/skipped events and encrypted identities.
It checks exact pre-existing bytes/fields and installed guard definitions after108, and verifies
that forbidden ciphertext/history/membership changes are rejected.
Runtime107 application status is **unverified** in every operational environment.
Operational migration applications, ENV changes and runtime activations are zero.

PR572 owns separate DB smoke diagnostics. D1's shared-file change is limited to schema inventory
and forward-only108/109 registration. Recheck latest main/open reservations before future integration.

## PR681 review correction and Migration109

The 2026-11-11 Human request keeps R3/Strict/deferred/Draft. Main
`cc371b08c4c01f7327ab8560fe24724ea262f427` was compared with the original base;
its trusted-proxy and OLD Test preparation changes are not copied into this Change.
The separate allocation lock was reacquired before reserving
`2026_11_11_000109_separate_v2_line_bot_auth_revision.php`, after checking32 live heads,
81 local/fetched refs, open PR files, worktrees, policies and the shared reservation ledger.
No competing109 reservation or LINE schema/trigger change was found. Logical108/109
reservations remain assigned to this Draft until explicit handoff/closeout.

109 adds `line_bots.authentication_revision`, initially equal to each existing management
revision so valid108-era OAuth snapshots remain valid. It does not rewrite OAuth transactions,
terminal rows, ciphertext or existing triggers. A callback already stale before109 still
requires restart. Existing107 and108 remain byte-identical. Clean1..109 and107→108→109
synthetic upgrade checks cover generation backfill/constraints and unchanged history/guards.
Rollback is forward-only: retain schema and deploy a reviewed correction; never restore a
writer that fails to advance authentication revision. No operational migration is performed.

## D2–D7 and Human handoff

- D3 must serialize entering `sending` on the same singleton lock. D1 rejects activation while
  any `line_deliveries.status = sending` exists. D3 must bind batches to their BOT and revalidate
  before sending; scheduled/unsent old-BOT batches must never silently use the new credentials.
  Automatic retries reuse the exact encrypted first expansion and retry key.
- D6 chat replies need the same BOT selection/serialization boundary. Media acquisition reuses
  the D3 worker every three seconds; private S3, unlinked recipients and all specified media
  types remain future scope. No chat/media/unsend implementation is included.
- Latest Human D6 decision: preserve original data and display “送信取消済み”. Human acknowledges
  [LINE unsend guidance](https://developers.line.biz/en/docs/messaging-api/receiving-messages/#processing-on-receipt-of-unsend-event)
  recommends preventing later viewing/use and describes deleting stored content.
  Before D6 implementation, recheck the official terms/guidance and present any necessary
  specification change for Human approval. Do not delete originals or alter this decision
  without new approval. This follow-up does not block D1.
- D7 reuses encrypted unlinked pending identities; no real friend-ID acquisition/backfill runs here.
- Human must keep Login and Messaging channels in the same Provider, update LINE Developers'
  linked official account and the existing Admin friend-add URL when switching, and configure
  each Webhook URL. No automatic provider configuration is performed.
- Browser acceptance requiring login/Admin MFA and real LINE is **NOT RUN — Human Browser
  Acceptance Pending**. Review secrets/status, revision conflicts, fresh authorization,
  connection failure, legacy URL and BOT switch behavior after a separately approved activation.
- D2 may begin only after Human Source integration GO and a new latest-main checkout.
  This Draft is not Ready, merged, deployed or released.
