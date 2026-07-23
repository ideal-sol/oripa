# Oripa Platform Repository

This repository follows `docs/md/spec_v1.4.md` as the master specification.

Repository構造は次の責任境界へ分離している。

- `apps/api/`: Laravel API
- `apps/admin/`: V2 Admin Next.js Skeleton
- `packages/*`: V2 First-party Package Skeleton
- `legacy/v1-frontend/`: V1 Next.js App Router reference
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

V2で未実装:

- Lottery logic
- Payment webhook handling
- Point ledger and point lots
- Probability versions
- Admin business flow、Auth、MFA、API接続
- First-party Package API

## V2 Workspace Skeleton

Root pnpm Workspaceは`apps/admin`と`packages/*`だけを対象とする。
`legacy/v1-frontend`は独立Lockfileを維持し、`apps/api`もpnpm Workspaceへ含めない。

```bash
pnpm install --frozen-lockfile
pnpm admin:typecheck
pnpm admin:lint
pnpm admin:build
```

非ProductionのV2構造Smoke TestはTask固有Project名で実行する。

```bash
docker compose -p oripa-v2-skeleton -f docker-compose.v2.yml up --build --wait -d
docker compose -p oripa-v2-skeleton -f docker-compose.v2.yml exec -T api \
  curl --fail --silent http://localhost:8000/api/health
docker compose -p oripa-v2-skeleton -f docker-compose.v2.yml exec -T admin \
  wget --quiet --output-document=- http://localhost:3000/api/health
docker compose -p oripa-v2-skeleton -f docker-compose.v2.yml down -v
```

このSkeletonはProduction利用不可であり、Business Logicや実Credentialを含まない。

## Requirements

Install Docker and Docker Compose on the host.

The host does not need PHP, Composer, Node.js, pnpm, PostgreSQL, Redis, or MinIO. Those run in containers.

## Setup

```bash
cp .env.example .env
cp apps/api/.env.example apps/api/.env
cp legacy/v1-frontend/.env.example legacy/v1-frontend/.env.local
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
