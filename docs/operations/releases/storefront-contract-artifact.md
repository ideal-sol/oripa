# Storefront Contract Artifact

## Authority

The finalized Package Version Compatibility Policy is authoritative. Core
components share compatibility family major `2`; package bundle, OpenAPI
document, Platform, Application, and Site Schema versions remain independently
recorded in the release ledger and Artifact Manifest.

`manifests/storefront-contract-releases.json` is the machine-readable Alpha
release ledger. It records complete immutable history, the latest immutable
release, and either one pending next-version candidate or `null` after release.
The ledger never authorizes publication by itself. Artifact Release Lock,
exact-head Required Checks, and the task release gate remain mandatory.

## Exact Merged Main Publication

`.github/workflows/storefront-contract-artifact-publish.yml` is the only
canonical contract-only publication workflow. Dispatch is permitted only with
`publication_mode=contract-only`, the Source Task ID, and the Human-confirmed
exact squash-merged SHA. The input SHA is confirmation, not selectable Source
authority: the workflow requires its event ref and event SHA to be the protected
`main` ref and its current live head.

Before any package command, the authority helper verifies all of the following:

1. GitHub reports `main` as protected and its current head equals the expected
   merged SHA. A stale SHA, PR head, non-main commit, or arbitrary branch fails.
2. Exactly one internal merged PR has that SHA as its squash merge commit, the
   Source Task identity matches, and the reviewed PR head tree equals the merged
   commit tree.
3. The latest `policy-gate`, `quality-gate`, `security-gate`,
   `integration-gate`, and `ci-gate` runs succeeded on the reviewed head.
4. The checked-out `HEAD` equals the authorized merged SHA.
5. The release ledger contains one valid next-version candidate without a
   pre-merge `source_commit`, and GitHub has no Artifact with that version name.
   Any duplicate version, including an expired Artifact, fails closed.

The contract-only job runs the Client checks, builds the referenced Site Schema
dependency, and then runs the Testkit checks before building only the Public
OpenAPI, Storefront Client, and Storefront Testkit bundle plus
`artifact-manifest.json` and `SHA256SUMS`. The referenced Site Schema is not
republished. The job imports the packed Client runtime
constant and requires it to equal both the packed package version and bundle
version. API image build count is zero. API push, API Activation, Admin build,
Storefront application build, and Migration creation or application are also
zero. The workflow has read-only repository permissions and never commits to `main`.

The upload uses the immutable version as its name, disables overwrite, and is
serialized across the repository. A required downstream readback job downloads
the uploaded Artifact by exact ID, verifies the GitHub outer digest, safely
extracts the exact five-file inventory, reruns the bundle validator, and matches
the Manifest Source Commit, version, Client, Testkit, and Public OpenAPI digests.
The run succeeds only after readback. A partial upload or digest mismatch leaves
the run failed and never counts as publication success.

## Release Ledger Reconciliation

The Source Task records only contract and version intent before merge. It must
not predict the merged Source Commit or future Artifact digests. After the
dedicated workflow succeeds, a separate small Release Metadata Task and PR
records the Artifact version, exact merged Source Commit, Manifest SHA, Client
SHA, Testkit SHA, and Public OpenAPI SHA in the release ledger and clears the
candidate under the existing validator.

This separate reconciliation preserves one Task, one PR, protected-main
provenance, and immutable version history. The publication workflow does not
edit the ledger, does not open a PR, and does not push or commit to `main`.

After reconciliation, readback reconstructs its verification target from the
canonical `latest_immutable` record. It preserves the record's validated
`breaking_change` value, so a contract-breaking manifest has identical
verification semantics before and after the pending candidate is cleared.
Missing or contradictory breaking metadata remains invalid; non-breaking
records retain their canonical false value.

## MIG-099 Canonical Rank Cutover

MIG-099 records the Human-authorized Rank Master and Gacha Rank Clean Cutover
as `contract-breaking` for the Alpha channel. Its pending artifact must set
`breaking_change` to `true`, advance the Public, Admin, and Webhook contract
versions together, and publish the matching Client and Testkit bundle. The
OpenAPI contract gate accepts this only for the exact documented authority,
scope, and alpha.29-to-alpha.30 transition on the Public and Admin surfaces.
It does not authorize other breaking changes. Storefront adoption remains an
explicit exact-pin update and separate UI implementation; no Runtime Activation
is performed by the artifact workflow.

## Immutable Alpha 24

STORE-SITE-034 adopted the canonical package-only `2.0.0-alpha.24` Artifact from
source `209252d9fcbad42090677f5a7bece52c5a5d3597`. It contains Client/Testkit
alpha.24, references Site Schema alpha.23, and retains Public OpenAPI alpha.23
with 54 operations. Its Manifest and file digests are fixed in the ledger.

Never rebuild, overwrite, replace, delete, or re-upload this version. The MIG-079
Payment source also used alpha.24 package labels but differs in OpenAPI, package
inventory, capabilities, exports, and operation count. It is therefore a version
collision, not the same Artifact.

## Payment Release

The canonical additive bundle `2.0.0-alpha.25` was published from source
`c1b55e8cc4e23b40c82372e739bc162604a53f2a` and contains:

- `@oripa/storefront-client@2.0.0-alpha.25`
- `@oripa/storefront-testkit@2.0.0-alpha.25`

It references, but does not rebuild or include, immutable
`@oripa/site-schema@2.0.0-alpha.23`. Platform and Application remain alpha.23.
The Public, Admin, and Webhook Payment contracts remain their canonical alpha.24
document versions. Advancing the package bundle does not rewrite unchanged
contract or Site Schema versions.

Canonical readback fixed these SHA-256 values:

- Manifest: `b9fcc89c4bcc97bcc86de0ef0613e283d0f90181f6b04167d6cb9a8bca2c4c21`
- Client: `881eacd845613d9976696d7fbcba68abf05bc2e19d6abf1ce211b7f13202be21`
- Testkit: `22e29b26c7daf608c2fbeeef85d6e8494ff8ff57c0efb1649209dc14d4f9205c`
- Public OpenAPI: `38761cf884c93b2a3f9b16c6718b88079a7c2c299d2335235609746f9b9b9397`
- `SHA256SUMS`: `fb68da2e7a3751459f393bc48d327e83d037bcca35d258eae08998ca0c26713f`

Alpha.25 remains immutable history and is never overwritten or republished.

## Payment Return Correlation Release

MIG-087 published the additive bundle `2.0.0-alpha.26` from exact Source Commit
`2dd1c7dbcf83b78f5d07fe3d965f9982d1f2fd05`. It contains Client and Testkit
alpha.26, Public OpenAPI alpha.25 with 64 operations, and references unchanged
Site Schema alpha.23. Platform and Application remain alpha.23; Admin and
Webhook changes are version metadata only.

Canonical Workflow Run `32821950630`, Artifact `9553537412`, Manifest, and
`SHA256SUMS` readback fixed these SHA-256 values:

- GitHub outer Artifact: `c1e7936ecec21dd80cd3395911c568ded060d3616b7eed20654582e47964a7eb`
- Manifest: `05ad837c3f4ebbf5875e4aed846d28df750c366cc5f4e3d8589799deea659e2e`
- Client: `80ebe7172fd4ca86fbcebe545cb2b18dfe4c3a76ccf9ad770b5291dd82225b3d`
- Testkit: `ca62c03bddc6a3a263f5853f512f0b085756744c23ab563365b0e6d7c8e53fde`
- Public OpenAPI: `888df7d36606aa05b599859ae27a0cd4343123d46cbbe9f4355f2f9fd649a6e5`
- `SHA256SUMS`: `6344d997fc77962e7891d2dee7a41b1f4f2e138c552b1548b060cbfe03f371a9`

Compatibility is contract-additive and Public OpenAPI `breaking_change` is
false. The ledger records alpha.26 as `latest_immutable` and clears `candidate`;
exact-pin adoption is GO, while Runtime Activation and Provider Browser E2E
remain deferred.

## Payment Grant Breakdown Release

MIG-088 published the additive bundle `2.0.0-alpha.27` from exact Source Commit
`856990ddeab266ee394d5e2750b689fb8211322b`. It contains Client and Testkit
alpha.27, Public OpenAPI alpha.26 with 64 operations, and references unchanged
Site Schema alpha.23. Platform and Application remain alpha.23; Admin and
Webhook changes are version metadata only.

Canonical Workflow Run `32833825150`, Artifact `9557923243`, Manifest, and
`SHA256SUMS` readback fixed these SHA-256 values:

- GitHub outer Artifact: `4b18a02767e44af7d9bc69690e2ba37133c7ad535a453a92d60c0ca02da46b10`
- Manifest: `a6b12bd35dfae03179baaa2981c6e90e483ba88a3f9245633510c49f2ea13283`
- Client: `31f3bc55a87150600a06c6abd2569835f67ee361f80cc1004082479fd851534f`
- Testkit: `32559b9c56e17fd86327b3ecf8311d878eb5ecb0d5344457c7e8ad8941f7ef57`
- Public OpenAPI: `cb4f6b8eae034776f5efcba12d7f2b1a2b2cec18a6103ad1208d24e8458f3333`
- `SHA256SUMS`: `31dad542a3f8b1423e84451f2dc8bffb3626d8145ab70c76a4b50da2c6eb4dd4`

Compatibility is contract-additive and Public OpenAPI `breaking_change` is
false. Public `PaymentGrant` adds the required Historical values
`limited_bonus_points` and `total_points`; existing operations remain unchanged.
The ledger records alpha.27 as `latest_immutable` and clears `candidate`, so a
same-version rebuild is rejected. Storefront exact-pin adoption is GO and
Runtime Activation remains deferred.

## Card UI Bootstrap Release

MIG-089 published additive bundle `2.0.0-alpha.28` from exact Source Commit
`06681c689eaba3458adb935753de128a4d12d57d`. It contains Client and Testkit
alpha.28, Public OpenAPI alpha.27 with 65 operations, and references unchanged
Site Schema alpha.23. Platform and Application remain alpha.23; Admin and
Webhook changes are version metadata only.

Canonical Workflow Run `32867602180`, Artifact `9570886895`, Manifest, and
`SHA256SUMS` readback fixed these SHA-256 values:

- GitHub outer Artifact: `7af368e92be29c396f77cab5f25e336ecdbc147067d2fbd1000ba3561a9fd339`
- Manifest: `2b9299baa5816a1ff65af147178bb76574411dbcaeda13d5242a32e38bfab6fa`
- Client: `7be14c543a1a1d69ad85af0549ddedce275ad86828c4e99dc90b6fc0af6a0a00`
- Testkit: `8bc1cd287d15a61c94694034b9ac5280f4b2e4f296d8a6de836ad64550bf0e94`
- Public OpenAPI: `41ebdddbd7c4edeedd36ad3810b2afa564495aa2d1c3e48a187f44c85deb85da`
- `SHA256SUMS`: `8e5d113274d4897d07c66ec613c6d1049e2b7fcdc5fa6b4441c69bda782d9349`

Compatibility is `contract-additive`, Public OpenAPI `breaking_change` is
false, and existing operations remain unchanged. The ledger records alpha.28
as `latest_immutable` and clears `candidate`, so a same-version rebuild is
rejected. Storefront exact-pin adoption is GO; Runtime Activation and Provider
Browser E2E remain deferred.

## Payment Resume JSON Request Release

MIG-094 published package-only bundle `2.0.0-alpha.29`. The Storefront Client
now sends the existing unpaid-payment resume operation as a JSON POST with an
empty object body, preserving the existing authenticated CSRF contract and
response shape. Storefront Testkit advances to the matching Client version;
Public OpenAPI alpha.27, its 65 operations, Site Schema alpha.23, Platform, and
Application versions remain unchanged.

The release does not weaken Browser Security middleware, create a Provider
session, alter the Payment state machine, activate an API Runtime, or apply a
migration. Resume eligibility adds only the initial `requires_action` plus
`UNPROCESSED` state pair for owned, unexpired Konbini and Virtual Account
Payments with an existing decryptable fincode HTTPS redirect; the existing
`processing` plus `AWAITING_CUSTOMER_PAYMENT` contract remains valid. Canonical
workflow Run `33084320143`, Artifact `9651612069`, and exact Source Commit
`5cde1e0a91151b584de8a63d19efd7b4a15e8ab1` produced these verified SHA-256
values:

- GitHub outer Artifact: `1e11a5f793ad009320b0a52cc83a14c9f1dd48ecaebc5fcb4e94c43973f7b97b`
- Manifest: `9e5059d1d098d435d16399d8ce7d60172befb1c2ffe979037bf93ae1c447423b`
- Client: `28e5756000847df3a1a27cf77be3da97beb4aef447486978ee74ecd979b425e1`
- Testkit: `1e976d1cd83c00e79c632636018c57461bc89940640d0de949568cc1769b0b56`
- Public OpenAPI: `41ebdddbd7c4edeedd36ad3810b2afa564495aa2d1c3e48a187f44c85deb85da`
- `SHA256SUMS`: `23a1afd8f69eacff43e5b0146259172e93754a542f88fa4294f52deec9c3a944`

The ledger records alpha.29 as `latest_immutable` and clears `candidate`, so a
same-version rebuild is rejected. Storefront exact-pin adoption is GO;
Platform API Runtime Activation remains deferred.

## Save Card Registration JSON Request Release

MIG-096 published package-only bundle `2.0.0-alpha.30`. Both Storefront Client
CSRF variants now send Card Registration Intent creation as a JSON POST with an
empty object body, preserving the existing endpoint, idempotency, CSRF, response,
and purchase orchestration contracts. Storefront Testkit advances to the matching
Client version; Public OpenAPI alpha.27, its 65 operations, Site Schema alpha.23,
Platform, and Application versions remain unchanged.

The release does not weaken Browser Security, add standalone registration
completion to the purchase flow, change Payment or Card authority, contact the
Provider, activate an API Runtime, or apply a migration. Card success and failure
return handling remains unchanged in Platform; the active Storefront must consume
the existing Platform-provided merchant return URLs when executing the Card.
Canonical api-only Workflow Run `33154172300`, Artifact `9678987823`, and exact
Source Commit `4a7703859473f0c3f5e317cfca454cb8dce401ae` produced these verified
SHA-256 values:

- GitHub outer Artifact: `f9c7e8f65b0121fc5d99d0b79292b8c1c43eb2046145c8112516b823ee955b6a`
- Manifest: `25667419d9db73a946f48ca1351f2c8b0e9fc1f371508efe2c44b9403852fe5a`
- Client: `f44e2da2d427621296f2bb27958ef7b20e217b5b07fbcf6cc342978e2ef9dae6`
- Testkit: `f349b6e07421507ccbdca9a6e0cbc07d79379b444fbe2119b1a92709319e8809`
- Public OpenAPI: `41ebdddbd7c4edeedd36ad3810b2afa564495aa2d1c3e48a187f44c85deb85da`
- `SHA256SUMS`: `5849402d1d7770751683c93d0dfd619edbf33eb7bd262094d6b3ce87948aa363`

The ledger records alpha.30 as `latest_immutable` and clears `candidate`, so a
same-version rebuild is rejected. Storefront exact-pin adoption is GO. API
runtime activation remains separately merge-first.

## Released 3DS-Verified Card Registration Contract

GOV-021 reconciled immutable `2.0.0-alpha.31` after the dedicated GOV-020
workflow published it from the exact MIG-098 squash commit
`ad078ecd1eebd68cd2443b347d387433177fd686` on protected `main`. Workflow Run
`33254741748` completed successfully on its first `workflow_dispatch` attempt
and produced the single available Artifact
`oripa-storefront-contract-2.0.0-alpha.31` with Artifact ID `9715454247`.

Canonical remote download and readback fixed these SHA-256 values:

- GitHub outer Artifact: `9e8921e7681abe52d3e5fba65e4fdf3186988df453fca4f39be86e811deb22f0`
- Manifest: `c11894fbfadaf3dd4e00c7f94973ede1bb00f580ece5e109d0118c74c3b69f74`
- Client: `0caf5e8ac829a1f13d1790298ba4a2fef3c50fe6ae11cad63329ab327cea40cf`
- Testkit: `932cc4cc6560aa595e01bb5d929320f8d2f70dda32d5a8dd70ec91e84acb8716`
- Public OpenAPI: `60a14073f7ee52d91b919c69fbc7444bf6afe391a887121bb4af5e45fbb85626`
- `SHA256SUMS`: `1a0a4295106e8e7bc951b9caf907c9cf844a913bf820e896312889ca3749a127`

The Manifest records `contract-additive`, `breaking_change: false`, browser-
compatible Client and Testkit alpha.31, Public OpenAPI alpha.28 with 71
operations, and referenced immutable Site Schema alpha.23. The Release Ledger
retains schema `2.0`, appends alpha.31 after unmodified alpha.30, sets the exact
alpha.31 record as `latest_immutable`, and clears `candidate` to `null`.
Artifact ID, Workflow Run, outer Artifact digest, and `SHA256SUMS` digest remain
in this canonical release record because the existing Ledger schema has no such
fields; the schema is not expanded during reconciliation.

Artifact Ledger reconciliation is GO. Artifact adoption and SITE-048 remain
HOLD until Human configures and read-only verifies the TEST/SANDBOX
`customers.payment_methods.updated` Webhook and a separate Platform Activation
Task applies Migration 000067, performs an API-only Build and Shared Preview API
Activation, and passes Runtime Acceptance. This reconciliation does not
redispatch or overwrite the Artifact and performs no Build or Activation.

## Released Account Security Contract

GOV-023 reconciled immutable `2.0.0-alpha.32` after the Account Security
publication workflow built it from the exact ACCT-001 squash commit
`4147487f8f1474d5261a12aa8a0ad124cebe922f` on protected `main`. Workflow Run
`33307134531` completed successfully on attempt 1 and produced the single
available, unexpired Artifact
`oripa-storefront-contract-2.0.0-alpha.32` with Artifact ID `9730828197`.

A fresh canonical remote download and readback fixed these SHA-256 values:

- GitHub outer Artifact: `e891ea105a03bc3e484d06ff837730d2c0f24ab5d7df887a3a7040011b8a6744`
- Manifest: `263955a5521a863635bf6ad23d604e52b1319e84052178288bad7b7c308de564`
- Client: `5d00dd111914d4bd6da248c99b98fcc697eb1507092fe6757015745e73856ad8`
- Testkit: `6124a6ac5837984eda60fdada0dae98fa24f28285ed674b7197f3b64bd7095be`
- Public OpenAPI: `9670bc769080da605c97cb9849b61f342cf0111bc39e91c09dbbf62fc4bcc720`
- `SHA256SUMS`: `755c4a752250edc77da01d6dd7c2b7ef781aa3cfc55b696be47c760517bd4237`

The Manifest records `contract-additive`, `breaking_change: false`, browser-
compatible Client and Testkit alpha.32, Public OpenAPI alpha.29 with 74
operations, and referenced immutable Site Schema alpha.23. The Release Ledger
initially retained schema `2.0`, appended alpha.32 after unmodified alpha.31,
set the exact alpha.32 record as `latest_immutable`, and cleared `candidate` to
`null`. Its Artifact ID, Workflow Run, outer Artifact digest, and
`SHA256SUMS` digest remain historical readback evidence in this runbook.

The later fresh semantic readback found that the packed Client
`package.json.version` is `2.0.0-alpha.32` while packed
`dist/constants.js` exports `STOREFRONT_CLIENT_VERSION` as
`2.0.0-alpha.31`. The transport therefore sends the old
`X-Oripa-Client-Version`. Alpha.32 remains published and immutable: it is never
deleted, overwritten, rebuilt, or republished. Its ledger `handoff_status` is
`retired`, meaning published but non-adoptable.

GOV-024 repaired Client package/runtime/header coherence and reserved
`2.0.0-alpha.33` as the next package-only candidate. Repair PR #428 merged as
`b42a1a45276fce69b282c183cfc5675a2d6d9be5`. The first publication Run
`33317049114` failed before upload because the referenced Site Schema had not
been built before Testkit typecheck; it produced no alpha.33 Artifact. GOV-025
added that dependency order without changing a package or contract and merged
as `9867c1ea50140efd1eff7a652d3da5bd36665e1d`.

Canonical publication Run `33318307918` attempt 1 then completed all authorize,
publish, and readback jobs from that exact protected `main` commit. It produced
the single immutable Artifact `oripa-storefront-contract-2.0.0-alpha.33`, ID
`9734141503`, with GitHub digest
`sha256:734b8e36fef261b72ab8013a0656c4a2ca3f1a6c8ea472d817c3b3ae7410e58c`.
A separate fresh download fixed these SHA-256 values:

- Manifest: `b6522d16230734ea7f4604be59a2585c29bcf03a2b447269e824e712759d893c`
- `SHA256SUMS`: `10252bf2cb15f80e2c26fd329c15092517d667267a9cc105ab74b9f5c3649328`
- Public OpenAPI: `9670bc769080da605c97cb9849b61f342cf0111bc39e91c09dbbf62fc4bcc720`
- Client: `846b0e036ebf76dd46ab1a2c9d6b67b786f9d2dfe5672d8b3a0eb31b7ad675a2`
- Testkit: `720d8cc6a0b1c786267de34af0f1fddefc5a517d5d064491f4a78af2e492df4d`

Fresh semantic readback imported the packed Client and made an actual transport
request. Bundle, Client Manifest, Client package, runtime constant, actual
`X-Oripa-Client-Version`, Testkit Manifest, and Testkit package all equal
`2.0.0-alpha.33`. Public OpenAPI remains independently fixed at
`2.0.0-alpha.29`, the same hash, and 74 operations. The ledger appends this
released record, minimally records its Run/Artifact/GitHub/checksum evidence in
`publication`, sets it as `latest_immutable`, retains alpha.32 as `retired`, and
clears `candidate` to `null`.

The validator requires:

1. A pending bundle to be exactly the next Alpha sequence after the latest immutable bundle.
2. Published package versions to equal that bundle version.
3. Referenced Site Schema version, digest, source bundle, and source tree to match immutable evidence.
4. Public OpenAPI digest and operation count to match the declared additive contract.
5. Client minimum Public API, required capabilities, Testkit dependencies, and operation count to match.
6. Client source package/runtime versions and the transport version header to remain coherent.
7. Packed Client package/runtime versions and packed Testkit package version to equal the bundle version.
8. The Artifact inventory to contain only Client, Testkit, Public OpenAPI, Manifest, and checksums.
9. Alpha.33 and later immutable records to contain valid Run, Artifact, GitHub digest, and `SHA256SUMS` evidence.
10. A settled ledger with `candidate: null` to reject a second publication attempt.

## Validation And Publication

```bash
python3 scripts/release/storefront_contract_artifact.py validate-source \
  --repository .

python3 scripts/release/storefront_contract_artifact.py build \
  --repository . \
  --source-commit <full-head-sha> \
  --output <new-empty-path>

python3 scripts/release/storefront_contract_artifact.py verify \
  --repository . \
  --output <artifact-path>
```

The dedicated post-merge workflow requires a pending candidate and builds it
only from the exact current protected `main` head. A missing candidate fails;
an existing Artifact with the same immutable version also fails rather than
skipping, overwriting, or republishing it. After the separate reconciliation PR
sets `candidate` to `null`, no further publication is authorized until a later
Source Task introduces a new next-version candidate.

Publication, Registry publication, Storefront adoption, Runtime deployment,
Migration application, Provider integration, and Production remain separate
states. A released Artifact is read back from the canonical GitHub Artifact,
rehashed, and compared with its Manifest and `SHA256SUMS` before GO.

## Pending SMS Phone Ownership Contract

SMS-001 reserves immutable bundle `2.0.0-alpha.35` as the next additive
contract candidate. It advances Public, Admin, and Webhook contract metadata to
`2.0.0-alpha.31`, publishes matching Client and Testkit packages, and adds the
`identity.sms-phone-ownership.v2` compatibility capability. The candidate adds
delivery lifecycle, retry timing, verified phone metadata, generic ownership
conflict, and SMS-required fulfillment contracts without adding operations.
Provider credentials, real SMS delivery, Runtime Activation, and Migration
application remain deferred and are not authorized by Artifact publication.

## Storefront Handoff

Storefront adoption pins the formally released immutable Artifact by exact
tarball and digest. It must preserve predecessor alpha.25 and rerun Site-specific
compatibility checks. Canonical normal return is
`/points/purchase/thanks?pid={Payment.id}` and failure/cancel return is
`/points/purchase/{PointProduct.id}?pid={Payment.id}`. Storefront always uses
ownership-checked `getPayment(pid)` as status authority. Card and PayPay may poll
`created`/`requires_action`/`processing` every 2 seconds for at most 30 seconds,
respecting `Retry-After` or `retry_after_seconds`. Konbini and Virtual Account
read state with `getPayment` and resume an eligible existing redirect only after
User action through `resumeUnpaidPayment`; they do not treat `next_action.url` as
durable. Runtime activation is not required for exact-pin Artifact adoption;
Provider Browser E2E remains HOLD until the Payment Backend source and Migration
`000065` are active in the target Runtime.

## Released Canonical Rank Contract

GOV-027 reconciles immutable `2.0.0-alpha.34` after the canonical publication
workflow built it from the exact MIG-099 squash commit
`576c35137946e5effcda63d6bf750d5ecc41150f` on protected `main`. Workflow Run
`33395772059` completed successfully on attempt 1 and produced the sole
immutable Artifact `oripa-storefront-contract-2.0.0-alpha.34`, ID
`9759273312`, with GitHub digest
`sha256:c6927b367f9d1ad1a5602792873da481405dc8c3d9c1ba12bbca1954c4e4c8fb`.
Fresh readback verified Manifest
`42f4bee68b787dac16d07accee1c6154c7cea392c521c41b14461d6b56221464`,
`SHA256SUMS` `555ae3637e71a57bff447aa084d21e649b598c878f64766b9f044d1e59f75355`,
Public OpenAPI
`27d0cdcee9194989058573d7e198066fa4af62017a0f301117ea4af034e733f0`,
Client `3363ebf849e3c7165b89ea9f037c681ab889d16539ce290383cad41d31c134c6`,
and Testkit `07916ff69e2e6882aa0e62ee676a65652382413f14f65459ba4e773a41f8a440`.

This is an exact-pin, contract-breaking release. It replaces the old public
`RankDisplay` presentation with the canonical Rank response; old `RankDisplay`
compatibility is intentionally not provided. Storefront must adopt the exact
alpha.34 Client/Testkit Artifact and implement canonical Rank presentation,
lineup image, `show_total_stock`/`total_stock`, Draw-result Rank snapshot, and
video playback before it can use the new Platform response.

Shared Preview Platform Activation is HOLD. The active Shared Preview Storefront
is exact-pinned to alpha.33 and reads `ranks[].id`, `name`, `code`, and
`prizes`, which are absent from the new canonical response. In addition,
`test.luxe-pack.biz` and `luxe-pack.biz` currently route `/api/v2` to the same
API upstream; a Preview Platform deployment could therefore change the
Production-facing API. No Preview migration, API/Admin activation, routing
change, or Production operation is authorized by this release reconciliation.
