# Production Readiness Gate Phase 1

Status: SHADOW ONLY / OBSERVATION ONLY / Production impact NONE.
Change: PRG-20261002, Strict Change, R4, Application Runtime Activation none.

Authority: `oripa_v2_production_readiness_gate_formal_spec_v1.1_2026-10-01.md`,
SHA-256 `e7e3a6fedfa232d06e00e36f14e79399d03fc6f5dfa8550f801b60d57a2e87a9`.
The Human-supplied implementation instruction prohibits merge and promotion;
these PRs stop for Human review after normal CI.

## Boundary and transport

Only consume an explicitly provided local, non-secret JSON handoff or synthetic
fixture. No Snapshot Producer, network client, runtime inspection, database
access, build, activation, rollback, GO generation or workflow dispatch exists
in this entry point. Existing Required Checks and Production workflows are
unchanged. The separate Shadow workflow is not an input to any existing gate.
Consumer tests also run in the existing release test suite in quality-gate.

CI-20261007 shares `change.py` with Platform CI. Its Git source classifier and
byte fingerprints produce canonical change records; they do not inspect a
runtime. Explicit CI adapter calls may use the existing read-only source API
transport for provenance and historical Security check/log evidence. The
Readiness CLI itself remains an offline handoff consumer and performs no calls.
See [Platform CI operations](../../../docs/operations/ci/README.md) for the
Development semantics and evidence invalidation contract.

```bash
python3 -m unittest tests.release.test_readiness
python3 -m scripts.release.readiness \
  --input tests/release/fixtures/readiness-no-handoff.json \
  --output /tmp/readiness-observation.json
```

Output creation is exclusive; an existing evidence file is never overwritten.
Evaluation HOLD/UNKNOWN/SYNC results exit successfully as observations. Test
failures and output I/O errors still fail. No-handoff CI records UNKNOWN, never
synthetic Production readiness. `tests/release/test_readiness.py:fixture`
constructs a complete synthetic READY example for tests only.

## Record contracts

`record-schemas.v1.json` contains the transport shapes. `records.py` supplies
parsers and semantic validators for Snapshot, Continuity, Human GO and browser
acceptance. Shape validation alone is insufficient: digest, chronology, scope,
exact candidate identity and evidence requirements must also pass.

Canonical bytes: UTF-8, lexicographically sorted object keys, compact JSON,
services sorted by service name, one trailing LF; exclude the record's own
digest field. Snapshot input services must already be sorted and unique.
SHA-256 is represented as `sha256:<64 lowercase hexadecimal characters>`.
Unknown values remain null with their status. Duplicate JSON keys and nonfinite
numbers are rejected. Timestamps require RFC3339 UTC. No fixed Snapshot TTL is
introduced. Manual continuity requires the Human role and all nine scope names;
an empty event list alone cannot establish continuity. Wrong base, missing
scope, gaps or changes require AUTHORITY_SYNC_REQUIRED. `approved_producer`
is representable for future transport but cannot assert manual-v1 continuity.

Human GO validation checks supplied records only and returns
HUMAN_GO_RECORD_VALID_NOT_AUTHENTICATED. A digest is not an approval signature.
Independent Human-origin evidence remains required before any future promotion.
Browser records are bound to exact source, Artifact, Build ID and plan digest;
PASS must cover every planned route, state and viewport. This implementation
does not perform browser acceptance or generate Human PASS/GO.

## Candidate and evidence protocol

Input is an object with `candidate`, `snapshot`, and `continuity`.
Candidate v1.0 requires `candidate_id`, `repository`, `base_sha`, `head_sha`,
`tree_sha`, `workflow_authority`, `authority_snapshot_digest`, `source`
(`platform`, `storefront` exact SHA or null), `service_inventory`, `artifacts`
(service-keyed service/Artifact ID/digest), four separate scopes (`build_scope`,
`activation_scope`, `acceptance_scope`, `rollback_scope`), `target_contract`,
`facts`, and Required Check evidence. Application source, tooling source and
protected-main/base are distinct identities. A Build scope does not activate
its services. Activation scope must have acceptance and rollback coverage.

Each fact has `status`, `value`, `evidence_reference`, and `identity` equal to
the candidate ID, repository, base/head/tree, workflow authority and Snapshot
digest. These are **trusted Human-assisted handoff observations**, not a new
way to authenticate an arbitrary JSON PASS. The transport must independently
establish immutable reference provenance and that the stated validator actually
ran. No automatic URL fetching or signature service is introduced in Phase 1.
Unbound/missing facts are UNKNOWN; explicit mismatch is HOLD. N/A needs an
immutable reference and `non_applicability_reason` in a permitted position.

| Requirement | Consumed evidence |
| --- | --- |
| R1 | exact_source, workflow_authority, approved_source, existing_source_policy, source_identity |
| R2 | exact service_scope and per-service artifact receipts, including reused artifacts |
| R3 | contract_provenance equal to the approved target_contract, never an implicit latest version |
| R4 | complete raw canonical GitHub check-run inventories for head/workflow and every non-null Platform/Storefront Source |
| R5 | runtime_delta_inventory from Snapshot services and all runtime surfaces, complete_diff, all_runtime_deltas_approved, no_known_holds |
| R6 | migration_assessment, compatibility, activation_order, env_requirement_delta, restore_point, exact service Stage evidence and RB1–RB6 |

Boolean assessment facts carry the existing validator or Human assessment
result; they do not make new provider, accounting or security decisions.
R4 reuses `infrastructure/github-app/check_run_gate.py` including latest-run,
source-App and exact-head validation, with the canonical Required Check set.
R4 also requires the bound `current_security_posture` fact carrying the canonical
Security record. Missing/malformed records are UNKNOWN. Visible unapproved
findings are HOLD even when all Development Required Checks succeeded.
The optional bound `canonical_change` fact carries `change.py` output and is
validated by that same module. Invalid classification uses NORMAL_STRICT_CI;
canonical Application/Security/CI changes require Full. Authority-only still
requires all existing runtime-exclusion and continuity proofs; classification
alone never enables a Production operation or Production Fast Lane.
Each Source Repository is mandatory even when another Repository's checks pass.
Reviewed-tree reuse requires an explicit `source_sha`, matching exact
`source_tree_sha` / `checked_tree_sha`, immutable `tree_evidence_reference`, and
the original checked `head_sha`. Missing or mismatching tree correspondence
cannot reuse those checks. No runtime or network lookup is performed.
`adapters.py` replays the existing Production source-authority validator using
only supplied API responses and maps `preview_image_artifact.verify_artifact`
receipts. It never invokes an online getter or rebuilds an Artifact.
Contract receipt producers retain the existing
`storefront_contract_artifact.verify_manifest` / Storefront vendored checker.
Do not reuse a hard-coded older approved Production contract as authority for
an alpha.42 candidate. Current approved Runtime and latest repository main may
legitimately differ.

## Result, lanes and reuse

Every applicable R1–R6 must be PASS or evidence-backed N/A. Artifact identity,
ARM64/service coverage, required Stage and rollback must pass. Any required
missing evidence prevents READY. Continuity drift takes final-state precedence,
while known hold reasons remain recorded. Internally READY/HOLD/INCOMPLETE /
UNKNOWN map to SHADOW_READY/SHADOW_HOLD/SHADOW_UNKNOWN. All results have
`blocking_authority=false` and `production_impact=NONE`.

Artifact receipts distinguish VERIFIED, PROVENANCE_INVALID,
VERIFICATION_INFRA_FAILURE and VERIFICATION_INCOMPLETE. Infrastructure failure
never asserts corruption and never requests a rebuild.

Lane classification starts with proven Platform impact. Missing proof falls
back to Full. Authority-only requires an allowlisted metadata class, no build
or activation request, runtime exclusion proof and NONE for every listed
runtime surface. Incomplete Authority-only eligibility records
AUTHORITY_ONLY_FAST_LANE_INDETERMINATE / NORMAL_STRICT_CI. It does not create a
Gate HOLD. Minor requires the exact digest-bound Storefront classifier record
with approved Machine Policy. Other Storefront changes use Normal.

For an Authority-only candidate, failed or missing entry proofs keep both the
effective lane and fallback lane at `NORMAL_STRICT_CI`, including incomplete
diff, baseline, Platform-impact or runtime-exclusion evidence. This is a
fallback state, not a fifth release lane. Its Step Matrix conservatively uses
the Full lane's general requirements and scope-dependent conditional steps,
not Normal Storefront requirements or Authority-only exemptions. It neither
assumes a Storefront release nor authorizes any build, activation or CI skip.

The four-lane Step Matrix represents REQUIRED, CONDITIONAL, N/A and REUSE.
N/A/REUSE are selected only with exact bound evidence; otherwise the proposal
stays CONDITIONAL. No skip or operation is executed. Required Artifact, ARM64,
Contract and rollback receipts may be reused; age alone does not expire them.

## Rollback

RB1 known-good exact target, RB2 Artifact availability, RB3 Contract/service
compatibility, RB4 DB/data/lease conditions, RB5 current procedure authority and
RB6 Operator/GO authority must all be evidenced. Historical procedures and
`previous` alone produce UNKNOWN. Minor additionally needs SAME Platform,
Contract, schema, write path, ENV requirements, config and routing, no Migration,
and the exact previous release. ROLLBACK_PREAUTHORIZED is recorded only from
supplied Human evidence and confirmed continuity. No rollback is executed.

## Limits and reserved decisions

This is a schema/evidence consumer, not a live Production auditor. Actual ENV
values remain `UNKNOWN — ENV FILE / RUNTIME ENV ACCESS PROHIBITED`. No actual
Production Candidate has been evaluated or accepted by these fixtures.
Storefront policy constants are a versioned Shadow proposal pending Human
approval; synthetic tests use explicitly synthetic approval data. Real handoff,
Human Browser Acceptance, Human GO, PR merge, Phase 2 and Promotion are pending.
Rollback of this Source change is a reviewed revert PR; runtime rollback is
neither necessary nor authorized.
