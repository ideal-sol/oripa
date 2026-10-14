# Banner CSV image-host policy delta — 2026-10-14

Human Feature: `BANNERCSV-F2B-HOST-20261014`. Machine Task: `BANNERHOST-20261014`.
Human-approved specification; R3 / Strict Change / Runtime Activation deferred.
Source implementation and isolated verification only. Separate Human Source
Merge GO, OLD Test GO and Production GO remain required.

## Authority and exact delta

The Human decision supersedes only the host-allowlist requirement in chapter 7.4
of `景品CSV_バナーCSV_Notion在庫同期_仕様書_v1.0.md` (external authority, not
rewritten here). Its corresponding unlisted-host rejection test and H2 host-list
prerequisite no longer apply. No other chapter or independent security condition
is changed. The earlier Human decisions for 200 rows and automatic Object Delete
HOLD continue to govern F2-B.

Before: syntactically valid HTTPS image URLs required an exact configured host
match; an absent or empty `V2_BANNER_IMPORT_IMAGE_HOSTS` rejected every host.
After: no application host allowlist is consulted. Any syntactically valid domain
may proceed to DNS validation; **accepting the CSV is not proof that DNS or image
fetch will succeed**. Every returned A/AAAA address must pass the existing public
address policy before one validated address is pinned for the transfer. Multiple
domains may coexist in one CSV and each row receives the same validation.

`V2BannerImportUrlPolicy::validate()` removes only the config lookup and exact
allowlist comparison. The config entry is removed from `v2_banner_import.php`.
Stale environment values are ignored, including old configured host names.
`IMAGE_HOST_NOT_ALLOWED` remains a safe existing error code for the retained
invalid-host/IP-literal checks, not for an unlisted domain. `IMAGE_URL_INVALID`
and `IMAGE_ADDRESS_NOT_ALLOWED` retain their existing meanings. No API response
shape, OpenAPI enum, generated client or schema change is required.

## Controls and review

Unchanged: HTTPS/443; no userinfo, fragment, controls or IP literals; host syntax;
all-answer IPv4/IPv6 validation including private, loopback, link-local, CGNAT,
reserved/documentation and transition ranges already denied by the policy;
validated-IP pinning and final peer-IP comparison; no redirect; proxy disabled;
TLS peer and hostname verification; no Cookie or authorization header; five-second
connect and twenty-second overall budget; streaming 5 MiB cutoff; finfo GIF/JPEG/
PNG/WebP verification. DNS is bounded by the existing resolver timeout.

Plan/Fetcher/Resolver/Transport, authorization, idempotency, lease/fencing,
bounded retry, Canonical storage/ledger and automatic Delete HOLD are unchanged.
Refresh OFF still ignores an existing Banner's URL entirely. Feature OFF still
prevents external requests and mutations. URL encryption, 1,800-second TTL,
secret purge and 30-day result retention are unchanged.

The finalized REV1 Security Baseline's inbound Host/CORS and browser CSP/provider
allowlists are different boundaries and remain intact. No baseline, security gate,
CI requirement or network policy is relaxed. No dependency is added.

Removing destination allowlisting expands reachable public HTTPS origins and
removes a defense-in-depth layer. Authorized operators can cause requests to
arbitrary eligible public services; valid MIME is not proof of benign image
content or ownership. Remote outages, malicious public endpoints, signed URL
expiry, bandwidth abuse and routing changes remain risks. Public address checks
do not authorize access to sensitive services exposed on public IPs. Deployment
owners must separately review egress policy, routing, DNS and rate/usage monitoring;
application host policy does not change firewall/proxy/egress rules. Never log
URLs, hostnames, signatures or upstream error bodies for troubleshooting.

## Verification and boundaries

Mock DNS and Mock Transport test missing/stale lists, distinct domains, all
non-public answer classes, mixed answers, pinning, redirects, proxy/TLS options,
streaming limits, redaction and Feature OFF. Laravel tests use fake Storage and
task-only synthetic PostgreSQL in network-none containers with a private Unix
socket. No real domain, operational DB or AWS endpoint is a test dependency.
Exact executed results and prior failures are recorded in the PR and Worklog;
these do not imply live S3, egress or Human Browser acceptance.

The past AWS test-connection incident remains **UNKNOWN**; its original evidence
is retained separately. Neither these tests nor this policy decision clears it.

## Deferred release-owner reference (not a runtime handoff)

This Source task creates no Runtime Candidate, deployment preparation, authority
update or activation handoff. The S3/OLD Test owner alone manages later combined
reflection of this change + F5 + F6, never adding them midway into an existing
S3/F4 rollout. Source ancestry at task start:

- F2-B PR #559: `50a8d413fc820fde92dd97695d2f86a13f59a921`.
- F5 PR #563: `a8cd9135c440f43fb84737b0f208d2046e9feb41`.
- F6 PR #564/base: `fb0170e6ad3a1ff67fa8616c7eb889b984520af7`.
- This change: use its eventual approved squash SHA, not this unmerged Draft.

The release owner must independently revalidate current OLD Authority and actual
runtime source, merged S3 delivery/503 changes, F5 API compatibility with F6's
000085 schema, exact combined source ancestry and migration state. No migration
is changed here; synthetic tests include 000085, not operational application.
F2 and F6 default OFF remain mandatory until separately authorized. The owner
also checks permitted DNS/HTTPS egress, timeouts and absence of proxy bypass.

After deployment under separate GO, Human Browser acceptance should cover mixed
public-domain CSV, partial failures/reimport, refresh OFF, progress/history,
secret-free errors and new draft/non-top records. F5/F6 acceptance belongs to their
owners. Remove obsolete `V2_BANNER_IMPORT_IMAGE_HOSTS` only in that separately
authorized environment change; leaving it present does not restrict new Source.

Rollback is a separately reviewed Source revert and deployment of the prior
artifact, not a schema rollback or Object deletion. Restoring the old policy also
restores its required host configuration; without it old Source fails closed.
Keep the feature OFF while reconciling pending jobs and operational configuration.
