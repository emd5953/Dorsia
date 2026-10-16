# Platform (ticket 01): eval round 2

- Branch: `feat/platform-skeleton` @ `276f25e`, rebased on `build/v1` (`83e4b47`)
- Judged against: ticket 01, contracts.md (C§2/C§3/C§8/C§9 as amended), process.md "Module eval"
- Date: 2026-10-16

**Verdict: PASS, 92/100.** All gates pass; all six round-1 defects are fixed, each confirmed by mutating the fix away and watching a test fail.

## Hard gates

| # | Gate | Result |
| --- | --- | --- |
| 1 | `make ci` on real Postgres | 137 passed, 895 assertions; same after all probes; concurrency group re-run 8x with 0 failures |
| 2 | PHPStan L8, Pint | No errors, no baseline, no `ignoreErrors`; Pint clean on 87 files |
| 3 | Arch | Wallet-model import in Inventory fails 1 test; Modules import in `App\Shared` fails 2; both reverted |
| 4 | Contract items | C§2 `consoleCommands`, C§3 columns, C§8 fencing and byte-exact replay, C§9 `http_error`: all present; all changed files in the ticket's list |

Live checks on :8100: `/up` 200; login 201; `/api/me` 200; bad password 401 `invalid_credentials`; no token 401 `unauthenticated`; `POST /api/me` 405 `method_not_allowed`. actionlint passes; `idempotency:prune` scheduled at 04:30.

## Round-1 defects

| # | Fix | Mutation | Result |
| --- | --- | --- | --- |
| 1 | `http_error` for unmapped 4xx | Revert to `server_error` | 4 tests fail |
| 2 | Byte-exact replay | Pretty-print body; force Content-Type | 1 test fails each |
| 3 | Takeover compare-and-swap test | Delete the `lock_token` match | Caught 10 of 17 runs (see defect 1) |
| 4 | Fenced completion and delete | Delete the `lock_token` match | 3 cases fail |
| 5 | `requests()` parallelism test | Run serially; `Fork::concurrent(2)` | Fails both |
| 6 | Money edge cases | Remove negative guard; remove overflow checks | Fails each |

## Score

| Area | Pts | Earned | Why points were lost |
| --- | --- | --- | --- |
| Correctness | 40 | 38 | Failed completion update returns 500 after commit (5); error renderer drops HttpException headers (6) |
| Edge cases | 25 | 23 | Hash collisions between different bodies (4); storage limits unhandled (5) |
| Concurrency / idempotency | 20 | 17 | Takeover race test probabilistic (1); status filter untested (2); replay identity and warning log partly guarded (3, 7) |
| Code quality | 15 | 14 | Unreachable `catch (Throwable)` in `execute()` |
| **Total** | 100 | **92** | |

## New defects (all low, none blocking)

1. Takeover race test (`tests/Shared/Concurrency/IdempotencyRaceTest.php:13-61`) catches a removed compare-and-swap only 10 of 17 runs. Add a deterministic test that changes `lock_token` between the middleware's read and update via `DB::listen`.
2. Takeover's `status='processing'` filter (`EnsureIdempotency.php:75`) is untested; removing it stays green. A waiting request could take over a just-completed row and run the handler twice. Same technique as 1.
3. Replay identity partly guarded (`IdempotencyMiddlewareTest.php:103-127`): a decode/re-encode passes. Use a body with `{}` and `1.0`; compare bytes in the 4xx replay test too.
4. Canonical JSON (`EnsureIdempotency.php:179-203`) treats `{"x":{}}` and `{"x":[]}`, and `{"1":"a","0":"b"}` and `["b","a"]`, as the same body. Preserve objects when canonicalising.
5. A failed completion update (`EnsureIdempotency.php:110-116`; e.g. a 140-char Content-Type vs `varchar(100)`) returns 500 after the handler committed and leaves the row `processing`. Catch it, log, delete the owned row, return the handler's response.
6. `ErrorEnvelope.php:51-61` drops `$e->getHeaders()`: 405 has no `Allow`, 429/503 lose `Retry-After`.
7. The "ownership lost" warning (`EnsureIdempotency.php:137-143`) is never asserted; add `Log::spy()`.

## Notes (not scored)

- `Concurrency::run`'s start barrier is a fixed 0.75 s wait, so it can't deadlock, but it costs 0.75 s per call and silently stops synchronising if forking takes longer. Replace with a real barrier plus a per-child timeout so a hung module test can't stall CI.
