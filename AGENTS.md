# AGENTS.md

## Project

This is an オリパ site project.

The master specification is:

- docs/md/00_spec_v1.4.md

Codex must follow the Laravel backend architecture.

## Reading order

Before implementation, read these files in order:

1. docs/md/00_spec_v1.4.md
2. docs/md/01_environment_laravel.md
3. docs/md/02_work_instruction_laravel.md
4. docs/md/03_implementation_instruction_laravel.md

## Architecture

- Backend: Laravel API
- Frontend: Next.js
- DB: PostgreSQL
- Cache / Queue: Redis
- Storage: S3 compatible storage
- Mail: Mailpit in local, SES or SendGrid in production

## Hard rules

- Do not put lottery logic in Next.js.
- All lottery, point, wallet, draw, inventory, payment webhook, probability version logic must be implemented in Laravel.
- Do not use frontend random logic.
- Do not use Math.random for lottery.
- Use CSPRNG on the Laravel backend.
- Use ppm integer probability. 1,000,000 ppm = 100%.
- A probability stage must total exactly 1,000,000 ppm including the minimum guarantee row.
- There must be no no_prize result.
- result_type must be either prize or point_back.
- draw_sequence_number must be generated under DB lock.
- draw_sequence_number must have no duplicates and no gaps per gacha.
- Use DB transactions for draw, point consumption, sold_count update, prize inventory update, draw_results, point_ledgers, and user_prizes.
- Published probability versions are immutable.
- Never edit a published probability version directly.
- Do not commit .env or secret files.

## Forbidden

- Do not change production data.
- Do not connect to production payment keys.
- Do not run destructive commands without explaining the command first, even in full access mode.
- Do not remove migrations without a reason.
- Do not weaken security settings.

## Local environment

- Use Docker Compose for local development.
- Host PHP, Composer, Node.js, PostgreSQL, Redis, MinIO, and Mailpit are not required.
- `backend/` is the Laravel API project.
- `frontend/` is the Next.js project.
- Use PostgreSQL locally; do not switch to SQLite or MySQL.
- Use Redis for cache, queue, and session wiring.
- Use MinIO as the local S3-compatible storage.
- Use Mailpit for local mail verification.
- Do not commit `.env`, secrets, `vendor`, `node_modules`, generated storage files, or local volume data.

## Current implementation scope

The current repository setup contains only local environment scaffolding and health checks.

Not yet implemented:

- lottery logic
- payment webhooks
- point ledgers and point lots
- probability versions
- admin screen business implementation

## Multi-Codex operation

- When multiple Codex agents are operating, read `TASK_BOARD.md` before starting work.
- Do not modify files outside your assigned scope.
- Frontend Sub Codex may modify only `frontend/`.
- Frontend Sub Codex must not touch Docker, Laravel, DB, nginx, `.env`, or `.env.example`.
- Docker operations are handled only by Main Codex.
- After work, update your own log under `worklogs/`.
- If you find changes that may conflict with another agent or human work, stop and report before editing.
