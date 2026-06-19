# TASK_BOARD

Last updated: 2026-06-19

This board defines ownership boundaries for running Main Codex and Frontend Sub Codex in the same repository.

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

