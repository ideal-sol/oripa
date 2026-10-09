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
retain private visibility but omit ACL headers. The existing Laravel S3 driver
is wrapped at construction with SDK command middleware that removes `ACL` from
`PutObject` and `CreateMultipartUpload` before serialization/signing. Object
Ownership must be Bucket owner enforced with Block Public Access enabled.
This does not make a bucket private by itself and does not change AWS settings.

### Offline ACL evidence and least-privilege boundary

The locked versions are Laravel 13.30.0, Flysystem 3.35.3, its S3 adapter 3.34.0,
and AWS SDK for PHP 3.384.6. The stock adapter/SDK sends
`x-amz-acl: bucket-owner-full-control` with the original explicit option. Removing
the option, or setting it to null, instead sends `x-amz-acl: private`; neither
omits the header. The former is accepted with Bucket owner enforced; the latter
is not. Thus configuration deletion alone is unsafe. An SDK command middleware
is necessary here, rather than an upload callback that misses multipart
initiation. The existing driver, credential chain, multipart threshold, keys,
body, MIME and application checksum behavior remain unchanged.

Offline handler tests assert the serialized header and command contents for all
seven supported MIME types, multipart video, Head/Get/Delete, and the stock
configuration comparisons. The multipart test checks one initiation, one
completion, distinct part numbers, and exact reconstructed bytes; multipart is
one logical object upload, not dual-write. These tests cannot prove AWS IAM or
bucket privacy.

| Operation | IAM action / boundary |
| --- | --- |
| PutObject; multipart initiation/parts/completion | `s3:PutObject` on the allowed object prefixes |
| HeadObject / GetObject (compatibility routes) | `s3:GetObject` on those objects |
| DeleteObject (failed-upload cleanup) | `s3:DeleteObject` on those objects; no version-delete permission |
| Explicit multipart abort / recovery, if used operationally | `s3:AbortMultipartUpload`; list-parts needs `s3:ListMultipartUploadParts`, not exercised by successful uploads |
| Distinguishing a missing HeadObject as 404 rather than 403 | `s3:ListBucket` on the bucket with a compatible prefix condition; validate actual requests at acceptance |

No `s3:PutObjectAcl`, `s3:GetObjectAcl`, public ACL, tagging, bucket administration,
or version operation is required by the tested asset path. The original ACL
request requires attention to the conditional `s3:PutObjectAcl` authorization;
acceptance must not infer permission from Bucket owner enforced accepting that
ACL value. No IAM policy is applied here. SSE-KMS, if separately selected, adds
KMS permissions; this task does not assume or configure it. Confirm the actual
EC2 role, prefix restrictions, missing-object behavior and multipart failures in
the later AWS Technical Acceptance.

References: [PutObject permissions](https://docs.aws.amazon.com/AmazonS3/latest/API/API_PutObject.html),
[Object Ownership errors](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-ownership-error-responses.html),
[operation/action mapping](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-with-s3-policy-actions.html),
[HeadObject](https://docs.aws.amazon.com/AmazonS3/latest/API/API_HeadObject.html).

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

1. Resolve unsupported referenced keys, including retained `fixture/*`, under
   a separate Human-approved decision. A valid key with a missing object does
   **not** trigger API 503: this boundary performs no filesystem/S3 existence
   lookup. It returns the valid public path; a later media fetch can fail.
   Human explicitly waives restoration of the previously identified missing
   OLD files. This phase does not restore/rename keys, delete records, change
   publication status, or silently include fixture prefixes.
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

## Focused review disposition — HOLD

The ACL correction does not resolve the response-boundary availability issue.
The focused synthetic HTTP test reproduces a new Draw that consumes points and
persists its results before returning `ASSET_DELIVERY_UNAVAILABLE` for a fixture
key. Same-key replay does not draw or debit again, but still returns 503 while
the reference remains unsupported; GET/history/User Prize can fail too. A new
idempotency key is not a safe recovery action. The test transaction is isolated;
no operational Draw was performed.

Missing DB identity, malformed identity and invalid/unsupported keys fail closed.
Old saved paths alone do not fail when their UUID resolves to a valid key;
private/archived assets resolve too. CloudFront errors are outside this API
response and cannot turn it into 503. A synthetic non-asset object with `path`
and `mime_type` also demonstrates the current structural heuristic's false
positive. No production endpoint containing that non-asset shape was established.

A blanket null fallback is not contract-safe: Public `ContentBanner.asset` and
Admin `AdminRankEffect.content_path` are required and non-null. Many Draw fields
use `NullablePresentationAsset`; `DrawPresentation.video_snapshot` is non-null,
although its parent `presentation` may be null. User Prize `presentation.image`
is nullable. Safe degradation therefore needs explicit response-context rules,
not arbitrary recursive nulling or URL substitution. No Contract change, new
placeholder URL, fallback video selection, snapshot rewrite, or financial change
is made in this focused patch.

Human decision is required for the treatment of required asset-bearing records
(for example, hiding an entire invalid banner/effect item versus a separately
approved Contract change), together with an explicitly scoped nullable projection
for historical Draw/User Prize. Unpublishing current Gachas alone cannot remove
historical snapshot references. Keep CDN activation and merge on HOLD until that
decision is implemented and tested. The current Storefront Phase 2 path interface
is unchanged; no new null/fallback semantics have been introduced.
