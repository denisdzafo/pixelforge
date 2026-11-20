# Thin wrappers around docker compose. Nothing here is required: `docker compose up -d` alone starts everything.
.PHONY: setup up down restart logs ps shell migrate fresh test analyse lint typecheck build

setup: up

up:
	docker compose up -d --build

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

ps:
	docker compose ps

shell:
	docker compose exec app bash

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app composer test

analyse:
	docker compose exec app composer analyse

lint:
	docker compose exec node npm run lint

typecheck:
	docker compose exec node npm run typecheck

build:
	docker compose exec node npm run build
