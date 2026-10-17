# Module Contracts

*Binding interface for build steps 1–8. Written October 16, 2026.*

This file is the contract between the six engineers. If code and this file disagree, the code is wrong. Any change goes through the architect as a contract change: edit this file in its own commit on `build/v1`, then notify affected owners. Section numbers like "§4" refer to [build_plan.md](../build_plan.md); "C§" refers to sections of this file.

---

## C§1. Pinned versions

Verified against Packagist and Docker Hub on 2026-10-16.

| Component | Pin | Notes |
| --- | --- | --- |
| PHP | 8.4 (8.4.26 in image) | Pest 5 requires `^8.4`; Laravel 13 requires `^8.3` |
| Base image | `dunglas/frankenphp:1.13.0-php8.4-bookworm` | ZTS build; `pcntl_fork` verified working |
| PHP extensions | `pdo_pgsql pgsql redis pcntl sockets intl bcmath zip opcache` | via `install-php-extensions` in the image |
| Laravel | `laravel/framework:^13.35` (skeleton `laravel/laravel` v13.11.0) | 13.37.0 is latest at writing |
| Octane | `laravel/octane:^2.22`, server **FrankenPHP** | `OCTANE_SERVER=frankenphp` |
| Sanctum | `laravel/sanctum:^4.3` | member API tokens |
| Reverb | `laravel/reverb:^1.12` | Pusher protocol, local only |
| Pest | `pestphp/pest:^5.3`, `pestphp/pest-plugin-laravel:^5.0` | arch plugin is bundled with Pest 5 |
| Larastan | `larastan/larastan:^3.13` (PHPStan 2.3) | level 8 |
| Pint | `laravel/pint:^1.32` | |
| Fork (tests only) | `spatie/fork:^1.2.8` (require-dev) | concurrency tests, C§12 |
| Postgres | `postgres:16.15-bookworm` | |
| Redis | `redis:7.4.11-bookworm` | |
| k6 | `grafana/k6:1.8.2` | |
| Docker Compose | v2 file format, no `version:` key | tested with Compose v5.1 |

Only Platform edits `composer.json` / `composer.lock`. Every package above is installed in ticket 01; nobody else adds packages without a contract change.

---

## C§2. Directory layout and module wiring

```
app/
  Providers/AppServiceProvider.php          Platform
  Shared/                                   Platform
    Money/            Money, Currency, CurrencyMismatchException
    Idempotency/      IdempotencyKey, EnsureIdempotency middleware, IdempotencyRecord model, exceptions
    Outbox/           Outbox, OutboxTopic, OutboxEvent model
    Exceptions/       DomainError base, Handler rendering helpers
    Members/          Member model, AuthTokenController, MeController
    Support/          ModuleServiceProvider base, Transactions
  Modules/
    Inventory/  Pricing/  Reservations/  Wallet/  Payments/  Realtime/
      <Name>ServiceProvider.php
      config.php                merged into config('<name lowercased>')
      routes/api.php            loaded under prefix /api, middleware ['api']
      Actions/        PUBLIC    one class per use case, method handle()
      Data/           PUBLIC    final readonly DTOs
      Enums/          PUBLIC
      Events/         PUBLIC    in-process domain events
      Exceptions/     PUBLIC
      Contracts/      PUBLIC    interfaces (Payments only)
      Models/         internal  Eloquent models
      Http/           internal  Controllers/, Requests/, Resources/
      Console/        internal  artisan commands
      Jobs/           internal
      Listeners/      internal
      Support/        internal
      Database/
        Migrations/   internal
        Factories/    internal to app code; tests and seeders may use them
        Seeders/      internal to app code; DatabaseSeeder may call them
database/
  migrations/       Platform (Shared tables)
  factories/MemberFactory.php                Platform
  seeders/DatabaseSeeder.php, MemberSeeder.php   Platform
  seeders/DropTestSeeder.php                 Performance
tests/
  Pest.php, TestCase.php, Support/           Platform
  Arch/ModuleBoundariesTest.php              Platform
  Arch/Contracts/<Module>ContractTest.php    owning engineer
  Shared/{Unit,Feature,Concurrency}/         Platform
  Modules/<Module>/{Unit,Feature,Concurrency}/   owning engineer
  Performance/                               Performance
loadtest/                                    Performance (except realtime-smoke.js: Payments & real-time)
docker/, Dockerfile, docker-compose.yml, Makefile, bin/, .github/, phpunit.xml, phpstan.neon, pint.json, .env.example   Platform
```

**Module wiring.** Each `<Name>ServiceProvider` extends `App\Shared\Support\ModuleServiceProvider`, which in `boot()`:

1. loads `routes/api.php` with `Route::prefix('api')->middleware('api')->group(...)`;
2. calls `loadMigrationsFrom(<module>/Database/Migrations)`;
3. `mergeConfigFrom(<module>/config.php, <key>)` (done in `register()`);
4. calls the overridable hooks `consoleCommands(): array<class-string>` (not `commands()`, which Laravel's `ServiceProvider` already defines), `schedule(Schedule $s): void` (registered via `callAfterResolving(Schedule::class, ...)`), and `listeners(): array<class-string, list<class-string>>`.

A module provider that overrides `boot()` or `register()` must call `parent::boot()` / `parent::register()`.

All six providers are listed in `bootstrap/providers.php` by Platform in ticket 01, with empty skeletons, so no later ticket edits a shared file. Config keys: `inventory`, `pricing`, `reservations`, `wallet`, `payments`, `realtime`.

**Boundary rules (enforced by `tests/Arch/ModuleBoundariesTest.php`).**

- Code in `App\Modules\X` may use another module `Y` only through `Y\Actions`, `Y\Data`, `Y\Enums`, `Y\Events`, `Y\Exceptions`, `Y\Contracts`. Never `Y\Models`, `Y\Http`, `Y\Console`, `Y\Jobs`, `Y\Listeners`, `Y\Support`, `Y\Database`.
- Allowed module dependencies (anything not listed is forbidden):

  | Module | May use |
  | --- | --- |
  | Inventory | Shared |
  | Pricing | Shared, Inventory |
  | Wallet | Shared |
  | Payments | Shared |
  | Realtime | Shared |
  | Reservations | Shared, Inventory, Pricing, Wallet, Payments |

- `App\Shared` must not use `App\Modules`.
- Every file in `app/` declares `strict_types=1`. No `dd`, `dump`, `var_dump`, `ray`, `env()` outside `config/` and module `config.php`.
- DTOs in `Data/` are `final readonly`. Action classes are **not** final (so other modules' tests can mock them) and expose exactly one public method, `handle()`.
- No cross-module Eloquent relations, no cross-module `exists:` validation rules, no cross-module raw SQL. Cross-module references are plain `bigint` ids.
- Foreign keys exist only within a module and to `members.id`.

Only `tests/` and the root `database/seeders/` are exempt from the boundary rule; they may use any module's factories and public actions. Module `Database/Seeders` and `Database/Factories` live under `app/Modules` and **are** scanned like any other module code, so they may reach other modules only through public layers and the dependency table.

The arch test's table scan is a case-insensitive text match over code and string literals (comments are stripped). Prose such as `"could not update payments"` or `"... from holds"` inside another module's code fails it, as does any mention of the `members` table outside Shared. Word messages and log lines so they don't put `from`/`join`/`into`/`update` directly before another module's table name.

---

## C§3. Table ownership and schema

Conventions for every table:

- Primary key `id bigint generated by default as identity` (Laravel `$table->id()`).
- Timestamps are **`timestamptz(6)`** (microseconds), stored UTC: `$table->timestampsTz(6)`, `$table->timestampTz('x', 6)`, `$table->softDeletesTz(precision: 6)`. Laravel's default is second precision, which breaks "older than this run" comparisons and same-second ordering.
- Every Eloquent model sets `protected $dateFormat = 'Y-m-d H:i:s.uP';` and every timestamp bound into a query-builder `where`/`insert`/`update` or raw SQL is passed as `->utc()->format('Y-m-d H:i:s.uP')` (constant `App\Shared\Support\Timestamps::FORMAT`, added by Platform). Otherwise Laravel truncates bindings to seconds even on a microsecond column.
- Money is `bigint` cents with a `currency char(3)` column on the same row (or on the parent row where noted).
- Status columns are `varchar` with a `CHECK (status IN (...))`, never Postgres enums (easier migrations).
- **Never call SQL `now()`/`CURRENT_TIMESTAMP` in application queries.** Pass PHP `now()` as a bound parameter so Pest time travel works. Column defaults may use `now()`.
- CHECKs, partial indexes and triggers are written with `DB::statement` / `DB::unprepared` in the migration that creates the table.

### Migration filename prefixes

Laravel orders migrations by filename across all paths. Each owner uses only its prefix; within it, increment the time part.

| Owner | Prefix | Example |
| --- | --- | --- |
| Platform (Shared, `database/migrations`) | `2026_10_01_` | `2026_10_01_000100_create_members_table.php` |
| Inventory | `2026_10_02_` | `2026_10_02_000100_create_cities_table.php` |
| Pricing | `2026_10_03_` | |
| Wallet | `2026_10_04_` | |
| Payments | `2026_10_05_` | |
| Reservations | `2026_10_06_` | |
| Realtime | `2026_10_07_` | (none expected) |

Laravel's default `0001_01_01_*` migrations are deleted by Platform; `failed_jobs` and Sanctum's `personal_access_tokens` are recreated under the Platform prefix.

### Shared (Platform)

**members**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| name | varchar(255) | |
| email | varchar(255) | unique |
| password | varchar(255) | bcrypt |
| created_at, updated_at | timestamptz | |

No `tier` column in this run (memberships are step 9).

**personal_access_tokens** — Sanctum's standard schema, `tokenable` morph to `members`.

**failed_jobs** — Laravel standard (`uuid` unique).

**idempotency_keys**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| member_id | bigint not null | FK members |
| key | varchar(200) not null | header value |
| method | varchar(8) not null | |
| path | varchar(255) not null | request path incl. route params |
| request_hash | char(64) not null | sha256, C§8 |
| status | varchar(16) not null | CHECK in (`processing`,`completed`) |
| response_status | smallint null | |
| response_body | text null | exact response bytes, never re-encoded |
| response_content_type | varchar(100) null | `Content-Type` header of the stored response |
| lock_token | uuid not null | owner of the current execution; new value on every takeover |
| locked_at | timestamptz not null | when processing began |
| completed_at | timestamptz null | |
| created_at | timestamptz not null | |

Unique `(member_id, key)`. Index `(created_at)` for pruning.

**outbox_events**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | publish order |
| topic | varchar(64) not null | an `OutboxTopic` value |
| payload | jsonb not null | C§7 shapes |
| attempts | integer not null default 0 | |
| last_error | text null | |
| created_at | timestamptz not null | |
| published_at | timestamptz null | |

Partial index `outbox_events_unpublished_idx ON outbox_events (id) WHERE published_at IS NULL`. Index `(published_at)` for pruning.

### Inventory (Booking)

**cities**: `id`, `name varchar(120)`, `slug varchar(120) unique`, `country_code char(2)`, timestamps.

**venues**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| city_id | bigint not null | FK cities |
| name | varchar(255) | |
| slug | varchar(255) | unique |
| timezone | varchar(64) not null | IANA name; CHECK `timezone <> ''`; validated against `DateTimeZone::listIdentifiers()` in the factory/seeder |
| status | varchar(16) not null default `active` | CHECK in (`active`,`inactive`) |
| base_per_person_cents | bigint not null | CHECK `> 0`; Pricing's base input |
| currency | char(3) not null default `USD` | |
| created_at, updated_at | timestamptz | |

Index `(city_id)`.

**drops**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| venue_id | bigint not null | FK venues |
| opens_at | timestamptz not null | slots in the drop have `released_at = opens_at` |
| opened_at | timestamptz null | set when `drop.opened` is written |
| sold_out_at | timestamptz null | set when every slot in the drop is fully booked |
| created_at, updated_at | timestamptz | |

Partial index `(opens_at) WHERE opened_at IS NULL`.

**slots**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| venue_id | bigint not null | FK venues |
| drop_id | bigint null | FK drops |
| starts_at | timestamptz not null | |
| capacity | integer not null | number of identical tables; CHECK `> 0` |
| held_count | integer not null default 0 | CHECK `>= 0` |
| booked_count | integer not null default 0 | CHECK `>= 0`; CHECK `booked_count <= capacity` |
| min_party_size | smallint not null | CHECK `min_party_size >= 1` |
| max_party_size | smallint not null | CHECK `max_party_size >= min_party_size` |
| released_at | timestamptz not null | bookable from this instant |
| sold_out_at | timestamptz null | first time `booked_count` reached `capacity`; never cleared |
| created_at, updated_at | timestamptz | `updated_at` bumped on every count change |

CHECK `slots_not_overbooked CHECK (held_count + booked_count <= capacity)`. Unique `(venue_id, starts_at, min_party_size, max_party_size)`. Index `(venue_id, starts_at)`, index `(drop_id)`, index `(updated_at)`.

**holds**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| slot_id | bigint not null | FK slots |
| member_id | bigint not null | FK members |
| party_size | smallint not null | CHECK `>= 1` |
| status | varchar(16) not null | CHECK in (`active`,`converted`,`released`,`expired`) |
| idempotency_key | varchar(255) not null | unique; `IdempotencyKey::scoped()` |
| expires_at | timestamptz not null | `created_at + inventory.hold_ttl_seconds` |
| expiring_notified_at | timestamptz null | `hold.expiring` written |
| closed_at | timestamptz null | CHECK `(status = 'active') = (closed_at IS NULL)` |
| close_reason | varchar(32) null | `converted`, `expired`, `payment_failed`, `payment_timeout` |
| created_at, updated_at | timestamptz | |

Partial unique `holds_one_active_per_member_slot ON holds (slot_id, member_id) WHERE status = 'active'`. Partial index `(expires_at) WHERE status = 'active'`.

### Pricing (Pricing)

**search_events**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| member_id | bigint not null | FK members |
| venue_id | bigint not null | |
| requested_date | date not null | venue-local date |
| requested_hour | smallint not null | venue-local hour, CHECK 0..23 |
| requested_at | timestamptz not null | UTC instant of local date + hour:00 |
| local_weekday | smallint not null | ISO 1 (Mon)..7 (Sun) of requested_date; CHECK 1..7 |
| party_size | smallint not null | CHECK 1..20 |
| party_band | varchar(8) not null | C§10 band key |
| had_availability | boolean not null | |
| created_at | timestamptz not null | search time; window and pruning use this |

Unique `search_events_dedupe (member_id, venue_id, requested_date, requested_hour, party_size)`; inserts use `ON CONFLICT DO NOTHING`. Partial index `(venue_id, local_weekday, requested_hour, party_band, created_at) WHERE had_availability = false`. Index `(created_at)`.

**demand_signals**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| venue_id | bigint not null | |
| weekday | smallint not null | ISO 1..7, venue-local |
| hour | smallint not null | 0..23, venue-local |
| party_band | varchar(8) not null | |
| sellout_seconds_p50 | integer null | same value across bands for one venue/weekday/hour |
| unmet_searches | integer not null default 0 | CHECK `>= 0` |
| bid_count | integer not null default 0 | CHECK `>= 0`; always 0 this run (D11) |
| computed_at | timestamptz not null | |

Unique `(venue_id, weekday, hour, party_band)`.

**price_quotes**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| hold_id | bigint not null | unique: one quote per hold |
| slot_id | bigint not null | |
| venue_id | bigint not null | |
| party_size | smallint not null | CHECK `>= 1` |
| per_person_cents | bigint not null | CHECK `> 0` |
| amount_cents | bigint not null | CHECK `amount_cents = per_person_cents * party_size` |
| currency | char(3) not null | |
| inputs | jsonb not null | C§10 shape |
| expires_at | timestamptz not null | equals the hold's `expires_at` |
| created_at | timestamptz not null | |

Rows are never updated or deleted.

### Wallet (Ledger)

**ledger_accounts**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| owner_type | varchar(16) not null | CHECK in (`member`,`venue`,`system`) |
| owner_id | bigint not null | `0` for system accounts |
| kind | varchar(16) not null | CHECK in (`cash`,`credit`,`coupon`,`venue_payable`,`platform`) |
| currency | char(3) not null | |
| balance_cents | bigint not null default 0 | cached projection, normal-side orientation (C§5.4) |
| created_at, updated_at | timestamptz | |

Unique `(owner_type, owner_id, kind, currency)`. CHECK `ledger_accounts_owner_kind`: `(owner_type='member' AND kind IN ('credit','coupon')) OR (owner_type='venue' AND kind='venue_payable') OR (owner_type='system' AND kind IN ('cash','platform'))`. CHECK `ledger_accounts_member_non_negative CHECK (owner_type <> 'member' OR balance_cents >= 0)`.

System accounts: `(system, 0, cash, USD)` = **card clearing**; `(system, 0, platform, USD)` = **platform**. All accounts, system ones included, are created lazily on first use (`INSERT ... ON CONFLICT DO NOTHING` then select), so `DatabaseTruncation` in concurrency tests cannot leave the ledger without them.

**ledger_transactions**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| type | varchar(32) not null | CHECK in (`booking`,`cancel_early`,`cancel_late`,`goodwill_credit`) |
| reference_type | varchar(32) not null | `hold`, `reservation`, `member` |
| reference_id | bigint not null | |
| idempotency_key | varchar(255) not null | unique |
| currency | char(3) not null | |
| metadata | jsonb not null default `'{}'` | |
| created_at | timestamptz not null | |

Index `(reference_type, reference_id)`.

**ledger_entries**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| transaction_id | bigint not null | FK ledger_transactions |
| account_id | bigint not null | FK ledger_accounts |
| amount_cents | bigint not null | CHECK `<> 0`; **positive = debit, negative = credit** |
| created_at | timestamptz not null | |

Index `(account_id)`, index `(transaction_id)`.

**Triggers (Wallet migration).**

1. `ledger_entries_balanced`: `CREATE CONSTRAINT TRIGGER ... AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_assert_balanced()`. The function raises `ledger transaction % does not balance` unless, for `NEW.transaction_id`: `SUM(amount_cents) = 0`, `COUNT(*) >= 2`, and every entry's account currency equals the transaction currency.
2. `ledger_transactions_have_entries`: deferred constraint trigger `AFTER INSERT ON ledger_transactions` raising unless the transaction has at least 2 entries at commit.
3. `ledger_append_only`: `BEFORE UPDATE OR DELETE` row trigger on `ledger_entries` and `ledger_transactions` that raises `ledger is append-only`. (TRUNCATE is deliberately not blocked so concurrency tests can truncate.)

Because Feature tests run inside a transaction that is rolled back (deferred triggers never fire), `tests/Pest.php` runs `SET CONSTRAINTS ALL IMMEDIATE` at the start of every Feature test. **All entries of one ledger transaction must therefore be inserted in a single multi-row `INSERT` statement**; that is required in production code too.

### Payments (Payments & real-time)

**payments**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| member_id | bigint not null | FK members |
| hold_id | bigint not null | |
| reservation_id | bigint null | set inside the confirm transaction |
| provider | varchar(32) not null default `fake` | |
| provider_ref | varchar(255) null | **unique** (nulls allowed) |
| idempotency_key | varchar(255) not null | unique; `hold:{holdId}:auth` |
| amount_cents | bigint not null | CHECK `> 0` |
| currency | char(3) not null | |
| status | varchar(16) not null | CHECK in (`pending`,`authorized`,`captured`,`failed`,`timed_out`,`refunded`,`capture_failed`) |
| decline_code | varchar(64) null | |
| created_at, updated_at | timestamptz | |

Index `(reservation_id)`, index `(hold_id)`.

**payment_webhook_events**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| provider | varchar(32) not null | |
| event_id | varchar(255) not null | |
| type | varchar(64) not null | |
| provider_ref | varchar(255) null | |
| payload | jsonb not null | |
| processed_at | timestamptz null | |
| created_at | timestamptz not null | |

Unique `(provider, event_id)`.

### Reservations (Booking)

**reservations**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint pk | |
| member_id | bigint not null | FK members |
| hold_id | bigint not null | **unique** |
| slot_id | bigint not null | |
| venue_id | bigint not null | |
| venue_timezone | varchar(64) not null | denormalized for display |
| starts_at | timestamptz not null | denormalized for the 8-hour rule |
| party_size | smallint not null | CHECK `>= 1` |
| min_spend_cents | bigint not null | CHECK `> 0`; equals the quote's `amount_cents` |
| coupon_cents | bigint not null default 0 | CHECK `>= 0` |
| credit_cents | bigint not null default 0 | CHECK `>= 0` |
| card_cents | bigint not null default 0 | CHECK `>= 0` |
| currency | char(3) not null | |
| payment_id | bigint null | Payments row when `card_cents > 0` |
| status | varchar(24) not null | CHECK in (`confirmed`,`cancelled_early`,`cancelled_late`) |
| idempotency_key | varchar(255) not null | unique; `IdempotencyKey::scoped()` |
| confirmed_at | timestamptz not null | |
| cancelled_at | timestamptz null | CHECK `(status = 'confirmed') = (cancelled_at IS NULL)` |
| created_at, updated_at | timestamptz | |

CHECK `coupon_cents + credit_cents + card_cents = min_spend_cents`. Index `(member_id, starts_at)`, index `(slot_id)`.

### Tables in build_plan §3 that are out of scope

`memberships`, `coupon_lots`, `admin_audit_log` are **not created** in this run (steps 9–10). The `coupon` account kind exists; nothing credits it.

---

## C§4. Shared types (app/Shared, Platform)

All times are `Carbon\CarbonImmutable` (`Date::use(CarbonImmutable::class)` in `AppServiceProvider`).

```php
namespace App\Shared\Money;

enum Currency: string { case USD = 'USD'; }

final class CurrencyMismatchException extends \LogicException {}

final readonly class Money
{
    /** @throws \InvalidArgumentException if $cents < 0 */
    public function __construct(public int $cents, public Currency $currency) {}
    public static function of(int $cents, Currency|string $currency): self;
    public static function zero(Currency $currency): self;
    public function add(Money $other): self;              // CurrencyMismatchException
    public function subtract(Money $other): self;         // CurrencyMismatchException; InvalidArgumentException if result < 0
    public function multiply(int $factor): self;          // InvalidArgumentException if $factor < 0
    public function min(Money $other): self;              // CurrencyMismatchException
    public function bps(int $basisPoints): self;          // intdiv(cents * bps, 10000), floor
    public function isZero(): bool;
    public function isPositive(): bool;
    public function equals(Money $other): bool;           // same cents and currency
    public function greaterThan(Money $other): bool;      // CurrencyMismatchException
    public function greaterThanOrEqual(Money $other): bool;
    /** @return array{cents: int, currency: string} */
    public function toArray(): array;
}
```

Money is never negative. Debit/credit signs exist only inside the Wallet module as raw `int` entry amounts.

```php
namespace App\Shared\Idempotency;

final readonly class IdempotencyKey
{
    /** @throws IdempotencyKeyInvalidException unless $value matches /^[A-Za-z0-9._:-]{1,200}$/ */
    public function __construct(public int $memberId, public string $value) {}
    /** Reads the Idempotency-Key header and the authenticated member. @throws IdempotencyKeyRequiredException */
    public static function fromRequest(\Illuminate\Http\Request $request): self;
    public function scoped(): string;   // "{$memberId}:{$value}"
}

final class EnsureIdempotency { public function handle(Request $request, \Closure $next): Response; } // alias 'idempotent'
final class IdempotencyKeyRequiredException extends DomainError {}   // 400 idempotency_key_required
final class IdempotencyKeyInvalidException  extends DomainError {}   // 400 idempotency_key_invalid
final class IdempotencyMismatchException    extends DomainError {}   // 422 idempotency_mismatch
final class IdempotencyInProgressException  extends DomainError {}   // 409 idempotency_in_progress
```

```php
namespace App\Shared\Outbox;

enum OutboxTopic: string
{
    case SlotHeld = 'slot.held';
    case SlotReleased = 'slot.released';
    case SlotBooked = 'slot.booked';
    case DropOpened = 'drop.opened';
    case DropSoldOut = 'drop.sold_out';
    case HoldExpiring = 'hold.expiring';
    case ReservationConfirmed = 'reservation.confirmed';
    case WalletUpdated = 'wallet.updated';
}

final class Outbox
{
    /**
     * Inserts one outbox_events row on the current connection.
     * @param array<string, mixed> $payload  shape per C§7
     * @throws \LogicException if called outside a DB transaction
     */
    public function record(OutboxTopic $topic, array $payload): void;
}

/** Eloquent model for outbox_events. Realtime may use it (it is Shared). */
final class OutboxEvent extends Model {}
```

```php
namespace App\Shared\Exceptions;

abstract class DomainError extends \RuntimeException
{
    abstract public function errorCode(): string;   // C§9 code string
    abstract public function httpStatus(): int;
    /** @return array<string, mixed> */
    public function details(): array { return []; }
}
```

`bootstrap/app.php` renders every `DomainError` as C§9's error envelope, and maps framework exceptions (validation, auth, model-not-found, 404, 405, 429, others) to the codes in C§9. Unexpected exceptions return `500 server_error` with no stack trace when `APP_DEBUG=false`.

```php
namespace App\Shared\Support;

final class Transactions
{
    public static int $baseLevel = 0;   // D17
    /** @throws \LogicException unless DB::transactionLevel() > self::$baseLevel */
    public static function assertActive(): void;
    /** @throws \LogicException if DB::transactionLevel() > self::$baseLevel */
    public static function assertNone(): void;
}

abstract class ModuleServiceProvider extends \Illuminate\Support\ServiceProvider { /* C§2 */ }
```

`App\Shared\Members\Member` extends `Authenticatable`, uses `HasApiTokens`, `HasFactory`. Modules may type-hint `Member` (for `$request->user()`) but must not query the `members` table directly; they use the authenticated member's id.

---

## C§5. Public actions per module

Every action is resolved from the container. Constructor dependencies listed here are the binding ones; an action may add internal (same-module) collaborators. "Requires txn" means the action calls `Transactions::assertActive()` and joins the caller's transaction. "Own txn" means it opens `DB::transaction()` itself. "No txn" means it calls `Transactions::assertNone()`.

### C§5.1 Inventory (Booking)

```php
namespace App\Modules\Inventory\Enums;
enum HoldStatus: string { case Active = 'active'; case Converted = 'converted'; case Released = 'released'; case Expired = 'expired'; }
enum HoldReleaseReason: string { case PaymentFailed = 'payment_failed'; case PaymentTimeout = 'payment_timeout'; }

namespace App\Modules\Inventory\Data;

final readonly class SlotData
{
    public function __construct(
        public int $id,
        public int $venueId,
        public ?int $dropId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $releasedAt,
        public int $capacity,
        public int $heldCount,
        public int $bookedCount,
        public int $minPartySize,
        public int $maxPartySize,
        public Money $basePerPerson,
        public ?CarbonImmutable $soldOutAt,
    ) {}
    public function available(): int;   // capacity - heldCount - bookedCount
}

final readonly class HoldData
{
    public function __construct(
        public int $id,
        public int $memberId,
        public int $partySize,
        public HoldStatus $status,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $createdAt,
        public SlotData $slot,
    ) {}
}

final readonly class HoldSlotInput
{
    public function __construct(public int $memberId, public int $slotId, public int $partySize, public string $idempotencyKey) {}
}

final readonly class SelloutStat
{
    public function __construct(public int $venueId, public int $weekday, public int $hour, public int $p50Seconds) {}
}
```

| Action | Signature | Txn | Behavior | Throws |
| --- | --- | --- | --- | --- |
| `HoldSlot` | `__construct(Outbox $outbox, Support\SlotGate $gate)`<br>`handle(HoldSlotInput $input, ?callable $inTransaction = null): HoldData` — `$inTransaction` is `callable(HoldData): void` | Own txn | Algorithm in C§6. On replay (a hold with `idempotency_key` exists) returns it without calling `$inTransaction`. | `SlotNotFoundException`, `SlotUnavailableException`, `DropNotOpenException`, `PartySizeNotAllowedException`, `SoldOutException`, `ActiveHoldExistsException`, anything thrown by `$inTransaction` (after rollback and gate release) |
| `GetHold` | `handle(int $holdId, int $memberId): HoldData` | Own txn only if expiring | Lazily expires the hold if `now() >= expires_at` (via `ExpireHold`) and returns the fresh state. | `HoldNotFoundException` (missing or not owned) |
| `ExpireHold` | `handle(int $holdId): bool` | Own txn | If status `active` and `now() >= expires_at`: status `expired`, `closed_at`, `close_reason=expired`, `held_count - 1`, outbox `slot.released`, gate release after commit. Returns true only if this call expired it. | — |
| `ReleaseHold` | `handle(int $holdId, HoldReleaseReason $reason): bool` | Own txn | If `active`: status `released`, `close_reason = $reason->value`, same slot/outbox/gate effects as expiry. Idempotent; returns false if not active. | — |
| `ConvertHoldToBooking` | `handle(int $holdId, int $memberId): SlotData` | Requires txn | Locks hold `FOR UPDATE`. Requires owner match, status `active` and `now() < expires_at`. Sets `converted`; `held_count - 1, booked_count + 1`; sets `slots.sold_out_at` if it is now full and was null; outbox `slot.booked`; if the slot has a drop and every slot in that drop is now full and `drops.sold_out_at` is null, sets it and writes `drop.sold_out`. Returns post-update slot. Does **not** touch the gate (the unit was already taken at hold time). | `HoldNotFoundException`, `HoldExpiredException`, `HoldNotActiveException` |
| `ReleaseBooking` | `handle(int $slotId): SlotData` | Requires txn | `booked_count - 1` (conditional `WHERE booked_count > 0`), outbox `slot.released`, gate release after commit. | `SlotNotFoundException` |
| `GetSlot` | `handle(int $slotId): SlotData` | — | | `SlotNotFoundException` |
| `GetSelloutStats` | `handle(CarbonImmutable $since): list<SelloutStat>` | — | One row per (venue, venue-local ISO weekday of `starts_at`, local hour) over slots with `sold_out_at >= $since`; `p50Seconds = percentile_cont(0.5)` of `sold_out_at - released_at` in seconds, rounded. | — |

Exceptions (`App\Modules\Inventory\Exceptions`, all extend `DomainError`): `SlotNotFoundException` 404 `not_found`; `VenueNotFoundException` 404 `not_found`; `DropNotFoundException` 404 `not_found`; `SlotUnavailableException` 409 `slot_unavailable`; `DropNotOpenException` 409 `drop_not_open`; `PartySizeNotAllowedException` 422 `party_size_not_allowed` (details `min_party_size`, `max_party_size`); `SoldOutException` 409 `sold_out`; `ActiveHoldExistsException` 409 `active_hold_exists`; `HoldNotFoundException` 404 `not_found`; `HoldExpiredException` 409 `hold_expired`; `HoldNotActiveException` 409 `hold_not_active` (details `status`).

Event:

```php
namespace App\Modules\Inventory\Events;

final readonly class AvailabilitySearched
{
    public function __construct(
        public int $memberId,
        public int $venueId,
        public string $venueTimezone,
        public string $requestedDate,     // 'Y-m-d', venue-local
        public int $requestedHour,        // 0..23, venue-local
        public int $partySize,
        public bool $hadAvailability,
        public CarbonImmutable $searchedAt,
    ) {}
}
```

Dispatched synchronously (`event(...)`) by the availability endpoint after it computes the response. Listeners must never throw into the request (Pricing's listener catches and logs).

**Contract stubs created by Platform in ticket 01** (so Pricing can build in parallel with Inventory): `Inventory\Events\AvailabilitySearched` (complete), `Inventory\Data\SelloutStat` (complete), `Inventory\Actions\GetSelloutStats` (stub returning `[]`). Booking owns and implements them from ticket 02 onward without changing signatures.

### C§5.2 Pricing (Pricing)

```php
namespace App\Modules\Pricing\Data;

final readonly class QuoteHoldInput
{
    public function __construct(
        public int $holdId,
        public int $slotId,
        public int $venueId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public int $partySize,
        public Money $basePerPerson,
        public CarbonImmutable $expiresAt,
    ) {}
}

final readonly class QuoteData
{
    /** @param array<string, mixed> $inputs */
    public function __construct(
        public int $id,
        public int $holdId,
        public int $slotId,
        public int $partySize,
        public Money $perPerson,
        public Money $amount,
        public array $inputs,
        public CarbonImmutable $expiresAt,
    ) {}
}
```

| Action | Signature | Txn | Behavior | Throws |
| --- | --- | --- | --- | --- |
| `QuoteHold` | `handle(QuoteHoldInput $input): QuoteData` | Requires txn | Computes price per C§10 from the latest `demand_signals`, inserts `price_quotes`. Idempotent on `hold_id` (returns existing row). Never waits on the signals job; no row means demand multiplier 1.0. | — |
| `GetQuoteForHold` | `handle(int $holdId): QuoteData` | — | | `QuoteNotFoundException` (extends `\LogicException`, renders 500) |
| `RecordSearch` | `handle(AvailabilitySearched $event): bool` | — | Inserts a `search_events` row with `ON CONFLICT DO NOTHING`; returns true if inserted. Computes `requested_at`, `local_weekday`, `party_band`. | — |
| `ComputeDemandSignals` | `__construct(GetSelloutStats $sellout)`<br>`handle(CarbonImmutable $now): int` | Own txn | C§10 job. Returns rows written. | — |
| `PruneSearchEvents` | `handle(CarbonImmutable $now): int` | — | Deletes rows with `created_at < now - pricing.search_retention_days`. | — |

Listener `Pricing\Listeners\RecordAvailabilitySearch` handles `AvailabilitySearched`, calls `RecordSearch`, catches `\Throwable` and logs `warning`.

### C§5.3 Wallet (Ledger)

```php
namespace App\Modules\Wallet\Enums;
enum AccountKind: string { case Cash = 'cash'; case Credit = 'credit'; case Coupon = 'coupon'; case VenuePayable = 'venue_payable'; case Platform = 'platform'; }
enum AccountOwnerType: string { case Member = 'member'; case Venue = 'venue'; case System = 'system'; }
enum LedgerTransactionType: string { case Booking = 'booking'; case CancelEarly = 'cancel_early'; case CancelLate = 'cancel_late'; case GoodwillCredit = 'goodwill_credit'; }

namespace App\Modules\Wallet\Data;

final readonly class SpendPlan
{
    public function __construct(public Money $coupon, public Money $credit, public Money $card) {}  // same currency or CurrencyMismatchException
    public function total(): Money;
}

final readonly class BookingPaymentInput
{
    public function __construct(public int $memberId, public int $venueId, public int $holdId, public int $reservationId, public SpendPlan $plan) {}
}

final readonly class CancellationRefundInput
{
    public function __construct(public int $memberId, public int $venueId, public int $reservationId, public Money $amount, public bool $late) {}
}

final readonly class CancellationRefund
{
    public function __construct(public int $transactionId, public Money $memberCredit, public Money $platformShare, public Money $venueRetained) {}
}

final readonly class LedgerEntryData
{
    public function __construct(public int $accountId, public AccountOwnerType $ownerType, public int $ownerId, public AccountKind $kind, public int $amountCents) {}
}

final readonly class LedgerTransactionData
{
    /** @param list<LedgerEntryData> $entries */
    public function __construct(public int $id, public LedgerTransactionType $type, public string $idempotencyKey, public Currency $currency, public array $entries, public CarbonImmutable $createdAt) {}
}

final readonly class WalletBalance
{
    public function __construct(public int $memberId, public Money $credit, public Money $coupon) {}
}

final readonly class ReconciliationReport
{
    /** @param list<array{account_id: int, cached: int, actual: int}> $drift */
    public function __construct(public int $accountsChecked, public int $globalSumCents, public int $unbalancedTransactions, public array $drift) {}
    public function clean(): bool;   // globalSum 0, no unbalanced, no drift
}
```

| Action | Signature | Txn | Behavior | Throws |
| --- | --- | --- | --- | --- |
| `PlanSpend` | `handle(int $memberId, Money $amount): SpendPlan` | — | Coupon balance first, then credit, remainder card (§5 spend order). Read-only, no locks. | — |
| `PostBookingPayment` | `__construct(Outbox $outbox)`<br>`handle(BookingPaymentInput $input): LedgerTransactionData` | Requires txn | Idempotency key `hold:{holdId}:booking`; replay returns the existing transaction. Locks member coupon/credit accounts `FOR UPDATE` (ascending id), re-checks balances ≥ plan amounts. Entries: debit member coupon `plan.coupon`, debit member credit `plan.credit`, debit system cash (card clearing) `plan.card`, credit venue_payable `plan.total()`; zero lines omitted. Writes `wallet.updated` if coupon or credit > 0. | `InsufficientWalletBalanceException` |
| `PostCancellationRefund` | `__construct(Outbox $outbox)`<br>`handle(CancellationRefundInput $input): CancellationRefund` | Requires txn | Key `reservation:{reservationId}:cancel`; replay returns stored result. Early: debit venue_payable `amount`, credit member credit `amount`. Late: `member = amount.bps(wallet.late_cancel.member_bps)`, `platform = amount.bps(wallet.late_cancel.platform_bps)`; debit venue_payable `member + platform`, credit member credit `member`, credit platform `platform`; `venueRetained = amount - member - platform`. Writes `wallet.updated`. | — |
| `IssueGoodwillCredit` | `__construct(Outbox $outbox)`<br>`handle(int $memberId, Money $amount, string $idempotencyKey): LedgerTransactionData` | Own txn | Debit platform, credit member credit. No HTTP endpoint this run (seeders and tests only). | — |
| `GetWalletBalance` | `handle(int $memberId, Currency $currency): WalletBalance` | — | Reads cached balances (missing accounts = 0). | — |
| `ReconcileLedger` | `handle(bool $rebuild = false): ReconciliationReport` | Own txn if `$rebuild` | Re-sums entries per account in normal orientation, compares with `balance_cents`, checks global sum and per-transaction sums. `$rebuild` rewrites cached balances from entries. | — |

Internal (not public): `Support\PostTransaction` (the only code that inserts ledger rows), account resolver.

**C§5.4 Balance orientation.** `cash` is debit-normal: `balance = SUM(amount_cents)`. `credit`, `coupon`, `venue_payable`, `platform` are credit-normal: `balance = -SUM(amount_cents)`. `balance_cents` is updated in the same transaction as the entries.

Exception: `InsufficientWalletBalanceException` 409 `wallet_balance_changed`.

Artisan: `wallet:reconcile {--rebuild}` prints the report as a table; exit code 0 when clean, 1 otherwise and logs `critical`. Scheduled daily 03:00 UTC.

### C§5.5 Payments (Payments & real-time)

```php
namespace App\Modules\Payments\Contracts;

interface PaymentGateway
{
    /** @throws GatewayTimeoutException  outcome unknown */
    public function authorize(AuthorizeRequest $request): GatewayResult;                            // Authorized | Declined
    /** @throws GatewayTimeoutException */
    public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult; // Captured | Failed
    /** @throws GatewayTimeoutException */
    public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult;  // Refunded | Failed (voids an uncaptured auth)
    /** @throws GatewayTimeoutException */
    public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult; // PaidOut | Failed (unused this run)
}

namespace App\Modules\Payments\Data;

final readonly class AuthorizeRequest
{
    /** @param array<string, string|int> $metadata  must include 'payment_id' */
    public function __construct(public Money $amount, public string $paymentMethodToken, public string $idempotencyKey, public array $metadata) {}
}

final readonly class GatewayResult
{
    public function __construct(public GatewayStatus $status, public ?string $providerRef, public ?string $declineCode = null) {}
}

final readonly class AuthorizePaymentInput
{
    public function __construct(public int $memberId, public int $holdId, public Money $amount, public string $cardToken) {}
}

final readonly class PaymentData
{
    public function __construct(public int $id, public int $memberId, public int $holdId, public ?int $reservationId, public PaymentStatus $status, public Money $amount, public ?string $providerRef, public ?string $declineCode) {}
}

namespace App\Modules\Payments\Enums;
enum GatewayStatus: string { case Authorized = 'authorized'; case Captured = 'captured'; case Refunded = 'refunded'; case PaidOut = 'paid_out'; case Declined = 'declined'; case Failed = 'failed'; }
enum PaymentStatus: string { case Pending = 'pending'; case Authorized = 'authorized'; case Captured = 'captured'; case Failed = 'failed'; case TimedOut = 'timed_out'; case Refunded = 'refunded'; case CaptureFailed = 'capture_failed'; }

namespace App\Modules\Payments\Exceptions;
final class GatewayTimeoutException extends \RuntimeException {}
```

| Action | Signature | Txn | Behavior | Throws |
| --- | --- | --- | --- | --- |
| `AuthorizePayment` | `__construct(PaymentGateway $gateway)`<br>`handle(AuthorizePaymentInput $input): PaymentData` | No txn | Idempotency key `hold:{holdId}:auth`. `INSERT ... ON CONFLICT DO NOTHING` a `pending` row. If it already existed: `authorized`/`captured` with same amount → return it; `pending` → `PaymentInProgressException`; `failed` → rethrow `PaymentDeclinedException` with stored code; `timed_out` → `PaymentTimeoutException`; different amount → `PaymentConflictException`. Otherwise calls `gateway->authorize()` with the same key and `metadata.payment_id`. Authorized → status `authorized`, store `provider_ref`. Declined → `failed`, `decline_code`, throw declined. Timeout → `timed_out`, throw timeout. | `PaymentDeclinedException`, `PaymentTimeoutException`, `PaymentInProgressException`, `PaymentConflictException` |
| `MarkPaymentForCapture` | `handle(int $paymentId, int $reservationId): void` | Requires txn | `UPDATE payments SET reservation_id = :r WHERE id = :id AND reservation_id IS NULL AND status = 'authorized'`; 0 rows → throw. Dispatches `Jobs\CapturePayment` with `->afterCommit()`. | `PaymentConflictException` |
| `VoidPayment` | `__construct(PaymentGateway $gateway)`<br>`handle(int $paymentId): void` | Own short txn, gateway call after it | Claims the payment with `UPDATE ... SET status = 'refunded' WHERE id = :id AND status = 'authorized' AND reservation_id IS NULL`; 0 rows → no-op (already attached, voided, failed or timed out). After commit calls `gateway->refund()` with key `payment:{id}:void`; on failure or timeout dispatches `Jobs\RefundPayment` (tries 5). Safe to call repeatedly. Because both this and `MarkPaymentForCapture` are conditional updates, a payment is either captured for one reservation or voided, never both. | — |

Internal: `Jobs\CapturePayment` (tries 5, backoff `[1, 5, 15, 60, 300]`; success → `captured`; final failure → `capture_failed` + `Log::critical`). `Jobs\RefundPayment` (same retry policy; final failure → `Log::critical`). `Support\HandleWebhook` (C§11). `Support\FakeGateway implements PaymentGateway`, bound as a **singleton** when `payments.gateway = fake`.

Exceptions (`DomainError`): `PaymentDeclinedException` 402 `payment_failed` (details `decline_code`); `PaymentTimeoutException` 504 `payment_timeout`; `PaymentInProgressException` 409 `payment_in_progress`; `PaymentConflictException` 409 `payment_conflict`; `InvalidWebhookSignatureException` 401 `invalid_signature`.

### C§5.6 Reservations (Booking)

```php
namespace App\Modules\Reservations\Enums;
enum ReservationStatus: string { case Confirmed = 'confirmed'; case CancelledEarly = 'cancelled_early'; case CancelledLate = 'cancelled_late'; }

namespace App\Modules\Reservations\Data;

final readonly class HoldView
{
    public function __construct(public HoldData $hold, public ?QuoteData $quote) {}  // quote is null only before ticket 06 merges
}

final readonly class ReservationData
{
    public function __construct(
        public int $id,
        public int $memberId,
        public int $holdId,
        public int $slotId,
        public int $venueId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public int $partySize,
        public Money $minSpend,
        public SpendPlan $payment,
        public ReservationStatus $status,
        public CarbonImmutable $confirmedAt,
        public ?CarbonImmutable $cancelledAt,
    ) {}
}

final readonly class CancellationData
{
    public function __construct(public ReservationData $reservation, public bool $late, public Money $creditRefunded, public Money $forfeited) {}
}
```

| Action | Signature | Txn | Behavior | Throws |
| --- | --- | --- | --- | --- |
| `PlaceHold` | `__construct(HoldSlot $holdSlot, QuoteHold $quoteHold, GetQuoteForHold $getQuote)`<br>`handle(int $memberId, int $slotId, int $partySize, IdempotencyKey $key): HoldView` | via HoldSlot | Calls `HoldSlot` with `$inTransaction = fn (HoldData $h) => $quoteHold->handle(new QuoteHoldInput(...from $h))`, then `GetQuoteForHold`. | Inventory hold exceptions |
| `GetHoldView` | `handle(int $memberId, int $holdId): HoldView` | — | `GetHold` (lazy expiry) + quote. | `HoldNotFoundException` |
| `ConfirmReservation` | `__construct(GetHold, ExpireHold, ReleaseHold, ConvertHoldToBooking, GetQuoteForHold, PlanSpend, PostBookingPayment, AuthorizePayment, MarkPaymentForCapture, VoidPayment, Outbox)`<br>`handle(int $memberId, int $holdId, ?string $cardToken, IdempotencyKey $key): ReservationData` | Mixed, C§6.2 | Confirm algorithm C§6.2. Replay: if a reservation with `idempotency_key = $key->scoped()` exists, return it. | `HoldNotFoundException`, `HoldExpiredException`, `HoldNotActiveException`, `CardRequiredException`, `PaymentDeclinedException`, `PaymentTimeoutException`, `PaymentInProgressException`, `PaymentConflictException`, `InsufficientWalletBalanceException` |
| `CancelReservation` | `__construct(ReleaseBooking, PostCancellationRefund)`<br>`handle(int $memberId, int $reservationId): CancellationData` | Own txn | C§6.3. HTTP replay is the middleware's job; the ledger key `reservation:{id}:cancel` is the backstop. | `ReservationNotFoundException`, `ReservationNotCancellableException`, `ReservationStartedException` |
| `GetReservation` | `handle(int $memberId, int $reservationId): ReservationData` | — | | `ReservationNotFoundException` |
| `ListReservations` | `handle(int $memberId): list<ReservationData>` | — | Newest `starts_at` first, max 100. | — |

Exceptions (`DomainError`): `ReservationNotFoundException` 404 `not_found`; `ReservationNotCancellableException` 409 `reservation_not_cancellable` (details `status`); `ReservationStartedException` 409 `reservation_started`; `CardRequiredException` 422 `card_required` (details `card_cents`).

### C§5.7 Realtime (Payments & real-time)

| Action | Signature | Behavior |
| --- | --- | --- |
| `PublishOutboxBatch` | `handle(int $limit = 100): int` | In a transaction: `SELECT ... FROM outbox_events WHERE published_at IS NULL ORDER BY id LIMIT :limit FOR UPDATE SKIP LOCKED`; for each row broadcast per C§7 via an internal `ShouldBroadcastNow` event; success → `published_at = now()`; failure → `attempts + 1`, `last_error`, stop the batch (preserve order). Returns rows published. |
| `PruneOutbox` | `handle(CarbonImmutable $now): int` | Deletes rows published more than `realtime.outbox.retention_days` ago. |

Artisan: `realtime:publish-outbox {--once} {--limit=100} {--sleep-ms=100}` (long-running loop in the `outbox` container; `--once` for tests), `realtime:prune-outbox` (daily 05:00 UTC). Channel authorization is registered in `RealtimeServiceProvider` (`Broadcast::routes(['middleware' => ['auth:sanctum']])` and `Broadcast::channel('member.{memberId}', ...)`).

---

## C§6. Booking algorithms

### C§6.1 Hold (Inventory `HoldSlot`)

Redis gate key `slot_gate:{slotId}` (Laravel Redis prefix `dorsia:`). Acquire is one Lua script:

```lua
-- KEYS[1] = slot_gate:{id}
local v = redis.call('GET', KEYS[1])
if not v then return -2 end            -- missing: caller rebuilds
local n = redis.call('DECR', KEYS[1])
if n < 0 then redis.call('INCR', KEYS[1]); return -1 end   -- sold out
return n
```

Release is a Lua script that `INCR`s only if the key exists. Rebuild takes `Cache::store('redis')->lock("slot_gate:{id}:lock", inventory.gate.rebuild_lock_seconds)`, reads the slot fresh from Postgres, and `SET`s `capacity - booked_count - held_count` with TTL until `starts_at + 1 day`.

Steps:

1. Load slot + venue by id (one query). Missing → `SlotNotFoundException`.
2. Validate, in order: venue `active` and `starts_at > now()` else `SlotUnavailableException`; `released_at <= now()` else `DropNotOpenException` if `drop_id` set, otherwise `SlotUnavailableException`; `min_party_size <= party_size <= max_party_size` else `PartySizeNotAllowedException`.
3. Gate acquire. `-1` → `SoldOutException` (no DB write). `-2` → rebuild, acquire once more; if still `-2` (lock contention), continue without a gate unit.
4. `DB::transaction`:
   - `UPDATE slots SET held_count = held_count + 1, updated_at = :now WHERE id = :id AND held_count + booked_count < capacity RETURNING *`.
   - 0 rows → leave the transaction, run internal `ExpireOverdueHolds(slotId)` (expires active holds with `expires_at <= now()` on that slot), retry the transaction once. Still 0 rows → rebuild the gate (do not INCR) and throw `SoldOutException`.
   - Insert the hold (`expires_at = now() + hold_ttl_seconds`), outbox `slot.held`, call `$inTransaction(HoldData)`.
5. Errors after a gate unit was taken (other than step 4's sold-out path) release the unit after rollback. Unique violation on `holds.idempotency_key` → release the unit and return the existing hold (replay). Unique violation on `holds_one_active_per_member_slot` → release the unit and throw `ActiveHoldExistsException`.

Postgres is the final word: a counter that is too high only lets extra requests reach step 4, where the conditional update rejects them.

**Hold expiry.** `now() >= expires_at` means expired. Enforced lazily by `GetHold` and `ConvertHoldToBooking`, and by the `inventory:expire-holds` sweeper (C§13). Expiry and release restore `held_count`, write `slot.released` and release the gate unit after commit, in that order.

**Counter rebuild sweep.** The same every-minute sweeper rebuilds gates for slots with `starts_at > now()` and `updated_at >= now() - 2 minutes`, bounding any counter drift (e.g. a crash between commit and INCR) to about a minute.

### C§6.2 Confirm (Reservations `ConfirmReservation`)

1. Replay check: existing reservation with this scoped key → return it.
2. `GetHold($holdId, $memberId)` (lazy expiry). Status `expired` → `HoldExpiredException`; not `active` → `HoldNotActiveException`.
3. `GetQuoteForHold`, `PlanSpend($memberId, $quote->amount)`.
4. If `plan.card > 0`: `$cardToken` null → `CardRequiredException` (hold kept). Else `AuthorizePayment` (outside any transaction). On `PaymentDeclinedException` → `ReleaseHold(PaymentFailed)`, rethrow. On `PaymentTimeoutException` → `ReleaseHold(PaymentTimeout)`, rethrow. `PaymentInProgressException` / `PaymentConflictException` → rethrow (hold kept).
5. `DB::transaction`: `ConvertHoldToBooking`; insert reservation (`status confirmed`, amounts from plan, `confirmed_at = now()`); `PostBookingPayment`; `MarkPaymentForCapture` if a payment exists; outbox `reservation.confirmed`.
6. If step 5 throws: if a payment was authorized, `VoidPayment` (it only voids unattached payments, so a concurrent winner's payment is safe). If the cause was `HoldExpiredException`, call `ExpireHold` so the slot is restored. `InsufficientWalletBalanceException` keeps the hold. Rethrow.

### C§6.3 Cancel (Reservations `CancelReservation`)

One transaction: lock the reservation `FOR UPDATE` (missing or not owned → `ReservationNotFoundException`). Status not `confirmed` → `ReservationNotCancellableException` (a retry with the *same* key never reaches here; the middleware replays the first response). `now() >= starts_at` → `ReservationStartedException`. `late = starts_at - now() < reservations.cancel_window_hours` (exactly 8:00:00 before is **early**). Update status/`cancelled_at`; `ReleaseBooking(slotId)`; `PostCancellationRefund(amount = min_spend, late)`. The refund always goes to member credit, however the booking was paid (§5).

---

## C§7. Outbox topics and Reverb channels

All payload timestamps are ISO 8601 UTC with `Z`, no fractional seconds. The publisher adds `outbox_id` to every broadcast payload.

| Topic (writer) | Payload | Channel (Laravel name / wire name) | Broadcast event name |
| --- | --- | --- | --- |
| `slot.held` (Inventory) | `{slot_id, venue_id, drop_id, starts_at, capacity, available, occurred_at}` | `venue.{venue_id}.slots` (public) | `slot.held` |
| `slot.released` (Inventory) | same as `slot.held` plus `reason`: `expired`/`payment_failed`/`payment_timeout`/`cancelled` | `venue.{venue_id}.slots` | `slot.released` |
| `slot.booked` (Inventory) | same as `slot.held` | `venue.{venue_id}.slots` | `slot.booked` |
| `drop.opened` (Inventory) | `{drop_id, venue_id, opens_at, slot_ids: int[]}` | `drop.{drop_id}` (public) | `drop.opened` |
| `drop.sold_out` (Inventory) | `{drop_id, venue_id, sold_out_at}` | `drop.{drop_id}` | `drop.sold_out` |
| `hold.expiring` (Inventory) | `{hold_id, member_id, slot_id, expires_at}` | `member.{member_id}` (private; wire `private-member.{id}`) | `hold.expiring` |
| `reservation.confirmed` (Reservations) | `{reservation_id, member_id, slot_id, venue_id, starts_at, party_size, min_spend_cents, currency}` | `member.{member_id}` | `reservation.confirmed` |
| `wallet.updated` (Wallet) | `{member_id, currency, credit_cents, coupon_cents}` (balances after the transaction) | `member.{member_id}` | `wallet.updated` |

`available` is `capacity - held_count - booked_count` read from the `RETURNING` row in the same statement. Events use `broadcastAs()` with the exact names above (clients listen to `.slot.held`). Private channel auth: member id must equal the authenticated member. Pushes are hints; clients re-fetch via the HTTP API.

There is one publisher process, so delivery is in `id` order and at-least-once.

---

## C§8. HTTP API

Base path `/api`. JSON only. All endpoints except `POST /api/auth/token`, `POST /api/webhooks/payments/fake` and `GET /up` require `Authorization: Bearer <sanctum token>` (`auth:sanctum`). No Laravel throttling on booking routes (edge concern, D16).

**Envelopes.** Success: `{"data": ...}`. Error: `{"error": {"code": "sold_out", "message": "Human readable.", "details": {}}}`. Timestamps as in C§7, plus `local_starts_at` in venue-local ISO 8601 with offset (`2026-11-07T20:00:00-05:00`). Money is `*_cents` integers with a sibling `currency`.

**Idempotency-Key** (middleware `idempotent`, applied to the three `POST` booking writes below, after `auth:sanctum`):

- Required; missing → 400 `idempotency_key_required`; not matching `^[A-Za-z0-9._:-]{1,200}$` → 400 `idempotency_key_invalid`. Clients should send a UUID.
- Scope is `(member_id, key)`.
- `request_hash = sha256(METHOD + "\n" + path + "\n" + canonical_json(body))`, where canonical JSON sorts object keys recursively and has no whitespace; an empty body hashes as `{}`.
- First request: insert a `processing` row with a fresh `lock_token` (`ON CONFLICT DO NOTHING`), run the handler. Response status < 500 → `UPDATE ... SET status='completed', response_status, response_body (exact bytes), response_content_type, completed_at WHERE id = :id AND lock_token = :mine AND status = 'processing'`. Status ≥ 500 or exception → `DELETE ... WHERE id = :id AND lock_token = :mine` so a retry re-executes. If either statement affects 0 rows, ownership was lost to a takeover: return this request's own response unchanged, store nothing, log `warning`.
- Same key, different hash → 422 `idempotency_mismatch` (§4).
- Same key, same hash, `completed` → replay the stored status, the stored body **byte for byte** and the stored `Content-Type`, plus header `Idempotent-Replayed: true`. Tests compare `getContent()` with `toBe`, not `assertExactJson`.
- Same key, same hash, `processing` and `locked_at` within `idempotency.stale_after_seconds` (30) → 409 `idempotency_in_progress` with `Retry-After: 1`. Older → take over with `UPDATE ... SET locked_at = :now, lock_token = :new WHERE id = :id AND status = 'processing' AND lock_token = :old` (0 rows → someone else took over or completed: re-read and apply these rules again), then re-execute; domain-level unique keys make re-execution safe. The slow original then fails the `lock_token` check above and cannot overwrite or delete the new owner's result.
- Rows are pruned after `idempotency.retention_hours` (24) by `idempotency:prune` (daily).

### Endpoints

| Method, path | Module | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /up` | Platform | — | 200 (Laravel health) | — |
| `POST /api/auth/token` | Shared | `{email, password, device_name}` | 201 `{data: {token, member: Member}}` | 401 `invalid_credentials`, 422 `validation_failed` |
| `GET /api/me` | Shared | — | 200 `{data: Member}` | 401 |
| `GET /api/cities` | Inventory | — | 200 `{data: City[]}` | |
| `GET /api/venues?city_id=` | Inventory | optional `city_id` int | 200 `{data: Venue[]}` (active only, by name) | 422 |
| `GET /api/venues/{venue}` | Inventory | — | 200 `{data: Venue}` | 404 |
| `GET /api/venues/{venue}/availability?date=&hour=&party_size=` | Inventory | `date` `Y-m-d` (venue-local), `hour` 0..23, `party_size` 1..20, all required | 200 `{data: {venue_id, timezone, date, hour, party_size, had_availability, slots: Slot[]}}` — `slots` = all slots that local date, by `starts_at`. Dispatches `AvailabilitySearched`. | 404, 422 |
| `GET /api/slots/{slot}` | Inventory | — | 200 `{data: Slot}` | 404 |
| `GET /api/drops/{drop}` | Inventory | — | 200 `{data: Drop}` | 404 |
| `POST /api/holds` | Reservations | `{slot_id: int, party_size: int 1..20}` + Idempotency-Key | 201 `{data: Hold}` | 404 `not_found`, 409 `sold_out`/`drop_not_open`/`slot_unavailable`/`active_hold_exists`/`idempotency_in_progress`, 422 `party_size_not_allowed`/`validation_failed`/`idempotency_mismatch`, 400 idempotency |
| `GET /api/holds/{hold}` | Reservations | — | 200 `{data: Hold}` (status may now be `expired`) | 404 |
| `POST /api/reservations` | Reservations | `{hold_id: int, card_token: string\|null}` + Idempotency-Key | 201 `{data: Reservation}` | 404, 409 `hold_expired`/`hold_not_active`/`payment_in_progress`/`payment_conflict`/`wallet_balance_changed`/`idempotency_in_progress`, 402 `payment_failed`, 504 `payment_timeout`, 422 `card_required`/`validation_failed`/`idempotency_mismatch` |
| `GET /api/reservations` | Reservations | — | 200 `{data: Reservation[]}` | |
| `GET /api/reservations/{reservation}` | Reservations | — | 200 `{data: Reservation}` | 404 |
| `POST /api/reservations/{reservation}/cancel` | Reservations | `{}` + Idempotency-Key | 200 `{data: {reservation: Reservation, refund: {credit_cents, forfeited_cents, currency, late}}}` | 404, 409 `reservation_not_cancellable`/`reservation_started`/`idempotency_in_progress`, 422 `idempotency_mismatch` |
| `GET /api/wallet` | Wallet | — | 200 `{data: {currency: "USD", credit_cents, coupon_cents}}` | |
| `POST /api/webhooks/payments/fake` | Payments | C§11 payload, header `Fake-Signature` | 200 `{data: {duplicate: bool}}` | 401 `invalid_signature`, 422 |
| `POST /broadcasting/auth` | Realtime | Pusher auth form fields | 200 Pusher auth JSON | 403 |

Route model binding is not used across modules; controllers take `int` ids and call actions. Holds and reservations not owned by the caller are 404, never 403.

### Resource shapes

```jsonc
// Member
{"id": 1, "name": "Ada", "email": "ada@example.test"}
// City
{"id": 1, "name": "New York", "slug": "new-york", "country_code": "US"}
// Venue
{"id": 3, "city_id": 1, "name": "Carbone", "slug": "carbone", "timezone": "America/New_York", "status": "active", "base_per_person_cents": 25000, "currency": "USD"}
// Slot
{"id": 42, "venue_id": 3, "drop_id": null, "starts_at": "2026-11-08T01:00:00Z", "local_starts_at": "2026-11-07T20:00:00-05:00",
 "capacity": 6, "available": 2, "min_party_size": 1, "max_party_size": 2, "released_at": "2026-10-01T00:00:00Z", "bookable": true}
// Drop
{"id": 7, "venue_id": 3, "opens_at": "...", "opened": true, "sold_out": false, "slot_ids": [42, 43]}
// Hold
{"id": 9, "status": "active", "party_size": 2, "expires_at": "...", "slot": Slot,
 "quote": {"per_person_cents": 37500, "amount_cents": 75000, "currency": "USD", "expires_at": "..."}}
// Reservation
{"id": 5, "hold_id": 9, "status": "confirmed", "slot_id": 42, "venue_id": 3, "party_size": 2,
 "starts_at": "...", "local_starts_at": "...", "min_spend_cents": 75000, "currency": "USD",
 "payment": {"coupon_cents": 0, "credit_cents": 20000, "card_cents": 55000},
 "confirmed_at": "...", "cancelled_at": null}
```

`bookable` = `released_at <= now && starts_at > now && available > 0`. `had_availability` = some slot that local date with local hour = `hour`, `bookable`, and `min_party_size <= party_size <= max_party_size`.

---

## C§9. Error codes

| Code | HTTP | Raised by |
| --- | --- | --- |
| `validation_failed` | 422 | framework validation; `details.errors` = field → messages |
| `unauthenticated` | 401 | missing/invalid token |
| `invalid_credentials` | 401 | `POST /api/auth/token` |
| `forbidden` | 403 | channel auth |
| `not_found` | 404 | unknown route, or any `*NotFoundException` |
| `method_not_allowed` | 405 | framework |
| `rate_limited` | 429 | framework (unused on booking routes) |
| `http_error` | the exception's own 4xx status | any other framework `HttpException` with a 4xx status not listed above |
| `idempotency_key_required` | 400 | Shared |
| `idempotency_key_invalid` | 400 | Shared |
| `idempotency_mismatch` | 422 | Shared |
| `idempotency_in_progress` | 409 | Shared |
| `sold_out` | 409 | Inventory |
| `drop_not_open` | 409 | Inventory |
| `slot_unavailable` | 409 | Inventory |
| `active_hold_exists` | 409 | Inventory |
| `party_size_not_allowed` | 422 | Inventory |
| `hold_expired` | 409 | Inventory |
| `hold_not_active` | 409 | Inventory |
| `wallet_balance_changed` | 409 | Wallet |
| `payment_failed` | 402 | Payments (`details.decline_code`) |
| `payment_timeout` | 504 | Payments |
| `payment_in_progress` | 409 | Payments |
| `payment_conflict` | 409 | Payments |
| `invalid_signature` | 401 | Payments webhook |
| `card_required` | 422 | Reservations (`details.card_cents`) |
| `reservation_not_cancellable` | 409 | Reservations |
| `reservation_started` | 409 | Reservations |
| `server_error` | 500 | anything unexpected, and any 5xx `HttpException` |

No other code strings may be emitted without a contract change.

---

## C§10. Pricing rules

**Party bands** (`pricing.bands`, ordered, first match wins): `1-2` (1–2), `3-4` (3–4), `5+` (5 and up). `Pricing\Support\PartyBands::forPartySize(int): string` and `PartyBands::keys(): list<string>`, both static (internal class). Where `demand_signals` rows for one venue/weekday/hour disagree (fixtures only; the job writes them consistently), the quote uses the first non-null `sellout_seconds_p50` and the newest `computed_at`.

**Per-person price** for a hold (all integer math, `bps` = basis points, 10000 = 1.0×):

1. `base` = `basePerPerson.cents` (from the venue).
2. Local weekday `w` (ISO 1–7) and hour `h` of `startsAt` in `venueTimezone`.
3. `tier_bps`: `peak` (15000) if `w ∈ pricing.tiers.peak.days` [5, 6] and `h ∈` [19, 20, 21]; else `shoulder` (12000) if `w ∈` [4, 5, 6, 7] and `h ∈` [18, 19, 20, 21, 22]; else `off_peak` (10000).
4. Load `demand_signals` rows for (venue, w, h). None → `demand_bps = 10000`. Otherwise:
   - `band_unmet` = the hold's band row `unmet_searches` (0 if missing); `venue_hour_unmet` = sum over all band rows.
   - `unmet_used` = `band_unmet` if `band_unmet >= pricing.thin_band_threshold` (5), else `venue_hour_unmet` (`used_fallback = true`).
   - `unmet_bps = min(unmet_used * pricing.demand.per_unmet_search_bps (100), pricing.demand.unmet_cap_bps (5000))`.
   - `sellout_bps`: first rule in `pricing.demand.sellout` with `sellout_seconds_p50 <= max_seconds`: `[{max_seconds: 60, bps: 2500}, {max_seconds: 600, bps: 1000}]`; null p50 or no match → 0.
   - `demand_bps = 10000 + unmet_bps + sellout_bps`.
5. `R = pricing.rounding_cents` (100), `D = 10000 * 10000 * R`. `per_person = intdiv(base * tier_bps * demand_bps + intdiv(D, 2), D) * R`, then `max(per_person, pricing.min_per_person_cents)` (1000).
6. `amount = per_person * party_size`. Quote `expires_at` = hold `expires_at`.

Example: base 25000, Saturday 20:00, no signals → 37500 per person, 75000 for 2.

**inputs jsonb** (exact keys): `formula_version` (1), `base_per_person_cents`, `local_weekday`, `local_hour`, `tier`, `tier_bps`, `party_band`, `signals_computed_at` (string or null), `band_unmet`, `venue_hour_unmet`, `used_fallback`, `unmet_used`, `sellout_seconds_p50` (int or null), `unmet_bps`, `sellout_bps`, `demand_bps`, `rounding_cents`, `min_per_person_cents`.

**Demand-signals job** (`pricing:compute-demand-signals`, every 15 minutes, `withoutOverlapping`):

1. `since = now - pricing.signals_window_days` (28).
2. Unmet: `COUNT(*)` of `search_events` with `had_availability = false AND created_at >= since`, grouped by `(venue_id, local_weekday, requested_hour, party_band)`.
3. Sell-out: `GetSelloutStats(since)` keyed by `(venue_id, weekday, hour)`.
4. For every `(venue, weekday, hour)` present in either set, upsert one row **per configured band** with that band's unmet count (0 if none), the shared `sellout_seconds_p50` (null if none), `bid_count = 0`, `computed_at = now`.
5. Delete rows with `computed_at < now` (stale combos). Steps 4–5 in one transaction.

---

## C§11. PaymentGateway and FakeGateway

`payments.gateway` selects the binding (`fake` only in this run). `FakeGateway` is a singleton whose provider-side state lives in Redis so it is shared across Octane workers and queue workers:

- `fakegw:idem:{idempotencyKey}` → provider ref (gateway-side idempotency: a repeated `authorize`/`capture`/`refund` with the same key returns the first result and never creates a second charge).
- `fakegw:charges` hash: provider ref → JSON `{amount_cents, currency, status, idempotency_key, payment_id}`.
- Provider refs look like `fake_ch_<26-char ulid>`.

**Token knobs** (usable over HTTP, e.g. by k6):

| `card_token` | authorize | capture |
| --- | --- | --- |
| `tok_ok` | Authorized | Captured |
| `tok_decline` | Declined `card_declined` | — |
| `tok_insufficient_funds` | Declined `insufficient_funds` | — |
| `tok_timeout` | records the charge as authorized provider-side, then throws `GatewayTimeoutException` | — |
| `tok_capture_fail` | Authorized | Failed |
| `tok_dup_webhook` | Authorized, and every webhook for this charge is emitted twice | Captured |
| anything else | Declined `invalid_token` | — |

**Programmatic knobs** (tests; call on `app(PaymentGateway::class)`, which is the singleton): `declineNext(string $code = 'card_declined'): void`, `timeoutNext(): void`, `failNextCapture(): void`, `emitWebhooks(bool $on = true): void`, `duplicateWebhooks(bool $on = true): void`, `latency(int $ms): void`. Inspection: `charges(): list<array<string, mixed>>`, `authorizeCalls(): int`, `assertChargedOnce(string $idempotencyKey): void`.

**Config knobs**: `payments.fake.latency_ms` (env `FAKE_GATEWAY_LATENCY_MS`, default 0), `payments.fake.emit_webhooks` (`FAKE_GATEWAY_EMIT_WEBHOOKS`, default false), `payments.fake.duplicate_webhooks` (`FAKE_GATEWAY_DUPLICATE_WEBHOOKS`, default false), `payments.fake.webhook_secret` (`FAKE_GATEWAY_WEBHOOK_SECRET`).

**Webhook emission.** When enabled, after each successful gateway call the fake dispatches `Jobs\DeliverFakeWebhook` (after commit), which POSTs to `{APP_URL}/api/webhooks/payments/fake` with the real signature; duplicates reuse the same `id`.

**Webhook payload**

```json
{"id": "evt_01J...", "type": "payment.authorized", "created": 1792195200,
 "data": {"provider_ref": "fake_ch_01J...", "amount_cents": 55000, "currency": "USD", "metadata": {"payment_id": 12}}}
```

`type` ∈ `payment.authorized`, `payment.captured`, `payment.refunded`, `payment.failed`. Header `Fake-Signature: sha256=<hex HMAC-SHA256 of the raw body with webhook_secret>`. Static helpers for tests: `FakeGateway::webhookPayload(string $type, string $providerRef, Money $amount, int $paymentId, ?string $eventId = null): array` and `FakeGateway::sign(string $rawBody): string`.

**Webhook handling** (one transaction): verify signature (constant-time) → insert `payment_webhook_events` `ON CONFLICT (provider, event_id) DO NOTHING`; conflict → 200 `{duplicate: true}` with no other effect. Otherwise lock the payment by `provider_ref`, falling back to `metadata.payment_id`, and apply forward-only transitions:

| Event | From | To / effect |
| --- | --- | --- |
| `payment.authorized` | `pending` | `authorized`, store `provider_ref` |
| `payment.authorized` | `timed_out` | store `provider_ref`; dispatch `VoidPayment` after commit (orphaned auth) |
| `payment.captured` | `authorized` | `captured` |
| `payment.refunded` | `authorized`, `captured`, `timed_out` | `refunded` |
| `payment.failed` | `pending` | `failed` |
| anything else | any | no-op (event still recorded) |

Unknown payment → record the event, log `warning`, return 200. Webhooks never post ledger entries (the ledger posts at confirm; D9).

---

## C§12. Config keys

Module config files are `app/Modules/<Name>/config.php`, merged under the lowercased module name. Shared keys live in `config/idempotency.php` (Platform).

| Key | Default | Env | Owner |
| --- | --- | --- | --- |
| `inventory.hold_ttl_seconds` | 300 | `HOLD_TTL_SECONDS` | Inventory |
| `inventory.hold_expiring_notice_seconds` | 60 | — | Inventory |
| `inventory.gate.rebuild_lock_seconds` | 5 | — | Inventory |
| `inventory.gate.rebuild_recent_minutes` | 2 | — | Inventory |
| `inventory.sweep_batch` | 500 | — | Inventory |
| `reservations.cancel_window_hours` | 8 | `CANCEL_WINDOW_HOURS` | Reservations |
| `wallet.late_cancel.member_bps` | 5000 | `LATE_CANCEL_MEMBER_BPS` | Wallet |
| `wallet.late_cancel.platform_bps` | 2500 | `LATE_CANCEL_PLATFORM_BPS` | Wallet |
| `wallet.currency` | `USD` | — | Wallet |
| `pricing.bands` | `[['key'=>'1-2','min'=>1,'max'=>2],['key'=>'3-4','min'=>3,'max'=>4],['key'=>'5+','min'=>5,'max'=>null]]` | — | Pricing |
| `pricing.thin_band_threshold` | 5 | — | Pricing |
| `pricing.signals_window_days` | 28 | — | Pricing |
| `pricing.search_retention_days` | 90 | — | Pricing |
| `pricing.tiers` | C§10 | — | Pricing |
| `pricing.demand.per_unmet_search_bps` | 100 | — | Pricing |
| `pricing.demand.unmet_cap_bps` | 5000 | — | Pricing |
| `pricing.demand.sellout` | C§10 | — | Pricing |
| `pricing.rounding_cents` | 100 | — | Pricing |
| `pricing.min_per_person_cents` | 1000 | — | Pricing |
| `payments.gateway` | `fake` | `PAYMENTS_GATEWAY` | Payments |
| `payments.fake.*` | C§11 | C§11 | Payments |
| `realtime.outbox.batch` | 100 | — | Realtime |
| `realtime.outbox.sleep_ms` | 100 | — | Realtime |
| `realtime.outbox.retention_days` | 7 | — | Realtime |
| `idempotency.stale_after_seconds` | 30 | — | Platform |
| `idempotency.retention_hours` | 24 | — | Platform |

The validity rule `member_bps + platform_bps <= 10000` is asserted in `WalletServiceProvider::boot()` (throws on misconfiguration).

---

## C§13. Scheduler

Registered by each module's provider `schedule()` hook. All use `->withoutOverlapping()->onOneServer()`.

| Command | Cadence | Owner | Does |
| --- | --- | --- | --- |
| `inventory:expire-holds` | every minute | Inventory | expire overdue holds (batched, one txn each), write `hold.expiring` for active holds with `expires_at - now <= notice` and `expiring_notified_at IS NULL`, rebuild recently touched gates |
| `inventory:open-drops` | every minute | Inventory | for drops with `opens_at <= now` and `opened_at IS NULL`: set `opened_at`, write `drop.opened` (one txn per drop). Holds do not wait for this; `released_at` governs bookability. |
| `pricing:compute-demand-signals` | every 15 minutes | Pricing | C§10 |
| `pricing:prune-search-events` | daily 04:00 UTC | Pricing | |
| `wallet:reconcile` | daily 03:00 UTC | Wallet | |
| `idempotency:prune` | daily 04:30 UTC | Platform | |
| `realtime:prune-outbox` | daily 05:00 UTC | Realtime | |

`realtime:publish-outbox` is not scheduled; it runs continuously in the `outbox` container.

---

## C§14. Docker, environment and commands

### Compose services (fixed names)

| Service | Image / command | Internal port | Host port |
| --- | --- | --- | --- |
| `app` | built from `Dockerfile`; `php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=${OCTANE_WORKERS:-auto} --max-requests=1000` | 8000 | `${APP_PORT:-8000}` |
| `worker` | same image; `php artisan queue:work redis --tries=5` | — | — |
| `scheduler` | same image; `php artisan schedule:work` | — | — |
| `outbox` | same image; `php artisan realtime:publish-outbox`; `profiles: [realtime]` until ticket 07 removes the profile | — | — |
| `reverb` | same image; `php artisan reverb:start --host=0.0.0.0 --port=8080` | 8080 | `${REVERB_PORT:-8080}` |
| `postgres` | `postgres:16.15-bookworm`, `-c max_connections=200`; init script creates `dorsia` and `dorsia_test` | 5432 | `${FORWARD_DB_PORT:-5432}` |
| `redis` | `redis:7.4.11-bookworm` | 6379 | `${FORWARD_REDIS_PORT:-6379}` |
| `k6` | `grafana/k6:1.8.2`; `profiles: [loadtest]`; mounts `./loadtest:/loadtest` | — | — |

The repo is bind-mounted at `/app` in every PHP service. No `container_name:` anywhere (it would collide across worktrees). `app` has a healthcheck on `GET /up`; `postgres` and `redis` have healthchecks; dependents use `condition: service_healthy`.

### Worktree isolation

Each worktree has its own `.env` generated by `bin/worktree-env <NN>`, where `NN` is the ticket number (`00` = the `build/v1` integration checkout used by the orchestrator and final eval). It sets `COMPOSE_PROJECT_NAME=dorsia-<NN>` and ports with offset `NN * 100`:

| Var | Formula | Ticket 03 example |
| --- | --- | --- |
| `APP_PORT` | 8000 + NN·100 | 8300 |
| `REVERB_PORT` | 8080 + NN·100 | 8380 |
| `FORWARD_DB_PORT` | 5432 + NN·100 | 5732 |
| `FORWARD_REDIS_PORT` | 6379 + NN·100 | 6679 |

These ranges cannot collide for NN 00–09. Containers always talk on internal ports by service name, so only host ports change.

### Environment (`.env.example`)

```
APP_NAME=Dorsia
APP_ENV=local
APP_DEBUG=true
APP_URL=http://app:8000
COMPOSE_PROJECT_NAME=dorsia-00
APP_PORT=8000
REVERB_PORT=8080
FORWARD_DB_PORT=5432
FORWARD_REDIS_PORT=6379
OCTANE_SERVER=frankenphp
OCTANE_WORKERS=auto
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=dorsia
DB_USERNAME=dorsia
DB_PASSWORD=dorsia
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PREFIX=dorsia:
REDIS_DB=0
REDIS_CACHE_DB=1
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=array
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=dorsia
REVERB_APP_KEY=dorsia-key
REVERB_APP_SECRET=dorsia-secret
REVERB_HOST=reverb
REVERB_PORT_INTERNAL=8080
REVERB_SCHEME=http
PAYMENTS_GATEWAY=fake
FAKE_GATEWAY_WEBHOOK_SECRET=fake-webhook-secret
FAKE_GATEWAY_LATENCY_MS=0
FAKE_GATEWAY_EMIT_WEBHOOKS=false
FAKE_GATEWAY_DUPLICATE_WEBHOOKS=false
HOLD_TTL_SECONDS=300
CANCEL_WINDOW_HOURS=8
LATE_CANCEL_MEMBER_BPS=5000
LATE_CANCEL_PLATFORM_BPS=2500
```

`config/reverb.php` reads the server port from `REVERB_PORT_INTERNAL` (host `REVERB_PORT` differs per worktree). `phpunit.xml` overrides: `APP_ENV=testing`, `DB_DATABASE=dorsia_test`, `REDIS_DB=2`, `REDIS_CACHE_DB=3`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`, `CACHE_STORE=redis`, `BCRYPT_ROUNDS=4`.

### Standard commands (Makefile; every engineer and eval agent uses exactly these)

| Command | Runs |
| --- | --- |
| `make setup` | build images, `composer install`, copy `.env` if missing, `key:generate`, `up`, `migrate:fresh --seed` |
| `make up` / `make down` | `docker compose up -d --wait` / `docker compose down` |
| `make fresh` | `php artisan migrate:fresh --seed` in `app` |
| `make test` | `php artisan test` (all suites) in `app`; `make test ARGS="--filter=Hold"` passes args |
| `make test-module M=Inventory` | `vendor/bin/pest tests/Modules/Inventory tests/Arch/Contracts/InventoryContractTest.php` |
| `make arch` | `vendor/bin/pest tests/Arch` |
| `make stan` | `vendor/bin/phpstan analyse --memory-limit=1G` (level 8; paths `app`, `database`, `routes`, `config`; `treatPhpDocTypesAsCertain: false`; no baseline, no `ignoreErrors` without architect approval) |
| `make pint` | `vendor/bin/pint --test` |
| `make pint-fix` | `vendor/bin/pint` |
| `make ci` | `pint`, `stan`, `test` in that order; non-zero exit on first failure |
| `make drop` | ticket 08: seed drop, run k6, run verification (C§15) |

All PHP commands run as `docker compose exec -T app ...`. CI (`.github/workflows/ci.yml`) runs `make setup` then `make ci` on `ubuntu-latest` for every push and PR to `build/v1` and `main`.

---

## C§15. Test conventions

- **Real Postgres and Redis only.** No SQLite, no array Redis. Tests run inside the `app` container against `dorsia_test` and Redis DB 2.
- **Locations.** `tests/Modules/<Module>/Unit` (no DB), `.../Feature` (HTTP and action tests), `.../Concurrency` (parallel tests). Shared tests mirror that under `tests/Shared/`.
- **Pest bootstrap (`tests/Pest.php`, Platform).**
  - `Unit`: `Tests\TestCase`, no DB trait.
  - `Feature`: `Tests\TestCase` + `RefreshDatabase`; `beforeEach` runs `DB::statement('SET CONSTRAINTS ALL IMMEDIATE')` and flushes Redis on both the `default` and `cache` connections (cache holds gate-rebuild locks and scheduler mutexes).
  - `Concurrency`: `Tests\TestCase` + `DatabaseTruncation`, `->group('concurrency')`; `beforeEach` flushes Redis (both test DBs).
  - `tests/Performance`: bound exactly like `Feature` (RefreshDatabase, immediate constraints, Redis flush).
  - If Pest's `in()` does not accept globs, Platform lists every module directory explicitly; module engineers never edit `Pest.php`.
- **Time.** Use `$this->travelTo()` / `$this->travel()`. Application code never uses SQL `now()` (C§3), so time travel is honored everywhere.
- **Concurrency helper** `Tests\Support\Concurrency` (Platform), built on `spatie/fork`:

  ```php
  /** Runs each closure in its own forked process after DB::purge()/reconnect and Redis purge. Returns results in order. */
  public static function run(array $closures): array;
  /** Fires $n requests in parallel; each closure returns a request spec. Result: list<array{status: int, json: array<mixed>, headers: array<string, string>}> */
  public static function requests(int $n, \Closure $make): array;  // $make(int $i): array{method: string, uri: string, token: string, body?: array<mixed>, headers?: array<string, string>}
  ```

  Children send requests through the in-process HTTP kernel with a real Sanctum bearer token created before forking. Concurrency tests use **at least 20 parallel processes**, commit their fixtures (that is why they use `DatabaseTruncation`), and assert on database state afterwards, not only on responses.
- **Idempotency tests.** Every write endpoint has (a) a same-key same-body replay test asserting identical status and body plus `Idempotent-Replayed: true`, (b) a same-key different-body test asserting 422 `idempotency_mismatch`, and (c) an assertion that the replay caused no extra rows (holds, payments, ledger entries, outbox rows).
- **Factories and seeders.** Each module owns factories for its models in `Database/Factories` (models override `newFactory()`). Tests and root seeders may use any module's factories; module factories and seeders obey C§2 boundaries. `DatabaseSeeder` (Platform) calls, if the class exists: `MemberSeeder`, `Inventory\Database\Seeders\InventorySeeder`, `Wallet\Database\Seeders\WalletSeeder`. `DropTestSeeder` is invoked only explicitly.
- **Contract tests.** Each engineer adds `tests/Arch/Contracts/<Module>ContractTest.php` that uses reflection to assert every public class, method signature (parameter names, types, return type) and enum case listed in C§5 for that module. Eval gate 4 runs these.
- **Fakes.** Use `Queue::fake()` / `Event::fake()` only where the assertion is "was dispatched". Never fake the database, Redis or the outbox writer.

---

## Decisions

Recorded where the build plan is silent or ambiguous. Change only via the architect.

- **D1. Slot = one table shape.** A slot is N identical tables (`capacity`) for a party-size range at one time; each hold or reservation takes one table. "Not enough capacity for the party size" (§2) means no slot at that hour with a fitting range and a free table. This matches "set bands to match the venues' real table shapes."
- **D2. `held_count` column on slots.** §4 says "held + booked under capacity"; a counter column makes that a single conditional `UPDATE` and a DB CHECK.
- **D3. Six modules for this run.** Inventory, Pricing, Reservations, Wallet, Payments, Realtime. Memberships and Integrations (§8) are out of scope; Payments and Realtime are modules because they have separate owners.
- **D4. Members and auth live in `app/Shared`**, owned by Platform. No `tier` column this run.
- **D5. Idempotency at two levels.** HTTP middleware on `(member, key)` replays responses; domain uniques (`holds.idempotency_key`, `reservations.idempotency_key`, `payments.idempotency_key`, `ledger_transactions.idempotency_key`, `reservations.hold_id`, `price_quotes.hold_id`) are the backstop. Header keys are scoped by member id before storage.
- **D6. Replays store 4xx responses.** A first `sold_out` replays as `sold_out` (§4: "return the first result"). 5xx are not stored, so they can be retried.
- **D7. Card is authorized before the confirm transaction and captured by a queued job after commit.** No network calls inside DB transactions. The payment idempotency key is per hold (`hold:{id}:auth`), so concurrent confirms on one hold can never create two authorizations.
- **D8. Payment decline or timeout releases the hold** (§4 "Release hold: payment fails"). `wallet_balance_changed`, `card_required`, `payment_in_progress` keep it.
- **D9. Ledger posts at confirm, not on capture/webhook.** The authorization guarantees the funds; a later capture failure is logged critical and left for staff (admin API is out of scope).
- **D10. Sign convention.** Entry `amount_cents` positive = debit, negative = credit. `cash` (card clearing) is debit-normal; all others credit-normal. Member balances can never go negative (DB CHECK).
- **D11. `bid_count` is always 0.** Custom requests are not in steps 1–8.
- **D12. Searches dedupe on venue-local date and hour**, not a UTC hour, so half-hour-offset timezones work. Window and pruning use `created_at`.
- **D13. Rule-based tiers** are peak Fri–Sat 19:00–21:59 (1.5×), shoulder Thu–Sun 18:00–22:59 (1.2×), otherwise 1.0×, venue-local; then demand multiplier from signals. Integer basis-point math, rounded to whole dollars.
- **D14. Late-cancel rounding remainder stays with the venue.** `member = floor(amount × 50%)`, `platform = floor(amount × 25%)`.
- **D15. Cancel boundary.** Exactly 8:00:00 before `starts_at` is early (§5 "8+ hours"). At or after `starts_at`, cancel is rejected (`reservation_started`).
- **D16. No app-level rate limiting on booking routes.** §7 puts it at the edge; the k6 test hits the app directly.
- **D17. Transaction assertions are relative to a base level.** `Transactions` keeps a static `$baseLevel` (0 in production). `Tests\TestCase::setUp()` sets it to `DB::transactionLevel()` after `RefreshDatabase` opens its wrapper transaction and resets it in `tearDown()`. `assertActive()` requires `level > base`; `assertNone()` requires `level <= base`. So the assertions mean the same thing in Feature tests as in production.
- **D18. One active hold per member per slot.** Fairness in a drop; enforced by a partial unique index.
- **D19. Lazy expiry is on hold reads and confirm, not on availability listings.** Availability shows `capacity - held - booked`; expired-but-unswept holds can make a slot look full for up to a minute. `HoldSlot` expires overdue holds on its slot before reporting sold out from Postgres. The Redis gate can falsely report sold out for at most one sweep interval after a crash.
- **D20. Drop-in push notifications and tier-ordered "who hears first" are out of scope.** They need memberships (step 9) and a push provider (Expo). `slot.released` is still written and broadcast on the venue channel.
- **D21. `drop.opened` is published by a minute scheduler**, so it may arrive up to 60 s after `opens_at`; bookability depends only on `released_at`. `drop.sold_out` fires when every slot in the drop is fully booked.
- **D22. `IssueGoodwillCredit` exists as an action without an endpoint** so seeders and the E2E test can fund wallets; the admin endpoint is step 10.
- **D23. Spend order uses the coupon account balance** (always 0 this run). Lot-level "nearest expiry first" arrives with `coupon_lots` in step 9.
- **D24. All seeded venues use USD**, in US timezones including one without DST (Pacific/Honolulu). Money refuses mixed currencies.
- **D25. No cross-module foreign keys** except to `members`. Integrity across modules is maintained by the actions and the denormalized columns on `reservations`.
- **D26. Single outbox publisher** for strict id order; `FOR UPDATE SKIP LOCKED` still makes a second one safe.
- **D27. Telescope is not installed** this run (it skews k6 latency and is not needed by any gate).
- **D28. Drop test shape.** One venue, one drop, one slot with `capacity = 50` for parties of 1–2: the worst case, all 5,000 members racing one row and one counter.
