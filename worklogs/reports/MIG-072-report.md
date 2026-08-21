# MIG-072 Gacha Draft / Unpublished Lifecycle Final Fix

## Task

- Issue `#356`, Risk `R4`, Base `094199a5e3f64918a854943811ee6895f4105d8b`.
- Branch `fix/MIG-072-gacha-draft-unpublished-lifecycle`, dedicated worktree `/var/www/oripa-worktrees/MIG-072`.

## Root Cause and Fix

- Application lifecycle mapping, Canonical Publish preflight/activation, and the PostgreSQL lifecycle guard all treated `unpublished` as terminal. This blocked both `draft -> unpublished` support and the newly approved `unpublished -> draft` restoration path.
- The application now permits `draft -> unpublished`, clones the latest immutable Published Version when a previously published Gacha returns to Draft, and permits Canonical Publish from that restored Draft while preserving the original `first_published_at` and prior history.
- Direct `unpublished -> published` remains rejected with `CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID`. Published sale/economic fields remain immutable in a restored Draft; presentation fields retain the existing Published edit whitelist.
- Migration `000061` narrows the PostgreSQL guard to allow only `unpublished -> draft`; every other existing lifecycle, pointer, schedule, and first-publication invariant remains intact. Its rollback fails closed once restoration data exists.
- Admin status choices now expose `draft -> unpublished` and `unpublished -> draft`, keep restored Draft publishable, and do not offer another schedule after publication history exists.

## Focused Verification

- Isolated PostgreSQL V2 `migrate:fresh`: all `61` migrations PASS.
- Focused API lifecycle: `11` tests / `270` assertions PASS, covering Draft unpublish/restore/publish, Published and paused unpublish, direct republish rejection, pause/resume, revision constraints, immutable Published fields, and unchanged Draw/Inventory/User Prize history.
- Focused Admin lifecycle: `1` file / `7` tests PASS. Admin typecheck, lint, production build, changed PHP syntax, focused migration-policy unit, and `git diff --check` PASS.
- Required Checks, exact-head self-review, Shared Preview activation, exact five-record mutation, acceptance, and closeout are pending.
