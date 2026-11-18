# NEWPROD-DRAWTZ-20261118 — limited Source / Authority Draft

Status: Draft preparation only; Task ID PRODTZ-20261118, R3 / Strict Change / Application Runtime Activation deferred. `activation_authorized=false`. No Ready/Merge, formal Production Artifact Build, Stage, operational DB/ENV/migration or Runtime change is authorized.

## Runtime baseline and observation boundary

Human supplies current NEW Runtime Source `516fe7a946683103ed68ee4875d5ea3d94327a0d`, Tree `53bf0e809b9724392e5af6eda75b1d1c15d333e2`. The latest OLD-side PR693 public Handoff (2026-11-16 07:09 UTC) and formal-build receipt identify that same Source and Artifact14203929426, but explicitly record Stage0/Runtime0. They are build provenance, not a live NEW observation. This OLD-side preparation has no direct verified NEW Runtime access. No older source is silently substituted.

**Before any Runtime GO, NEW Codex must measure the currently running API and selected consumers:** immutable image IDs/digests, OCI revision, Source/Tree, running command and image pins; actual DB ledger107 through the existing read-only path. Match the supplied baseline exactly; any different Source, Tree, unknown identity or ledger is HOLD and requires a new bounded composition decision. Do not deploy latest main as Runtime Source.

## Reproducible composition

The exact definition is [production-pr705-payload.json](../../../manifests/production-pr705-payload.json), validated by [production_pr705_payload.py](../../../scripts/ops/production_pr705_payload.py). The existing PR692 manifest/helper/digest remain byte-identical. Start from merged PR688, invoke the existing PR692 materializer, then add only the PR705 Application blob; the derived commit's parent is the reproduced PR692 Source. Neither synthetic Source is claimed as a GitHub merged commit.

| Identity | Value |
|---|---|
| Anchor PR688 | `9235350523accdb2c3d41dca869d7d44f0863ba5` |
| Existing PR692 Source / Tree | `516fe7a946683103ed68ee4875d5ea3d94327a0d` / `53bf0e809b9724392e5af6eda75b1d1c15d333e2` |
| PR692 retained service Blob | `030ecd03ec4431432b92648d997eb6b623d8336c` |
| PR705 merge / reviewed HEAD | `3ff2eda41ebf6e18a5a5d8668a6031ec66d0e33f` / `f213d9f849fadfb03d73b2301ee93cf684add5ed` |
| PR705 reviewed / merged Tree | `b85567730183030e5ab006c681aff5bb74bb6e0b` |
| Draw service old / new Blob | `7d2a2dbbc7f0b6bf5f4a05a498dba09015752780` / `b2a74c20b28ce1bf5ce660fd9b516be5eb23dbb0` |
| New limited Source | `42cac7fd8e176b8686fedffc7673734036bf670e` |
| New limited Tree | `e215dedcc1437fe2865aaa73282608f0708914e4` |
| Full Git inventory SHA256 | `42f389c8e97f14c9a0909c6a4862b824b9a56cf7aafd345f7675c656e66a91f3` |
| Manifest SHA256 | `3f4fbd4ef3b98a72fb7ea700ef2a1a1489997d3f340a5c7f9442d4af02da46e8` |

Complete delta against existing Source: `apps/api/app/Domain/Draw/Services/V2DrawEligibilityService.php`, one hunk +3/-1 preserving DateTimeInterface instead of reparsing its timezone-less string. Exact byte replacement, old/new blobs, commit bytes, tree and inventory are checked. PR692 wallet initialization service Blob remains unchanged. All other files are identical, including tests, Composer inputs, Dockerfiles, generated contracts, Admin and Agency. Contract alpha.52 and exact migrations001–107 are retained; migrations108+, LINE delivery D1–D7 and other later main changes are excluded. Existing LINE login/rewards, rank, draw/point/payment and settings are preserved.

## Authority / formal Build boundary

Production authority routing is a new exact-source case alongside unchanged PR692, PR666 and Admin Node cases. It requires merged internal PR688/692/705 with pinned merge/review/tree identities and Required Checks, exact current protected workflow main and its Required Checks, and exact manifest digest. A Draft checkout cannot become live workflow authority. `pr_number=705` identifies fix provenance; it is never the new Authority PR number.

Only after separate Human Authority Merge and formal Build GO, use the existing `platform-production-arm64-artifact.yml`, `image_mode=agency`, `change_id=NEWPROD-DRAWTZ-20261118`, `pr_number=705`, and the limited Source above. Keep Workflow Authority SHA separate from Runtime Source/Tree and OCI revision. No new workflow or Build mode. The normal bundle includes API/Admin/Agency images, but only API is eligible for Runtime activation. No new formal Artifact/Image digest is available in this Draft; do not invent one or reuse a mismatched PR692 image for this fix.

Required before Build: actual Authority merge, fresh protected-main identity/checks, all three source PR proofs, reproducible payload, release compatibility and effective Security/Readiness checks. Existing unrelated security findings remain HOLD for Production until separately resolved or explicitly handled under the applicable Human policy; merge-gate success does not establish Runtime image Security PASS. Do not add exceptions or suppress findings. Keep existing formal Build/verify/Stage mechanisms.

## Ledger107 verification

Use only task-isolated PostgreSQL and exact limited Application with migrations001–107. Overlay reviewed PR705 regression methods and existing PR692 wallet tests for execution only; overlays/vendor/cache are not part of payload inventory or formal Build input. Verify actual APP/PHP JST and SQL session UTC, DB-reloaded Eloquent and offset-bearing rows, 15h1m/23h59m/24h/24h1s and7-day boundaries, evaluate/assertForDraw parity and PR692 walletless/zero/stale/batch behavior. Record executed results on the Draft PR; main's Ledger116 results are supporting evidence only and cannot replace this check. Preserve synthetic evidence and restore test timezone settings. No Production DB access.

## Later NEW API-only handoff

Apply latest Human sequence: **technical preparation → maintenance → Drain → final financial Gate → API-only Cutover → normal public recovery**. This release-specific sequence supersedes the older maintenance decision left open in PR693. Reuse the [Canonical Runbook](new-production-canonical-release-runbook.md); no new release framework, timer command or financial decision is introduced.

During technical preparation, NEW Codex confirms live baseline and rollback assets, verified formal Artifact/outer and inner checksums, ARM64 image IDs/OCI revision and exact limited inventory, unchanged Contract alpha.52/Ledger107 and retained settings. Stage only under separate GO, preserving Admin/Storefront/Agency images and ENV. Maintenance and existing canonical writer Drain precede the final financial Gate; any in-flight payment/point/draw/automatic-exchange/rank ambiguity is HOLD. Never manufacture zero activity by deleting history or retrying uncertain operations.

Inventory API-code consumers before GO: F2-B image pin, six existing Timers (including automatic exchange), member-rank Worker and any pinned API code process. Explicitly select consumers whose references must follow the new API image; preserve their commands, schedules, enablement, ENV, financial flags and LINE settings. Drain/rebind/restart/resume only selected consumers through existing procedures. Do not leave a selected consumer on old code while claiming completion, and do not introduce LINE delivery workers/timers.

Rollback baseline is the freshly measured pre-cutover API/consumer immutable image for Source516fe7a9, not the older PR688 fallback receipt. Retain those assets and restore only changed references through the existing path if required; no DB/point reversal, schema rollback, rebuild or history reset. Resolve ambiguous jobs before replay. Verify API/selected consumer identity and health, retained financial behavior, then restore normal public availability. Admin/Storefront/Agency remain unchanged throughout. Exact activation/consumer scope and Runtime GO remain Human decisions.
