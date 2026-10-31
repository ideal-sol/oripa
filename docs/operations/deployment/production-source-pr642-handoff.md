# PRODAUTH-20261031 — integrated Production Source preparation

Status: proposed Authority update, Ready preparation only. Risk R3; Lane Strict
Change; Application Runtime Activation deferred. Related Issue [#643](https://github.com/ideal-sol/oripa/issues/643)
remains OPEN. Latest Human direction on 2026-10-31 selects the integrated code
for NEW Production after OLD Test acceptance. Authority merge needs separate
Human approval after timing coordination. No Artifact Dispatch/Build/Stage,
Runtime, DB, ENV, Worker, Timer, B2 export or operational use is authorized here.

## Exact identities and source evidence

- Previous protected Production Authority Source: PR #639,
  `bbf61feccacf6a0333705ae6eefdf15a012a5475`. This is an Authority comparison
  base, not a live Runtime readback.
- Proposed Runtime Source: PR [#642](https://github.com/ideal-sol/oripa/pull/642)
  merge `5fef1a09f35f8b4fce7f68a15cbb9f6d0240da20`.
- Reviewed Source HEAD: `b9b409af169e83d1de2b0ede3f60bdc5b5d6b457`.
- Reviewed/merged Tree: `f98276f2e27f48f6ce50d94a1f55bef9598abd45`, equal.
- Source CI [41163094633](https://github.com/ideal-sol/oripa/actions/runs/41163094633)
  PASS; five Required Checks PASS. Source Strict
  [review at merge](https://github.com/ideal-sol/oripa/pull/642#issuecomment-6538639058)
  was created 2026-10-31 03:50:36 UTC, with SEV-0/1=0 and unresolved postal
  SEV-2=1 conditionally accepted for Source Merge only. This historical review
  is not a fresh review of this Authority PR or a Runtime waiver.
- Integrated application delta: #636 seven-day prize exchange; #640 receipt
  expiry display; #641 shipping mail variables; #642 B2 export. Intervening
  #638 Authority metadata remains distinct from payload behavior.

Authority PR HEAD/Tree, later Authority squash and Workflow Authority must be
recorded separately in the PR. None replaces the exact Runtime Source above.
The existing manifest schema, authorization helper, five Required Checks,
security guard and native ARM64 workflow remain unchanged.

## Contract, migration and reflection scope

Client/Testkit `2.0.0-alpha.48`, Site Schema and Public/Webhook contract sources
are byte-identical to previous Authority Source and alpha.48 publication Source
`6e945df343d3d42ffa844b3b369ca0c171a6bbbe`. Reuse immutable Artifact
`12819254428`, Run `40697440380`, manifest SHA-256
`9affb95def4858a748a5343614dcdf4d8e0ca20705d5d227cc48e976f01df60f`;
the [release ledger](../../../manifests/storefront-contract-releases.json) is
the digest authority. No rebuild or republish of that contract is needed.

PR642 adds Admin-only `POST /admin/api/v2/shipping-requests/export-b2`, JSON/base64
and CSV responses, warning types and generated Admin client. It does not extend
the Public Client. The immutable alpha.48 Admin snapshot predates this addition;
it must not be described as the current Admin contract. API and Admin must use
the integrated source together for later reflection; Storefront/Agency need no
change for this delta. Existing workers/timers stay in place; selecting code
does not authorize their restart or shipping mail delivery tests.

Migration inventory is 001–103, byte-identical to previous Authority Source:
no migration added, changed, created or applied here. Human reports NEW Ledger103;
no pending migration is expected if that report matches later canonical readback.
Do not reuse the previous Authority's historical Ledger101/102/103 action plan.

## B2 state and unresolved Runtime gate

No new B2 ENV settings and no CSV output or operational use. Static source
inspection confirms required sender/billing settings default empty;
`V2B2ShippingCsv::settings()` throws HTTP503 `B2_EXPORT_NOT_CONFIGURED` before
row generation, encoding or export audit. Existing Source tests for this behavior
are reused. Admin displays the button; it is disabled only for zero selections,
more than500 selections or export in progress. A valid selection can call the
endpoint and receives the Japanese configuration error. There is no complete
feature-disable flag, and no claim of live ENV readback or Browser verification.

`B2_OFFICIAL_HEADER_PENDING` remains: official Excel95 headings, PDF97 fields,
implementation97 columns; equality and actual B2 import acceptance are unverified.
Malformed recipient postal values can reach column11 without warning. Issue643
retains SEV-2 and its unresolved decision, bounded implementation, Backend/Admin
tests, CI/review and pre-Runtime conditions. Earlier exceptions waived only
PR642 Source Merge prerequisites. Latest Human direction permits preparing this
integrated Source with B2 unused; it does not prove the existing Runtime gate
resolved. Runtime GO remains HOLD until the release owner resolves that condition.

Preferred minimal resolution is a separately approved B2 postal warning/error
decision and bounded implementation with existing Backend/Admin tests, followed
by normal gates. If PR642 prevents the required Runtime gates and Human chooses
separation, shortest candidate is the already merged #641 Source
`21bd066bb50de0a167b5051513a74703a230bf43` (contains #636/#640/#641, excludes
#642). Revalidate that Source's tree/checks/review, alpha.48 compatibility and
Authority through a reviewed update. This is a proposal only: no Source change,
revert, separation or gate weakening is performed or authorized here.

## OLD Test coordination and later release

OLD Test owner must use the same exact Source/Tree and Client/Testkit alpha.48;
its Payload Authority, Build Control, architecture, Artifact and OCI identities
remain separate from Production Authority. Local OLDTEST-20261031 worktree was
observed at this same exact Source. No OLD Test files or Runtime are changed here.
This handoff is the shared identity record, not a claim of owner acknowledgement
or OLD acceptance. Formal merge timing and acceptance evidence remain pending. OLD Test Authority
PR [#644](https://github.com/ideal-sol/oripa/pull/644) has since merged at
`5459de9f6dc6b292d165a16155451488497ecfe7`, selecting this same PR642 payload.
This metadata merge is Build Control authority, not a replacement Runtime Source.

Parallel preparation can complete Authority CI/review, static inventory and
release gate/rollback planning. After OLD acceptance, coordinate timing, obtain
separate Human Authority merge approval, refresh Strict review (30-minute window)
and revalidate current main/head/checks. Formal artifact, stage and Runtime each
need their own GO. Keep canonical deployment lock, writer fencing, backup,
image/OCI provenance, F2-B drain/resume, image pin/recovery marker and health gates.

Preserve Human-reported UnivaPay TEST/Webhook/dedicated Worker, Rank ON/Generation1,
Ledger103, F2-B, Storefront/Agency, FINCODE settings and financial data, GTM,
Basic authentication and existing ENV. Keep
`V2_PRIZE_SEVEN_DAY_AUTO_CONVERT_ENABLED=false`; feature ON needs separate GO.
No rank initialization, reactivation, backfill, history exclusion authorization,
financial change or migration replay. No live Production inspection occurred.
Before merge, rollback is withdrawal of this proposed metadata PR. After merge,
changing approved Source requires Human authority and a reviewed PR; any later
Runtime rollback uses the canonical immutable recovery authority.
