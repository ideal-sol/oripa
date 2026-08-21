# OPS-019 Canonical Gacha Shared Preview Activation

## Task

- Issue `#350`, Pull Request `#351`, Risk `R4`, Base `9e8e644c1302b3257f1076a829a4aba6198fd0b9`.
- Branch `chore/OPS-019-canonical-gacha-preview-activation`, dedicated worktree `/var/www/oripa-worktrees/OPS-019`.
- Activation source `ba17d719767346569bb2e60ae9e733103848f8e6` passed the five Required Checks and fresh fixed-head self-review before the mutable window.

## Stage 0 and Reconciliation

- Local, origin, and GitHub `main` matched the approved base. Task lanes, Shared Locks, Preview OS lock, competing deployments, OPS-018 credential evidence, Runtime health, Resource Gate, and rollback candidates passed.
- Shared Preview started at 55 migrations, batch 26, through `000055`. `000056`, `000057`, and `000059` predicates passed. The only `000058` blocker was the approved Preview fixture: Gacha 10/11/12 and Version 8-12 had total 9 and snapshot/operational capacity 18.
- New migration `2026_09_12_000060_reconcile_preview_gacha_capacity.php` is ordered after `000057` and before `000058`. It accepts only the exact fixture/preconditions, locks the exact rows, permits only `total_count` 9-to-18 inside the same transaction, restores both original guards before commit, and fails closed on mismatch or `down()`.
- Clone validation applied `000056 -> 000057 -> 000060 -> 000058 -> 000059` as batch 27. A separate mismatch clone wrote no ledger/data and retained both guards.

## Shared Preview Activation

- The canonical GitHub workflow built exact-source API/Admin images in Run `32476998489`, Artifact `9444761948`; no Preview-host build ran.
- The Preview Deployment Lock and OS lock were held only for the mutable window. A root-only mode-0600 pre-activation database backup was created at `/var/lib/oripa-v2-evidence/OPS-019/ops019-pre-activation.dump`.
- Live Shared Preview applied `000056`, `000057`, `000060`, `000058`, and `000059` in batch 27. Versions 8-12 moved only from total 9 to 18; Draw State totals for Versions 8/11/12 moved only from 9 to 18; sold counts remained 10/0/6; snapshot and operational capacities remained 18; global capacity violations became zero.
- Seventeen related row types retained identical row counts and fingerprints, including Inventory/Adjustment, Draw Request/Result, User Prize/History, Exchange, Shipping, QA Guarantee/Execution, and Probability history. Both immutable guards were restored with no exception residue.
- API was activated first, then Admin. API now runs `oripa-v2-api:preview-OPS-019-ba17d7197673`; Admin runs `oripa-v2-admin:preview-OPS-019-ba17d7197673`; both OCI revisions equal the activation source.
- API/Admin/PostgreSQL/Redis are healthy with restart count 0. `v2_private` remains internal, API alone has egress, and Admin/PostgreSQL/Redis remain private-only. No rollback was required and both locks were released.

## Read-only Smoke

- API health, anonymous session, Gacha list, existing Gacha detail, and slug detail returned 200. Admin health and session endpoints returned 200.
- The deployed Admin bundle contains the canonical basic-information/Rank/prize UI, remaining-total display, reason field, and thumbnail-preview implementation. Exact-source review confirms Probability/Preflight and Request ID/internal code are absent from the normal Gacha edit path.
- Authenticated Admin browser verification was attempted with the only retained Preview test credential, but it is stale and login returned 401. No password reset, account mutation, or substitute credential was created; authenticated list/detail and all mutation scenarios remain Human Browser Verification.
- `luxe-pack.biz`, `test.luxe-pack.biz`, and both Storefront API session routes returned 200. API/Admin activation logs and host Nginx recorded zero 500/502/504 and zero runtime error patterns.
- Production, Storefront Runtime, Payment/Coin/Draw semantics, runtime env/credentials, Nginx/DNS/network topology, and unrelated data were unchanged. Unexpected business/history mutation is zero.

## Verification

- PHP syntax, focused irreversible-down test, Policy Unit 153, Quality Unit 4, Security Unit 10, Local Policy Gate, Local Quality Gate, clone full-order apply, clone mismatch fail-closed, exact-source Required Checks, and activation fixed-head self-review passed.
- Human Browser Verification remains for new Gacha creation, full Draft editing, Rank/prize add/edit, interactive thumbnail preview, capacity rejection, immediate/scheduled publish, pre-start editing, post-publish whitelist/disabled fields, Japanese errors, and authenticated confirmation that Probability/Preflight/Request ID/internal code are hidden.
