# Pricing (ticket 04): eval round 1

**Verdict: PASS, 94/100** (Correctness 39, Edge cases 23, Concurrency 18, Code quality 14).

Gates: `make ci` green (257 tests); arch test fails on a Wallet import and on raw SQL against `slots`; every C§3/C§5.2/C§12/C§13 item present; concurrency group 3/3. Mutation testing: 37 mutants, 35 caught. Survivors: prune schedule without `->timezone('UTC')`; QuoteHold picking oldest `computed_at`.

Defects (sent to Pricing as feat/pricing-hardening):
1. Low: `Listeners/RecordAvailabilitySearch.php:22` — a deadlock/serialization error inside a caller's transaction leaves that transaction aborted and Laravel's counter out of sync, because Laravel doesn't roll back to the savepoint before rethrowing and the listener swallows it.
2. Low: `Actions/RecordSearch.php:23-24` — a DST-nonexistent local hour maps to the same instant as the next hour; dedupe unaffected; document or normalise.
3. Low: `Actions/ComputeDemandSignals.php:73-82` — manual runs can overlap; an older run committing last overwrites newer rows. Take a `pg_advisory_xact_lock`.
4. Info: QuoteHold replay with a different party size returns the first quote; matches contract, untested.
5. Info: PriceCalculator overflows above ~3.5e10 cents.
6. Test gaps: the two surviving mutants.
