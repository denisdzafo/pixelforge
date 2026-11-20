.PHONY: setup up down restart logs ps shell migrate fresh test test-js analyse pint lint typecheck build check

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
	docker compose exec app php artisan test

test-js:
	docker compose exec node npm test

analyse:
	docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G

pint:
	docker compose exec app vendor/bin/pint --parallel

lint:
	docker compose exec node npm run check:fix

typecheck:
	docker compose exec node npm run types:check

build:
	docker compose exec node npm run build

check: test test-js analyse typecheck
	docker compose exec app vendor/bin/pint --parallel --test
	docker compose exec node npm run check
