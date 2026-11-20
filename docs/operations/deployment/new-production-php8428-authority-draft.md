# PHP 8.4.28 limited Production Authority — Draft

Task PHP8428-20261120; Human Change NEWPROD-PHP8428. R3 / Strict Change;
Application Runtime Activation deferred. Draft and validation only. This does
not authorize Authority Merge, Build, Stage, Cutover or OLD Test Runtime update.

## Immutable Source

PR715 merged at 4fb1d80a5fca4d8f12829a26972aa2c9402a92c3; reviewed HEAD
3083d1278162ed5fc529c393600e3996b02fc120; reviewed/merged tree
00abe579aade9353b90e86f8ba44c9edeccd71db. Reuse its successful Required CI
[44659499582](https://github.com/ideal-sol/oripa/actions/runs/44659499582).

Runtime Source: 0254792e8dc0baafbef45c6f2ca861ee91cfa191.
Tree: 39cfb3d2a290b93711738596728c25127a94ba4d.
Inventory SHA256: 17e845f9497d53a87cc2a13b5c3900def2795bb5a3ed9b40891809edfed7aca9.
Design Manifest SHA256: 5ed92be79c7046ffdd9baf213efa6e3524fabc517c0de5587110146b9d8512c6.

The unchanged design/helper and prior PR707 materializer prove the exact
PR688 -> PR692 -> PR705 -> PHP715 provenance. The only Runtime delta from
42cac7fd8e176b8686fedffc7673734036bf670e is PHP CLI/Apache base digests and
necessary version checks. Contract alpha.52, Ledger107, LIVE payment, LINE and
member rank remain. No LINE delivery D1–D7, migration108+, later Admin,
application or dependency changes enter the Runtime Source. Preview libc
6.1.192-2 is excluded; historical Recipe/manifests remain immutable.

## Canonical Build handoff — separate GO required

Reuse `.github/workflows/platform-production-arm64-artifact.yml` on exact
then-current protected main after separate Authority Merge and Build GO.
Workflow Authority is that protected main, independently of Runtime Source.
Inputs: source_sha above; change_id NEWPROD-PHP8428; pr_number 715;
image_mode agency; platform linux/arm64. Existing approved origin inputs must
be supplied without recording private configuration here. No new workflow.

The Source Authority checks all four source PRs, reviewed trees, successful
Required Checks and current protected-main continuity. It materializes the
synthetic commit locally without a synthetic remote ref. Activation remains
unauthorized. The workflow builds API/Admin/Agency verification images under
its existing agency inventory; activation scope is API only. Admin, Storefront
and Agency runtime stay unchanged. API uses `--target production`; the separate
Preview stage and its old libc pin are not ancestors of this target. If an
actual Build reveals another dependency path, HOLD and propose a minimal
control-only remedy; do not change the approved limited Source.

## Later API consumers and rollback

Under separate NEW Cutover GO, measure current API/consumer Source, Tree,
immutable image and Ledger107; unknown/mismatch HOLD. Preserve rollback image
and existing Authority/Artifact identities. Follow the Canonical maintenance,
Drain, final financial Gate, API Cutover and public recovery sequence.

F2-B, six Timers and member-rank Worker that currently reference the API image
may follow the verified new API image through the existing deployment path,
preserving service references, Commands, Schedules, flags, provider/LINE settings
and operational ENV. Capture and compare those values before/after under that
later GO. Do not infer that every worker uses the API image. Independent
mail/SMS Source/Recipe remains unknown; keep separate and prepare a separate
limited release once provenance is established. No worker update is claimed.

Migration NONE; operational ENV NONE. No database reversal or schema rollback.

## Security and outstanding gates

Production Security remains HOLD: earlier limited candidate OS Critical3 /
High197; current dependency posture HOLD. Required development security-gate
PASS means no introduced dependency regression, not Production Security PASS.
No new Critical is attributed to this PHP repair. Exact formal ARM64 limited
Application Image has not been built or security-validated.

At the formal Artifact stage, retain fresh SBOM/scan and verify exact installed
packages, fixed versions and actual loading/reachability: CVE-2025-7458
libsqlite3-0, CVE-2026-6653 libxml2, CVE-2023-45853 zlib1g, plus existing High.
Check the vendor advisory conditions against loaded extensions/libraries and
reachable inputs; unknown impact remains HOLD. PHP is source-built in the
official image, so scanner absence alone does not prove its version: retain
base provenance and actual CLI/Apache8.4.28 checks. Any required OS remediation
must be proposed separately with a newly approved Source identity if necessary.
No exception, suppression, security relaxation or unrelated remediation here.

Readiness recognizes this exact limited Source but cannot infer Artifact,
Production security or Runtime readiness. New major runtime finding or Required
Gate failure is HOLD. Authority and Readiness positive/negative tests, previous
PR692/705/666/Admin regressions, exact reproduction and fresh Strict review are
required on this Draft. No formal Build, Runtime or Migration is performed.
