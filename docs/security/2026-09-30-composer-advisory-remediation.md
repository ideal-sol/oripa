# SEC-20260930 — Composer advisory remediation

Status: source remediation only; deployment not authorized.
Lane: Strict Change. Risk: R3. Application Runtime Activation: deferred.
Protected base: `3b07a157a8c8ff7d466984dea6c7a5ae1a4d3254`.

## Scope and separation

The Human authorizes a separate Security Change to resolve the two new Composer
advisories blocking Authority-only PR #519. That PR's source approval, source
identity, tests and head are preserved. No advisory is added to the security
baseline. Production operations, Production exact-source workflow dispatch,
Production Runtime Artifact generation, NEW server operations and Activation
remain prohibited. Only existing Required CI verification builds are permitted.

## Findings and dependency paths

| Advisory | Affected versions | Locked before | Minimum fixed version | Dependency path |
| --- | --- | --- | --- | --- |
| [GHSA-jh5r-qr3c-85q8 / CVE-2026-102279](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) | Laravel `<12.69.0`; `>=13.0.0,<13.30.0` | `laravel/framework v13.15.0` | `v13.30.0` on the existing 13.x line | `oripa/backend -> laravel/framework` (direct runtime dependency) |
| [GHSA-cxf4-7mrp-vvpr / CVE-2026-102601](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr) | Flysystem `<=3.35.2` | `league/flysystem 3.34.0` | `3.35.3` | Backend -> Laravel -> Flysystem; Backend -> Flysystem AWS S3 adapter -> Flysystem; Laravel -> Flysystem local adapter -> Flysystem |

Both advisories are rated Low. Their Composer records were reported on
2026-09-29 at 18:24:25Z and 18:10:14Z respectively. The previously accepted empty
Composer baseline remains valid through 2026-10-02, but a prior CI PASS cannot
authorize newly published findings.

## Runtime impact

Laravel's vulnerability affects exception-page tooltips when `APP_DEBUG=true`.
The application defaults debug to false, and the canonical runtime Compose
definition explicitly supplies false. Those source settings mitigate the
reported trigger; no deployed ENV is inspected or changed in this task. The
[official 13.30.0 release](https://github.com/laravel/framework/releases/tag/v13.30.0)
includes the exception-tooltip correction. Installed source review confirms
plain `data-tippy-content` tooltips disable HTML while the distinct trusted HTML
selector retains its intended behavior. No new Browser verification is claimed.

Flysystem's default path normalizer previously treated a failed UTF-8 regular
expression as an ordinary no-match, permitting malformed UTF-8 and control
characters to reach adapters. The fix rejects both a positive match and a regex
failure. Public/Admin asset read and upload, catalog/content assets, private
report exports and health storage probes use Laravel Storage and therefore this
runtime dependency. Local and S3 adapters remain installed at their previous
locked versions. Traversal outside the disk root remains rejected; valid ASCII
and Japanese asset paths retain their normalization. See the
[upstream fixed release](https://github.com/thephpleague/flysystem/releases/tag/3.35.3).

Laravel moves from 13.15.0 to the minimum fixed 13.30.0 within the same major,
which includes other upstream minor changes. Broad existing Backend regression
validation is required, including identity/session, payment/point/draw,
transactions/concurrency and asset/storage behavior. No application business
source, API contract, schema, migrations, worker configuration, ENV or provider
decision is changed.

## Minimal dependency resolution

Resolve only `laravel/framework:13.30.0` and `league/flysystem:3.35.3` with
Composer's minimal-change mode and dependencies enabled. PHP 8.4 resolution
updates exactly those two package records; all other production/development
packages, versions, root constraints, lockfile content hash and platform settings
remain unchanged. The checked-in lockfile is byte-identical to Composer's output.
Install from that lock in an isolated PHP 8.4 test environment without invoking
Composer scripts or plugins. No Dockerfile or baseline change is needed.

## Verification and deployment state

- Strict Composer manifest validation passes; a fresh locked audit has no
  advisories and no abandoned packages.
- Flysystem regression: 6 tests / 8 assertions PASS on the fixed dependency;
  the same tests with the exact prior upstream normalizer reject the regression
  with three expected failures. This negative control is separate from PASS.
- Existing security-gate tests: 10 PASS. Focused and full V2 regression, Required
  CI, exact-head review and merge evidence are recorded in the Security PR.
- Focused Backend regression: 196 tests / 2,326 assertions PASS. Full V2 and
  final-head CI results are recorded separately in the linked PR/Issue #520.
- Existing 77 V2 migrations initialize only a dedicated disposable test DB after
  canonical target checks. MIG-999 is a guard-compatible test namespace, not a
  new migration reservation. Migration creation and shared/Production
  application are zero; test database state is destroyed at closeout.
- No local image build, deployed runtime/ENV change, service activation,
  Provider send, worker update, Site deployment, secret rotation, contract/client
  update or stable release occurs. Existing runtime/rollback images are retained.

## Authority and rollback

The security fix is protected-source preparation, not Runtime Acceptance.
Production Runtime Target `62c3c813081cf0ea526c66e7e4131c6816de2449` remains
unchanged and contains the prior vulnerable dependency versions. A Security
merge cannot silently replace that exact Human-approved source or claim the
running environment was remediated. A future fixed-runtime build/activation and
any new source acceptance require their separate authorities and gates.

Rollback of this source-only update is a new reviewed revert Change. Rolling
back dependencies reintroduces the two advisories; no database rollback or
secret rotation is indicated by this change. Runtime rollback is not executed.

## SECINT-20260930 — Human-approved integrated security remediation

Issue #522 tracks the explicitly approved integrated Strict Security Change.
Protected main still has the two Composer findings, while Composer-only PR #521
fails on new pnpm findings. Separate main-based remediation PRs cannot pass the
unchanged all-audit gate in either merge order. Reuse original Composer checkpoint
`3c8e7e68df0e2051fce19ce1bb6d7fbf801ff20d` and recovery commit
`4e3c5095ed9b0702c75e8b50b03bd0a9c66fd8a9` as ancestors of the integrated branch;
the Composer lockfile and Flysystem regression remain byte-identical. Preserve
#521 without updates until the integrated PR passes Required Checks and merges,
then close it as superseded. Authority PR #519 remains separate and unchanged
until the approved post-security main synchronization.

| npm advisory | Installed version | Affected range on installed line | Minimum fixed version | Dependency path |
| --- | --- | --- | --- | --- |
| [GHSA-q2hr-2g5m-vwhr](https://github.com/advisories/GHSA-q2hr-2g5m-vwhr), Moderate quadratic CPU DoS | brace-expansion 5.0.9 | `>=4.0.0 <5.0.12` | 5.0.12 | Admin/legacy -> ESLint -> minimatch -> brace-expansion |
| [GHSA-qhr7-859c-m2p7](https://github.com/advisories/GHSA-qhr7-859c-m2p7), High nested-brace stack exhaustion | brace-expansion 5.0.9 | `>=4.0.0 <5.0.11` | 5.0.11 | Same transitive development-tool path |
| [GHSA-6j4f-fj2g-mc7p](https://github.com/advisories/GHSA-6j4f-fj2g-mc7p), High comma-parser stack exhaustion | brace-expansion 5.0.9 | `>=4.0.0 <5.0.10` | 5.0.10 | Same transitive development-tool path |
| [GHSA-hrr3-gc8f-f4qj](https://github.com/advisories/GHSA-hrr3-gc8f-f4qj), Moderate encoded-host case normalization | fast-uri 3.1.7 | `>=3.0.0 <3.1.8` | 3.1.8 | Site Schema -> AJV 8.20.0 -> fast-uri (transitive runtime library) |

Workspace audit reproduced four findings, and the independent preserved legacy
reference reproduced the three brace findings. The prior incomplete overrides
match these findings, but legacy also requires its own brace override/lock update.
Choose brace-expansion 5.0.12 to fix all three advisories on the existing major
line, and fast-uri 3.1.8 on the existing major line. Root and legacy manifests and
locks change only these overrides, target package versions/integrities and their
dependency references. Preserve unrelated peer-snapshot formatting and packages;
both pinned-pnpm frozen installs succeed without scripts or lockfile mutation.

The existing policy validator also pins the exact audited root override values.
Synchronize only its brace/fast-uri expected versions and matching positive test
fixture; retain strict equality and add rejection tests for both former versions
and semver ranges. This directly necessary bounded dependency-guard maintenance
does not change Lane classification, check requirements, workflow, protection,
baseline or validation logic. The integrated PR declares these two additional
validator/test paths; no guard is removed or weakened.

Fresh locked Composer, workspace pnpm and legacy pnpm audits each return exit 0
with zero findings. Thirteen targeted dependency assertions pass for normal brace
expansion, Japanese paths, all three advisory-shaped brace inputs in both
installations and fast-uri percent-encoded scheme-relative host normalization.
Reuse the source-equivalent 196-test / 2326-assertion Backend PASS and completed
834-test / 9150-assertion Full V2 recovery evidence (ten existing opt-in skips);
the interrupted zero-byte XML is not PASS. Prior #521 integration PASS is
historical Composer evidence, not a substitute for the integrated head's fresh
Required CI. No duplicate manual Full suite is run.

The original empty baseline, CI workflows, protection and all five Strict
Required Checks remain unchanged. Current Required ARM64 verification is
permitted; Production Artifact workflows, builds and Activation are not.
Brace changes affect development tooling; fast-uri affects future consumers of
Site Schema validation. No deployed dependency, ENV or runtime is changed, and
the Human-approved Production Runtime Target remains the exact source stated
above, including its prior dependency versions. Rollback needs a new reviewed
dependency Change and reintroduces the findings; no migration is involved.
