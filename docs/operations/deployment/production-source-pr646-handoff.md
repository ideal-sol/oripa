# PR #646 Production Source preparation

PRODSOURCE-646-20261031: R3 / Strict Change / Activation deferred.
Human requests Ready for Review only; merge requires separate Human approval.

Runtime Source: `a35468d259c99dda7e64ce6f0c7fccda2ee9e665` (PR #646).
Reviewed head: `b5d28b52999a5875f5f3b53edfa9bb4917979d11`.
Source tree: `689053e45289e7518aa5798e699589cf8a0757fb`.
Reuse the existing Production approved-source manifest and canonical workflow.
Authority PR HEAD and later merge are never Runtime Source.

Future target is Platform API only; Admin, Agency and Storefront are retained.
V2_FIRST_PURCHASE_LIMIT_SCOPE=any_product is the intended future setting.
No Artifact Dispatch, deployable Build, Runtime, ENV or DB operation is authorized.
Preserve Ledger103 (no new/applied migrations), seven-day automatic conversion OFF,
UnivaPay TEST, Webhook, dedicated Worker, F2-B and Rank ON. Never copy OLD Test ON.
Issue #643 and existing B2 limitations remain outside this change.

## Existing test-history effect

The existing eligibility service joins each user's payments to the historical
plan, including retired versions. With any_product, first_purchase_users plans
are considered across products. Non-failed/canceled/expired payments block a
new purchase. Those terminal statuses also block if success history exists or
a konbini/virtual_account payment has the existing provider-start evidence.
Test purchases therefore can block test accounts across all first-user products.
The existing 24-hour qualification and reused-phone restrictions remain.
Keep all history unchanged; no deletion, rewriting, reset or exclusion.

## Verification and rollback

Authority regression tests cover exact source/PR identity, prior-source rejection,
migration and contract boundaries, and activation_authorized=false.
Required CI and exact-head fresh self-review are recorded on the PR.
No live Production verification is claimed. Source rollback requires a reviewed
metadata PR; runtime rollback is outside this preparation.
