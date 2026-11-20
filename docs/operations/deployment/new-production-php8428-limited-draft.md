# NEWPROD-PHP8428 — limited PHP security update draft

R3 / Strict Change / Application Runtime Activation deferred. Issue none.
Human authorizes Source preparation, isolated validation and a Draft PR only.
Merge, formal Production Artifact Build, Stage and Cutover require separate GO.

## Source identity and reproducibility

Human-supplied running Source is `42cac7fd8e176b8686fedffc7673734036bf670e`,
tree `e215dedcc1437fe2865aaa73282608f0708914e4`, API image
`sha256:9e574d901df9c25630db29c3febb02a481180afeb5a8d4ded89dbb94f0095883`.
These match the retained PR707 build receipt; this is not a fresh live NEW observation.

Run `python3 scripts/ops/production_php8428_design.py` from this reviewed control
checkout. It creates only a temporary local Git clone and objects, reuses the
unchanged PR693/PR707 materializers, then applies exact replacements to the
reproduced Source. The manifest pins input/output blobs, replacement counts,
complete tree inventory and deterministic commit bytes. Any mismatch fails.

- [Design manifest](../../../manifests/production-php8428-payload-design.json)
- Candidate Source: `0254792e8dc0baafbef45c6f2ca861ee91cfa191`
- Candidate tree: `39cfb3d2a290b93711738596728c25127a94ba4d`
- Inventory SHA256: `17e845f9497d53a87cc2a13b5c3900def2795bb5a3ed9b40891809edfed7aca9`
- Manifest SHA256: `5ed92be79c7046ffdd9baf213efa6e3524fabc517c0de5587110146b9d8512c6`

The synthetic commit is not a merged GitHub commit or Build authority. Its only
changed files are `infra/docker/backend/Dockerfile` and
`scripts/ci/policy_gate.py`: PHP image pins and required version checks.
Every other byte remains identical to the running Source, including PR692,
PR705, alpha.52, migrations001–107, LIVE payment implementation, LINE integration
and member rank. No LINE delivery D1–D7, migrations108+, newer Admin or newer
Docker extension/OS additions are imported from main. The control PR updates
the existing regression fixture separately; it is not a payload overlay.

Two bounded control-CI repairs are excluded from the limited payload: the
Preview-only linux-libc-dev pin moves from unavailable6.1.190-1 to6.1.192-2
(already shipped by the new official base), and the immutable OLD Test Recipe
test compares its original fixed control Source rather than latest main.
Existing recipe/authority bytes and every assertion about its transformations
remain intact. The exact Human Task ID is recognized by the existing policy.

## Official images and checks

Registry index digests were rechecked on 2026-11-20:

| Image | Index SHA256 | linux/arm64/v8 manifest SHA256 |
| --- | --- | --- |
| php:8.4.28-apache-bookworm | `530fe79ff4c2737a4de714a7b1e6c741dbdb3dafcee0ce49a2b4993ca11ad7dd` | `0fc7225e35eafe9a984ec4f64712949b56c8dbc621d35e4ab2d6fde4552e8669` |
| php:8.4.28-cli-bookworm | `85da896b3eeb8bcd83fe612e51a529b7d5983a1fab92ef5f63c7ac6e2842fc46` | `72510d9d7b82e37d32334cc50aab6c59fa499043775a51d4b636a40149dc8a1f` |

All four stages now require exactly PHP_VERSION_ID80428. Composer's pinned
PHAR, lockfiles, platform checks, extension compilation and post-copy autoload
checks remain unchanged. The final Apache build still performs an actual local
HTTP apache2handler version/autoload probe and deletes the probe afterward.
Build extensions from the new base; never copy binaries/extensions from 8.4.27.

The [official PHP changelog](https://www.php.net/ChangeLog-8.php#8.4.28)
records security fixes including timezone-cache use-after-free
CVE-2026-129496 and DOM serialization double-free CVE-2026-129501.
Version provenance alone does not establish that an entire application image
has no other vulnerabilities. Required security-gate and all other Required
Checks remain unchanged. Record current image scan results and any new severe
runtime findings separately; do not suppress unrelated existing High findings.
Final executed validation and exact-head CI evidence belong in the Draft PR.

## Independent mail/SMS workers

OLD-side PR693/PR707 artifact receipts establish API/Admin/Agency image
provenance, not the independent NEW mail/SMS image build recipes. Human reports
both workers run PHP8.4.27. Their exact running image ID, OCI revision,
Dockerfile, build target and any overlays remain unverified here. Do not infer
that these independent images equal the API image or that this PR updates them.

Default decision: separate limited worker release until provenance proves safe
reuse. Obtain each current immutable image/Source/tree/recipe and compare its
application, vendor, extension, entrypoint and configuration inputs. Rebuild
that exact worker Source with only the verified PHP8.4.28 pins and version
checks, preserving commands, environment, queue behavior and credentials.
If evidence establishes exact API artifact compatibility, explicitly authorize
selected worker references to use that verified artifact under a later GO.
Otherwise assign each separate artifact its own Source/tree, scan, isolated
no-send mail/SMS tests and rollback identity. No worker/timer was updated.

## Formal artifact handoff

Keep existing Production Authority manifest, source routing, workflows,
Artifact receipts and rollback image untouched. This PR supplies a deterministic
design, not a newly enabled Production dispatch route. Before formal Build,
complete the existing authority route's exact-source integration with the new
manifest digest and reviewed PR provenance, under separately reviewed control
changes and Human GO. Do not pass the new synthetic Source through the old
PR705 authority or replace the source with latest main.

Require fixed-head checks/self-review, reproducible payload, ARM64 application
validation, effective security/readiness and explicit Build GO before the
canonical artifact workflow. Record Workflow Authority separately from payload
Source/tree and OCI revision. Preserve existing API rollback image above;
fresh NEW readback must confirm it before later Stage/Cutover. Worker rollback
identities must be captured independently. No DB rollback or migration is needed.
