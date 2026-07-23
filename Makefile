.PHONY: setup build up down restart logs ps migrate test backend-test frontend-lint frontend-typecheck health shell-backend shell-frontend

setup:
	cp .env.example .env || true
	cp apps/api/.env.example apps/api/.env || true
	cp legacy/v1-frontend/.env.example legacy/v1-frontend/.env.local || true
	COMPOSE_BAKE=false docker compose build
	docker compose run --rm backend composer install
	docker compose run --rm -e CI=true frontend pnpm install --force

build:
	COMPOSE_BAKE=false docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down
	docker compose up -d

logs:
	docker compose logs -f --tail=200

ps:
	docker compose ps

migrate:
	docker compose exec backend php artisan migrate

test: backend-test frontend-lint frontend-typecheck

backend-test:
	docker compose exec backend php artisan test

frontend-lint:
	docker compose exec frontend pnpm lint

frontend-typecheck:
	docker compose exec frontend pnpm typecheck

health:
	curl -s http://localhost:8000/api/health
	curl -s http://localhost:3000/api/health

shell-backend:
	docker compose exec backend bash

shell-frontend:
	docker compose exec frontend sh
