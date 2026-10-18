# Drop load test

Run `make setup` once, then `make drop`. The target resets the local database, creates a 5,000-member drop, races one k6 VU per member over ten seconds, waits up to 60 seconds for capture jobs, and checks the database, fake gateway and wallet reconciliation. Each run writes a compact k6 summary under `loadtest/results/`; detailed metrics are not saved.

`DROP_TEST_MEMBERS=50` reduces the seeder size for `DropTestSeederTest.php`. Do not use that setting for a load run. The generated bearer tokens and drop IDs are stored under the ignored `loadtest/.data/` directory.

The verifier checks exactly 50 confirmed bookings, slot capacity and reservation uniqueness, payment uniqueness and attachment, one matching capture per card-funded reservation, balanced ledger entries, and 50 booking ledger transactions. The Make target separately compares captured payment count with the fake gateway Redis charge hash and runs `wallet:reconcile` after the default queue drains.

To prove `verify.sql` fails on a broken state, seed a test database, insert a 51st confirmed reservation for the drop slot with its required related rows and adjusted slot count, then run `psql -v ON_ERROR_STOP=1 -v slot_id=<slot id> -f loadtest/verify.sql`; it raises an exception at the first violated invariant. Alternatively, create an authorized payment with a null reservation ID and run the same command. Restore the database afterward with `make drop-seed`.

Docker Desktop on macOS can cap p95 latency through VM and host networking overhead. If all integrity checks pass and only latency exceeds 300 ms locally, record the measured hold and confirm p95s and treat the Linux CI or host run as the latency reference, per `docs/team/process.md`.

## Results

Each completed run is recorded in `RESULTS.md` with its date, commit, machine, counts, latency and pass/fail status for every build plan §9 row. Small JSON summaries are kept alongside it.
