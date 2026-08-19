# OPS-009 C2 Limited Bonus Runtime Activation

## Task

- Issue: #303
- Risk: R4
- Base SHA: `6075512ba2c248dc711e5a9e89e7f5289d1a4c41`
- Branch: `chore/OPS-009-c2-limited-bonus-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-009`
- Activation source, PR, final head, squash SHA: pending.

## Preflight

- Repository root `/var/www/oripa`, clean `main`, local/Remote main, and task base were fixed to `6075512ba2c248dc711e5a9e89e7f5289d1a4c41` after `git fetch --prune`.
- Current API is `oripa-v2-api:preview-MIG-062W-dfefa07e1a90`, OCI revision `dfefa07e1a905bba07a56079d02ebfbaabfafc94`, image ID `sha256:ff2a41240fcbd126acac61107a1e6d73af121bd8247a2734debf2367ee6bc30d`.
- Current Admin is `oripa-v2-admin:preview-MIG-062T-0b1f982d1aea`, OCI revision `0b1f982d1aeaee81a6e7aea648695fe48053016e`, image ID `sha256:d640ea51684c11ea952c36001f0c1886c150551a2e9c7c61ada1a359dff9b152`.
- API and Admin Docker health are healthy. Internal API `/api/health`, internal Admin `/api/health`, external Admin `/api/health`, and both Payment Review User origins `/api/v2/auth/session` returned 200.
- API revision does not contain migration `000055`; Admin revision has no `期間限定ボーナスコイン` marker. The current Limited Bonus route returned 404. Authenticated browser smoke showed Point Purchase list and edit working, two visible products on the first page, normal edit fields present, no console/page/gateway errors, and no Limited Bonus section.
- Public authenticated read returned two published Point Products and Wallet 200 with private/no-store. Opaque product-ID and Wallet payload hashes were recorded in root-only evidence without storing credentials or response data in the Repository.
- Current database migration status has 53 Ran migrations through `000053`; `000054` and `000055` are absent from the current image. C2 Campaign table, snapshot table, and Payment Limited Bonus column are absent.
- Preservation baseline: Point Purchase Plans 3, Payments 0, Payment Point Grants 0, Wallets 2, Point Lots 4, Point Ledger Entries 8, Migrations 53.
- Preview Deployment Lock is held by OPS-009. Migration Allocation, Integration, and Artifact Release Locks are not acquired.

## Activation

- Pending immutable Preview image build/import, migration `000055` normal application, and API/Admin update.

## Smoke

- Pending post-activation API/Admin/UI/Public/Wallet read smoke.

## Safety

- No source feature change, new migration, package artifact release, Storefront change, DB reset, data deletion, Payment, Draw, Refund, Chargeback, Campaign mutation, or retrospective Grant has occurred.
- Fail closed on migration failure, schema mismatch, source mismatch, lock conflict, Remote movement, or required destructive action.
