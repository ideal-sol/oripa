# Contact Production Preparation: source review and deferred runtime plan

Status: Repository/build-path preparation only. R4, Strict Change, Application
Runtime Activation `none`. No Production artifact dispatch, migration, service,
ENV, Nginx, mail, or activation is authorized by this document.

## Exact authority

- Platform Runtime Source: `e16f65504dc5286de2fcd70988b770d1a16d1eaf`, PR #496,
  dispatch Change ID `PREFILL-20260924`.
- Initial protected workflow base: `369d6670f40d28419ad22a4b93cacc44a11a6ff0`.
  The source-to-base diff has only the contract ledger, release documentation,
  two test files and Worklog. Application Runtime delta: NONE.
- Storefront Runtime Source: `342341a82131a7f80a4e7508172f1e764ec7ad84`.
  Contract bundle/Client/Testkit: `2.0.0-alpha.38`; Public OpenAPI has its own
  version `2.0.0-alpha.34`. These version numbers must not be conflated.
- Immutable Contract Artifact ID: `10786577343`. The Storefront protected
  metadata and verifier bind the requested source, exact package/lockfile pins,
  corresponding `CONTACT-PREFILL-001` manifest, provenance ID and all approved
  SHA-256 digests. Retained historical alpha.36 files are not Build provenance.
- Storefront PR #110 reviewed/final head:
  `68c4a869655797189d8f928c65290f7c81f9fc0c`. GitHub run `35947415604` has all five
  Required Checks successful for that head. The self-review is
  [PR #110 evidence](https://github.com/ideal-sol/luxe-pack-storefront/pull/110#issuecomment-5806420179).
  Both reviewed and squash trees are
  `eb6a989bfe037e07574661db7046c68c205371f9`; direct content diff is zero.
  Empty check-runs on the squash commit do not negate these checks. No rerun.

## Migration 000076

Read source:
`apps/api/database/migrations-v2/2026_10_02_000076_add_v2_contact_reply_and_follow_up.php`.
No migration source is changed and no migration is applied by this Change.

Laravel's `Migration::$withinTransaction` defaults to true; this migration does
not override it. `Migrator::runMigration` wraps up/down in the connection
transaction when the schema grammar supports it. PostgreSQL grammar enables
schema transactions. Thus the ordinary Laravel PostgreSQL migration path wraps
all DDL, template DML and guard replacement in one transaction. Directly calling
`up()` outside the Migrator is not that contract and is not the deployment plan.

Up creates `contact_user_messages`: bigint identity/primary key, unique UUID,
restrict-on-delete FKs to `contact_inquiries` and `users`, ciphertext body,
request UUID, timestamp, and `(contact_inquiry_id, id)` index. It installs two
append-only triggers rejecting UPDATE/DELETE and TRUNCATE through the existing
`v2_contact_reject_history_mutation()` function.

The indexes are built on a new empty table. FK validation concerns that empty
referencing table; no bulk update, existing-table index build, table rewrite or
full scan of existing users/contact rows is requested. The two referenced
tables do acquire SHARE ROW EXCLUSIVE locks for FK creation, blocking concurrent
writes while held. New-table DDL takes strong locks on the new table.

Then up drops `mail_templates_fixed_set_guard`, inserts exactly one
`contact_reply` template (new UUID, revision 1, timestamps), and recreates the
same BEFORE INSERT/UPDATE/DELETE row trigger using the unchanged
`v2_mail_templates_guard_fixed_rows()` function. No existing template is
updated/deleted; no mail is sent. The guard rejects INSERT/DELETE and changes to
template identity/label, while allowing the established template-content edits.

DROP TRIGGER takes ACCESS EXCLUSIVE on `mail_templates`, held until transaction
end; CREATE TRIGGER takes SHARE ROW EXCLUSIVE, without reducing the already-held
stronger lock. Existing template reads and writes can wait, including mail
render/schedule operations. A message already rendered before the lock may
continue its external send. Other sessions cannot exploit a committed guard-free
window: the replacement and insert commit together, or all roll back. Locks and
trigger changes are supported by [PostgreSQL locking documentation](https://www.postgresql.org/docs/17/explicit-locking.html),
[FK lock documentation](https://www.postgresql.org/docs/17/sql-altertable.html),
and [PostgreSQL 17 trigger source](https://github.com/postgres/postgres/blob/REL_17_STABLE/src/backend/commands/trigger.c).

Expected work is small catalog DDL plus one indexed insert. Duration is NOT
measured on Production; lock waits/long transactions and catalog/I/O contention
can dominate and make elapsed time unbounded by this source. No lock_timeout or
statement_timeout is set here. A later migration GO must choose a bounded
maintenance window and session timeout/abort procedure without changing ENV.
There is no up-time row-count-dependent backfill. Production row counts have
not been read by this task.

Down first uses EXISTS on `contact_user_messages`, then (when empty) EXISTS on
`outbox_messages.event_type = contact.reply.email.requested`. Any follow-up or
any such new event, including delivered/failed events, throws before destructive
DDL. No event-status exception exists. The Outbox check can scan existing rows:
the base indexes are status/availability, lease expiry and aggregate identity,
not event_type; cost depends on Outbox size and where a match occurs.

Only with both checks empty does down drop the new table, drop the template
guard, delete `contact_reply`, and recreate the guard. Existing FKs/references,
missing expected guard, permission errors or other SQL failures also fail the
transaction. Down does not check template edits/revision, and would discard an
edited template if permitted. It does not establish a safe concurrent-writer
rollback protocol: stop new contact producers/consumers before considering DB
rollback. Never delete history/events to force down to pass.

Application rollback and DB rollback are separate. The old application can run
against the additive schema with its own matching config; the old mail catalog
iterates its configured keys and ignores the additional DB row. Do not combine
old code with new cached config (the old/new catalog counts are 16/17). New data
must stay retained, although old UI cannot display follow-ups and old API emits
legacy reply events. Stop the new consumer and prevent unsupported follow-up
traffic before an application rollback. After new follow-up/reply-event data,
DB down is prohibited; retain 000076 and use forward correction.

## Compatibility and ordering

The approved new sources above are fixed. The old Production runtime SHAs were
not supplied/inspected in this repository-only phase. For reproducible source
comparison, pre-000076 Platform is `a8e3079` (parent of `b63c37e`), pre-phone-required
Platform is `df2c67449ec4a885a5e41fcec8128145c0eb9ee9`, and old Storefront examples
are `84a32e05d0d23822b17cd9fdbbc6a5018945bf77` (pre-follow-up) and
`814990b1abf3be571c127f111d7a7d787dcf62a1` (alpha.37). These are source comparisons,
not claims about currently active Production bytes. Reconcile the actual old
image/source and matching config before activation GO.

| Combination | Source result |
| --- | --- |
| 000076 -> old API | SAFE for existing behavior after commit: additive table/template; no existing column, constraint or function removed. Operational lock window remains. |
| new API -> old Storefront | UNSAFE for guaranteed submission: old phone input is optional and sends null/empty; new API requires a nonempty string (1..32 chars), returning 422 otherwise. Pre-follow-up clients also lack the new authenticated/idempotent contract. |
| new API -> old Admin | Existing requests/session remain compatible; Admin session implementation unchanged, user session additions are in a separate realm. Contact detail adds optional user_messages and mail template enum gains contact_reply. The runtime client casts JSON, not strict schema parsing. Old UI omits new history, so it is not full feature acceptance. |
| new Storefront -> pre-000076 API | Ordinary inquiry fields including phone are accepted, but UNSAFE for full semantics: inquiry_id and Idempotency-Key are ignored, causing a new inquiry instead of follow-up and no replay guarantee. Login-session missing prefill fields fall back to manual input; SMS lookup failure is handled. |
| new Storefront -> alpha.37 API | SAFE for Contact source contract: authenticated follow-up/idempotency already exist; required phone is accepted by the older optional field. Current-user prefill can fall back to manual input. |
| new Storefront -> new API | SAFE for the approved Contact contract: required name/email/phone, canonical client, inquiry_id ownership/fallback, authenticated session/CSRF and stable Idempotency-Key all align. |
| Outbox -> Contact Worker off | DB reply/history/status/audit and new pending Outbox event commit together. API returns queued; no delivery success is promised. New email is not sent until this dedicated consumer is activated. |

Recommended future sequence (NOT EXECUTED):

1. Migration 000076, with the bounded lock window and post-commit guard check.
2. Storefront exact approved source, before phone-required API.
3. API exact approved source; verify contact contract/config readiness.
4. Admin from the approved Platform artifact; verify follow-up/history UI.
5. Dedicated Contact Reply Mail Worker after mail authority/readiness GO.

Keep contact submission/administrative reply traffic controlled during the
Storefront/API cutover if the old API predates alpha.37. Do not claim seamless
follow-up/idempotency during that window. Existing already-loaded old browser
pages can still submit missing phone after cutover; require refresh/re-entry and
expect 422 on stale requests. Worker-last prevents newly sent follow-up links
from reaching an old API and allows pending new events to accumulate safely.
The current Storefront must precede the phone-required API unless equivalent
traffic gating proves no old requests can reach it.

## Deferred Contact Reply Mail Worker plan

No canonical Production Contact service currently exists in the Repository.
Proposed canonical service name: `contact-reply-mail-worker`, with a unique
worker identity per instance. This is a plan, not an installed Compose service.

| Setting | Proposed runtime authority |
| --- | --- |
| Image | Reuse exact approved API Production image by immutable digest, same OCI source e16f655; no separate image/build. |
| Entrypoint | `php artisan v2:contact:work-reply-mail-outbox --worker=contact-reply-mail-worker --limit=10` |
| Execution | One-shot bounded command, not Laravel queue:work; supervise repeated invocations with 5-second idle delay. No unsupported --sleep/--daemon flag. |
| Working directory / user | `/var/www/backend`, image's non-root `www-data`. |
| Networks | Same site's API private DB network and approved mail egress network; no published port. Exact live network names deferred to runtime preflight. |
| DB | Existing site's PostgreSQL connection, encryption key and audit authority; no new DB or shared site access. |
| Redis | No direct claim/send dependency in this worker: Outbox leasing is PostgreSQL. Inherit existing API bootstrap/config dependencies; it does not consume a Redis queue. |
| Restart | `unless-stopped`; single replica initially, persistent failed/backlog alerting. |
| Poll / limit | 5 seconds between completed command invocations, batch limit 10 (CLI accepts 1..100); claims one event at a time, 60-second lease. |
| Logging | stdout/stderr processed count plus existing audit event contact.reply_mail_processed; existing log authority/rotation; no recipient/body/ENV dump. |
| Liveness | Supervisor/child process check plus heartbeat after each completed batch; heartbeat age and pending oldest-age/failed count distinguish a live but stalled worker. No HTTP health endpoint. |
| Shutdown | Supervisor handles TERM/INT, stops new batches and waits for active child within bounded grace. This one-shot command has no signal/drain implementation; deployment wrapper must implement/test it. Killing in-flight send can leave uncertain delivery. |
| ENV | Inherit only the existing same-site API runtime and approved Mail transport authority from `/etc/oripa-v2/*.env`, never Build inputs. Actual file/key presence is a later read-only preflight; none were read/changed here. |
| New ENV keys | NONE. Poll/limit/service identity are command/supervisor settings. |
| Legacy event | Never claim contact.reply.requested; only topic contact.notification plus event contact.reply.email.requested. |

Compose candidate should use `image`, `working_dir`, `user`, existing `env_file`
bindings, the approved network memberships, no ports, an explicit supervised
command and `restart: unless-stopped`. Do not reuse the API HTTP healthcheck.
Use existing writable log/cache conventions and an ephemeral heartbeat file;
do not mount repository source or add a build stanza. A signal-safe supervisor
definition is deferred to the separate runtime implementation GO.

Read-only key-presence preflight must cover APP_KEY, PostgreSQL DB_* authority,
the existing audit keyring settings, MAIL_MAILER/MAIL_FROM_* and the selected
transport's existing credentials/settings (SMTP or Mailgun), plus the existing
public-origin authority used for inquiry links. Inspect names/presence only;
do not expose values or invent a new mail provider. Inherit effective API mail
config, not an old worker image's code. `V2ContactReplyMailService` uses Laravel
Mail::html and resolves recipient from the current registered User email.

Pending backlog is durable; no claim means attempts remain zero. On activation,
the worker uses FOR UPDATE SKIP LOCKED, first-attempt sends and then delivered.
Failures are terminal failed events; there is no automatic mail retry. Reclaimed
expired leases have attempts >1 and are marked uncertain/failed without resending.
Operator reconciliation is required for uncertain sends. Command exit success
and processed count do not prove delivered: delivery failures are caught and
audited, so monitor states explicitly. Legacy events, receipt/admin notification
events and other worker queues are excluded. Mail is rendered at delivery time
from current registered identity/template, not a frozen recipient/body snapshot.
