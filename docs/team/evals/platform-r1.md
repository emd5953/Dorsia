# Platform (ticket 01): eval round 1

- Branch: `feat/platform-skeleton` @ `e533998`, worktree `.claude/worktrees/agent-ad569963a6d2726df`, stack `dorsia-01`
- Judged against: ticket 01, contracts.md (including the amendment on `build/v1` @ `2b3a131`), process.md "Module eval"
- Date: 2026-10-16

**Verdict: FAIL.** All gates pass. Score is 86/100, below the bar of 90.

The branch is not rebased onto `build/v1`. It is based on `779c1ed`, but `build/v1` is now at `2b3a131` ("Contracts: consoleCommands hook, http_error code, stan and Performance test rules"). process.md requires a rebase before each eval round. That amendment changes how deviation (2) is judged (see below).

## Hard gates

| # | Gate | Command | Result |
| --- | --- | --- | --- |
| 1 | Full Pest suite on real Postgres | `make ci` (in worktree) -> `php artisan test` | PASS: 114 tests, 806 assertions, exit 0 |
| 1b | Fresh clone | `git clone` -> `bin/worktree-env 02` -> `make setup` -> `curl localhost:8200/up` -> `make ci` | PASS: `/up` returned 200, 114 passed, exit 0 |
| 2 | PHPStan L8, no baseline; Pint | `make stan`, `make pint` | PASS: "No errors" on 70 files, no `phpstan-baseline*`, no `ignoreErrors`; Pint clean on 87 files |
| 3 | Arch | `make arch` with a probe that adds `use App\Modules\Wallet\Models\X;` to `app/Modules/Inventory/Actions/GetSelloutStats.php` | PASS: the probe fails the source-scan rule. A second probe adds `use App\Modules\Inventory\...` to `app/Shared/Outbox/Outbox.php`, and both Shared rules fail. Both probes were reverted and the tree is clean. Pest's `not->toUse` alone does not flag the unused import of a class that does not exist. The source scan does, so the rule holds. |
| 4 | Contract items | Read against C§2/C§4/C§5.1 | PASS on signatures. `Money`, `Currency`, `CurrencyMismatchException`, `IdempotencyKey`, `EnsureIdempotency`, the four exceptions with matching statuses, `Outbox`, `OutboxTopic` (8 cases), `OutboxEvent`, `DomainError`, `Transactions`, `ModuleServiceProvider` (amended hooks), `Member` all match. The three Inventory stubs match C§5.1 byte for byte. Error-code conformance fails against the amended C§9; this is counted as defect 1, not as a gate failure. |

Other checks:
- Ran two stacks side by side: `docker compose ls` showed `dorsia-01` and `dorsia-02` running(6) with no conflicts.
- actionlint passes (exit 0).
- No `container_name` in `docker-compose.yml`. The `outbox` service is under the `realtime` profile and `k6` under `loadtest`.
- `schedule:list` shows `idempotency:prune` at `30 4 * * *` UTC.
- Every changed file is in the ticket's allowed list.

## Acceptance criteria

| Criterion | Result | Evidence |
| --- | --- | --- |
| Clean clone setup, then `/up` returns 200 | PASS | Fresh clone as worktree 02 |
| Two worktrees side by side | PASS | `dorsia-01` and `dorsia-02` both running |
| `make ci` green, no baseline | PASS | See gates |
| `make arch` fails on the two violations | PASS | Probes above |
| `migrate:fresh --seed` tables and partial index | PASS | `\dt`: failed_jobs, idempotency_keys, members, migrations, outbox_events, personal_access_tokens. `outbox_events_unpublished_idx btree (id) WHERE published_at IS NULL` is present. Unique `(member_id,key)`, the status CHECK and the `created_at` index are present. |
| Auth flow | PASS | Live curl on :8100: login returns 201 with a token; `/api/me` returns 200 `{data:{id,name,email}}`; bad password returns 401 `invalid_credentials`; no token or a garbage token returns 401 `unauthenticated`. Also checked: 404 `not_found`, 405 `method_not_allowed`, malformed JSON returns 422 `validation_failed`. |
| Idempotency per C§8 | PASS, with defects 2 to 4 | Tests plus probes: same key on another path returns 422; nested key order is canonical; list order matters; an empty body hashes as `{}` |
| Outbox outside, rolled-back and committed transaction | PASS | Tests exist. Mutation: removing `Transactions::assertActive()` makes a test fail. |
| Money: negatives, mixed currencies, `bps` floors | PASS, with defect 6 | |
| `Concurrency::requests(20, …)` really parallel | PARTIAL (defect 5) | The self-test uses `Concurrency::run`, not `requests` |
| Transactions D17 | PASS | Unit and Feature tests. A probe in `tests/Modules/X/Feature` confirms the `Modules/*/Feature` glob in `Pest.php` binds RefreshDatabase, and the base level equals the wrapper level. |
| actionlint | PASS | |

Mutation probes, each reverted:
- Remove the hash comparison: 3 tests fail.
- Remove `assertActive` in Outbox: 1 test fails.
- Store 5xx responses: 1 test fails.
- Drop the replay header: 4 tests fail.
- **Remove the stale-takeover CAS (`where locked_at = :old`): suite stays green** (defect 3).

## Engineer-reported deviations

1. `commands()` renamed to `consoleCommands()`: **acceptable.** Ratified in contracts C§2 by `2b3a131`.
2. Non-mapped 4xx HttpException renders `server_error` with its original status: **defect.** The amended C§9 adds `http_error` for these and keeps `server_error` for 5xx. See defect 1.
3. phpstan `treatPhpDocTypesAsCertain: false`: **acceptable.** Ratified in C§14.
4. `tests/Performance` bound as Feature tests: **acceptable.** Ratified in C§15.

## Score

| Area | Points | Earned | Justification |
| --- | --- | --- | --- |
| Correctness | 40 | 35 | Every ticket rule traced to code and a test, and ACs re-verified live. Lost 3 because 4xx framework errors emit a code the amended contract forbids (defect 1). Lost 2 because replays are not the stored response byte for byte (defect 2). |
| Edge cases | 25 | 22 | Good coverage: key length 200/201, unicode and newline keys, canonical JSON, the stale boundary, 5xx not stored, the debug-off trace check (also verified that debug on leaks nothing). Lost 2 for Money with negative `bps` and silent truncation, and TypeError on overflow (defect 6). Lost 1 because no test covers unmapped 4xx and 5xx HttpExceptions. |
| Concurrency / idempotency | 20 | 15 | The 20-way race test is solid: one execution, then 201 or 409, never 5xx, and it asserts DB state. Lost 2 because the stale-takeover CAS is unprotected by any test (defect 3). Lost 2 because completion and forget are not fenced to the lock owner (defect 4). Lost 1 because the parallelism self-test does not exercise `requests()` (defect 5). |
| Code quality | 15 | 14 | Thin controllers, clean shared kernel, no dead modules, no logic beyond the ticket's scope. Lost 1 for dead branches in `ErrorEnvelope::fromThrowable`: Laravel's `prepareException` converts `ModelNotFoundException` and `AuthorizationException` to HttpExceptions before render callbacks run. |
| **Total** | 100 | **86** | |

## Defects

1. **Major: unmapped 4xx HttpException renders `server_error`, and the branch is not rebased.**
   - Where: `app/Shared/Exceptions/ErrorEnvelope.php:68`.
   - Probe: `abort(400)` returns 400 `{"error":{"code":"server_error",...}}`, and `abort(419)` returns 419 `server_error`. The amended C§9 (`build/v1` @ `2b3a131`) requires `http_error` with the exception's own 4xx status. `server_error` is only for 500 and 5xx HttpExceptions.
   - Fix: rebase onto `build/v1`. In the `default` arm, return `http_error` when the status is 400 to 499 and `server_error` (keeping the status) when it is 5xx. Add tests for `abort(400)`, `abort(419)` and `abort(503)`.

2. **Major: idempotent replays are not the stored response byte for byte.**
   - Where: `app/Shared/Idempotency/EnsureIdempotency.php:101-111` (store) and `:133-140` (replay); column `response_body jsonb` in `database/migrations/2026_10_01_000400_create_idempotency_keys_table.php`.
   - Probe: the first response is `{"data":{"call":1}}`; the replay is `{"data": {"call": 1}}`. jsonb also reorders object keys (shorter keys first), so a Hold or Reservation body replays with different key order. C§15 asks for an "identical ... body", and ticket 08 counts any non-identical retry as `unexpected`. The current test uses `assertExactJson`, which sorts and decodes both sides, so it hides the difference.
   - Fix: raise with the architect a contract change from `response_body jsonb` to `json` (which preserves the text exactly) or `text`. Until then, at minimum re-encode on replay with the same flags Laravel uses. Add `expect($second->getContent())->toBe($first->getContent())` to the replay test, using a body with several keys of different lengths.

3. **Minor: the stale-takeover CAS has no test.**
   - Where: `EnsureIdempotency.php:68-72`; tests in `tests/Shared/Feature/IdempotencyMiddlewareTest.php` and `tests/Shared/Concurrency/IdempotencyRaceTest.php`.
   - Mutation: deleting `->where('locked_at', $record->getRawOriginal('locked_at'))` leaves all 114 tests green. My concurrency probe (one stale `processing` row committed, then 20 parallel requests) shows exactly 1 execution with the CAS and 2 executions without it.
   - Fix: add that concurrency test. Seed a committed stale row, fire 20 parallel same-key requests, and assert exactly one handler row, with the rest 409 or replay and no 5xx.

4. **Minor: completion and forget are not fenced to the request that holds the lock.**
   - Where: `EnsureIdempotency.php:104-112` (update) and `:125-131` (`forget`). Both match on `(member_id, key)` only.
   - Scenario: request A stalls for more than 30 s, and request B takes over and completes with a 201. When A finishes:
     - if A gets a 4xx (say 409 `active_hold_exists`, because B already created the hold), A's update overwrites B's stored response, so later replays flip from 201 to 409;
     - if A gets a 5xx, A deletes B's completed row, so the next retry re-executes.
   - Fix: keep the `locked_at` value written at insert or takeover. Add `->where('status', 'processing')->where('locked_at', $lockedAt)` to both the completion update and the delete. If the update affects 0 rows, return the handler's response without storing it.

5. **Minor: the parallelism self-test does not exercise `Concurrency::requests`.**
   - Where: `tests/Shared/Concurrency/ConcurrencyHelperTest.php:9-40` uses `Concurrency::run`. The acceptance criterion names `Concurrency::requests(20, ...)`, the HTTP path the module engineers will actually use.
   - Fix: add a second self-test. Register a test route whose handler inserts under `pg_sleep(0.5)`, call `Concurrency::requests(20, ...)`, and assert it finishes in under 5 s with 20 rows and 20 2xx responses.

6. **Minor: Money edge cases.**
   - Where: `app/Shared/Money/Money.php:61-64`. `bps()` accepts negative basis points: `Money::of(100,'USD')->bps(-1)` silently returns 0 cents, because `intdiv` truncates toward zero rather than flooring. Larger negative values throw a misleading "Money cannot be negative".
   - Where: `Money.php:35`, `:51`, `:63`. Overflow in `add`, `multiply` and `bps` surfaces as a `TypeError` (float passed to the int constructor or to `intdiv`) instead of a meaningful exception.
   - Fix: throw `InvalidArgumentException` when `$basisPoints < 0`. Detect overflow (for example `is_int()` on the intermediate result) and throw `OverflowException`. Add unit tests for both.

## Notes (not scored)

- `tests/Arch/ModuleBoundariesTest.php` scans the whole of `app/Modules/<X>`, including `Database/Seeders` and `Database/Factories`. C§2 exempts seeders from the boundary rule, so a future module seeder that uses another module's factory would fail arch. Consider excluding `Database/Seeders` from the module-to-module scan, or document the limitation.
- `AuthTokenController` skips the hash check when the email is unknown, which leaks account existence through response timing. Low risk (no rate limiting is in scope), but a dummy `Hash::check` is cheap.
- `config/sanctum.php` keeps `'guard' => ['web']`. It is harmless with no stateful domains and no session middleware on `api`, but `[]` would make the API strictly bearer-only.
