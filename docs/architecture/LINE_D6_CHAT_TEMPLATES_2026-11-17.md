# LINE D6 chat and templates — implementation record

> Authorization update (2026-11-18): [latest Human decision](LINE_D1_D7_AUTHORIZATION_OVERRIDE_2026-11-18.md) supersedes all LINE-specific Fresh MFA/TOTP/WebAuthn requirements below. Existing roles, login/session, CSRF and D7 Human/provider attestations remain required.

Change LINE-DELIVERY-20261109-D6; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Human authorization ends at Draft PR,
five successful required checks and fresh exact-head Strict self-review.
This document records source implementation and deferred runtime conditions.
Final exact-head checks and review evidence are recorded on the Draft PR.

## Authority and isolation

Start base and directly checked remote main:
`0b6735e6f4aae730a4db798c70f83ef2232d0fef`.
Dedicated branch `feat/LINE-DELIVERY-20261109-D6` protects the occupied CONTACT
checkout and its uncommitted Worklog. No production authority, payload,
deployment, runtime, environment, worker or applied migration is changed.
Migrations 000107–000114 are immutable.

The Human D6 instruction dated 2026-11-17 overrides the older v1.4 retention,
S3, deferred content-fetch worker, linked-only reply and opt-out assumptions.
Messages are retained; unsend retains a tombstone and removes normal access to
the original content. Replies are transactional D3 pushes, including eligible
unlinked friends, with no new sending transport or pacing rule.

## Reuse boundaries

- D5 LineMessageEditor and verified immutable public media support five message
  types, at most five messages, approved tags and full-image imagemap links.
- D3 enqueuePush with source chat and durable UUID owns batch and quota creation.
  Chat history and enqueue must commit in one transaction. No provider send
  occurs in the request that enqueues a reply.
- D2 final eligibility and authentication revision fences remain authoritative.
  A conversation never invents a member or follower observation.
- Chat permissions are read/reply for owner/admin/operator. Template management
  is owner/admin; template reading includes operator. Existing BOT management
  and public-media creation permissions are not broadened.
- Templates copy into new drafts; edits cannot mutate previously frozen batches.

## Incoming content and runtime prerequisite

The existing local filesystem disk uses `storage/app/private`. The compose
source mounts the existing persistent API asset volume at that location. A
read-only inspection of the currently running OLD Test API found that same
volume mounted read-only. Existing persistence therefore does not establish
that this runtime can write received attachments. No mount was changed.

Implementation uses a distinct private chat prefix and an authenticated
Admin endpoint, never the D5 public media path or S3 presigned URLs. Write
failure must be represented as unavailable, never as successful preservation.
Before a separately authorized runtime activation, confirm private storage
write access, cross-surface visibility, backup and capacity under the approved
runtime authority. This investigation does not authorize a runtime change.

Official documentation checked on 2026-11-17:
[receiving messages](https://developers.line.biz/ja/docs/messaging-api/receiving-messages/)
and [Messaging API reference](https://developers.line.biz/en/reference/messaging-api/).
LINE recommends asynchronous event processing; the Human decision instead
requires bounded synchronous content acquisition without a later fetch worker.
Content not ready within that opportunity must retain an accurate unavailable
state. Complete preservation of content that becomes ready later is not
guaranteed by that design.

## Implemented boundaries

Migration000115 was allocated under the exclusive Migration Allocation Lock
after rechecking remote main,88 fetched/local refs,21 open PRs, registered
worktrees and the shared reservation ledger. It adds conversations, encrypted
chat history, BOT-scoped event deduplication, categories and encrypted templates.
The logical reservation remains permanent. Physical-lock handoff is recorded
on the PR. Migrations000107–000114 remain byte-identical to the base.

Webhook chat handling follows existing signature/friend processing. Only
one-to-one events on the active BOT/generation are accepted. BOT/subject hash
identity and event/message uniqueness preserve duplicate and out-of-order
events, including unsend before the original message. Tombstones clear body
and attachment references; historical rows cannot be deleted or rewritten.
No new follower observation, account or member reward is inferred from chat.
Later member linking is resolved from current, non-revoked identity bindings;
suspended/closed members are hidden. Existing encrypted attachment bytes may
remain after cancellation, with no normal API reference or retrieval path.

The synchronous media opportunity has an eight-second budget, at most100
events, one fetch claim per message, fixed LINE host and no redirects,8MiB
stream/file cap, MIME/type checks and bounded image dimensions. Database lock
waits are limited to500ms and statements to1500ms. A processing deadline or DB
failure returns a safe503; previously committed events deduplicate on retry.
202 remains pending_external, other acquisition/write failures unavailable.
A crashed fetching claim displays unavailable and is never retried by a worker.
Incoming attachment names/URLs are not trusted or retained. Ciphertext is
written under a random private BOT/conversation/message key and checked for
successful persistence before it is marked stored. Authenticated attachment
responses are bounded, no-store, nosniff and sandboxed. Image/video/audio
previews use user-requested authenticated blobs; other files download as octet
stream. CSP permits blob media only on the chat route.

Admin list/history are paginated; history uses received-time/id ordering.
Read watermarks do not mark later arrivals read. Status writes use revisions.
The reply UUID binds administrator, conversation and immutable content; history,
D3 batch and common quota reservation commit together. Retrying the same request
returns the same message. The editor freezes an ambiguous request until retry
resolves; there is no automatic new-key resend. API acceptance does not assert
LINE arrival/read. Reloading the page requires checking existing history before
submitting a new operation. Operator media selection is read-only over D5's
verified library; Owner/Fresh-MFA creation rights remain unchanged.

Categories are BOT-scoped, ordered and revisioned; deletion while used is
rejected. Templates are encrypted, revisioned and validated by D5. Copying into
chat or a new broadcast creates independent editor content. Existing scheduled
deliveries and D3 frozen payloads are never edited by template operations.
Admin OpenAPI, generated types, client, role permissions and navigation agree;
Public contracts and financial authority are unchanged. Audit events contain
only operation/actor/target identifiers, not message content or attachment keys.

## Verification record

Isolated synthetic PostgreSQL/Redis and fake HTTP/storage cover receive types,
signature/active-BOT boundaries, duplicate/cancellation ordering, private
attachment retrieval,202/404/MIME/size/deadline/write failure, read watermark,
unlinked/linked replies, quota rollback, concurrent lock fencing, roles/CSRF,
category/template revisions and old migration history. No real LINE/S3 call is
part of these tests. Final counts and any test-fixture corrections are reported
on the PR, including clean/historical/repeated upgrade and backup/restore.
Admin lint/typecheck and related DOM checks, OpenAPI generation, policy and DB
guards are executed; required CI supplies the canonical pinned build/runtime
checks. Local cached image and dependencies are reused; no local image build.
Human Browser Acceptance: NOT RUN — Human Browser Acceptance Pending.

## D7 and runtime handoff

D7 must retain same-BOT observed friend identity, safe later member linking,
unknown follow dates and separation from reward eligibility. No recovery
command is added or executed by D6. Quota opening baseline, actual BOT/friend
state, webhook/storage acceptance and browser verification require later GO.
D5's known FFmpeg SEV-2 remains accepted for Source Merge only; fixed-image
SBOM and reachable dependency re-audit remain prerequisites for runtime.
Before runtime, additionally verify private-storage write permission under the
actual API identity, persistence across replacement, backup/restore of both
database and encrypted files plus key availability, authenticated cross-surface
retrieval, capacity/monitoring and cancellation visibility. No runtime volume,
permission, ENV, worker, Production Authority or payload is modified here.
No opt-out, retention/deletion worker, media fetch worker, D4 HOLD recovery or
shipping_url support is added. Rollback is source rollback with115 history
retained; schema down deliberately fails closed.
