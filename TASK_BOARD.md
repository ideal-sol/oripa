# TASK_BOARD

Last updated: 2026-06-19

This board defines ownership boundaries for running Main Codex and Frontend Sub Codex in the same repository.

## Specification Priority

Use the following order when requirements conflict:

1. Latest explicit human decision
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`
4. `docs/md/spec_v1.5.md`
5. `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
6. `docs/md/spec_v1.4.md`
7. `AGENTS.md`
8. `TASK_BOARD.md`
9. `docs/SHARED_CONTEXT.md`
10. `docs/md/all_check.md`

## Main Codex Scope

Main Codex owns backend, infrastructure, and domain logic.

- Laravel API
- `backend/`
- `database/`
- migrations
- Models
- Services
- Controllers
- `routes/api.php`
- Queue
- Scheduler
- payment webhooks
- lottery logic
- point logic
- probability version management
- Docker
- Nginx
- infrastructure-related work

## Frontend Sub Codex Scope

Frontend Sub Codex may edit only:

- `frontend/*`

Frontend Sub Codex should focus on:

- Next.js UI
- page layout
- client-side display state
- CSS
- public/admin screen presentation
- API client usage from existing Laravel API contracts

Frontend Sub Codex must not implement lottery, point consumption, payment webhook, or probability logic in Next.js.

## Frontend Sub Codex Must Not Touch

- `backend/`
- `database/`
- `routes/api.php`
- Laravel Controllers
- Laravel Services
- Laravel Models
- Migration files
- `docker-compose.yml`
- Dockerfiles
- nginx settings
- `.env`
- `.env.example`
- `composer.json`
- `composer.lock`
- lottery logic
- point consumption logic
- probability calculation logic
- probability version management
- payment webhooks
- DB schema changes

## Frontend Sub Codex Forbidden Commands

Frontend Sub Codex must not run:

- `docker compose up -d --build`
- `docker compose build`
- `docker compose down`
- `docker system prune`
- `docker builder prune`
- `php artisan migrate`
- `composer install`
- `composer update`
- `npm install`
- `pnpm install`
- `rm -rf`
- editing `.env`

If one of these commands appears necessary, Frontend Sub Codex must stop, write the reason, and wait for human approval or Main Codex approval.

## Coordination Rules

- Before starting work, read `TASK_BOARD.md`, `docs/SHARED_CONTEXT.md`, and the relevant `worklogs/*.md`.
- Do not modify files outside your ownership boundary.
- If a change needs another area, record the request in the worklog and hand it to the owner.
- After work, update your own worklog under `worklogs/`.
- If a file has unexpected changes or looks likely to conflict, stop and report before editing.
- Do not revert another Codex or human change unless explicitly instructed.

## ADMIN-REF-001 Status

- Status: deferred.
- Owner: Main Codex only.
- Backup branch: `backup/admin-refactor-deferred-20260626-0847`.
- Backup commit: `e0a8537`.
- Reason: Next.js dev server compile/cache load after route splitting caused 504 timeouts under the current server specs.
- Route conflict was fixed, but 504 continued.
- Current policy is to prioritize the stable admin structure.
- Current active admin structure: stable pre-refactor admin dashboard using `frontend/src/app/admin-dashboard.tsx` and `frontend/src/app/admin/[[...segments]]/page.tsx`.
- Do not restart ADMIN-REF-001 or edit route-split admin files until the project owner explicitly reopens the refactor.
- Full admin refactoring should be retried after all feature additions are complete and the server specs are upgraded.
- New admin features should be added to the current stable admin structure for now.

## Next Backend Task

- Task: daily point balance snapshots.
- Status: next pre-release critical feature.
- Existing foundation: `point_balance_snapshots` table and Model exist.
- Missing or unconfirmed: Service, Command, Scheduler, and tests.
- Reason: daily storage of paid/free unused point balances is required for funds settlement law support.
- Main Codex owns this task.
