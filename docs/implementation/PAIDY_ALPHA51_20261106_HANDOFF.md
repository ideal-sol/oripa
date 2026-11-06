# PAIDY-ALPHA51-20261106 — approved contract-breaking release preparation

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
