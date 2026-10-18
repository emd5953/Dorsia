# Drop test results

Machine: Docker Desktop for macOS, Apple Silicon (`aarch64` Linux VM), 12 vCPUs, 7.75 GiB VM memory. Commit under test: `8c88a37`.

## 2026-10-18 — full drop attempt 1

- Command: `make drop`
- Seeder: completed 5,000 members, tokens, drop and goodwill credits in 2.05 s.
- k6: container exited 137 during initialization at about 1,597/5,000 VUs. No HTTP requests or summary completed; `make drop` stopped before queue verification.
- §9: confirmed, double bookings, duplicate charges, ledger, latency and loser response checks unavailable. Host VM memory pressure is the observed blocker; this is not a booking API error result.

## 2026-10-18 — full drop attempt 2

- Command: `docker compose --profile loadtest run --rm -e GOMEMLIMIT=4GiB k6 run /loadtest/drop.js`
- k6: container exited 137 during initialization at about 1,972/5,000 VUs. No HTTP requests or summary completed. The Go memory limit did not avoid the Docker Desktop memory cap.
- §9: all full-run checks unavailable.

## 2026-10-18 — 500-VU diagnostic (not an acceptance run)

- Command: `DROP_VUS=500` passed to the pinned k6 container after a fresh `make drop-seed`.
- Counts: 50 confirmed, 450 clear `409 sold_out`, 0 double bookings, 0 duplicate charges, 0 5xx, 0 unexpected responses/replay mismatches.
- Ledger: sum 0; 50 booking transactions; `wallet:reconcile` reported 0 unbalanced transactions and 0 accounts with drift. `make drop-verify` exited 0, including fake gateway charge count equality.
- Hold p95: 7.69 ms. Confirm p95: 42.12 ms. Both below 300 ms in this reduced diagnostic.
- The compact k6 JSON summary is `loadtest/results/2026-10-18T13-46-39-225Z.json` in the worktree; it is currently ignored by the ticket's requested `.gitignore` pattern.
- This reduced run validates the flow and verifier but does not satisfy the 5,000-VU §9 pass condition.
- Verifier negative self-check: inserted a temporary authorized payment with null reservation id, ran `verify.sql` against the diagnostic slot, observed the expected `orphan authorized or captured payments: 1` exception, and deleted the temporary row.

## 2026-10-18 — ticket 07 live smoke

- Command: `docker compose --profile loadtest run --rm k6 run /loadtest/realtime-smoke.js`
- Result: exit 0; all four checks passed (demo login, private channel auth, hold succeeds, requested `slot.held` arrives within five seconds). General HTTP p95 was 349.27 ms across the smoke requests; it is not a drop-only latency measurement.

## Gate status

- `make setup`: passed with `BUILDX_CONFIG=/private/tmp/dorsia-buildx08` to keep Buildx activity files inside the writable temp area.
- Seeder feature test: passed (20 assertions). Final `make ci`: passed (993 tests / 4,814 assertions, PHPStan clean, Pint 368 files). `make arch`: passed (106 tests / 900 assertions).
- Required three consecutive 5,000-VU passing runs are blocked by the Docker Desktop VM memory cap. Run them on a Linux host/runner with enough memory and append the results here.
