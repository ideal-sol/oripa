# OPS-009 C2 Limited Bonus Runtime Activation

## Task

- Issue: #303
- PR: #304
- Risk: R4
- Base SHA: `6075512ba2c248dc711e5a9e89e7f5289d1a4c41`
- Branch: `chore/OPS-009-c2-limited-bonus-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-009`
- Task Policy SHA-256: `5efd9b1fff5cb75258f48c579a610c06407a5b317745ad243ed12462c6be0396`
- Activation source: `d8374dc918248385f9cceb92d96122800e3c70bd`
- Final docs-only head and squash SHA: pending Closeout.

## Preflight

- Repository root `/var/www/oripa`, clean `main`, local/Remote main, and task base were fixed to `6075512ba2c248dc711e5a9e89e7f5289d1a4c41` after `git fetch --prune`.
- Current API is `oripa-v2-api:preview-MIG-062W-dfefa07e1a90`, OCI revision `dfefa07e1a905bba07a56079d02ebfbaabfafc94`, image ID `sha256:ff2a41240fcbd126acac61107a1e6d73af121bd8247a2734debf2367ee6bc30d`.
- Current Admin is `oripa-v2-admin:preview-MIG-062T-0b1f982d1aea`, OCI revision `0b1f982d1aeaee81a6e7aea648695fe48053016e`, image ID `sha256:d640ea51684c11ea952c36001f0c1886c150551a2e9c7c61ada1a359dff9b152`.
- API and Admin Docker health are healthy. Internal API `/api/health`, internal Admin `/api/health`, external Admin `/api/health`, and both Payment Review User origins `/api/v2/auth/session` returned 200.
- API revision does not contain migration `000055`; Admin revision has no `期間限定ボーナスコイン` marker. The current Limited Bonus route returned 404. Authenticated browser smoke showed Point Purchase list and edit working, two visible products on the first page, normal edit fields present, no console/page/gateway errors, and no Limited Bonus section.
- Public authenticated read returned two published Point Products and Wallet 200 with private/no-store. Opaque product-ID and Wallet payload hashes were recorded in root-only evidence without storing credentials or response data in the Repository.
- Current database migration status had 53 Ran migrations through `000053`; candidate status showed existing `000054` and `000055` Pending. C2 Campaign table, snapshot table, and Payment Limited Bonus column were absent.
- Preservation baseline: Point Purchase Plans 3, Payments 0, Payment Point Grants 0, Wallets 2, Point Lots 4, Point Ledger Entries 8, Migrations 53.
- Preview Deployment Lock is held by OPS-009. Migration Allocation, Integration, and Artifact Release Locks are not acquired.

## Activation

- Required 5 checks passed on exact source `d8374dc918248385f9cceb92d96122800e3c70bd`. Its API tree `06f9a3cab0da6cfbddbef9fe5735eb52edfb779d` and Admin tree `14fade219644fc423a432e6d385664d0d3a3c2b8` exactly equal fixed latest `main`; OPS-009 changes only operational evidence.
- GitHub-hosted Preview Image Build Run `32212497380` produced Artifact `9351297179`. Outer/GitHub SHA-256 is `5c4263d68ae198f2d27c93686c0ec09836009c27c506e488f5491e2b8651bb7f`, manifest SHA-256 is `cfde7aa4fe514fa4ee0f3c0c43bba52b8e392e30287e92b686ea047f01ea1ab9`, and both images are verified `linux/amd64` with OCI revision equal to the activation source.
- Before migration, a PostgreSQL custom dump was stored in root-only evidence. SHA-256 is `82e697d8cc60ed61884e3460766845e880657eba583ba31ba3e2024d10ebabc2` and `pg_restore --list` reported 1331 TOC entries.
- Candidate `migrate:status` showed only existing `000054` and `000055` Pending. Normal `php artisan migrate --force --path=database/migrations-v2` applied them in order as batch 26; no fresh/reset/drop/truncate/history deletion/trigger bypass occurred.
- After migration, all 55 migrations are Ran. Campaign/snapshot tables, Payment column, three Limited Bonus constraints, and four protection triggers are present. Campaign rows, snapshots, Payments, Payment Grants, and Limited Bonus total are all zero, so no retrospective Grant occurred.
- Point Purchase Plans 3, Payments 0, Payment Point Grants 0, Wallets 2, Point Lots 4, and Point Ledger Entries 8 were unchanged. Stable hashes for all six business tables match before/after exactly.
- API updated to `oripa-v2-api:preview-OPS-009-d8374dc91824`, image ID `sha256:ef8b309fbe008ef3c8804ed00cb5d1413ea1761e469d525a069f8bf9bf19afb4`. Admin updated to `oripa-v2-admin:preview-OPS-009-d8374dc91824`, image ID `sha256:653fc955f2a3b7b214e70d8ac684ea98878f68f1bcd7e19bb0a5d53f381ef7ed`.
- Existing project, fixed API/Admin IPs `192.168.61.10`/`192.168.61.11`, loopback ports `8611`/`3611`, restart policy, database, network, and API persistent asset volume were preserved. Host build and image removal were not performed.

## Smoke

- API and Admin Docker health, internal API/Admin health, external Admin health, and both Payment Review User session origins: PASS/200.
- Authenticated Admin Point Purchase list and edit: PASS. Existing first-page product count remained 2, normal edit fields remained visible, and no console/page/gateway error occurred.
- `期間限定ボーナスコイン` section: visible. Campaign-unset edit showed the canonical empty state, and Limited Bonus list/read API returned 200.
- Authenticated Public Point Product read: 200 with the same two opaque product IDs as preflight and canonical `limited_bonus` fields. Wallet read: 200/private/no-store.
- Campaign create/update was not executed because no mutation was required. Real Payment, Draw, Refund, and Chargeback were not executed.
- API/Admin container logs, Nginx access log, and browser response capture found HTTP 500/502/504 count 0 for the activation smoke window.

## Safety

- No source feature change, new migration, package artifact release, Storefront change, DB reset, data deletion, Payment, Draw, Refund, Chargeback, Campaign mutation, or retrospective Grant occurred.
- Fail closed on migration failure, schema mismatch, source mismatch, lock conflict, Remote movement, or required destructive action.

## Failure handling and rollback

- Initial PR-event Policy Gate rejected three missing canonical PR headings. The PR body was corrected without changing the source head; a manual exact-head run then passed all Required 5 checks.
- The first Preview Image Build dispatch occurred before checks completed and correctly failed closed. It produced no artifact. The same exact head was dispatched only after Required 5 PASS, and the second run succeeded.
- Prior API/Admin image IDs remain retained for Application rollback. The root-only preactivation DB dump is the recorded database restore point. Automatic migration down is not planned; any schema rollback requires a separate approved operation because `000054`/`000055` are durable forward migrations.

## Scope and closeout

- Preview Deployment Lock was acquired before shared Runtime mutation and released after all Runtime smoke passed. Migration Allocation, Artifact Release, and Integration Locks were never acquired.
- Repository full suite/full build, Storefront Repository, package artifact publication, formal Production deployment, Provider changes, and mutation smoke were not performed by explicit scope.
- Remaining blocker: none. Exact final docs head checks, fresh self-review, squash merge, Issue/branch/worktree cleanup, and main synchronization remain Closeout steps.
