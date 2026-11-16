# NEWPROD PR692 limited hotfix — Draft handoff

Status: Draft preparation only. Risk R3; Lane Strict Change; Application Runtime Activation deferred; Issue none. No Authority/Source merge, formal Production Build, Stage, migration or Runtime activation is authorized by this Change. `activation_authorized=false`.

## Exact source

The immutable composition is [production-pr692-payload.json](../../../manifests/production-pr692-payload.json). It fixes the base PR688 SHA/tree/reviewed head, merged/reviewed PR692 SHA/tree, before/after blobs, deterministic commit bytes, resulting tree and full Git inventory SHA256. The derived commit is deliberately not claimed as a merged GitHub commit.

- Base Source: `9235350523accdb2c3d41dca869d7d44f0863ba5`
- Base Tree: `f9d41f6566b4c1e771bb7895dabad1639b80b810`
- PR692 Merge: `4f9f9a60cdb3bbe754a1931c8db72615e66c85a9`
- PR692 Reviewed HEAD: `690c70547dbac9b8acc57a2350f5cc54ad86444d`
- PR692 Reviewed/Merged Tree: `a35e1d52da7c3adbe568c59a799dc7fd02d050d5`
- Runtime Source: `516fe7a946683103ed68ee4875d5ea3d94327a0d`
- Runtime Tree: `53bf0e809b9724392e5af6eda75b1d1c15d333e2`
- Inventory SHA256: `4d33d8e2de4f08a41af6e009391eda9d53fb38d3201aa3eeacc15f82ad81254e`

Only `apps/api/app/Domain/PrizeShipping/Services/V2PrizeShippingService.php` changes: one hunk +5/-1. Before blob `e43907d546a5035ae4b7a76920e9ebc6e36b6613`; after blob `030ecd03ec4431432b92648d997eb6b623d8336c`. The entire tree equals the PR688 tree with only that blob replaced. Therefore LINE delivery D1/D2 additions and all other post-base application changes are absent. Exact migrations are 001–107; 108/109/110 are absent. Laravel composer inputs, packages, contracts (alpha.52), Dockerfiles and all other base files remain byte-identical. Admin PR663 activation is excluded; current Admin image, Agency and Storefront stay unchanged.

## Authority and build handoff

PR692 has its own hardcoded manifest digest, source identity and composition validator. PR666 and Admin Node exceptions retain their existing identities and validators. The canonical Production authority points at this exact derived source with activation false. Both PR688 and PR692 must be merged internal PRs on main with the pinned reviewed heads, matching reviewed/merged trees and authentic latest Required Checks; current protected main workflow authority must also pass all five checks. Materialization requires a pristine base checkout and rejects changed blobs, additional paths, inventory, commit, tree and migration drift. Arbitrary cherry-picks remain unauthorized.

Workflow Authority SHA: **not established by this Draft**. After separate Human Authority Merge GO, fix the exact then-current protected main SHA containing this implementation and manifest; record it separately from Runtime Source. A Draft head must fail live protected-main authorization. If main moves before dispatch, recapture and validate authority, never adopt it as Runtime Source.

No workflow changes or new API-only Build mode are needed. On separate Human Build GO, dispatch existing `.github/workflows/platform-production-arm64-artifact.yml` on protected main with `image_mode=agency`, `change_id=NEWPROD-PR692-AUTHORITY`, `pr_number=692`, the Runtime Source above and Human-confirmed current public/Admin/Agency origins. This generates the normal native ARM64 API/Admin/Agency bundle. The PR number identifies the approved fix provenance, not a claim that the synthetic source is PR692's merged tree. OCI revision for every generated image equals the derived Runtime Source, never workflow authority or latest main. Only the API image is eligible for the later authorized cutover. Bundled Admin/Agency images confer no activation permission.

Required future evidence: workflow run ID and authority SHA; both reviewed-source check results; reproduced source/tree/inventory; source secret/PII scan; applicable current dependency/vulnerability and Release Gates; native ARM64 host check; production-readiness (effective domain checks); package/verify/load receipt; immutable outer artifact SHA256/digest and inner image IDs/digests; OCI revision. Preserve `production-source-authority.json`, `production-readiness.json`, immutable artifact ID and verification receipts. Digests and artifact IDs are NOT_AVAILABLE until the authorized Build; do not prefill them. Reuse alpha.52 formal Client/Testkit Artifact 13742019147 without repack/publication.

## Executed verification

- Live GitHub PR688/PR692 merged/reviewed identities, reviewed/merged tree equality and authentic Required Checks: PASS (read-only verification; evidence in task evidence directory).
- Source reproduction, manifest/inventory and pristine checkout: PASS. Full tree delta is the one approved blob; no API test overlays enter the formal source.
- Isolated PostgreSQL17 with no external network, no published ports, synthetic fixtures and PHP8.4.23: migrations001–107 applied only to this new test DB; ledger query returned count107 and final migration107. No migration files created; no operational/Production migrations applied.
- On exact derived application source and unchanged locked Laravel dependencies, PR692's two reviewed test-file overlays were used only for verification: selected walletless/zero/stale and existing auto-exchange regressions: **10 tests, 114 assertions PASS**. Includes wallet initialization, Point Operation/Lot/Ledger, 180-day lot expiry, replay, existing wallet retention, rollback and healthy batch continuation. Overlays were removed after testing and tracked diff rechecked zero.
- Canonical production authority/payload/admin-node and release readiness unittest entrypoints: **90 tests PASS**. Negative cases include unprotected or moving main, unmerged/foreign/wrong merge PR, tree/check failure, manifest/identity/inventory/commit tampering and dirty payload. Historical authority tests use their immutable historical source rather than treating current authority as unchanged.
- Local policy-gate: PASS. Required CI and final-head Strict self-review are recorded on the Draft PR after final push; they are not implied by this document.
- Production Build, Stage, Browser/E2E, external provider integration, Production DB and Runtime validation: NOT RUN; outside this preparation GO. Prior PR692 development/concurrency evidence remains attached to PR692; this Change does not claim that evidence used Ledger107.

## NEW server Stage/cutover handoff (future GO only)

Stage the verified immutable bundle through the existing Canonical route. Record current API image/digest and exact rollback container/release before touching Runtime; preserve all current Admin/Agency/Storefront references and compare after cutover. Cutover scope is API image only plus API-code consumers explicitly selected in the subsequent Human GO. Migration NONE; Ledger107 and current ENV/Secret settings remain unchanged. Preserve payment, member rank, LINE rewards, client-IP security and HTTPS configuration.

Inventory API-code consumers before cutover: automatic prize exchange Timer/job, existing six Timers, member-rank worker and F2-B. If their containers pin the API image, they require explicit selection of this same derived image/digest to receive the fix; do not leave the automatic exchange job on old code while claiming completion. Drain/restart only approved consumers; preserve commands, schedules, flags, financial settings and enablement. No LINE delivery workers/timers are introduced. Exact consumer selection is a later runtime decision, not authorized here.

Rollback: switch only changed API/approved consumer image references back to their recorded prior immutable images; keep Ledger107, do not reverse point operations or DB ledger. The fix writes existing schema and accounting records. Preserve evidence and investigate ambiguous in-flight jobs before retrying. ALB maintenance requirement remains undecided: resolve during Stage/cutover planning based on API replacement behavior and writer/drain scope. No ALB changes in this Draft.
