# 01 — Platform skeleton

| | |
| --- | --- |
| Owner | Platform |
| Depends on | nothing (contracts.md merged) |
| Blocks | every other ticket |
| Branch | `feat/platform-skeleton` off `build/v1` |
| Worktree env | `bin/worktree-env 01` → `COMPOSE_PROJECT_NAME=dorsia-01`, ports 8100 / 8180 / 5532 / 6479 |
| Build plan | §1 (skeleton part of step 1), §3 conventions, §4 idempotency-key rule, §6 outbox rule ("written in the same transaction"), §7 local column, §8 boundaries, conventions, testing pyramid |
| Contracts | C§1, C§2, C§3 (Shared tables + migration prefixes), C§4, C§8 (envelopes, idempotency, auth endpoints), C§9, C§12 (`idempotency.*`), C§13 (`idempotency:prune`), C§14, C§15, D4, D5, D6, D17, D27 |

## Files you may touch

- Repo root: `composer.json`, `composer.lock`, `artisan`, `Dockerfile`, `docker-compose.yml`, `Makefile`, `.env.example`, `.gitignore`, `.dockerignore`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `bin/worktree-env`
- `bootstrap/**`, `config/**`, `public/**`, `routes/**`, `storage/**` (gitkeeps), `resources/**` (delete what is unused)
- `docker/**`, `.github/workflows/ci.yml`
- `app/Providers/**`, `app/Shared/**`
- `app/Modules/*/` **skeleton only**: `<Name>ServiceProvider.php`, `config.php` (returning `[]`), `routes/api.php` (empty group), `Database/Migrations/.gitkeep`
- `app/Modules/Inventory/Events/AvailabilitySearched.php`, `app/Modules/Inventory/Data/SelloutStat.php`, `app/Modules/Inventory/Actions/GetSelloutStats.php` (contract stubs, C§5.1)
- `database/migrations/**`, `database/factories/MemberFactory.php`, `database/seeders/DatabaseSeeder.php`, `database/seeders/MemberSeeder.php`
- `tests/Pest.php`, `tests/TestCase.php`, `tests/Support/**`, `tests/Arch/ModuleBoundariesTest.php`, `tests/Shared/**`

Nothing else. Do not create `loadtest/` contents.

## Non-goals

Memberships, coupon grants, admin API or staff guard, Expo, Stripe, Telescope, Horizon, production deploy, rate limiting. No module business logic.

## Work

1. `laravel/laravel` v13 skeleton, API-only: remove web views/Vite/`routes/web.php` content except `/up`. Delete default `users`, `cache`, `jobs` migrations. Install every package in C§1 (`octane`, `reverb`, `sanctum`, `spatie/fork` dev, `pest` 5 + laravel plugin, `larastan`, `pint`). Run `octane:install --server=frankenphp`, `reverb:install`, `install:api` and keep only what is needed.
2. `Dockerfile` from `dunglas/frankenphp:1.13.0-php8.4-bookworm`, extensions per C§1, Composer binary, workdir `/app`. `docker-compose.yml` exactly per C§14 (service names, host-port vars, healthchecks, `outbox` under `profiles: [realtime]`, `k6` under `profiles: [loadtest]`, no `container_name`). `docker/postgres/init.sql` creates `dorsia_test` owned by `dorsia`.
3. `bin/worktree-env <NN>`: POSIX shell; copies `.env.example` to `.env` if absent, then sets `COMPOSE_PROJECT_NAME` and the four port vars per C§14; refuses NN outside `00`–`09`; idempotent.
4. `Makefile` targets exactly as C§14. `make ci` exits non-zero on the first failure.
5. `phpstan.neon`: Larastan, level 8, paths `app database routes config`. `pint.json`: `laravel` preset + `declare_strict_types: true`.
6. `.github/workflows/ci.yml`: on push/PR to `build/v1` and `main`: `cp .env.example .env`, `make setup`, `make ci`.
7. `app/Shared` per C§4: `Money`, `Currency`, `CurrencyMismatchException`, `IdempotencyKey`, `EnsureIdempotency` (alias `idempotent`) + `IdempotencyRecord` model + four exceptions, `Outbox`, `OutboxTopic`, `OutboxEvent`, `DomainError`, `Transactions` (D17), `ModuleServiceProvider` (C§2), `Members\Member`, `Members\Http\AuthTokenController` (`POST /api/auth/token`), `Members\Http\MeController` (`GET /api/me`), `idempotency:prune` command + schedule.
8. Exception rendering in `bootstrap/app.php`: every `DomainError` → C§8 envelope; framework exceptions → C§9 codes; `server_error` otherwise.
9. `AppServiceProvider`: `Date::use(CarbonImmutable::class)`, `Model::shouldBeStrict()` outside production, `DB::prohibitDestructiveCommands()` in production.
10. Six module skeletons registered in `bootstrap/providers.php`; the three Inventory contract stubs.
11. Shared migrations under prefix `2026_10_01_`: `members`, `personal_access_tokens`, `failed_jobs`, `idempotency_keys`, `outbox_events` (C§3, including the partial index).
12. `DatabaseSeeder` (guarded calls per C§15), `MemberSeeder` (demo member `demo@dorsia.test` / `password`), `MemberFactory`.
13. `tests/Pest.php`, `tests/TestCase.php` (sets `Transactions::$baseLevel`), `tests/Support/Concurrency.php` (C§15), `tests/Arch/ModuleBoundariesTest.php` (every rule in C§2's boundary list, including the dependency table, strict types, no debug functions, DTO `final readonly`).

## Acceptance criteria

- [ ] On a clean clone: `bin/worktree-env 01 && make setup` finishes, then `curl -fsS localhost:8100/up` returns 200.
- [ ] Two worktrees (`01` and `02`) run `make up` at the same time with no port or container-name conflict (`docker compose ls` shows `dorsia-01` and `dorsia-02`).
- [ ] `make ci` is green: Pint clean, PHPStan level 8 with zero errors and no baseline file, all tests pass.
- [ ] `make arch` fails if a deliberately added `use App\Modules\Wallet\Models\X;` appears in `app/Modules/Inventory` (prove with a throwaway local check; do not commit it), and fails if `App\Shared` imports `App\Modules`.
- [ ] `php artisan migrate:fresh --seed` creates exactly the Shared tables plus Laravel/Sanctum ones; `\d outbox_events` shows `outbox_events_unpublished_idx ... WHERE published_at IS NULL`.
- [ ] `POST /api/auth/token` with demo credentials → 201 with a token; that token on `GET /api/me` → 200 member JSON; bad password → 401 `invalid_credentials`; no token on `/api/me` → 401 `unauthenticated` envelope.
- [ ] Idempotency middleware behaves exactly as C§8 (all bullets), on a test-only route registered inside the test.
- [ ] `Outbox::record()` outside a transaction throws `LogicException`; inside a rolled-back transaction leaves no row; inside a committed one leaves exactly one row with the given topic and payload.
- [ ] `Money` rejects negative amounts and mixed currencies; `bps()` floors.
- [ ] `Concurrency::requests(20, ...)` really runs in parallel: a self-test where 20 children each insert into a scratch table under `pg_sleep(0.5)` finishes in under 5 s and leaves 20 rows.
- [ ] `Transactions::assertActive()`/`assertNone()` behave per D17 in Feature tests and plain code.
- [ ] CI workflow file is valid (`act`-free check: `docker run --rm -v $PWD:/repo rhysd/actionlint:latest -color /repo/.github/workflows/ci.yml` passes).

## Required tests

- `tests/Shared/Unit/MoneyTest.php`: construction, negative rejection, add/subtract/multiply/min/bps, mismatch, equals/compare, `toArray`.
- `tests/Shared/Unit/IdempotencyKeyTest.php`: valid/invalid formats, length 200 vs 201, `scoped()`.
- `tests/Shared/Feature/IdempotencyMiddlewareTest.php`: missing key 400; invalid key 400; first call stored; same key+body replays status/body + `Idempotent-Replayed: true` and the handler ran once; same key different body 422 `idempotency_mismatch`; same key from a *different member* is independent; 5xx response is not stored and the retry re-executes; canonical JSON (key order differences hash equal); stale `processing` row (> 30 s) is taken over; fresh `processing` row → 409 `idempotency_in_progress` with `Retry-After: 1`; prune command deletes rows older than 24 h only.
- `tests/Shared/Concurrency/IdempotencyRaceTest.php`: 20 parallel identical requests with one key on a test route whose handler sleeps 300 ms and inserts a row → exactly one row inserted; every response is either the stored 201 or 409 `idempotency_in_progress`; zero 5xx.
- `tests/Shared/Feature/OutboxTest.php`, `tests/Shared/Feature/AuthTest.php`, `tests/Shared/Feature/ErrorEnvelopeTest.php` (validation, 404 route, 405, unexpected exception → `server_error` without trace when debug off).
- `tests/Shared/Concurrency/ConcurrencyHelperTest.php` (the parallelism self-test above).
- `tests/Arch/ModuleBoundariesTest.php`.

## Eval agent will check

- Fresh clone → `bin/worktree-env 01` → `make setup` → `make ci`, timing nothing, failing on any warning-level PHPStan error.
- Opens `docker-compose.yml` and confirms: service names, no `container_name`, port vars, healthchecks, profiles.
- Runs two worktrees side by side.
- Reads `ModuleBoundariesTest` and checks every rule from C§2 is encoded, including the dependency table, not just the internals list.
- Mutation-style probes: removes the hash comparison in the middleware and expects a test to fail; removes the transaction assertion in `Outbox::record` and expects a test to fail.
- Confirms the three Inventory stubs match C§5.1 signatures byte for byte.
- Confirms no business tables, no module logic and nothing from the non-goals list.
