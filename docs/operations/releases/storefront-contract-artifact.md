# Storefront Contract Artifact

## Authority

The finalized Package Version Compatibility Policy is authoritative. Core
components share compatibility family major `2`; component Minor/Patch and
OpenAPI document versions are independently managed and recorded in a manifest.

`manifests/storefront-contract-releases.json` is the machine-readable Alpha
release ledger and current candidate. It does not authorize publication by
itself. Artifact Release Lock, exact-head Required Checks, and the task release
gate remain mandatory.

## Additive Contract Model

The next bundle is `2.0.0-alpha.24` and publishes:

- `@oripa/storefront-client@2.0.0-alpha.24`
- `@oripa/storefront-testkit@2.0.0-alpha.24`

The bundle references, but does not rebuild or include, the immutable
`@oripa/site-schema@2.0.0-alpha.23` tarball. Platform and Application remain
`2.0.0-alpha.23`, while the additive Public, Admin, and Webhook contracts advance
independently to `2.0.0-alpha.24`. The Public OpenAPI candidate digest and the
Site Schema predecessor digest/source tree are machine-validated.

This is not an arbitrary mismatch allowance. The validator requires:

1. The bundle to be the next Alpha sequence after the latest immutable bundle.
2. Every published package version to equal the bundle version.
3. Every referenced package version, digest, source bundle, and source tree to
   equal the latest immutable evidence.
4. The additive Public OpenAPI version, digest, and operation count to equal the
   governed candidate without changing the immutable predecessor evidence.
5. Client minimum Public API and Testkit dependency/compatibility metadata to
   equal the manifest versions.
6. The artifact inventory to contain only the published tarballs, the candidate
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

## Storefront Handoff

After MIG-079 merges, STORE-SITE-034 synchronizes its existing branch with the
latest Platform `main`, consumes the additive `2.0.0-alpha.24` contract/client
candidate, reruns its exact-head Required Checks and fresh self-review, then
acquires Artifact Release Lock before dispatching the canonical artifact
workflow. No STORE-side validator workaround is allowed.
