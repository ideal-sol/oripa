# Storefront Contract Artifact

## Authority

The finalized Package Version Compatibility Policy is authoritative. Core
components share compatibility family major `2`; component Minor/Patch and
OpenAPI document versions are independently managed and recorded in a manifest.

`manifests/storefront-contract-releases.json` is the machine-readable Alpha
release ledger and current candidate. It does not authorize publication by
itself. Artifact Release Lock, exact-head Required Checks, and the task release
gate remain mandatory.

## Package-only Model

The next bundle is `2.0.0-alpha.24` and publishes only:

- `@oripa/storefront-client@2.0.0-alpha.24`
- `@oripa/storefront-testkit@2.0.0-alpha.24`

The bundle references, but does not rebuild or include, the immutable
`@oripa/site-schema@2.0.0-alpha.23` tarball. Platform, Application, and Public,
Admin, and Webhook OpenAPI remain `2.0.0-alpha.23`. The Public OpenAPI and Site
Schema references include the exact predecessor digest/source tree.

This is not an arbitrary mismatch allowance. The validator requires:

1. The bundle to be the next Alpha sequence after the latest immutable bundle.
2. Every published package version to equal the bundle version.
3. Every referenced package version, digest, source bundle, and source tree to
   equal the latest immutable evidence.
4. Client minimum Public API and Testkit dependency/compatibility metadata to
   equal the manifest versions.
5. The artifact inventory to contain only the published tarballs, the unchanged
   Public OpenAPI snapshot, the manifest, and checksums.

## Immutable Boundary

`2.0.0-alpha.21`, retired `2.0.0-alpha.22`, and latest handoff
`2.0.0-alpha.23` remain immutable. The validator rejects a candidate bundle or
published package version at or below the current high-water mark. Referenced
`alpha.23` Site Schema is never repacked into the new artifact.

Do not modify, overwrite, delete, or re-upload an existing Artifact. A failed
candidate receives a new version after a separate governed allocation.

## Validation

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

The workflow calls all three operations. There is no skip flag. Publication,
Registry publish, Storefront installation, Runtime deployment, and Production
deployment are separate states and are not performed by validation.

## STORE-SITE-034 Resume

After GOV-016 merges, STORE-SITE-034 synchronizes its existing branch with the
latest Platform `main`, reruns its exact-head Required Checks and fresh
self-review, then acquires Artifact Release Lock before dispatching the canonical
artifact workflow. It must use bundle/package version `2.0.0-alpha.24`; no
STORE-side validator workaround is allowed.
