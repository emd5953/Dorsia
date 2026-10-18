# Platform hardening (feat/platform-hardening @ 87eec54): eval

**Verdict: MERGE.** Gates pass (Pint, PHPStan L8, 148 tests, concurrency group 10/10). Items 1–7 from platform-r2 each fail a test when mutated away.

Open defects, sent to Platform as feat/platform-hardening-2:
1. Medium: the Concurrency barrier, timeout and failure reporting have no tests; four mutations survive (`tests/Support/Concurrency.php:41-80`).
2. Low: a child that fails before the barrier (e.g. too many DB connections) stalls siblings 30 s and is reported as a timeout instead of its real error.
3. Low: a crashed child makes the parent delete the barrier dir while siblings still run; orphans hold DB connections up to 30 s.
4. Low regression: form-encoded body hashing depends on key order (`EnsureIdempotency.php:214-218`).
5. Low security: the completion-failure warning logs the full SQL with bindings, including the response body (`EnsureIdempotency.php:122-126`).
6. Low: top-level JSON `[]` hashes the same as `{}`; undocumented.
