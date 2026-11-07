# PAIDY-ALPHA51-20261106 — approved contract-breaking release preparation

## REL-051 — Paidy alpha.51 publication readback and ledger

R3 / Strict Change / Application Runtime Activation none; Issue none.
Source PR670 squash 705d03caab389c0f9600b3e85420532aa6d46439; reviewed head
a942b5157459f93e7641ef55ab6d83a17d5ef760 / Tree d87799ea65d6b476a8855e8653cb7a6c0f779b55.
Source Required5 PASS; fresh schema2.0 Strict self-review SEV0/1=0.
Canonical publication https://github.com/ideal-sol/oripa/actions/runs/42352571854 attempt1
completed authorization, contract-only Build/upload and readback. Independent local
canonical readback verified exact Artifact ID, outer digest, all five inner files,
Source SHA, runtime Client version51, Public47 and referenced Site Schema23.

Artifact ID 13566710138; name oripa-storefront-contract-2.0.0-alpha.51.
- outer_sha256: `ac2c528b8c434cd147322e327a86882120639e71a70cdc596b40a86b61375326`
- manifest_sha256: `2ab973443a4db6306a516944502da0ba2c3427167611c674c58be0ec6f7de502`
- sha256sums_sha256: `a8de5320cc027c14563d95e6994a0006ec89ab3ed52991f5a272d3dc7501f622`
- public_openapi_sha256: `3e7a91d456250cccdf4be0b5874970f6ecac32d9a853fce1b29ef0a594376795`
- client_sha256: `bf58fc8c31e5d423bd5e50ec2265d0e09e13a32ce10fe716559e1930abdfce3f`
- testkit_sha256: `f5654b8b6ec12d0063ee11a549fae9f6b52bcc34605e0baa6c9233c600fe5e7a`

Ledger appends alpha51, retains all30 predecessor records exactly, sets
latest_immutable=alpha51/candidate=null; contract-breaking/true and contracts47
remain unchanged. No new Source/package/contract/gate changes. Historical tests
retain exact alpha50 authority; pending51 preparation is tested at Source squash;
released51 validates measured identity, breaking metadata and reissue rejection.
The separate ledger PR still requires its own Required5 and fresh Strict review.

Local ledger verification: Release109 and OpenAPI83 PASS; canonical released-source
validation and the existing five-file readback bundle verification PASS. The first
OpenAPI run had two missing-Ajv environment errors in the fresh Worktree; reusing
the unchanged, previously installed Site Schema dependencies passed all83 without
installation, Build, test changes or assertion changes. Secret/PII scan found0
candidates across the8 ledger paths. Policy validation is recorded in the PR.

Final review also binds the Security metadata negative-test fixture to the real
Source squash705d03ca instead of the pre-merge PR commit. Dependency fixture bytes
are identical; this preserves test availability after task-branch cleanup without
changing gates or assertions. The ledger scope is consequently9 paths; a normal
new-head CI supersedes its initial run. No manual CI rerun is requested.

Existing Composer High4/Moderate5/Low3 findings remain unresolved (Critical0).
Source-only metadata classification is not Runtime Security acceptance. No
Storefront adoption, LINE665/Storefront228 change, Runtime/ENV/Secret/Worker change,
operational Migration, provider communication or real payment. Local additional
Build0; manual CI rerun0; canonical publication dispatch1, attempt1. Existing
Strict CI automatic builds are separate from publication and Runtime activation.


## Resumed bounded CI correction — 2026-11-07

Latest Human approval permits only proven internal release-metadata classification
and the existing Admin asynchronous editor test fix in this Source PR. Governance
REV3 Engineering Blockers in the Same PR allows these focused corrections.
Previous HOLD records below are historical, not the current approval boundary.
Base/main and PR670 head unchanged at resumption; existing local Handoff/Worklog
preserved. Risk R3 / Strict Change / Activation none. Release flock reacquired.

Composer Manifest/Lock are byte-identical to Base. Their SHA256 values are
51456f9a34dffc0e28e7fe35fda9f472e5150acb64b467f02cafb2fa27008d1a and
f04bdd327de411b13663c8c687185039af675c3f9262047905e539a6745b25aa.
Full pnpm packages/snapshots/integrities and external references are unchanged;
only internal workspace Client50→51 changes, with link target unchanged.
Client/Testkit manifest differences are exact own-version/compatibility metadata.

The new proof recomputes canonical Base/HEAD provenance and compares every
dependency input byte-for-byte after exact permitted replacements in three files.
Canonical dependency_delta=PRESENT and both fingerprints remain intact. Unknown
paths, hooks/configs, external versions/integrities, Composer changes, new/missing
findings and Critical/escalated severity cannot use this bounded proof. Audits and
baseline remain intact. The proof is limited to50→51 and the12 observed findings,
none approved or removed; actual Security Posture remains HOLD_UNAPPROVED_FINDING
and the downstream Runtime evaluator still returns HOLD. This permits only this
Source release metadata decision, not Runtime acceptance or vulnerability closure.

Fresh Composer audit returned the same12 IDs with null severity. Official vendor
Advisories (and CNA CVSS for the three original CVEs) establish High4/Moderate5/
Low3/Critical0, all in Composer runtime packages, not dev dependencies. Supplemental
severity evidence does not overwrite raw audit severity. All12 remain unresolved;
security maintenance is still required independently of this artifact publication.

| Advisory ID | Package | Official severity | Scope | Source |
|---|---|---|---|---|
| PKSA-g499-cm4q-v35y | symfony/filesystem | High | Runtime | [GHSA-6prc-h956-hxjq](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq) |
| PKSA-yd1s-kk7g-k2j3 | symfony/http-client | High | Runtime | [GHSA-gq53-v8w2-gjhj](https://github.com/symfony/symfony/security/advisories/GHSA-gq53-v8w2-gjhj) |
| PKSA-gfw6-4rnd-4533 | symfony/http-client | Low | Runtime | [GHSA-c7hm-wqwp-f9v8](https://github.com/symfony/symfony/security/advisories/GHSA-c7hm-wqwp-f9v8) |
| PKSA-24ph-xkrh-y79j | symfony/http-foundation | Moderate | Runtime | [GHSA-jrf8-gvm4-7cgh](https://github.com/symfony/symfony/security/advisories/GHSA-jrf8-gvm4-7cgh) |
| PKSA-pr6q-2w5s-kzzf | symfony/http-foundation | Low | Runtime | [GHSA-3h27-hjv7-fqjx](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx) |
| PKSA-nxjs-s6hn-vb6m | symfony/http-foundation | Moderate | Runtime | [GHSA-xg2q-4rrq-657q](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q) |
| PKSA-b2gg-vk1g-6d5q | symfony/http-foundation | Moderate | Runtime | [GHSA-5mx8-mwrp-5693](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693) |
| PKSA-3gxv-7xhq-mgmz | symfony/http-kernel | Low | Runtime | [GHSA-wg5q-m2ww-f3c9](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9) |
| PKSA-y8c5-1b7f-r1gj | symfony/routing | Moderate | Runtime | [GHSA-xqw3-x93m-887x](https://github.com/symfony/symfony/security/advisories/GHSA-xqw3-x93m-887x) |
| PKSA-grh8-hvfp-h9pf | symfony/serializer | High | Runtime | [GHSA-h38r-xrmh-cwhr](https://github.com/symfony/symfony/security/advisories/GHSA-h38r-xrmh-cwhr) |
| PKSA-nyd8-k74r-fsf2 | symfony/serializer | High | Runtime | [GHSA-pxj9-jh84-vhm5](https://github.com/symfony/symfony/security/advisories/GHSA-pxj9-jh84-vhm5) |
| PKSA-y18k-qjc6-knrv | symfony/serializer | Moderate | Runtime | [GHSA-hqp2-7rr8-hf26](https://github.com/symfony/symfony/security/advisories/GHSA-hqp2-7rr8-hf26) |

Admin reproduction: two isolated mail-suite runs passed36; concurrent focused mail
and RichTextEditor suites reproduced the same missing-body label at line85 for
contact_reply (40 passed/1 failed), matching the earlier email_verification CI
failure. useEditor immediatelyRender=false renders the body after the subject.
Only getByLabelText→await findByLabelText changed. Assertions/default timeout and
Admin implementation are unchanged. After correction focused41 and ESLint PASS.

Security61 (including new negative cases), canonical-change/Readiness62 PASS.
Fresh real Composer + four pnpm audits with the actual Base/HEAD prove metadata
classification PASS while preserving12 unapproved findings and Runtime HOLD.
One initial new-test run had3 fixture empty-commit errors; fixture fixed without
changing production checks/assertions. Reuse prior OpenAPI83/Release108/Policy347
and Client/Testkit evidence where source remains unchanged; final CI/review must
bind the new head. No manual CI rerun or local Build. Formal publication/readback
and ledger reconciliation remain pending the gate-compliant exact Source squash.
No Paidy logic, LINE665, Storefront228, Runtime, operational DB/Migration, ENV,
Secrets, Worker, audit baseline or workflow change.


## Current execution HOLD — Required CI (2026-11-06)

Human breaking-release approval remains valid. Source PR670 is Draft:
https://github.com/ideal-sol/oripa/pull/670
HEAD 3602b5f6d482728711e229c9a8f6cc4e49e53b31; Tree 2cc389346edfa8e1cba5b5de92a84064f9119f3c.
Base/live main remains 8eadec09a187e3f220dc09d36e9a14b98511931b.

Required CI run42216694566: policy-gate=success; quality-gate=failure; security-gate=failure.
Security job127888630708 fails with12 unapproved Composer advisories;
dependency_delta=PRESENT / BLOCK_PR_INTRODUCED_SECURITY_FINDING.
Composer manifests/locks are byte-identical to Base. Dependency changes are
Client/Testkit release metadata and the exact internal workspace51 specifier.
Current gate hashes complete dependency files and offers no applicable
release-metadata exclusion. No Security Gate/exception/baseline change is made.
The12 audit severities are unknown; they are not represented as resolved or
assigned SEV0/1=0. Additional Security work needs a separate scope decision.

Quality job127888630746 fails the unchanged Admin mail-editor test at
apps/admin/test/admin-mail-template-management.test.tsx:85 (email_verification,
missing メール本文 label;502 passed/1 failed). No Admin behavior/test is changed.
OpenAPI83, Release108, Policy347 PASS locally. CI Client47, Site Schema10,
Testkit55 and their generation/typecheck/lint/build/export/network checks PASS.
Contract comparison still detects only the two Human-approved required fields.

Final machine-readable Strict self-review NOT ISSUED because Required5 fail.
Ready/merge/publication/readback/ledger-finalization NOT RUN; official alpha51
artifact count0. Remote ledger remains latest50/candidate=null. Source branch
contains only pending51 intent. No future digests or Source squash SHA invented.
Manual CI rerun0; local/manual additional Build0; existing Strict CI automatic
builds ran, including its ARM64 image validation job. Runtime/Migration creation
and operational application/ENV/Secret/Worker changes0. CI isolated database
smoke is separate from operational DB use. LINE665 and Storefront228 unchanged.
Keep the branch/worktree. This HOLD evidence is local-uncommitted plus PR body;
do not create an extra Source commit/CI run only to record a blocked state.
Artifact Release flock is released on HOLD; reacquire and revalidate all live
authority before any later publication. No publication dispatch occurred.


## Latest Human decision (2026-11-06)

The historical HOLD below is resolved by explicit Human approval of
contract-breaking / breaking_change=true, bundle and Client/Testkit alpha.51,
Public/Admin/Webhook alpha.47, referenced Site Schema alpha.23, Activation none.
Only account_registration_date and previous_payment_methods required additions
are authorized. The exact PR662 five-field schema is unchanged. Admin/Webhook
change only version metadata; no Laravel, financial, LINE or Storefront changes.

Formal publication uses the canonical contract-only workflow after this Source
PR's gate-compliant squash merge, then a separate ledger reconciliation PR.
The pending candidate has the measured Public bundle hash, no future Source SHA
or Artifact digest. Immutable alpha.50 history and Site Schema are retained.
The official alpha.51 Artifact inventory was empty at preflight. Artifact Release
flock is held through publication/reconciliation. No other release work is adopted.

The new Public46→47 compatibility predicate delegates to the exact existing H1
normalization, preserving the Source-only exception and rejecting unrelated
changes, wrong versions/types, optionalized required fields and open objects.
Negative tests compare the actual immutable alpha.50 source. Historical release
tests reconstruct their original ledger/source instead of comparing to a new
pending version. CI's Task ID grammar registers this exact Human ID only.

Source generation and validation outcomes, fixed HEAD/Tree, Required5 and Strict
review are recorded in the Source PR. Initial local tests exposed stale version/
candidate assertions; corrected with exact new candidate checks and historical
fixture isolation, without weakening required validation. Browser/real-provider/
Runtime acceptance remains NOT RUN. No local publication or application build.
Rollback is a reviewed source correction before publication; published versions
remain immutable and any post-publication fix requires a new version.

## Historical preflight record (superseded decision, retained evidence)

# PAIDY-ALPHA51-20261106 — compatibility preflight HOLD

Risk R3 / Lane Strict Change / Application Runtime Activation none.
Base: `8eadec09a187e3f220dc09d36e9a14b98511931b`.
An isolated branch protects the occupied primary checkout and other Changes.

Human authorizes Paidy-only alpha.51 Source preparation, gate-compliant merge,
canonical contract-only publication and separate ledger reconciliation. The same
instruction requires HOLD before publication when compatibility is breaking or
the release mode cannot be established without relaxing the canonical gate.

## Read-only evidence

- Protected-main remote ref was read and fetched; it matches the fixed base.
- PR662 squash `417b5558d6dc439605c57685c6972c6300b2842b` is an ancestor.
  Its five-field Public schema is present in main.
- Main ledger latest immutable is alpha.50; candidate is null. No alpha.51
  Artifact-existence conclusion is made from the ledger alone.
- LINE PR665 is open, Draft and unmerged; its branch was not imported or edited.
- Read root/nested package, OpenAPI and documentation rules, current Governance,
  Release Gates, GitHub Operations ADR, Compatibility Policy, canonical contract
  publication workflow, artifact validator and OpenAPI compatibility code.
- Used the retained formal alpha.50 Public bundle as the comparison baseline.
  Its SHA-256 matches the canonical ledger:
  `c5cbb72aa0b5a2fa8a76d9e034ae223d2209ea468dcba68de8a8dad2b58eb2a3`.
- Invoked the existing `breaking_changes` and
  `is_authorized_paidy_v23_extension` functions directly against that immutable
  baseline and main's bundle, with a deep-copy-only proposed version alpha.47.
  No source bundle or generated package was changed by this comparison.

## Blocking compatibility result

The canonical comparator detects:

```text
components.schemas.PaymentPaidyCheckoutAction.payload.buyer_data: required field added: account_registration_date
components.schemas.PaymentPaidyCheckoutAction.payload.buyer_data: required field added: previous_payment_methods
```

The PR662 existing exact H1 exception returns true for the unchanged main
alpha.46 Source transition, but false for proposed Public alpha.47. Its code
expressly requires both documents to be alpha.46 and describes Source-only
approval with formal new-version publication requiring separate GO. Current
approval to publish is conditional on canonical compatibility, not authority to
extend that exception or mislabel this comparison as contract-additive.

Therefore contract-additive is not established by the existing gate. No rule,
exception, required field, schema, version or release mode was changed to pass.

## Minimal decision and next step

Human should approve the release as contract-breaking if retaining these exact
required fields. The current artifact validator requires breaking_change=true
and Public/Admin/Webhook contract versions all matching the next Public version
(alpha.47); that exceeds this task's anticipated Public-only/additive version
intent and must be decided before implementation. No schema redesign or Paidy
financial logic change is needed for this proposal.

After that decision, revalidate remote main, competing publication/candidates,
Artifact identity and Release Lock, then resume canonical Source/generation/
tests/Required5/Strict review and publication/ledger PRs. Do not publish from
PR662's SHA or the present base by substituting either for a future Source squash.

## Execution state

PASS: immutable baseline digest, canonical comparison execution, existing
exception boundary, base/ancestry/ledger/LINE Draft read-only checks.
BLOCKED/HOLD: publication compatibility classification (two breaking findings).
NOT RUN: generation, package tests/builds, full OpenAPI generation gate,
Required CI, final-head Strict review, merge, publication/readback and ledger PR.
No claim of Required5, publication or package/Runtime acceptance PASS.

No Source/ledger/package/lockfile/gate/workflow changes; only this Handoff and
Worklog HOLD evidence are pending locally. Commit/push/PR/merge0, Build0,
manual CI rerun0, publication0, Migration creation/application0, Runtime/ENV/
Secret/Worker/OLD Test/Production0, Storefront PR228 change0, LINE change0.
No release lock was acquired because compatibility stopped before candidate
preparation/publication. Task branch/worktree are retained for the decision.
Rollback: discard/withdraw local evidence through normal reviewed cleanup;
there is no published or runtime change to reverse.
