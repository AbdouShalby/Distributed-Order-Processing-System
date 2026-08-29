.PHONY: up down build restart logs fresh seed test test-integration integration-down test-scheduler scheduler-down lint worker

# ==========================================
# Docker Commands
# ==========================================

up:
	docker compose up -d --wait

down:
	docker compose down

build:
	docker compose build --no-cache

restart:
	docker compose restart

logs:
	docker compose logs -f

logs-app:
	docker compose logs -f app

logs-worker:
	docker compose logs -f worker

logs-scheduler:
	docker compose logs -f scheduler

logs-reverb:
	docker compose logs -f reverb

# ==========================================
# Application Commands
# ==========================================

install:
	docker compose exec app composer install

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

seed:
	docker compose exec app php artisan db:seed

# ==========================================
# Development
# ==========================================

tinker:
	docker compose exec app php artisan tinker

shell:
	docker compose exec app sh

worker:
	docker compose exec app php artisan queue:work redis --tries=3 --backoff=1,3,5

# ==========================================
# Testing
# ==========================================

test:
	docker compose exec app php artisan test

test-unit:
	docker compose exec app php artisan test --testsuite=Unit

test-feature:
	docker compose exec app php artisan test --testsuite=Feature

test-integration:
	docker compose -p dops-m2 -f docker-compose.integration.yml up --build --abort-on-container-exit --exit-code-from test

integration-down:
	docker compose -p dops-m2 -f docker-compose.integration.yml down -v

test-scheduler:
	docker compose -p dops-m2a3 -f docker-compose.integration.yml --profile scheduler-verification up --build --abort-on-container-exit --exit-code-from scheduler-test scheduler scheduler-test

scheduler-down:
	docker compose -p dops-m2a3 -f docker-compose.integration.yml --profile scheduler-verification down -v

lint:
	docker compose exec app ./vendor/bin/pint

lint-check:
	docker compose exec app ./vendor/bin/pint --test

# ==========================================
# Health
# ==========================================

health:
	curl -s http://localhost:8000/api/health | jq .

# ==========================================
# Setup (first time)
# ==========================================

setup: build
	docker compose run --rm --no-deps --user 0 app chown 1000:1000 /var/www/vendor
	docker compose run --rm --no-deps app composer install --no-interaction
	docker compose up -d --wait
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate:fresh --seed
	@echo "✅ Setup complete! API available at http://localhost:8000"
