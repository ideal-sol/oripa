# Shared Context

Last updated: 2026-06-19

## Project Overview

This repository is an online オリパ site project.

Users buy points, spend points to draw gachas, receive prizes or minimum guarantee point-back results, and manage prizes through a user-facing Next.js frontend. Admin users manage gachas, categories, ranks, prizes, probability settings, announcements, users, shipping, payments, points, static pages, and operational guides from a Next.js admin UI backed by Laravel APIs.

## Current Development Status

The project has moved beyond scaffolding. Laravel API, PostgreSQL, Redis, MinIO-compatible storage, Mailpit/Mailgun wiring, Discord notification services, user pages, admin pages, gacha management UI, upload flows, and public gacha/detail pages have been implemented in stages.

The latest pushed commit at the time this file was created is:

- `88de4a3 Update notifications, mail, and public UI`

The working tree before creating these shared coordination files had only `worklogs/` as an untracked directory.

## Technical Stack

- Backend: Laravel API
- Frontend: Next.js
- DB: PostgreSQL
- Cache/Queue: Redis
- Web Server: Nginx
- Storage: S3-compatible storage, local MinIO
- Mail: Mailpit locally, Mailgun API for production-style mail sending
- Admin notifications: Discord webhook

## Master Specification

`AGENTS.md` references `docs/md/00_spec_v1.4.md`, but that file does not currently exist in this working tree.

The existing specification files are:

- `docs/md/spec_v1.4.md`
- `docs/md/environment_setup_v1.0.md`
- `docs/md/word.md`
- `docs/md/implementation.md`
- `docs/md/OripaPricingPlanner.jsx`

Until the `00_*` files are added or the instruction is corrected, treat `docs/md/spec_v1.4.md` together with `docs/md/word.md` and `docs/md/implementation.md` as the practical project specification sources already used in this repository.

If `docs/md/00_spec_v1.4.md` is later added, it should be treated as the master specification as instructed by `AGENTS.md`.

## Implemented Areas

- Laravel API foundation
- PostgreSQL data model and migrations for the main domain
- Admin authentication and admin middleware
- Admin APIs for gachas, categories, ranks, prizes, probability versions, users, payments, shipping, points, announcements, contacts, purchase plans, static pages, and rank assets
- User authentication, registration, profile, point history, prize box, draw history, shipping history, contact form, static pages
- Draw API connected to Laravel domain services
- Point lot and ledger handling
- Paid/free point distinction, with paid points intended to have no expiration and free points expiring
- Probability version management and ppm-based probability handling
- Minimum guarantee handling
- Public top page, gacha detail page, draw animation flow, draw result UI
- Rank asset settings for reusable rank images/videos
- Profit simulation features
- Image/video upload flow through Laravel storage
- Mailgun mail sending configuration via Laravel Mail
- Discord notification services and daily sales report command
- 404 and error pages for user/admin side
- Nginx domains for `luxe-pack.biz` and `admin.luxe-pack.biz`

## Not Yet Complete / Needs Careful Review

- Real production payment provider integration and webhook hardening
- Full production QA pass
- Legal/accounting confirmation around paid point handling and funds settlement obligations
- Production storage migration strategy if moving uploaded assets from local/MinIO-compatible storage to AWS S3
- PWA and push notification strategy, pending business decision
- Wider concurrency/load testing for draw and point operations

## Recently Active Work

- Backend Docker image was rebuilt and only the backend container was recreated to apply PHP upload limits.
- Running PHP settings now show:
  - `upload_max_filesize = 64M`
  - `post_max_size = 72M`
- Application upload limits shown in admin UI:
  - images: 5MB
  - videos: 50MB
- Public gacha detail page recently gained:
  - recommended gacha section
  - PC/tablet 2-column prize grid
  - mobile 1-column prize grid
  - prize probability display removed
- Public top gacha cards were updated toward the reference design.
- Public header logo now loads `/logo.png` directly.

## Frontend Items That May Need Future Work

- Admin UI polish and spacing consistency
- Public page visual tuning against reference sites
- Public gacha detail layout refinements
- Admin forms and validation message display improvements
- Responsive checks for mobile/tablet/desktop
- Better empty states where test data is sparse
- Client-side loading states and navigation feedback
- Upload field UX and preview improvements

Frontend Sub Codex may work on these only inside `frontend/`.

## Important Domain Rules

- Lottery logic belongs only in Laravel.
- Do not put lottery logic in Next.js.
- Do not use frontend random logic for lottery.
- Point consumption belongs only in Laravel.
- Probability calculation belongs only in Laravel.
- Payment webhooks belong only in Laravel.
- There is no complete no-prize result.
- Minimum guarantee is required.
- Two-stage probability display/management exists.
- Published probability versions are immutable.
- Probability is represented as ppm integers.
- `1,000,000 ppm = 100%`.
- `draw_sequence_number` must be generated under DB lock and must not duplicate or gap per gacha.
- Frontend Sub Codex must not touch anything outside `frontend/`.

## Docker Operation Rules

The server has previously become heavy/hung from broad Docker operations. Follow these rules:

- Do not run `docker compose up -d --build` for all services.
- Before Docker work, check:
  - `free -h`
  - `df -h`
  - `docker system df`
  - `docker compose ps`
- Start or recreate services in small steps.
- Preferred order when starting services:
  - postgres / redis / mailpit
  - backend
  - nginx
  - frontend
  - minio
- Logs must use:
  - `docker compose logs --tail=100 <service>`
- Heavy commands such as `npm install`, `pnpm install`, `composer install`, `docker build`, and Next.js build require explaining the command and purpose before execution.
- Do not run `docker system prune -a --volumes` without explicit permission.
- Frontend Sub Codex must not run Docker commands. Ask Main Codex or the human operator.

## Known Issues / Operational Notes

- `AGENTS.md` references `docs/md/00_spec_v1.4.md`, but the actual existing file is `docs/md/spec_v1.4.md`.
- `docker compose build backend` may fail with `unknown flag: --allow` due to Docker Compose/Buildx Bake behavior. Main Codex used `COMPOSE_BAKE=false docker compose build backend` successfully.
- The active environment uses Next.js dev server, so `_next` HMR/WebSocket warnings can appear before production build/deployment.
- The frontend and admin domain can return 502 if the `frontend` container is stopped.
- Next.js image optimization cache can return stale optimized images; the public header logo was changed to load `/logo.png` directly.

## Next Things To Do

- Keep frontend-only work isolated to `frontend/`.
- If UI work reveals missing API fields or backend behavior, Frontend Sub Codex should record it in `worklogs/codex-frontend.md` and ask Main Codex.
- Before production release, run a full QA pass against the QA checklist and review legal/payment/storage operations.
- Decide whether to implement PWA and push notifications after business policy is finalized.

