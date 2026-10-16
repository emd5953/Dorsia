EXEC = docker compose exec -T app
RUN = docker compose run --rm --no-deps -T app
ARGS ?=

.PHONY: setup up down fresh test test-module arch stan pint pint-fix ci

setup:
	@test -f .env || cp .env.example .env
	docker compose build
	$(RUN) composer install --no-interaction --prefer-dist
	@grep -q '^APP_KEY=' .env || echo 'APP_KEY=' >> .env
	@grep -q '^APP_KEY=.\+' .env || $(RUN) php artisan key:generate
	$(MAKE) up
	$(EXEC) php artisan migrate:fresh --seed --force

up:
	docker compose up -d --wait

down:
	docker compose down

fresh:
	$(EXEC) php artisan migrate:fresh --seed --force

test:
	$(EXEC) php artisan test $(ARGS)

test-module:
	@test -n "$(M)" || { echo "usage: make test-module M=<Module>"; exit 1; }
	$(EXEC) vendor/bin/pest tests/Modules/$(M) tests/Arch/Contracts/$(M)ContractTest.php

arch:
	$(EXEC) vendor/bin/pest tests/Arch

stan:
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G

pint:
	$(EXEC) vendor/bin/pint --test

pint-fix:
	$(EXEC) vendor/bin/pint

ci:
	$(MAKE) pint
	$(MAKE) stan
	$(MAKE) test
