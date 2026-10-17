# M7 defect and verification record

The corrections and regression boundaries are recorded below. Final closure and
executed counts are determined by the exact-head tests/CI/browser evidence on the
canonical M7 Draft PR. No performance disposition closes a functional defect.

| ID | Reproduction / finding | Correction | Regression / status |
| --- | --- | --- | --- |
| M7-F01 | Batch projection used `to_jsonb(state)`, resolving to the User state string instead of the joined rank record; summary/list failed | Unambiguous `member_state` composite alias | Actual PostgreSQL summary, active totals, list/cursor and month-boundary cases |
| M7-F02 | 500 Unicode characters passed rank validation but exceeded Audit's byte-length check and rolled back | Audit validates UTF-8 and the specified character count, retaining the 500-character bound | 500 emoji, 501 rejection, malformed UTF-8 and original Audit regressions |
| M7-F03 | Inspection showed consumed entitlement slots could be cleared without proof of full recovery | Database requires matching full recovery before release; delete/replace rejected | DB mutation rejection, full/partial recovery and actual generation-two grant replace obsolete synthetic release fixtures |
| M7-F04 | Closed-account rank had no consumer freeze integration in M3 | Materialize/freeze on closure; projection/worker/payment accounting preserve frozen rank | Closed details across later months and delayed paid settlement |
| M7-F05 | New summary used the wrong protected-route prop | Use the existing permission prop | Typecheck and Operator browser/read tests |
| M7-F06 | Existing User detail combined two closed objects with `allOf`, rejecting valid detail fields; its phone pattern was double escaped | One closed detail object preserves all existing fields and accepts the canonical Japanese mobile format | Contract accepts actual detail keys/phone and rejects unknown secret fields |
| M7-F07 | Helper text was included in the accessible name of the point/reason inputs | Explicit input names and associated descriptions | UI input/confirmation and real-browser form scenarios |
| M7-F08 | A Wallet lock wait could cross JST month start after the initial clock sample | Resample after Wallet lock; reject the obsolete month and require a fresh preview in the same rollback boundary | Controlled lock-boundary clock change, zero old-month writes and successful new-month retry |
| M7-F09 | A pending next-page response could overwrite newly selected User history or manual-history filters | Abort the old generation and discard its late success/error before updating displayed history | Deferred-response regressions for both history surfaces |

Initial test-harness failures are separate: JSONB reorders object keys on replay,
so equality must compare key/value meaning while retaining indexed-list order.
The original strict PHP key-order assertion failed despite identical JSON values;
the replacement semantic equality retains complete payload comparison.
Initial contract lint rejected missing security declarations on six new
operations; all now explicitly require the Admin session. Initial UI lint
rejected direct effect invocation; the existing deferred-load pattern is used.
These initial runs are FAIL, never final PASS evidence.

M2/M3 performance findings remain OPEN and unchanged. Final outcomes, exact head,
executed counts and any further defects must be recorded before handoff.
