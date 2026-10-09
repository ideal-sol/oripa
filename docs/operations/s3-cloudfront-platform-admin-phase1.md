# S3 / CloudFront Platform and Admin — Phase 1

## Authority and scope

- Human decision: `S3-OLD-PLATFORM-ADMIN-PHASE1-20261009`.
- Strict Change / R3; Application Runtime Activation: deferred.
- Source, isolated synthetic tests, PR and CI only. Human review precedes merge.
- No AWS configuration, real S3 writes, existing-object migration, runtime
  activation, Storefront change, or prize CSV development is included.
- Each environment has an independent private S3 bucket, CloudFront and OAC.
  Versioning and object-level CloudFront authorization are intentionally absent.
  Knowledge of a CloudFront URL permits fetching private/archived assets too.

## Storage and activation contract

Existing upload services already use the effective Laravel filesystem disk and
pass `ContentType` explicitly. This implementation retains base64 input, MIME
validation, 5 MiB image / 50 MiB video limits, generated immutable object keys,
byte size and SHA-256 calculation, and existing rollback behavior. No converter,
new upload protocol, dual-read or dual-write is added.

The S3 driver uses the AWS SDK default credential provider chain, with no
explicit access key/secret configuration. Deployment must supply an EC2 IAM
role, not credential ENV variables or a shared profile. Credential-chain and
role permissions must be verified by the later AWS acceptance task. S3 writes
use private visibility and `bucket-owner-full-control`, compatible with
bucket-owner-enforced object ownership; no public-read ACL is requested.

| Setting | Consumer | Meaning |
| --- | --- | --- |
| `FILESYSTEM_DISK` | Platform runtime | `local` until coordinated cutover, then `s3` |
| `AWS_BUCKET` | Platform runtime | Environment-specific bucket; no source default |
| `AWS_DEFAULT_REGION` | Platform runtime | Region; existing Tokyo default retained |
| `AWS_ENDPOINT` | Platform runtime | Optional non-production/custom endpoint; leave unset for normal AWS |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Platform runtime | Default false; optional emulator compatibility |
| `V2_ASSET_PUBLIC_BASE_URL` | Platform and Admin runtime | Exact HTTPS CloudFront/custom origin, optional trailing slash |

`AWS_URL` remains an existing filesystem option, not the public asset authority.
Neither bucket names nor distribution domains belong in database rows.

With the CDN base unset/empty, response paths and Admin local previews preserve
the previous behavior; the filesystem default without ENV is local. A configured
CDN base enables read-response normalization and requires the S3 disk. Invalid
origin or local+CDN configuration fails before API controllers execute. An HTTPS
origin cannot include userinfo, a subpath, query, fragment or wildcard.

Admin reads the base at runtime in its dynamic root layout and proxy. It is not
a `NEXT_PUBLIC_*` build variable. The future release still requires its ordinary
application build/deployment, but changing this origin does not require baking a
new domain into client JavaScript. Supply the same origin to both Platform and
Admin at activation; their API origins remain unchanged.

## Public path and snapshot interface

Only these exact key roots are resolvable:

| Stored object key | Public relative path |
| --- | --- |
| `admin-assets/gacha/...` | `/gacha/...` |
| `admin-assets/top-banner/...` | `/top-banner/...` |
| `admin-assets/rank-masters/...` | `/rank-masters/...` |
| `admin-assets/rank-effects/...` | `/rank-effects/...` |

Keys permit ASCII letters, digits, slash, hyphen, underscore and dot. Empty,
dot/dot-dot segments, percent encoding, backslashes, control characters and
other prefixes (including `fixture/*`) are rejected. Public paths are limited
to 512 characters to satisfy the existing PresentationAsset contract. The
CloudFront Function reverses the four public prefixes; it is a separate,
preserved task, not part of this PR.

API JSON response middleware normalizes asset-shaped references using their
UUIDs and a single batched `catalog_presentation_assets` lookup per response.
It never infers the object key from an old URL. This includes nested Gacha,
Login Gacha, banner/notice, rank, prize, Draw result/history, representative
video, result/lineup/video snapshots, User Prize and saved idempotent responses.
Existing metadata selection and authorization remain intact; the normalizer
itself does not filter resolved objects by `is_public` or archive status.

Only asset path fields are changed. Rank, prize, order, wallet and economic
values remain unchanged. Persisted `storage_identifier`, `public_path`,
`draw_results.display_snapshot`, `draw_requests.response_data`, and other
snapshots/idempotency records are never rewritten. Existing Platform content
routes and their authorization/filtering remain; they read the one effective
disk. No authenticated private preview endpoint is introduced.

Public `path` and banner `image_url` remain relative, slash-prefixed strings.
Admin `public_path`, `content_path`, and snapshot `path` also remain relative;
Admin joins the runtime origin only for media. Existing Admin banner
`public_url` is absolute and uses the same resolver. OpenAPI and generated
contracts need no schema change; 503 uses the existing default Problem response.

For the next Storefront task: join its CloudFront base to these four relative
prefixes, do not replace its API base, and preserve local compatibility while
the Platform CDN setting is absent. Fixed `static-assets/*` and
`NEXT_PUBLIC_STATIC_ASSET_BASE_URL` belong to the separate Storefront task.

## Fail-closed behavior and cutover prerequisites

An unresolved UUID or unsupported key in a response returns a no-store 503
`ASSET_DELIVERY_UNAVAILABLE`; no arbitrary URL or old Admin path is emitted.
This can reject an entire collection. For a mutation, output normalization
occurs after the existing transaction has committed: do not treat a 503 as
proof that the mutation did not execute; reuse the original idempotency key.
Therefore full reference/snapshot/key validation is a mandatory pre-cutover
gate, not something to discover during customer draws. Historical malformed
asset objects without IDs cannot be repaired by prefix substitution.

1. Resolve missing source files and unsupported referenced keys, including
   retained `fixture/*`, under a separate Human-approved decision. This phase
   does not rename keys, delete records, or silently include fixture prefixes.
2. Complete all-object migration and size/checksum/MIME verification first,
   including referenced archived assets. Keep local rollback sources.
3. Verify private bucket/OAC, Function association on every reachable behavior,
   GET/HEAD and video Range behavior, exact CDN CSP origin, and browser media
   delivery. Node/PHP mocks are not AWS acceptance.
4. Under a later approval, stop Admin asset uploads only across **all** writers
   sharing the database, complete the final delta, and coordinate disk/CDN
   settings for those readers/writers. OLD Platform 8611 and 8621 share DB and
   assets: switching Test alone while another writer stays local is forbidden.
5. Activate compatible Storefront and Admin readers together with Platform,
   accept the environment, then reopen uploads. No step is executed here.

Rollback is not a blind disk toggle after new S3 uploads: local would lack
post-cutover objects. A later Human-approved rollback must stop uploads and
reconcile new objects before reverting the single effective disk. No automatic
recovery, dual-read/write, local deletion, or bucket mutation is provided.

## Validation boundary

Focused tests use synthetic data in disposable PostgreSQL/PHP containers with
no external network route, no host ports, and no production volumes. Existing
schema migrations are applied only to that disposable database. Storage fakes
exercise application upload metadata; a real Flysystem/S3 adapter with an SDK
handler mock verifies PutObject key, bytes, ContentType and ACL without AWS.
Admin tests cover local/private/public previews, URL validation and exact-origin
image/media CSP. Draw tests cover read/replay immutability and 1,000-item batched
normalization. Browser/E2E, builds, deployments and real AWS tests are not run.
Executed counts and CI outcomes are recorded in the task worklog/PR.
