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

The validator requires:

1. A pending bundle to be exactly the next Alpha sequence after the latest immutable bundle.
2. Published package versions to equal that bundle version.
3. Referenced Site Schema version, digest, source bundle, and source tree to match immutable evidence.
4. Public OpenAPI digest and operation count to match the declared additive contract.
5. Client minimum Public API, required capabilities, Testkit dependencies, and operation count to match.
6. The Artifact inventory to contain only Client, Testkit, Public OpenAPI, Manifest, and checksums.
7. A settled ledger with `candidate: null` to reject a second publication attempt.

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

The canonical workflow detects whether a pending candidate exists. It builds and
uploads exactly one candidate from an approved exact head. Once release evidence
is reconciled into immutable history and `candidate` becomes `null`, later
workflow runs skip Storefront Artifact creation instead of republishing the same
version.

Publication, Registry publication, Storefront adoption, Runtime deployment,
Migration application, Provider integration, and Production remain separate
states. A released Artifact is read back from the canonical GitHub Artifact,
rehashed, and compared with its Manifest and `SHA256SUMS` before GO.

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
