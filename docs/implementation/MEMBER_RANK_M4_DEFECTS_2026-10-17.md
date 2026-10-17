# M4 defect and validation record

Task RANKPURCHASE-20261017 / Human Change MEMBERRANK-M4-20261017.
Initial failures remain recorded even after correction. Performance is separate
from functional acceptance. No final PASS is implied by this working record.

| ID | Initial observation | Cause / correction | Regression |
| --- | --- | --- | --- |
| M4-SETUP-01 | OpenAPI bundle command failed at lint; subsequent contract test failed with missing new field | Fresh worktree had no Node dependencies; install frozen existing lockfile before generation | Bundle and 48 contract tests PASS |
| M4-TEST-01 | Expanded35-case run: API assertion read null instead of7 | Test used a nonexistent data envelope for single-payment response; preserve canonical response and assert the root field | Corrected run pending |
| M4-TEST-02 | Expanded35-case run: cancellation expected Bronze25 but got Beginner0 | Test had only200 prior-month points and no skip; correctly demoted. Set explicit synthetic skip1 to isolate setting cancellation; retain separate maintenance-boundary tests | Corrected run pending |
| M4-TEST-03 | M2/M3/M7 assertions predate positive purchase grants | Preserve exact promotion assertions and add exact separate purchase snapshots/amounts to expected wallet totals; activation backfill still creates no purchase origin for historical payments | Regression pending |
| M4-CI-01 | Policy suite291: 29 failures and1 error, Alpha identity mismatch | Synthetic workspace fixture hardcoded old client45/Public41 while canonical pending candidate is46/42; align fixture only, retain identity and negative guards. Release tests separately preserve historical released-only fixtures and explicitly verify live pending46 | Policy291 and Release95 PASS |
| M4-TEST-04 | Real boundary test observed User/Plan/Wallet child lock timeouts | Parent observer reused PostgreSQL transaction statistics snapshot and did not observe the new wait; explicitly clear observation snapshot each poll, retain deadlines and require a real Lock wait | Corrected3 tests/39 assertions PASS |
| M4-CI-02 | Run39030121649 DB integration failed, but generic performance JSON exhausted the diagnostic limit before the actual PHPUnit failure | Human explicitly authorized a diagnostic-only overlap with572: prioritize failure identifiers/assertions/exceptions, retain existing filtering and2400-character limit, additionally reject email/IP/phone-shaped lines and cap individual lines | Focused diagnostic regression and subsequent CI cause identification recorded in PR; original CI failure is not classified as performance |
| M4-TEST-05 | Diagnostic run39032230710 identifies SQLSTATE55006 in CurrentUserPointReadContractTest legacy-no-expiry fixture; isolated one-case reproduction also errors | The existing test inserts a historical lot with its new-expiry guard temporarily disabled, then ALTER ENABLE runs before M4's newly deferred lot-origin event. Execute all queued constraints immediately before restoring the guard; do not disable M4 constraints or alter assertions, production guards or000092 | One-case FAIL reproduced before correction; corrected full read-contract6 tests/68 assertions PASS; final-head full V2 results bound in PR |

Real forced PostgreSQL deadlock retry and seven-process payment/replay/worker/
manual-rank/rank-point contention passed in the initial concurrency run; its
three boundary failures remain recorded rather than relabeled as performance.
Final-head combined acceptance and all subsequent failures/corrections are bound
in the canonical Draft PR. No timeout, assertion, security gate or hash cost is
relaxed. Only measured processing-speed optimization may remain OPEN.

Before diagnostic Human GO, the b9ea head passed M4 functional47/345,
M4 concurrency5/118, legacy concurrency6/152, corrected legacy7/65 and actual
Public responses3 plus14 negative cases. Earlier broad307/2739 failed10:
seven old wallet assertions and three stale-observer failures, all covered by
those corrected runs. CI39030121649 still failed and is separate evidence.
No technical acceptance was declared from the local passes alone.
