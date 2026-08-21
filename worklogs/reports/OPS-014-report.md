# OPS-014 Canonical Gacha Preview Runtime Activation

## Task

- Issue: `#340`
- PR: `#341`
- Risk: `R4`
- Base/candidate: `d01a3ca7511691a729a781959ab715ddd0d43f7a`
- Branch: `chore/OPS-014-canonical-gacha-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-014`
- Task Policy SHA-256: `b1f636c74ae85472466fbedc62d9ad81ea199c15b2adfbc63b14b6dc349e645d`

## Candidate Gate

- `git fetch --prune` completed before work.
- Local `main`, `origin/main`, GitHub `main`, and the confirmed candidate all exactly equal `d01a3ca7511691a729a781959ab715ddd0d43f7a`; no later Platform commit was selected or deployed.
- MIG-067 `7434709fcbf2faf93cd7e22fce51d1badc3411b9`, MIG-068 `4d3eb24ec5450088bd2df6b8cfe1bc1b06173ddd`, and MIG-069 `d01a3ca7511691a729a781959ab715ddd0d43f7a` are ancestors of the candidate.

## Preflight

- `CODEX_ACTIVE_TASKS`, all Shared Locks, the Storefront lane, and the Preview Deployment OS lock were idle/free. No competing deployment process was active.
- API, Admin, PostgreSQL, and Redis were healthy with restart count `0`.
- Resource Gate readback: 22 GiB disk available, 3393 MiB memory available, and 5420 MiB swap available.
- Active API: `oripa-v2-api:preview-OPS-012-8c14b513393f`, image ID `sha256:4bfbb204539e3e1e329c18c489e80382b70dcb6ce5c1bead1ad476f59b23280e`, OCI revision `8c14b513393f4cecea70a1516b2ebc2624944450`.
- Active Admin: `oripa-v2-admin:preview-OPS-012-8c14b513393f`, image ID `sha256:d7d028c1f3f4ab9d8362c87e0d131edae7f3e16c17704af6440b2531728d3109`, OCI revision `8c14b513393f4cecea70a1516b2ebc2624944450`.
- Current Nginx access log contains zero HTTP 500/502/504 responses. This is preflight evidence, not post-activation evidence.

## Migration Gate

- Shared Preview migration ledger contains 55 rows through `2026_09_10_000055_add_v2_limited_bonus_domain_core`, with latest batch `26`.
- `000056`: **Pending**.
- `000057`: **Pending**.
- `000058`: **Pending**.
- `000059`: **Pending**.
- The explicit continuation condition requires `000056` and `000057` Ran and `000058` and `000059` Pending. Because both prerequisites are Pending, OPS-014 failed closed before migration or Runtime write.
- No migration was modified, created, applied, rolled back, reset, refreshed, or forced. No trigger was disabled and no business/history row was rewritten.

## Blocked Activation

- Canonical Preview Image Build was not dispatched.
- No artifact was downloaded or loaded and no image was built on the Preview host.
- API/Admin were not recreated. Before/after images, OCI revisions, container identities, network boundaries, runtime environment, and rollback candidates remain unchanged.
- The Preview Deployment Lock was not acquired because the task never reached the mutable Runtime window; therefore no release action was necessary.
- Application rollback and migration rollback were not performed or required.

## Data Preservation

- Read-only row counts and ordered row fingerprints for 11 Gacha, Probability, schedule, Draw State, and inventory tables were stored in root-only evidence.
- No migration, deployment, Admin operation, or business mutation occurred after the baseline. Existing business data is therefore preserved and unexpected retrospective mutation is `0` for OPS-014.
- Gacha create, Rank/prize mutation, immediate publish, scheduled publish, Payment, Coin, Refund, and Draw were not executed.

## Smoke Status

- Current preflight API/Admin/PostgreSQL/Redis health: PASS.
- Restart loop: none; all four restart counts are `0`.
- Post-activation smoke, Admin login/session, Gacha list/detail, basic information, Rank, prizes, thumbnail preview, Probability/Preflight visibility, and console/runtime error checks: **NOT RUN** because activation was blocked before migration.
- HTTP 500/502/504 post-activation count: **NOT APPLICABLE**. Preflight current Nginx log count is `0`.

## Closeout Validation

- PASS: Policy Unit 152 tests, Quality Unit 4 tests, and Security Unit 10 tests.
- PASS: local `policy-gate`, `quality-gate`, and `security-gate`.
- PASS: fresh Composer, workspace pnpm, and legacy pnpm audits with zero findings.
- PASS: deployment JSON parse, exact allowed-path review, `git diff --check`, binary/submodule review, and high-confidence secret scan.
- Required five GitHub checks and fresh fixed-head self-review are required on the final evidence head before squash merge.

## Human Browser Verification Remaining

All requested Human Browser verification remains pending because no activation occurred:

- New Gacha creation and all Draft fields.
- Rank add/edit and prize add/edit.
- Thumbnail preview.
- Total-count remainder and over-capacity save rejection.
- Immediate publish and scheduled publish.
- All-field editing before scheduled start.
- Eight-field editing after publication and disabled immutable fields.
- Probability/Preflight hidden from normal UI.
- Japanese errors and hidden Request ID/internal code.

## Scope And Remaining Blocker

- Storefront, Production, Payment/Coin/Refund, Draw Core, Public Contract/Artifact, Nginx/DNS/TLS, network boundary, runtime environment, Legacy Probability physical records, and Application source changes are all `0`.
- Remaining blocker: Shared Preview must first reach a separately authorized, canonical state where `000056` and `000057` are Ran. OPS-014 may not infer or apply those missing prerequisites under the explicit task gate. A new activation attempt must re-read exact `main`, locks, Runtime, resources, and the complete migration ledger.
