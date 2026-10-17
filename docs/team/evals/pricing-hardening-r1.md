# Pricing hardening (feat/pricing-hardening @ 01f8ea9): eval

**Verdict: MERGE.** `make ci` green (292 tests); diff limited to Pricing; concurrency group 3/3. Raw-savepoint listener reviewed as safe (no Laravel counter interaction, no name collision, aborted-caller path swallowed and logged). Every pricing-r1 defect fails a test when mutated away.

Open (sent to Pricing):
1. Low: no-transaction path of `RecordAvailabilitySearch.php:25` untested; issuing a savepoint with no open transaction would silently drop every search in production. Needs a non-transactional (Concurrency-group) test. Matters once Inventory dispatches `AvailabilitySearched`.
2. Low: nothing pins the advisory lock in `ComputeDemandSignals.php:31` as transaction-scoped; a session lock would leak on pooled connections.
3. Info: Inventory reads now run inside the signals transaction (locks held to commit; only blocks DDL).
4. Info: overflow guard slightly conservative; no negative-base check.
5. Info, pre-existing: `QuoteHold.php:83` takes sellout p50 from rows in no particular order.
