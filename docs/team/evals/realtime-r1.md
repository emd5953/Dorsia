# Real-time via the outbox (ticket 07): eval round 1

**Verdict: FAIL, 79/100** (Correctness 33, Edge cases 19, Concurrency 16, Code quality 11). Gates pass: `make ci` 628 tests; arch 77; concurrency 3/3; exhaustive topic match proven via `match.unhandled`. Live: `/broadcasting/auth` 200/403/401/401 (revoked token); a live row reached Reverb in <2 s; SIGTERM exits 0.

Mutation testing: 31 mutants. Survivors: M24 channel callback returns true (private-channel auth untested); M11 FOR UPDATE without SKIP LOCKED (contract requires SKIP LOCKED); M26/M27 schedule; M28–M31 `--once`, SIGTERM handler, `--limit`, prune command registration; M06/M07 id validation; M18, M22 equivalent.

Defects:
1. High: `loadtest/realtime-smoke.js` can't pass — `ws.connect()` and `ws.setTimeout` don't exist in k6 1.8.2's websockets API; the check runs before any message; no thresholds, so k6 exits 0 on failure.
2. Medium-high: `PublishOutboxCommand.php:30-46` — any DB error outside the per-row try kills the publisher for good; the `outbox` service has no restart policy.
3. Medium: `PublishOutboxBatch.php:44-49` — one bad row (e.g. `venue_id: "5"`, unknown topic) blocks every later row forever; attempts climb ~10/s, nothing logged.
4. Medium: `ChannelAuthTest.php:13` re-registers its own channel callback, so the provider's is never tested (tests boot the null broadcaster).
5. Low-medium: `PruneOutbox.php:18` binds the cutoff without `Timestamps::bind` (wrong cutoff for non-UTC `$now`; seconds truncation).
6. Low: malformed `channel_name`/`socket_id` on `/broadcasting/auth` → 500, not 403.
7. Low: `realtime.outbox.batch` and `sleep_ms` config never read.
8. Low: payload keys pass through unfiltered onto public channels.
