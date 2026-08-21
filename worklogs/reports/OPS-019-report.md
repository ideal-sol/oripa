# OPS-019 Canonical Gacha Shared Preview Activation

## Task

- Issue: `#350`
- Pull Request: `#351`
- Risk: `R4`
- Base SHA: `9e8e644c1302b3257f1076a829a4aba6198fd0b9`
- Branch: `chore/OPS-019-canonical-gacha-preview-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-019`

## Stage 0

- Local, origin, and GitHub `main` equal the approved base SHA and the main worktree is clean.
- Active task, Shared Lock, Preview OS lock, competing deployment, OPS-018 credential readiness, Runtime health, PostgreSQL/Redis health, migration ledger, Resource Gate, and rollback candidates passed read-only inspection.
- Shared Preview starts at 55 migrations, latest batch 26, through `000055`; `000056` through `000059` are pending.
- All `000056`, `000057`, and `000059` predicates pass. The only `000058` blocker is the Human-approved exact Preview test fixture: Gacha 10/11/12 and Version 8 through 12 have `total_count=9`, snapshot capacity 18, and operational capacity 18.

## Forward Reconciliation

- New migration `2026_09_12_000060_reconcile_preview_gacha_capacity.php` is lexically ordered after `000057` and before `000058` while using the next unused allocation ID `000060`.
- The migration recognizes only the exact Preview fixture code/ID boundary. An absent fixture is a package-safe no-op; any partially present or mismatched target fails before data mutation.
- It locks exact Gacha, Version, Draw State, snapshot relation, and operational inventory rows. It temporarily inserts an exact `total_count`-only 9-to-18 branch into both immutable guards inside the migration transaction, updates only five Version and three Draw State rows, restores both original function definitions, verifies exact restoration, and then commits.
- It never disables a trigger or constraint. `down()` fails closed rather than rewriting protected history.

## Task Clone Verification

- A read-only dump of Shared Preview was restored into an isolated non-Production Task database. Live Shared Preview remained at 55 migrations and all target totals remained 9.
- The clone applied `000056 -> 000057 -> 000060 -> 000058 -> 000059` in batch 27. All five target Version totals and three Draw State totals became 18; snapshot and operational capacities stayed 18; sold counts stayed 10, 0, and 6; both global `000058` capacity predicates became zero.
- Seventeen target-related row types retained identical before/after row counts and fingerprints, including Version fields other than total count, Draw State fields other than total count, Prize Inventory, Inventory Adjustment, Draw Request/Result, User Prize/History, Exchange, Shipping, QA Guarantee/Execution, and Probability history.
- Both immutable guard definitions were restored with no OPS-019 exception residue. A separate exact-mismatch clone run failed before reconciliation, retained all five Version and three Draw State totals at 9, wrote no reconciliation ledger row, and left both guards unchanged.
- Focused irreversible-down test, PHP syntax, Policy Unit 153 tests, and `git diff --check` pass. Full Required Checks and exact-source Preview image build remain pending.

## Pending Activation

- Open the Draft PR, pass all five Required Checks, create fresh fixed-head self-review evidence, and build the exact PR-head API/Admin Preview artifact.
- Acquire the Preview Deployment Lock and OS lock only for the mutable window; take the canonical database rollback backup; apply the five migrations; activate exact-source API then Admin with the existing environment and network boundary; run read-only smoke; release locks.
- Final Runtime evidence, final Required Checks, fresh fixed-head self-review, squash merge, Issue close, and cleanup remain pending.
