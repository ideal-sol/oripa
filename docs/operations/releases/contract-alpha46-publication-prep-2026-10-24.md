# alpha.46 Contract publication preparation — CONTRACT46-20261024

Status: **DRAFT / Human Source Merge and publication GO pending**.
Risk R3; Strict Change; Application Runtime Activation `none`.

This record prepares an exact-current-main publication authority. It approves no
Source Merge, publication, upload, Registry publish, Tag/Release, Site change,
operational migration, OLD Test/Production, ENV/AWS/Docker or Runtime action.
The local Bundle below is verification output, not a published Artifact.

## Current synchronization and security verification — 2026-10-25

Security PR616 (NEXTSEC-20261025) is formally Squash Merged at
`a58aa2edebd51032e5f1e2e2f674f49861576054`, Tree
`acab716885f18d73d8c44500aecf26d8778b5be9`. This is the inspected protected
main and current PR614 Base. It is not alpha.46 publication authority and its
Task ID must not be used for this Contract publication.

Original Draft614 HEAD `7a1f1a3612ebfc661257e0b561cbf4a45569a993` / Tree
`581cd3ca0e2b3be7e7f81609fb93e033bf241c45` is retained as ancestry. The original
merge-tree conflicted only in the two Worklog append blocks. A normal
preparatory commit relocated only614's existing record away from that boundary,
without deleting main history. Then the actual Git clean merge passed the
canonical `sync-task-branch-base` checker, with exact parents
`a57f98bfc5c2164d5e2e1fdb3492a11a0901361d` and the main SHA above, canonical Tree
`3278f09217121479e42a6b0ee50ba52603fa0cda`, sync commit
`629a265d30fa3e0ab94a18988b6ae823b549e2e1`. No manual conflict candidate, force
push, reset, branch recreation or path-scope expansion was used.

Both fast-uri3.1.9 exact pin/rejection coverage and the exact
CONTRACT46-20261024 registration/invalid-ID rejection are retained. The only
PR-owned delta against the new main is this document, Policy Gate exact Task ID
registration/test, and Worklog. Security manifests/lockfiles are inherited main
Source and remain byte-identical to616. The installed API transport does not
recognize the Git-only sync operation; the transient0600 policy enabled it only
during Git synchronization, then restored the API-compatible Draft-only set
with Base rebound to the verified new main. Canonical source/wrappers/Gates and
all exact path constraints were preserved.

### Historical HOLD and current measured posture

The Oct24 preparation had HOLD_UNAPPROVED_FINDING:9 unapproved path occurrences,
including High zod in Workspace/Legacy and High undici under Admin jsdom, plus
four fast-uri runtime findings under referenced Site Schema23. That historical
HOLD and old local Bundle hashes are not current acceptance evidence.

616 supplies compatible locked zod4.6.6 in Workspace/Legacy, Admin
jsdom30.0.0→undici8.12.2, and the existing root override fast-uri3.1.9. Fresh
Workspace/Legacy Full and Production audits now each report0 findings/raw exit0;
fresh Composer locked audit also exits0. The baseline, approved dev-only
exception, Gate code and Required Checks are unchanged. Final-head unchanged
Security Gate evidence must expose current_findings0, unapproved_findings0 and
PASS_APPROVED_POLICY and is recorded verbatim in the PR.

For this docs/Task-ID-only delta, the unchanged Gate classifies dependency_delta
as NONE against current main, so its development_security_result is
PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION even when the fresh audit inventory
is0. It cannot emit PASS_FRESH_AUDITS for that classification. Do not change the
Gate, dependency Source or comparison Base to manufacture the requested label.
A no-regression label alone is never remediation evidence: keep the actual0
counts, fresh raw audit inputs and PASS_APPROVED_POLICY separate and explicit.
The Human's requested label condition and any clarification are recorded in the
PR; no approval or waiver is inferred from this explanation.

### Contract, inventory and final-head evidence

All Contract/package/ledger bytes match616 and the original614 inputs. Public
SHA256 remains81ee96478ee3d80539817b54321f9f6d181c2a78a3dae2062527dd81049dcec4;
canonical comparison against actual immutable45 keeps all81 operations, adds3
for84, removes0 and finds0 breaking changes. Latest immutable45, pending46,
Public/Admin42, Webhook40, Client/Testkit46 and Site Schema23 reference remain.
Schema23 Source Tree11f6bee77dd463c2f90352537f817404cf3042bd and immutable artifact
are unchanged; no23 reissue.

Fresh exact-name official repository Artifact inventory on 2026-10-25 returns0
for `oripa-storefront-contract-2.0.0-alpha.46`, without filtering expired entries.
All16 canonical publication runs and15 per-run Artifact records were rechecked;
no46 existing/expired/partial Artifact or newer publication attempt exists.
Latest official publication remains45. Repeat inventory at future publication
GO; any matching existing Artifact blocks same-version upload/reissue.

Final HEAD/Tree and final-head Gate/Client/Testkit/export/local Bundle results
are fixed in PR614 after this source document is committed. Do not predict a
self-referential commit or future Squash SHA in this document. The local-only
Bundle must use the actual clean final HEAD and INPUT_TASK_ID=CONTRACT46-20261024.
Its Manifest source_commit/commit timestamp and resulting Manifest digest
naturally differ from the Oct24 Bundle; report actual package/OpenAPI/SHA256SUMS
digests and any payload difference separately. Neither a Draft HEAD nor any
local digest is official publication evidence.

Separate Human GO remains required for614 Ready/Source Merge, and later for the
one-time canonical publication from its actual approved Squash SHA still equal
to protected current main. expected_merged_sha remains UNASSIGNED. Main drift
HOLDs. Formal authorize/publish/readback and later independent Ledger
Reconciliation remain NOT RUN. Runtime/operational migrations/OLD Test/Production
and Storefront changes are0.

## Historical Source and candidate inspection — 2026-10-24

- Protected main inspected: `bb9f6bd4bd50b6cc9c0662ccd9dee1c1d2f4b2cb` (#612).
- SHIPPINGMIN #608 Squash: `69dde1868f20b6fbacc06849c8ff0535b74b145e`.
- alpha.46 Contract input bytes at inspected main are identical to #608.
  #612 changes only the OLD Test helper, its tests/runbook and Worklog.
- Previous immutable alpha.45 Source: `c4efbee32a7846bc94f7375d0d5f71779aa0ac77`.
  The alpha.46 delta includes earlier Member Rank/purchase and Gacha eligibility
  Source plus SHIPPINGMIN #608; it is not
  restricted to the shipping endpoint. Existing81 operations remain, with
  GET `/member-ranks`, `/me/member-rank`, `/me/shipping-policy` added (84 total).
- Candidate remains `pending`, `contract-additive`, `breaking_change=false`;
  Client/Testkit `2.0.0-alpha.46`, Public/Admin `2.0.0-alpha.42`, Webhook
  `2.0.0-alpha.40`. Site Schema `2.0.0-alpha.23` is referenced, not republished.
- Public bundle SHA-256:
  `81ee96478ee3d80539817b54321f9f6d181c2a78a3dae2062527dd81049dcec4`.
- No Candidate field, immutable history, approved authority, API, package or
  dependency version is changed by this preparation.

The runbook [Exact Merged Main Publication](storefront-contract-artifact.md)
and `scripts/release/storefront_contract_publication.py` require actual merged
PR identity, protected current main/event/checkout SHA equality, reviewed/merged
Tree equality, all five reviewed-head checks and a pending unissued version.
They do not require a new API or package-code edit in that PR. This document
explicitly carries forward the verified #608 inputs and publication intent;
it does not impersonate #608 or #612. After separate approval, a real Squash
Merge of this Task can supply the new authority only if all existing conditions
hold. Neither today's main nor a Draft PR head is pre-approved publication SHA.

Use `chore/CONTRACT46-20261024`: the installed Git transport prohibits task pushes
to protected `release/**`. The Task ID itself is preserved. A narrowly scoped
exact Task ID registration/rejection test is necessary because the existing
Task syntax does not accept a digit in the prefix. No gate, security condition,
check, skip logic or publication workflow is weakened.

## Public, Client and Testkit compatibility

GET `/api/v2/me/shipping-policy` retains User Session, required SMS and no-store.
It returns decimal strings `minimum_points` / `effective_required_points`, a
safe-integer `revision` and `zero_total_allowed=false`. Existing shipping POST
success contracts and idempotency remain. New409
`SHIPPING_MINIMUM_POINTS_NOT_MET` returns decimal strings for configured/effective
minimum, selected total and shortfall, plus `setting_revision`. Consumers use
BigInt and preserve SMS/address/key/payload across uncertain network outcomes.

Client exports `getShippingPolicy()`, generated types and
`isShippingMinimumPointsProblemError()` from the existing Browser/Server surfaces.
Packed runtime version, package version and compatibility metadata must agree.
Testkit exports `SHIPPING_POLICY_FIXTURES` and
`SHIPPING_MINIMUM_PROBLEM_FIXTURE`, matches Client alpha.46 and84 operations.
The existing root/browser/server/types and Testkit module exports are preserved.

The actual immutable alpha.45 Artifact12031381227, run38866011951, was downloaded
read-only. Its outer digest, Manifest, package/OpenAPI digests, SHA256SUMS and
exact five-file inventory match the ledger. Canonical OpenAPI comparison gives
zero breaking findings and zero removed operations. Do not replace alpha.45.

## Historical publication and collision inventory — 2026-10-24

The preparation inspected all16 canonical publication runs and each run's
Artifact metadata, including expired entries where returned. The most recent
publication is alpha.45 (2026-10-16); no alpha.46 run, failed attempt or partial
upload is present. Exact repository Artifact-name inventory for
`oripa-storefront-contract-2.0.0-alpha.46` has total_count0.
This is a time-bound observation and must be repeated at publication GO.
Any existing version, including expired, blocks reissue/overwrite/re-upload.

No physical Artifact Release Lock holder was observed. This Task acquires no
publication lock because it edits no candidate/ledger and publishes nothing.
Open superseded alpha.45 reconciliation PR573 must not be used to overwrite
current immutable/candidate state. Its old lock wording is not authority for
this release. Preserve it and all unrelated PRs; recheck release coordination
and take the canonical release lock before formal publication/reconciliation.

## Historical local-only Artifact verification — 2026-10-24

Toolchain: Node22.22.3 / pnpm10.12.1. Frozen install, OpenAPI checks/tests,
Client checks, Site Schema build, Testkit checks and release tests use the
unchanged canonical commands. No API/Admin image build is required locally.
The canonical builder/validator generates exactly the two tarballs,
`public.openapi.json`, `artifact-manifest.json`, `SHA256SUMS` into an isolated
local directory. No extra local marker is inserted into the five-file Bundle.

The following is a **local baseline build**, using actual clean HEAD
`bb9f6bd4bd50b6cc9c0662ccd9dee1c1d2f4b2cb` and
`INPUT_TASK_ID=CONTRACT46-20261024`. Its digests are not future official
publication digests or Source authority. Final Draft HEAD checks and its
separate clean-head local Bundle are recorded in the Draft PR; package bytes
must remain identical across this documentation/registration-only change.

| Local file | SHA-256 (verification only) |
|---|---|
| `SHA256SUMS` | `af367fedac2cdfb47a35eb2c8558dd21fb9f0404d6b37cce96cf043f67b63c9e` |
| `artifact-manifest.json` | `88d56aef2c37721179cff0c3d4881a9f43257c8b9a1a2ac969853d3a71be986c` |
| `oripa-storefront-client-2.0.0-alpha.46.tgz` | `2c386da05e82fa8b51e0f1b90d80c801ee6bd7b166d7d857796591a072bdd660` |
| `oripa-storefront-testkit-2.0.0-alpha.46.tgz` | `df9ec63f9f9718839a3bb984eeb45ea806bc00a1b608424695118c521a523b1e` |
| `public.openapi.json` | `81ee96478ee3d80539817b54321f9f6d181c2a78a3dae2062527dd81049dcec4` |

The builder's wire field `disposition=published` describes the intended package
role; a locally generated Manifest is not proof of remote publication. Do not
copy local Source Commit, Manifest hash or package digests into the release
ledger as official evidence. The official merged Source SHA is unassigned.

## Formal execution plan — only after later Human GO

1. Human separately approves/merges this Source Authority PR after exact-head
   Required Checks and fresh machine-readable review PASS, SEV-0/1 zero.
   Preserve reviewed/merged Tree equality and content diff0.
2. Acquire the canonical Artifact Release Lock; re-read protected main,
   associated actual Squash PR, Candidate and all Artifact inventory. If another
   Task advanced main, HOLD; never reuse #608, #612 or this Draft's SHA/Task.
3. Obtain separate exact publication GO. Use only the existing
   `Storefront Contract Artifact Publish` workflow on `main`:

| Input | Value after authorization |
|---|---|
| publication_mode | `contract-only` |
| source_task_id | `CONTRACT46-20261024` if this Authority PR is approved/merged |
| expected_merged_sha | UNASSIGNED; actual approved Squash SHA that is still current main |

The canonical GitHub App has issued `actions:write`, `contents:write`,
`pull_requests:write` and `checks:read`. The official dispatch request is the
fixed POST `/repos/ideal-sol/oripa/actions/workflows/storefront-contract-artifact-publish.yml/dispatches`,
`ref=main`, with exactly the inputs above. A future approved App invocation must
validate/fix that request and Source identity before dispatch. Today's Task
Policy grants no publication operation. The installed `dispatch-task-checks`
operation dispatches Platform CI only and must not be used as a publication
command. No global wrapper, workflow or privilege setting is changed here.

The existing workflow's `authorize`, `publish` and `readback` must all succeed.
Never publish from an arbitrary branch/PR head, move main backward, use another
Task ID, relax the authority helper or pre-fill candidate.source_commit.
A partial upload is a HOLD and an existing immutable version, not permission to
retry/rebuild the same version.

## Official evidence and separate reconciliation

Official Run ID/attempt, Artifact ID/name, GitHub outer digest, Manifest SHA,
Client/Testkit/Public OpenAPI SHA, SHA256SUMS SHA and exact merged Source remain
NOT ASSIGNED. After the one authorized workflow succeeds, fix these actual
values and perform canonical exact-ID readback. Do not treat a local ZIP hash
as GitHub's outer Artifact digest.

Only then create a separate Release Ledger Reconciliation Task/PR. It records
actual remote evidence, appends/preserves immutable history, advances
latest_immutable toalpha.46 and clears candidate under the existing validator.
No reconciliation edit is performed in this Task. Keep release coordination
through that independent review; never rebuild or overwrite the version.

## Downstream and acceptance

After formal publication and ledger reconciliation, Site Codex verifies the
Artifact/Manifest/checksums, exact-pins Client/Testkit alpha.46 and completes
shipping-minimum PR178 real-API integration, its CI and fresh review. This
Platform Task changes no Storefront Repository.

OLD Test owner separately verifies Platform Source/Control and approved
artifacts, migration000098, Site/Platform compatibility, Human Browser
Acceptance and shipping behavior. NEW Production belongs to its own operator.
Runtime/ENV/AWS/Docker/DB changes and official publish/upload/Tag/Release are0
for this Task. Formal workflow authorization/publication/readback remain NOT RUN.

Final HEAD/Tree, executed local checks, all five Required Checks, fresh review,
local final-head digests and the exact Draft URL are the corresponding PR's
review record. A schema-required technical MERGE recommendation is not Human
Source Merge or publication authority. Retain Draft and wait for separate GO.
