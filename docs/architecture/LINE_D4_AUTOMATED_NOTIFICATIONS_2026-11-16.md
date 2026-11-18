# D4 automated LINE notifications

> Authorization update (2026-11-18): [latest Human decision](LINE_D1_D7_AUTHORIZATION_OVERRIDE_2026-11-18.md) supersedes all LINE-specific Fresh MFA/TOTP/WebAuthn requirements below. Existing roles, login/session, CSRF and D7 Human/provider attestations remain required.

Change LINE-DELIVERY-20261109-D4; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Authorization ends at Draft PR,
Required Checks and Fresh Strict self-review. No Ready, merge or activation.

## Authority and integration

Human D4 prompt2026-11-16 v1.0, specification1.4 sections9/11/13–15 and the
subsequent Human shipping-URL and PR693 integration decisions govern this Change.
D1 PR681, D2 PR690 and D3 PR691 are merged source prerequisites.
Base main04cc02d8ebace3cd0b7cc52a1ba90d83ba523a78 incorporates PR693.
Both Changes' policy tests and Worklogs are retained; PR693 Authority/Payload
and operational validation paths remain byte-identical to that main.
The limited Production Source516fe7a946683103ed68ee4875d5ea3d94327a0d,
Tree53bf0e809b9724392e5af6eda75b1d1c15d333e2 and
Inventory4d33d8e2de4f08a41af6e009391eda9d53fb38d3201aa3eeacc15f82ad81254e
are unchanged. D4 source is not a Production Payload.

## Events and transaction ownership

No existing application consumer claims the following topic/event pairs at
the integrated base. D4 claims each pair separately through V2OutboxService;
it does not claim unrelated payment adjustments or other topics.

| Notification | Source | Stable identity and eligibility |
| --- | --- | --- |
| Shipping | shipping.status / shipping.status.changed | Shipping aggregate UUID; only the first real transition to shipped. Re-shipment, delivered and repeated processing do not notify again. QA draw items are excluded. |
| Payment | payment.lifecycle / payment.succeeded | payment_public_id and the durable payment_point_grants row; the common success writer covers providers. Pending/failure/refund/chargeback events are not claimed. |
| First purchase | Same payment success event | Lowest grant ID for that member, under the existing success writer's member-row serialization. Subsequent purchases or reversed worker order cannot displace the first success. Retained grants remain facts after adjustments. |
| Rank promotion | member_rank.events / member_rank.promoted | Existing member_rank_changes.public_id, introduced by000091; only to_level > from_level. No new rank identity table or backfill. |

Rank history insertion and Outbox append occur in the owning domain transaction.
The recorder covers purchase accounting, manual rank, manual cumulative-point
increases and EventPromotionService, used by monthly and transfer processing.
The existing rank-code mapping is reused. One actual jump produces one event;
bonus entitlement rows do not invent intermediate rank transitions. Demotion,
same-rank changes, read projections and compensation without a promotion do not
emit promotion events. Existing financial, bonus and eligibility decisions remain
in their original services. Rollback removes both the history and event.

Payload is `{user_public_id, from_rank_code, to_rank_code, change_public_id}`.
Outbox deduplication keys use the existing change UUID. D4 never calls quota,
settings or LINE from a payment/shipping/rank owning transaction.

## Settings, privacy and event-time boundary

Four settings start at revision0/OFF. Shipping/payment are transactional only;
first purchase/rank default to promotional and can select either category.
One common rank-promotion template supports the existing personalization tags.

Settings are append-only encrypted versions with DB-clock effective timestamps.
An event uses the newest revision strictly earlier than its recorded Outbox
creation time. Thus enabling later never backfills an old event, and editing or
disabling affects later events. An earlier event retains its historical setting
even when processed after an edit. The existing Outbox timestamp has second
precision: enablement in that same second is conservatively excluded. The UI
states this event-time behavior; revisions and expected_revision prevent stale
updates. No snapshot is reconstructed from the current template after queuing.

Admin API GET/PUT use existing line.bot.read/line.bot.manage permissions,
Owner-only mutation, fresh MFA, Admin session and CSRF middleware. Read/write
responses are no-store. Audit records include only event/revision/enabled,
never body, ciphertext or recipient. React textareas render values as text.
OpenAPI, generated Admin types and the approved Admin client cover the routes.
Bodies use D3 messages format,1–5 text entries and UTF-16 limits; unverified
media is refused. D3's normal tag validator is unchanged.

## Shipping URL Human decision

No shipping-detail URL is established. A shipping template may retain
`%shipping_url%`, but every such notification is skipped with the durable safe
code `shipping_url_unsupported`; the editor explicitly displays that it cannot
send. Other event types cannot save that tag. Shipping notifications without
the tag use the ordinary delivery path. No guessed URL, Host/Forwarded input
or automatic substitution with `/mypage/prizes` is used. A separate Storefront
design Change must implement the detail page and establish its official URL;
a later reviewed Platform change may then enable substitution. D4 creates no
Storefront page.

## Durable handoff and failure handling

Each notification gets a namespaced UUIDv5 from Outbox UUID plus notification
kind. Payment and first purchase therefore have distinct source keys. The
existing D3 source_key UNIQUE and D4 outcome UNIQUE(outbox_id,event) guard
reprocessing. Only linked-member references from the existing D2 classifier
are eligible; the D2 predicate is rechecked by enqueuePush and D3 before HTTP.
Unlinked friends never receive personal business notifications.

For each leased event, D4 queues all applicable notifications, reserves shared
D3 quota and records outcomes in one transaction, then acknowledges that event
inside the same transaction. Partial quota failure or ack failure rolls back
every handoff from that attempt. `delivered` on the Outbox means durable D3
handoff or a recorded final skip; it is not a LINE delivery receipt.

Quota/DB/validation failures retry after60 seconds, at most5 claims including
expired leases. Exhaustion leaves the original event failed with
`line_automated_handoff_hold`, with no delivered acknowledgement. No automatic
terminal revival, replacement key or loss of the original source event occurs.
Human-directed recovery must diagnose the safe code, establish quota/BOT state,
and explicitly arrange a reviewed requeue of the same event and keys. There is
no D4 recovery UI or new financial rollback path. Historical template versions
remain available for deterministic recovery; operators must review delayed
notification relevance before authorizing recovery.

Only D3 sends LINE HTTP. Its frozen encrypted payload, UUIDv4 retry key,
BOT/authentication revision fencing, final D2 locks, shared quota month coverage,
unknown-after23-hours boundary and approved bounded HTTP transaction remain
unchanged. Username/rank/points are expanded once by D3 at its original send
snapshot; `%rank%` means the then-current rank, not an additional fixed promotion
destination tag. Later setting edits never modify a queued batch or retry.

## Migration, activation and rollback

000113 adds only automated heads, encrypted immutable setting versions and
immutable outcomes. It does not alter000107–000112 or existing rank history.
Allocation was audited under the shared lock; logical113 remains reserved.
Physical lock disposition is recorded at verified Draft handoff. The migration
is forward-only; keep tables/history during rollback and use a reviewed forward
correction. UPDATE/DELETE/TRUNCATE guards protect version and outcome history.

The explicit line-automated Compose profile runs the dedicated finite command;
restart is `no`, no scheduler is registered, and the runtime flag defaults false.
All settings start OFF and missing shared quota baseline still rejects handoff.
No workers were started operationally. Runtime activation, baseline confirmation
and Human browser/login/MFA/real LINE acceptance require separate OLD Test GO.
Stopping D4 consumption does not cancel already committed D3 batches; any such
recovery must respect D3's existing immutable retry and unknown-state controls.

## Verification and handoff

Executed counts, initial failed fixtures, final Required5 and exact-head Strict
review are recorded in the Worklog and Draft PR. No documentation or DOM test
is claimed as browser/E2E or real-LINE acceptance. Tests use synthetic BOTs,
Http::preventStrayRequests and isolated PostgreSQL. Operational migration0,
Runtime0, real LINE0, local image Build0; existing fixture images are reused.

- D5: verified media/creation tools, quota baseline/HOLD UI, and coordination with
  the separate Storefront shipping-detail URL Change. Preserve event revisions,
  unknown outcomes and immutable queued messages.
- D6: use enqueuePush/shared quota, distinct durable source keys and existing D2
  checks; do not add another direct paid-send path.
- D7: preserve same-BOT observed-friend handling and later account linkage. D4
  still requires an actual linked member for individual business events.
- Human Browser Acceptance Pending; operational quota remains unconfigured by
  this task. No Ready, merge, deployment, formal artifact or Production action.
