# 08 — k6 drop test

| | |
| --- | --- |
| Owner | Performance |
| Depends on | 06 and 07 merged into `build/v1` |
| Blocks | final product eval |
| Branch | `feat/perf-k6` off `build/v1` |
| Worktree env | `bin/worktree-env 08` → `dorsia-08`, ports 8800 / 8880 / 6232 / 7179 |
| Build plan | §1 (drop burst, p95 < 300 ms, zero double bookings, zero ledger drift), §9 drop test and pass table, §9 step 8 |
| Contracts | C§8 (hold/confirm endpoints, idempotency), C§11 token knobs, C§14 (`k6` service, `make drop`), C§15, D16, D28 |

## Files you may touch

- `loadtest/**` except `loadtest/realtime-smoke.js`
- `database/seeders/DropTestSeeder.php`
- `tests/Performance/**`
- `Makefile`: **only** the `drop`, `drop-seed`, `drop-run`, `drop-verify` targets
- `.gitignore`: add `loadtest/.data/` and `loadtest/results/*.json` if not present

You do **not** fix application code. When a check fails, write the finding (symptom, evidence, suspected module, reproduction command) in `loadtest/RESULTS.md` and hand it to the owning engineer via the orchestrator. Config-only tuning (e.g. `OCTANE_WORKERS`, Postgres `max_connections`) is proposed to Platform the same way.

## Non-goals

Edge simulation (Cloudflare, waiting room, rate limits), Lambda pre-warming, websocket load, multi-venue drops.

## Work

1. `DropTestSeeder` (run with `php artisan db:seed --class=DropTestSeeder`; assumes `migrate:fresh` first):
   - 5,000 members `drop-member-{0001..5000}@dorsia.test` inserted in bulk; one Sanctum token each, inserted in bulk with known plain-text tokens.
   - One venue (New York, base 25000 USD), one drop opening at seed time + 60 s, one slot at the next Saturday 20:00 local with `capacity = 50`, party size 1–2 (D28).
   - 1,000 of the members (every fifth) receive a 10000-cent goodwill credit via `IssueGoodwillCredit` (key `drop:goodwill:{member_id}`) so wallet + card splits are exercised.
   - Writes `loadtest/.data/tokens.json` (`[{member_id, token}]`) and `loadtest/.data/drop.json` (`{venue_id, drop_id, slot_id, opens_at}`).
   - Completes in under 60 s.
2. `loadtest/drop.js` (k6 1.8.2):
   - Scenario `per-vu-iterations`, 5,000 VUs, 1 iteration each; each VU sleeps until `opens_at` + uniform random 0–10 s (the 10-second ramp), then:
   - `POST /api/holds {slot_id, party_size: 2}` with `Idempotency-Key: <uuid K1>`, then the **same request again** with K1; check both responses are identical (status and body).
   - If 201: `POST /api/reservations {hold_id, card_token: "tok_ok"}` with key K2, then repeat with K2; check identical.
   - Tags requests `name:hold` / `name:confirm`. Custom counters: `confirmed`, `sold_out`, `server_errors` (any 5xx), `unexpected` (anything other than 201/409 `sold_out`/replay-equal).
   - Thresholds: `http_req_duration{name:hold}` and `{name:confirm}` `p(95)<300`; `server_errors` `count==0`; `unexpected` `count==0`; `confirmed` `count==50`.
   - Writes a JSON summary to `loadtest/results/<timestamp>.json` via `handleSummary`.
3. `loadtest/verify.sql` — psql script with `DO` blocks that `RAISE EXCEPTION` on failure (run with `ON_ERROR_STOP=1`):
   - reservations with status `confirmed` for the drop slot = 50;
   - double bookings = 0: `booked_count = 50`, `held_count + booked_count <= capacity`, no two reservations share a `hold_id`, no member holds two reservations for the slot;
   - duplicate charges = 0: at most one payment per hold; zero payments in `authorized` or `captured` with `reservation_id IS NULL` (after the queue drains); every reservation with `card_cents > 0` has exactly one `captured` payment of that amount;
   - ledger: `SUM(amount_cents)` over all `ledger_entries` = 0; every transaction sums to 0; exactly 50 `booking` transactions for the drop's holds.
4. `make drop` = `drop-seed` (`migrate:fresh` + `DropTestSeeder`) → `drop-run` (k6 via `docker compose --profile loadtest run --rm k6 run /loadtest/drop.js`, app at `http://app:8000`) → wait until the `default` queue is empty (max 60 s) → `drop-verify` (`verify.sql` + `php artisan wallet:reconcile`, both must exit 0). Also compares the fake gateway's charge count in Redis (`HLEN dorsia:fakegw:charges`) with captured payments.
5. `loadtest/README.md`: how to run, what each check means, known Docker Desktop latency caveat (process.md).
6. `loadtest/RESULTS.md`: one section per run (date, commit, machine, p95 hold/confirm, counts, pass/fail per §9 row, findings).

## Acceptance criteria

- [ ] `make drop` runs end to end from a clean `make setup` with no manual steps.
- [ ] Three consecutive `make drop` runs each pass every §9 row: exactly 50 confirmed, 0 double bookings, 0 duplicate charges, ledger sum 0, booking p95 < 300 ms, losers get a clear 409 `sold_out` and zero 5xx.
- [ ] `wallet:reconcile` exits 0 after each run.
- [ ] Every VU's retry with the same key returned a response identical to the first (the `unexpected` counter is 0).
- [ ] Results of all three runs recorded in `RESULTS.md` with the JSON summaries committed under `loadtest/results/` (small summaries only, not raw metrics).
- [ ] If p95 fails only on Docker Desktop for macOS, a Linux run (CI runner or Linux host) is recorded as the reference per process.md.
- [ ] `tests/Performance/DropTestSeederTest.php` (runs with a reduced count via a seeder parameter/env such as `DROP_TEST_MEMBERS=50`) asserts the slot, drop, members, tokens and goodwill credits are created as specified.
- [ ] Gates: `make ci` and `make arch` still green.

## Required tests

- `tests/Performance/DropTestSeederTest.php` as above.
- `verify.sql` self-check: running it on a deliberately broken state (e.g. a test DB with 51 reservations, or an orphan authorized payment) fails; document how in `loadtest/README.md`.

## Eval agent will check

- Re-runs `make drop` three times itself on a fresh clone and reads the k6 summary plus `verify.sql` output.
- Confirms the script really retries each request once with the same key and compares responses.
- Confirms verification queries measure what §9 says (reads the SQL), including duplicate charges against both `payments` and the fake gateway.
- Confirms no application code was changed on this branch (`git diff --stat build/v1...` limited to allowed files).
