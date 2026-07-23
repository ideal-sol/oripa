# Oripa Local Development Environment

This repository follows `docs/md/spec_v1.4.md` as the master specification.

The local environment is intentionally split into:

- `apps/api/`: Laravel API
- `frontend/`: Next.js App Router
- PostgreSQL
- Redis
- MinIO
- Mailpit

Lottery, point, wallet, inventory, probability version, draw, and payment webhook logic must be implemented in Laravel. Next.js is only for UI and API calls.

## Current Scope

Implemented in this setup task:

- Docker Compose services
- Laravel API skeleton
- `GET /api/health`
- Next.js skeleton
- Next.js page that calls the Laravel health API
- PostgreSQL / Redis / MinIO / Mailpit wiring
- Makefile commands
- Environment examples
- Domain directory placeholders for later Laravel implementation

Not implemented yet:

- Lottery logic
- Payment webhook handling
- Point ledger and point lots
- Probability versions
- Admin UI or admin business flows

## Requirements

Install Docker and Docker Compose on the host.

The host does not need PHP, Composer, Node.js, pnpm, PostgreSQL, Redis, or MinIO. Those run in containers.

## Setup

```bash
cp .env.example .env
cp apps/api/.env.example apps/api/.env
cp frontend/.env.example frontend/.env.local
COMPOSE_BAKE=false docker compose build
docker compose run --rm backend php artisan key:generate
docker compose up -d
docker compose exec backend php artisan migrate
```

Or use Makefile:

```bash
make setup
docker compose run --rm backend php artisan key:generate
make up
make migrate
```

## URLs

- Frontend: http://localhost:3000
- Laravel API health: http://localhost:8000/api/health
- Mailpit: http://localhost:8025
- MinIO Console: http://localhost:9001

MinIO local credentials:

- User: `minio`
- Password: `minio_password`
- Bucket: `oripa-local`

## Common Commands

```bash
make up
make down
make logs
make ps
make migrate
make backend-test
make frontend-lint
make frontend-typecheck
make health
```

Manual checks:

```bash
curl http://localhost:8000/api/health
curl http://localhost:3000/api/health
docker compose ps
docker compose exec backend php artisan test
docker compose exec frontend pnpm lint
docker compose exec frontend pnpm typecheck
```

## Laravel Notes

The Laravel API uses:

- PostgreSQL via `DB_CONNECTION=pgsql`
- Redis for cache, queue, and session
- MinIO through the S3 filesystem disk
- Mailpit through SMTP

Domain placeholders are under `apps/api/app/Domain`.

Business logic must be added to Laravel service/action classes, not controllers and not Next.js.

## Next.js Notes

The frontend reads:

```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
INTERNAL_API_BASE_URL=http://backend:8000/api
```

`INTERNAL_API_BASE_URL` is used by server-side rendering inside Docker. Browser-facing API calls should use `NEXT_PUBLIC_API_BASE_URL`.

## Specification Constraints

- Do not implement lottery logic in Next.js.
- Do not use frontend random logic.
- Do not use `Math.random` for lottery.
- Use Laravel CSPRNG for future lottery implementation.
- Store probabilities as integer ppm.
- A stage must total exactly `1,000,000ppm`, including the minimum guarantee row.
- Do not implement `no_prize`.
- `result_type` must be `prize` or `point_back`.
- Generate `draw_sequence_number` under DB lock.
- Published probability versions must be immutable.
- Do not commit `.env`, secrets, `vendor`, `node_modules`, or local volume data.

## Troubleshooting

If dependencies are missing inside a container:

```bash
docker compose run --rm backend composer install
docker compose run --rm -e CI=true frontend pnpm install --force
```

If MinIO storage health is `error`, confirm the bucket exists:

```bash
docker compose run --rm minio-init
```

If ports are already in use, edit `.env` and change `BACKEND_PORT`, `FRONTEND_PORT`, `MAILPIT_UI_PORT`, or `MINIO_CONSOLE_PORT`.
