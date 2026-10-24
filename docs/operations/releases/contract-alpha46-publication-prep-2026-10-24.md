# alpha.46 Contract publication preparation — CONTRACT46-20261024

Status: **DRAFT / Human Source Merge and publication GO pending**.
Risk R3; Strict Change; Application Runtime Activation `none`.

This record prepares an exact-current-main publication authority. It approves no
Source Merge, publication, upload, Registry publish, Tag/Release, Site change,
operational migration, OLD Test/Production, ENV/AWS/Docker or Runtime action.
The local Bundle below is verification output, not a published Artifact.

## Source and candidate

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

## Publication and collision inventory

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

## Local-only Artifact verification

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
