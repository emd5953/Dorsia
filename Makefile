EXEC = docker compose exec -T app
RUN = docker compose run --rm --no-deps -T app
ARGS ?=

.PHONY: setup up down fresh test test-module arch stan pint pint-fix ci drop drop-seed drop-run drop-verify

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

drop: drop-seed drop-run drop-verify

drop-seed:
	$(EXEC) php artisan migrate:fresh --force
	$(EXEC) php artisan db:seed --class=Database\\Seeders\\DropTestSeeder --force

drop-run:
	@mkdir -p loadtest/results
	docker compose --profile loadtest run --rm k6 run /loadtest/drop.js

drop-verify:
	@set -eu; \
	for attempt in $$(seq 1 60); do \
	  pending=$$(docker compose exec -T redis redis-cli LLEN dorsia:queues:default); \
	  delayed=$$(docker compose exec -T redis redis-cli ZCARD dorsia:queues:default:delayed); \
	  reserved=$$(docker compose exec -T redis redis-cli ZCARD dorsia:queues:default:reserved); \
	  if [ "$$pending" = 0 ] && [ "$$delayed" = 0 ] && [ "$$reserved" = 0 ]; then break; fi; \
	  if [ "$$attempt" = 60 ]; then echo "default queue did not drain within 60 seconds"; exit 1; fi; \
	  sleep 1; \
	done; \
	slot_id=$$(docker compose exec -T app php -r '$$d=json_decode(file_get_contents("loadtest/.data/drop.json"), true, 512, JSON_THROW_ON_ERROR); echo $$d["slot_id"];'); \
	docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U dorsia -d dorsia -v slot_id=$$slot_id < loadtest/verify.sql; \
	captured=$$(docker compose exec -T postgres psql -At -U dorsia -d dorsia -c "SELECT count(*) FROM payments WHERE status = 'captured'"); \
	charges=$$(docker compose exec -T redis redis-cli HLEN dorsia:fakegw:charges); \
	if [ "$$captured" != "$$charges" ]; then echo "fake gateway charges ($$charges) differ from captured payments ($$captured)"; exit 1; fi; \
	$(EXEC) php artisan wallet:reconcile
