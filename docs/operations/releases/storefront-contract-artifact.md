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

The ledger records alpha.25 as `latest_immutable` and clears `candidate` to
prevent a same-version second publication.

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
tarball and digest. It must preserve predecessor alpha.24 and rerun Site-specific
compatibility checks. Runtime activation is not required for exact-pin Artifact
adoption; Provider Browser E2E remains HOLD until the Payment Backend source and
Migration `000065` are active in the target Runtime.
