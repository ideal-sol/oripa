# AGENTS.md

## Project

This is an オリパ site project.

Specification priority is:

1. Latest explicit human decision
2. docs/md/spec_v1.5.1.md
3. docs/md/spec_v1.6_draft.md
4. docs/md/spec_v1.5.md
5. docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md
6. docs/md/spec_v1.4.md
7. AGENTS.md
8. TASK_BOARD.md
9. docs/SHARED_CONTEXT.md
10. docs/md/all_check.md

The as-built approval decision is:

- docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md

Codex must follow the Laravel backend architecture.

## Reading order

Before implementation, read these files in order:

1. docs/md/spec_v1.5.1.md
2. docs/md/spec_v1.6_draft.md
3. docs/md/spec_v1.5.md
4. docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md
5. TASK_BOARD.md
6. docs/SHARED_CONTEXT.md

Historical reference files:

- docs/md/spec_v1.4.md
- docs/md/all_check.md
- docs/md/word.md
- docs/md/implementation.md

## Current Next Critical Task

- Daily point balance snapshots are the next pre-release critical backend task.
- `point_balance_snapshots` table and Model exist.
- Service, Command, Scheduler, and tests are not implemented or not yet confirmed.
- Daily storage of paid/free unused point balances is required for funds settlement law support.
- Do not start broader admin refactoring before this and other remaining release-critical features are handled.

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

## Approved as-built specifications

- Items in `docs/md/all_check.md` that are marked `VERIFIED`, `IMPLEMENTED_UNTESTED`, or `PARTIAL` in `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md` are approved by the project owner as formal specifications.
- Do not treat those items as waiting for human specification approval.
- Keep approval status separate from implementation, test, E2E, release, legal/accounting, and infrastructure readiness.
- `IMPLEMENTED_UNTESTED` means the specification is approved, but tests/E2E/real-device checks are still missing.
- `PARTIAL` means the currently implemented behavior is approved. Do not change it casually; keep the unimplemented portion as a remaining task.
- `PENDING`, `FUTURE`, and `NOT_FOUND` are not approved implemented features.
- Mock payment is approved only as a development/testing feature. It is not production payment functionality.

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
