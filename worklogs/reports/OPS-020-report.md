# OPS-020 Latest API/Admin Shared Preview Activation

## Task

- Issue `#393`, Draft Pull Request `#394`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- `OPS-016` was already used, so the next unused operations identifier `OPS-020` was allocated without reusing an existing Task.
- Base and latest application source were `2680e76a414f8bc80906ca7ff7a1c96b808165fc`; the activation evidence head was `6cc5e8d5c5194ebf122f8d819f2fa6c54fd1cf12`. API and Admin application trees are unchanged between those heads.

## Migration

- Shared Preview advanced from 64 migrations, batch 31, through `2026_09_18_000064_add_v2_mail_templates` to 66 migrations, batch 32, through `2026_09_22_000066_add_v2_verification_failed_user_state`.
- Canonical order was `2026_09_21_000065_add_fincode_payment_backend_core` followed by `2026_09_22_000066_add_v2_verification_failed_user_state`. Both were merged to latest main, had complete predecessors, were additive, and passed live-copy apply, rollback, and reapply validation before live apply.
- `000065` created zero Payment, Payment Point Grant, or fincode business rows and rewrote zero existing Payment rows. `000066` backfilled zero existing Users and preserved state counts `active=5`, `closed=7`, `pending_verification=4`.
- A first one-off migration runner failed before database access because its fixed API address was already occupied. Ledger, data, and Runtime remained unchanged; the corrected private-network runner applied both migrations successfully.
- The root-only rollback backup is retained at `/var/lib/oripa-v2-evidence/OPS-020/ops020-pre-activation.dump`. Migration creation was zero; Shared Preview apply was two; Production apply was zero.

## Build and Activation

- Required Checks for activation source `6cc5e8d5c5194ebf122f8d819f2fa6c54fd1cf12` passed: Policy `98384657365`, Security `98384702496`, Quality `98384702499`, Integration `98386832977`, and CI `98386846911`. Check rerun count was zero.
- The first evidence-only successor `a57ce0181b43cbe8275f416e2336e19f2e62c51c` triggered automatic checks before the PR body could be updated from the activation head. Policy failed the stale expected-head metadata and downstream checks failed/skipped closed. No gate or assertion was weakened and no same-head rerun was used; this report-only successor records the failure before a correctly predeclared head is pushed.
- Canonical workflow `preview-image-build.yml` ran once as Run `33032347596` and produced Artifact `9630790720`, digest `sha256:13834c1b43ee8c7d0a82b921fda641e850be9f8f46a04da2c3bae24bb45890b8`. Host build count was zero and the Storefront artifact step was skipped.
- API runs `oripa-v2-api:preview-OPS-020-6cc5e8d5c519`, image `sha256:a0494542769e6f9d1a4e4291311f385dd3decf71078236f1a77d6aab5914d85b`. Admin runs `oripa-v2-admin:preview-OPS-020-6cc5e8d5c519`, image `sha256:74a28be1adf716b2814638f77200900c52032961b7e4bb1e802e6acf42b1dfd6`. Both OCI revisions equal the activation source, are healthy, and have restart count zero.
- Required rollback images remain loaded: API `oripa-v2-api:preview-MIG-078-59173e3a0e96` / `sha256:c072865555a4ba369f6f81b6ffaa5d46beadb282d35ab12923c73e84cd1c1d44`; Admin `oripa-v2-admin:preview-MIG-078-59173e3a0e96` / `sha256:1f00f2fbef44adb4699aaf5b968a426495f6383704c5d0ba44e5ecda60a18c7e`. Rollback was not required.
- PostgreSQL container `6689deb8e6c7e3db530598bf8178b7992bbd2646c5f1e6c2e7d787e56887f35c` and Redis container `4f50897b8e52622aa770d4ec63b38e4d194c13bfccf35f1a3e0f5d0ebe240842` retained their identities, start times, volumes, and private-only network. API alone retains the existing egress network; Admin remains private-only. Storefront, DB/Redis volumes, private network, and unrelated containers were not recreated or changed.

## Post-Activation Smoke

- API health returned 200 with app, database, Redis, and storage all `ok`; API session returned 200. Admin health and Admin session returned 200. API/Admin containers are healthy and PostgreSQL accepts connections.
- Host Nginx access recorded zero 500/502/504 after activation. Docker HTTP 500/502/504 count was zero. Four Nginx temporary-file buffering notices were not connection failures, timeouts, or 5xx responses.
- `FINCODE_PAYMENT_ENABLED` remains disabled by unset/false default. `FINCODE_SECRET_API_KEY`, `FINCODE_PUBLIC_API_KEY`, `FINCODE_WEBHOOK_SIGNATURE`, and `FINCODE_API_BASE_URL` remain unset. Credential mutation, Payment enable mutation, Provider communication, Payment creation, Production mutation, and Storefront activation were each zero.
- Root free space was `23,643,103,232` bytes before build and `22,962,905,088` bytes (21.38 GiB) after activation and targeted clone cleanup, remaining above the 20 GiB gate. No disk cleanup or prune ran.

## Data Preservation

- Immediately after migration apply, Payment, Point Operation/Ledger/Lot/Wallet, Point Product, User count, and User-state aggregates exactly matched the pre-migration baseline. This proves the two migrations caused zero business mutation.
- During post-activation Browser work, four external non-QA User operations occurred independently of OPS-020: two `draw` spends of 1,000 free points and two `prize_exchange` grants of 40 free points. Payment and Payment Point Grant counts remained zero; User states and Point Product data remained unchanged.
- Consequently the activation-window global Point aggregates do not equal the initial baseline even though OPS-020 issued no draw, exchange, Coin, Point, Payment, User, or Provider mutation. No attempt was made to reverse or mask this legitimate concurrent Shared Preview activity.

## Browser Acceptance

- The live Admin login page returned 200, but the only retained normal Preview test credential is stale: login POST returned 401 before MFA. No password reset, Admin creation, account mutation, substitute credential, or session fabrication was performed. Browser responses contained zero 500/502/504 and zero page errors.
- After Human reported an authenticated session ready, read-only preflight reconfirmed the exact API/Admin Runtime, healthy state, and ledger 66/batch 32 without rebuilding, migrating, or activating. Two valid MFA-complete server-side Admin sessions were observed, but no cookie, Playwright storage state, local Browser profile, or attached Browser process was available to the Codex Browser. One normal-flow retry with the retained credential again returned 401 before MFA, with zero 500/502/504 and zero page errors. Authentication was not bypassed or changed.
- Human subsequently designated the Human authenticated Browser as the authoritative OPS-020 Browser Acceptance environment. Cookie, storage-state, and CDP handoff to Codex are not required. Codex did not retry retained credentials or perform authentication changes; the Human report for the three acceptance areas was received and recorded as PASS.
- Human authenticated Browser Acceptance is `PASS`. Payment navigation appears in canonical `ガチャ → 決済 → 配送` order, `決済 > 決済状況` is reachable, the default is `決済成功 / すべて`, and status/method filters were confirmed.
- Human confirmed that both Rank Effect create and edit screens no longer display `Rank relation / 対象ランクと表示順`.
- Human confirmed User List defaults to active Users only and that ID, status, registration-date, and `verification_failed` filters are available and operate as expected.
- Exact-source focused evidence is reusable and passed on the deployed application tree: MIG-085 covers `ガチャ → 決済 → 配送`, `決済状況`, default `succeeded/all`, status and Card/PayPay/Konbini/Bank filters, reset, and cursor; MIG-090 covers Rank relation UI removal while preserving normal material fields and Gacha rank configuration; MIG-091 covers User List default `active`, ID/status/`verification_failed`/registration-date filters, reset, URL query, and cursor.
- Human Browser evidence is the post-activation acceptance authority; focused source evidence remains supporting regression evidence. No expired verification link was executed and User lifecycle mutation remained zero.

## Status and Blockers

- Activation, Runtime smoke, migration readback, and Human Browser Acceptance are complete. Fresh final-head checks/self-review, readying, merge, branch/worktree cleanup, and main synchronization remain the closeout sequence.
- Seven external non-QA User point operations observed after activation remain recorded as unrelated concurrent activity. OPS-020 did not create, delete, reverse, or reinterpret them, and global Point aggregate invariance is not claimed.
- OPS-020-caused Payment, Coin, and User business mutation is zero. Payment and Payment Point Grant rows remain zero; User state counts remain `active=5`, `closed=7`, `pending_verification=4`.
- Product blockers remain outside OPS-020 closeout: fincode Sandbox credentials, Webhook signature and registration, Payment enable, Storefront latest Runtime, and four-method Provider Browser E2E.
- Canonical build count `1`, dispatch count `1`, API activation count `1`, Admin activation count `1`, check rerun count `0`; one automatic metadata-race failure is recorded separately. The Task remains in progress, so final elapsed, Human wait, merge actor, squash SHA, and final cleanup metrics are not yet available.
