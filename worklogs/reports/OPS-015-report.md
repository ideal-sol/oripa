# OPS-015 Shared Preview Migrations 000056 / 000057

## Task

- Issue: `#342`
- PR: `#343`
- Risk: `R4`
- Base: `25047b47dcefaffff20b453cf607f393dbb8f786`
- Branch: `chore/OPS-015-shared-preview-migrations-56-57`
- Worktree: `/var/www/oripa-worktrees/OPS-015`
- Task Policy SHA-256: `e83f51aa4b6b20926a1a87bc83cbd568acac4d70cd9171609ed73e2ad388c367`

## Start Gate

- `git fetch --prune` completed before task creation.
- Clean local `main`, `origin/main`, GitHub App readback, and public GitHub API readback all exactly matched `25047b47dcefaffff20b453cf607f393dbb8f786`.
- All Platform and Storefront lanes were idle. Migration Allocation, Platform Integration, Artifact Release, and Preview Deployment Locks were `none`; the Preview Deployment OS lock was free.
- OPS-015 was unused in GitHub Issue history, remote branches, worktrees, and Task Policies before allocation. Issue `#342`, the dedicated branch/worktree, and the R4 Task Policy were then created.

## Credential Rotation Gate

- The task found no authoritative completion evidence for rotation of the Preview Runtime credentials exposed in the OPS-014 tool transcript.
- The root-only Preview environment file metadata predates the OPS-014 exposure. Credential values were not displayed, recorded, copied, hashed, or reacquired.
- Database writes therefore failed closed before migration activation. No Shared Lock was acquired because the task never entered a mutable Preview window.

## Read-only Migration Preflight

- Target: non-Production Shared Preview database `oripa_v2_mig061a`, schema `public`.
- Start and final ledger: 55 exact ordered migrations through `2026_09_10_000055_add_v2_limited_bonus_domain_core`; latest batch `26`.
- `000056`: Pending. `000057`: Pending. `000058`: Pending. `000059`: Pending.
- `000056` replaces only the catalog master protection function and contains no business-row rewrite or data fail-closed predicate.
- `000057` preflight returned zero multi-Gacha Rank owner groups, zero projected same-Gacha duplicate-code groups, and zero duplicate unowned Rank-code groups. Existing data therefore satisfies its explicit up-migration fail-closed conditions.
- The guarded runner rejected the historical Preview env shape because it now contains an unexpected credential field. The task did not weaken that guard or derive another env file; target and ledger confirmation used narrow read-only SQL inside the existing PostgreSQL container without printing credentials.

## Activation Result

- `000056`: **NOT APPLIED**; batch not allocated.
- `000057`: **NOT APPLIED**; batch not allocated.
- `000058`: **Pending**.
- `000059`: **Pending**.
- `migrate:fresh`, rollback, trigger disablement, manual DDL, business/history writes, and existing Migration edits were not performed.

## Data Preservation

- Before/after read-only row counts and ordered full-row JSON MD5 fingerprints matched for 16 relevant tables: Gacha, Category, Tag, Rank, Gacha/Tag and Version/Rank/Prize relations, Prize, Publish Schedule, Draw State, Prize Inventory and adjustment history, User Prize and status history, and Audit Log.
- Preserved counts include Gacha `9`, Category `6`, Tag `3`, Rank `11`, Gacha Versions `11`, Version Ranks `8`, Version Prizes `21`, Prize `17`, and Audit Logs `1272`.
- Unexpected retrospective business mutation: `0`. History rewrite: `0`.

## Runtime And Scope Preservation

- API, Admin, PostgreSQL, and Redis remained healthy with restart count `0`.
- API and Admin image names, image IDs, OCI revisions, and Compose config hashes remained unchanged from OPS-014.
- Runtime build, artifact download/load, deployment, and API/Admin activation: `0`.
- Storefront, Production, Payment, Point, Coin, Refund, Draw Core behavior, Nginx, DNS/TLS, network boundaries, runtime environment, and Application source changes: `0`.
- Rollback: not performed and not required.

## Remaining Blocker

- Shared Preview credentials exposed during OPS-014 require authoritative rotation completion evidence before any database write.
- Canonical Gacha Runtime Activation is **not startable** because `000056` and `000057` remain Pending. After rotation is confirmed, a new independently authorized migration activation must re-read latest `main`, Task state, all locks, the full ledger, and data guards before applying only those two migrations.

## Closeout Validation

- PASS: Policy Unit 152 tests, Quality Unit 4 tests, and Security Unit 10 tests.
- PASS: local `policy-gate`, `quality-gate`, and `security-gate`.
- PASS: fresh Composer, workspace pnpm, and legacy pnpm audits with zero findings.
- PASS: deployment JSON parse, exact allowed-path review, `git diff --check`, binary/submodule review, and high-confidence secret scan.
- Required five GitHub checks and fresh fixed-head self-review remain required on the final evidence head before squash merge.
