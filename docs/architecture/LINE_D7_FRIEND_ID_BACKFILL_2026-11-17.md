# LINE D7 friend ID recovery — design and manual runbook

> Authorization update (2026-11-18): [latest Human decision](LINE_D1_D7_AUTHORIZATION_OVERRIDE_2026-11-18.md) supersedes all LINE-specific Fresh MFA/TOTP/WebAuthn requirements below. Existing roles, login/session, CSRF and D7 Human/provider attestations remain required.

Change LINE-DELIVERY-20261109-D7; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Base492efde433f49c66ad11d28cd343543688f73b88.
Authorization ends at Draft PR, Required5 and fresh Strict self-review.
This source is not permission to execute recovery or activate any runtime.

## Human decisions and precedence

Specification1.4 chapter13 says to discard unmatched IDs and not retain an ID
list in a database. The later merged
[D2 contract](LINE_D2_RECIPIENT_BOUNDARIES_2026-11-15.md) explicitly authorizes
encrypted same-BOT observations for unlinked subjects, including recognition
after later linking. That newer Human decision takes precedence; D7 uses the
existing registry, vault, HMAC keyring and recipient resolver without replacing
their contracts. No plaintext ID table, file, CSV or diagnostic is introduced.

The additional Human D7 approval explicitly permits temporary encrypted staging
solely to establish complete follower-list acquisition. It does not permit
partial acquisition to alter formal friend or member state. Phase A writes only
D7 staging and aggregate progress. Phase B begins only after all pages succeed
and the captured active BOT/authentication revision still matches.

## Acquisition and failure boundaries

The existing V2LineBotTransport issues/caches encrypted access tokens. A dedicated
method uses only HTTPS api.line.me/v2/bot/followers/ids, no redirects,1000 IDs per
request,3-second connection and10-second request limits, and128KiB response cap.
Unknown shapes, invalid user IDs, invalid continuation strings and repeated or
cyclic tokens fail closed. Continuation tokens remain in process memory only;
only bounded in-memory SHA256 digests support cycle detection. Token strings,
credentials, response bodies and exception chains never enter output or audit.

[Official Messaging API reference](https://developers.line.biz/en/reference/messaging-api/#get-follower-ids)
checked2026-11-17: limit maximum1000; a short page may still have next; continuation
tokens expire after24 hours;403 means verified/premium-account access is absent.
The command follows next until absent, with a stricter one-hour acquisition
deadline and10000-page safety bound. Exhausting a bound is failure, never success
or silent truncation. Request pacing is at most two follower requests/second.
400/401/403/429/5xx, timeout or unexpected responses terminate acquisition with
zero formal recovery/member-ciphertext writes. There is no automatic HTTP retry,
alternate BOT, alternate API, token restart or quota mutation. After rate limits
or outages, an operator may separately authorize a fresh run.

Observation time is captured immediately before each request, conservatively
bounding the acquisition instant; DB delay never advances it. A duplicate within
one acquisition retains its first staged observation. It cannot invent a follow
date, receipt/read proof or permanent deliverability.

## Storage, locking and restart

Migration000116 was allocated under the shared exclusive allocation lock after
109 refs,21 open PR heads,68 worktrees,active policies and the permanent ledger
were audited.000107–000115 remain immutable. The migration adds only
line_friend_id_runs and line_friend_id_staging. Run identity, BOT, generation,
operator UUID and expiry cannot be rebound; aggregate progress is retained.
Staged HMAC/ciphertext bindings cannot be updated. The existing recovered-history
guards and D2 recipient sources are unchanged.

A PostgreSQL session advisory lock permits one D7 invocation at a time, including
cleanup/resume; process termination releases it. Every database phase verifies
the connection identity. HTTP is outside database transactions. The existing
settings singleton fence is reacquired for each page and each formal subject
write, with1-second lock/5-second statement limits. A changed BOT or generation
rejects the response/write; switching away and back cannot restore authority.

Phase A inserts encrypted rows keyed by run/HMAC. No recovered, friendship,
pending-follow,identity-ciphertext,member,reward or delivery state changes.
On acquisition failure, the run is failed before chunked staging deletion.
If the database is unavailable, output says unknown and retains the run UUID;
it never asserts successful cleanup or zero committed effects without evidence.

Phase B reads one staged subject at a time. Existing vault backfill and registry
record, aggregate counters and staged-row deletion commit together. An interrupted
run reports applied counts and partial result. A ready/applying run may resume
without any HTTP call, under the original BOT/generation and original observation
timestamps. Retry preserves ciphertext, HMAC compatibility and monotonic history.
Completed rows are removed immediately; failed rows are removed in500-row chunks.
No compensation/delete of formal recovery history is attempted.

Staging expires24 hours after run creation. The next manual invocation or explicit
cleanup-only invocation discards abandoned acquisition and expired staging.
Ready/applying staging remains resumable until expiry; expiration preserves any
already committed formal observations and aggregate counts. No worker/scheduler
is added. If there is no later manual invocation, physical deletion is not
guaranteed at24 hours: operators must schedule a Human-owned cleanup action.

Access uses the existing restricted application DB role and existing application
encryption/HMAC keys. Do not grant reporting, Admin UI, export or recipient
selection access to staging. Ciphertext/HMAC may remain in WAL and encrypted
backups after SQL deletion, subject to the site's existing approved backup/WAL
retention and access policy. Deletion is not secure erasure; backup expiry and
key availability remain operational responsibilities. No backup policy or key
rotation is changed by D7. Key loss/removal may make a staged run non-resumable;
expire/clean it and authorize a fresh acquisition, never substitute identity.

## Deferred manual operation

Only after separately approved source merge, runtime deployment, schema/key
readiness and explicit Human execution GO may a trusted shell operator run:

```sh
php artisan v2:line:backfill-friend-ids --human-approved --same-provider-confirmed --actor=<operator-uuid>
php artisan v2:line:backfill-friend-ids --human-approved --same-provider-confirmed --actor=<operator-uuid> --resume=<run-uuid>
php artisan v2:line:backfill-friend-ids --human-approved --actor=<operator-uuid> --cleanup-only
```

The flags attest an existing approval; they are not an approval mechanism or an
HTTP authorization boundary. Record the operator UUID in the private operational
approval record, without adding names/emails to command diagnostics. Confirm the
LINE Login channel and active Messaging BOT share the approved provider in LINE
Developers; no provider identity is inferred from channel IDs. The API does not
provide that provider relationship through this endpoint. Existing connection
success is required and actual endpoint eligibility is established by acquisition.
Missing active BOT/configuration/tables/keys fails closed without legacy fallback.

Output and audit action line.friend_ids.backfill contain run/operator/BOT
identifiers,state and counts only. created/updated/unchanged partition applied;
filled counts existing null ciphertexts complemented;duplicates counts duplicate
acquired IDs;fetched includes all accepted page entries. failures counts failed
attempts, not an invented number of rejected people. http_status403 identifies
the account eligibility problem. ready/applying with partial result requires
inspection/resume;unknown requires reading durable progress before any retry.
No Admin button, Web API, Worker or Scheduler calls this command.

## D1–D7 final handoff and exclusions

D1 BOT/Webhook/rewards remain authoritative;D2 owns eligibility and later linking;
D3 owns sending,quota,retry identity and final recheck;D4 automated notifications
and HOLD are unchanged;D5 owns broadcast/media;D6 owns chat/templates;D7 adds only
manual two-phase observations. Newer negative/unfollow and equal-time negative
still win. No follow event/date,registration/linking,benefit,rank,point or payment
is fabricated. An old snapshot cannot establish current deliverability.

Preserve [D6 handoff](LINE_D6_CHAT_TEMPLATES_2026-11-17.md), including private
storage acceptance,known FFmpeg SEV2 Source-only acceptance and D6 SEV3 concurrent
attachment/unsend limitation. Runtime SBOM/reachability re-audit remains required.
shipping_url remains a separate Change. CONTACT,other worktrees and PR692/693
Production Authority/Payload are out of scope. No Public/Admin OpenAPI change.

Rollback disables D7 by restoring the previous application source, retains the
additive schema and immutable formal observations, and uses an approved manual
cleanup for residual staging. Migration down is rejected. Formal deletion,
rewriting history or reversing legitimate observations is not a rollback method.

## Verification status

Exact executed test counts,initial fixture failures,Required Checks and fresh
fixed-head self-review are recorded in the Worklog and Draft PR. Mock HTTP and
isolated synthetic PostgreSQL only; no real users or LINE accounts.
Browser/E2E,operational migration,Runtime,real LINE/S3/CDN and command execution
against an operational environment are NOT RUN and require separate Human GO.
