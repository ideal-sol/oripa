# D5 LINE delivery management — Draft source design

Change LINE-DELIVERY-20261109-D5; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Human approval ends at Draft PR.
Base main: 64ace8b4c66617db1fc359ae11a331da7d62e92b.

The Human D5 instructions and subsequent GD/ffprobe/isolated-CI approval govern
this Change. Source changes do not authorize installing dependencies in OLD Test
or Production, operational migrations, worker startup, real LINE/S3 operations,
formal artifact publication, Ready or merge.

## Responsibility and contracts

Admin routes use the existing line.bot.read and line.bot.manage permissions;
all writes require Owner and Fresh MFA. No new role or permission is introduced.
Admin session/CSRF middleware remains the existing boundary. OpenAPI defines
delivery management, preview, pacing defaults, explicit batch resend, quota
management and upload intent/finalize. D6 templates and chat remain separate.

The message editor emits the existing five D3 message forms. Imagemaps use only
one full-image region. Link destinations require HTTPS and explicit configured
hosts in V2_LINE_DELIVERY_ALLOWED_LINK_HOSTS; absent configuration rejects links.
Text links are unchanged; no openExternalBrowser parameter is appended.
The configured CDN origin supplies media URLs, never Request Host/Forwarded.
The existing public asset resolver's path allowlist is unchanged.

The D3 sender remains the only delivery HTTP authority. Frozen encrypted
payloads, original retry keys, 23h expiry, BOT authentication generation,
pre-send D2 locks and common quota charges remain in force. Accepted means API
acceptance, never proof of delivery or reading. D4 HOLD recovery is absent and
shipping_url remains unsupported until the separate Storefront URL Change.

## Segment and resolution

Fixed JSON fields are ANDed; rank levels within a selected set are ORed.
Member rank uses the existing batched rank projection. Purchase amount uses
commercial payments with durable point grants, minus successful refunds and
chargebacks, plus successful chargeback reversals. It never uses rank points
or transfer allowances. Last purchase means the latest established purchase
timestamp, including historical purchases subsequently adjusted. No purchase
matches the absent-days condition but never the within-days condition.
Wallet points use paid plus free balances. Calendar ranges are inclusive JST
dates. Recovered-friend observation time is never used as follow time.
Unknown member attributes do not match member filters.

The same filter runs after D2 classification for preview and actual resolution.
D2's BOT/subject deduplication stays unchanged. Resolution spools internal
references to a bounded-memory temporary file and creates all batches/charges
in the existing all-or-nothing transaction. Time/statement/lock limits bound
failure rather than permit partial campaign admission. No daily recipient cap
or additional approval workflow is introduced.

## Media boundary

Uploads go directly from the browser to fixed S3 keys under line-upload-private.
Each five-minute intent binds Owner, BOT generation, kind, MIME, byte count and
SHA-256. The signed PUT also binds checksum, length and conditional creation.
Finalize reads actual bytes into private temporary files, checks length/hash,
and runs GD or a bounded local ffprobe process. Caller MIME and flags are not
verification. Public objects use separate admin-assets/line-delivery UUID keys,
conditional creation and immutable manifests. No delete/update API exists.
Concurrent or repeated finalization fails closed; failed partial publication
requires a new intent, retaining any created objects rather than overwriting.
Future D6 incoming attachments must use a separate private prefix.

GD enforces pixel/byte limits, actual JPEG/PNG decoding and generated preview
limits. Imagemaps produce 240/300/460/700/1040 widths. Carousel assets must be
square and are re-encoded to 1024 pixels and at most1MiB. Video probing uses
fixed argv, local files only, MOV/MP4 demuxing, disabled external data references,
15-second wall/10-second CPU/512MiB address-space limits and bounded probe/output.
The process inherits no application environment; stream decoding heuristics are
disabled with -nofind_stream_info. This verifies MP4 container metadata, not
complete playback of every frame. Missing/invalid dimensions fail closed.
Missing dependencies reject media. Real CDN/IAM/CORS configuration remains a
later Human operation; this Change supplies no real credentials or bucket changes.

Debian's ffmpeg binary package supplies ffprobe; no media service is added.
The existing PHP version, Composer lock and digest-pinned base images remain.
GD and runtime shared libraries are present in future Production/Preview as well
as development definitions. The production PHP/Apache verification remains the
final Docker build assertion; dependency checks precede it.

Dependency review (2026-11-16): the isolated Debian Bookworm package is
ffmpeg7:5.1.9-0+deb12u1; amd64 and arm64 packages are published by Debian.
[Debian package and copyright](https://packages.debian.org/bookworm/ffmpeg)
provide ffprobe in the ffmpeg binary package (no standalone ffprobe package).
Runtime stages copy only ffprobe and its package copyright notices from the
same-architecture build stage and install the eight FFmpeg shared-library
packages reported by its ELF NEEDED entries, with their OS dependencies.
ffmpeg/ffplay/qt-faststart executables are not shipped in
Production/Preview; the encoder is only used by synthetic test fixtures.
No separately operated media service is introduced. Debian's GPL-enabled build
and retained package copyright notices apply. GD uses the existing PHP bundled
extension with JPEG/PNG runtime libraries; Composer dependencies are unchanged.

[Debian security tracker](https://security-tracker.debian.org/tracker/source-package/ffmpeg)
reports unresolved issues: package installation is not a vulnerability-free
claim. Network demuxers, RTP output, encoding and heuristic frame decoding are
outside this fixed local MOV metadata invocation. In particular
[CVE-2026-125262](https://security-tracker.debian.org/tracker/CVE-2026-125262)
concerns LCEVC frame decoding; heuristic decoding is disabled. This containment
is not a general OS sandbox or a waiver for upstream vulnerabilities. Record
this as a residual dependency risk, re-audit the exact image SBOM before any
later runtime activation, and do not extend probe formats/decoding without
security review. No Security Gate exception or suppression is introduced.

## Migration and handoff

000114 is formally reserved under the shared allocation flock after audit of
31 live heads,88 local refs,20 open PRs,worktrees,policies and the coordination
ledger. It adds segment/planned-count/creation identity fields, baseline evidence
cut time and media intents/manifests. Existing107–113 are byte-identical.
Additional guards retain the old D3 guards and prevent changes to resolved
segments, creation identity, finalized manifests and media history.
Forward-only: retain data/assets; never restore a writer that ignores segments.
Physical lock release and logical reservation retention require verified handoff.

Quota baseline and halt call D3 services. Human evidence is a private operational
record referenced only by UUID; approximate provider totalUsage never initializes
or resets the ledger. Existing periods and unknown charges are not cleared.
There is no same-month rebase, force-unhalt or bulk recovery operation.

- D6: reuse the message editor, verified-media contract and enqueuePush/common
  quota; implement template/category/chat contracts in a separate Change.
- D7: preserve same-BOT recovered observations and unknown follow dates; never
  infer reward entitlement or real delivery from these references.
- Browser/login/MFA/real LINE/CDN: NOT RUN — Human Browser Acceptance Pending.
- Final-head CI/review/test evidence and residual dependency findings belong to
  the Draft PR. This document never authorizes runtime activation.
