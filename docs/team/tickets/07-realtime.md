# 07 — Real-time via the outbox

| | |
| --- | --- |
| Owner | Payments & real-time |
| Depends on | 01 merged (outbox writer and `OutboxTopic`); 05 done (same engineer). Runs alongside 06. |
| Blocks | 08 |
| Branch | `feat/realtime-outbox` off `build/v1` |
| Worktree env | `bin/worktree-env 07` → `dorsia-07`, ports 8700 / 8780 / 6132 / 7079 |
| Build plan | §6 "Real-time" (channel table, outbox rule, pushes are hints), §4 step 7, §9 step 7 |
| Contracts | C§5.7, C§7, C§8 `/broadcasting/auth`, C§12 `realtime.*`, C§13 `realtime:prune-outbox`, C§14 `outbox` service, D20, D26 |

## Files you may touch

- `app/Modules/Realtime/**`
- `tests/Modules/Realtime/**`, `tests/Arch/Contracts/RealtimeContractTest.php`
- `loadtest/realtime-smoke.js` (new)
- `docker-compose.yml`: **only** remove `profiles: [realtime]` from the `outbox` service

## Non-goals

Writing outbox rows (each module writes its own), push notifications to members not viewing the venue, tier ordering, Pusher production config beyond the standard driver switch, client SDKs.

## Work

1. `PublishOutboxBatch` per C§5.7; internal `Support\ChannelMap` (`OutboxTopic` + payload → channel object + event name) covering every `OutboxTopic` case (exhaustive `match`, so a new topic fails static analysis); internal broadcast event `Support\OutboxBroadcast implements ShouldBroadcastNow` with `broadcastOn()`, `broadcastAs()`, `broadcastWith()` (payload + `outbox_id`).
2. Commands `realtime:publish-outbox {--once} {--limit=100} {--sleep-ms=100}` (loop handles `SIGTERM` gracefully) and `realtime:prune-outbox` (scheduled daily 05:00 UTC); `PruneOutbox` action.
3. In `RealtimeServiceProvider`: `Broadcast::routes(['middleware' => ['auth:sanctum']])`, `Broadcast::channel('member.{memberId}', fn (Member $m, int $memberId) => $m->id === $memberId)`. Public channels need no authorization.
4. Enable the `outbox` compose service.
5. `loadtest/realtime-smoke.js` (k6 websockets, Pusher protocol): logs in as the demo member, subscribes to `venue.{id}.slots` and `private-member.{id}` (auth via `/broadcasting/auth` with the bearer token), places a hold over HTTP, and passes if `slot.held` arrives within 5 s with the right `slot_id`. Run with `docker compose --profile loadtest run --rm k6 run /loadtest/realtime-smoke.js`.

## Acceptance criteria

- [ ] For each of the 8 topics, an outbox row is broadcast on the C§7 channel with the exact event name and payload + `outbox_id` (assert with `Event::fake([OutboxBroadcast::class])` and inspect `broadcastOn/As/With`).
- [ ] Private channel names: Laravel channel `member.{id}` (wire `private-member.{id}`); `venue.{id}.slots` and `drop.{id}` are public.
- [ ] Published rows get `published_at`; a second `--once` run publishes nothing.
- [ ] Rows are published in ascending `id`; when broadcasting row N throws, N gets `attempts +1` and `last_error`, rows after N in that batch stay unpublished, and the next run retries N first.
- [ ] A transaction that writes an outbox row and rolls back produces no broadcast (end-to-end through `Outbox::record` and `PublishOutboxBatch`).
- [ ] Two publishers running concurrently never broadcast the same row twice (`SKIP LOCKED`).
- [ ] `/broadcasting/auth` for `private-member.{own id}` → 200; for another member's id → 403; without token → 401.
- [ ] `realtime:prune-outbox` deletes published rows older than 7 days, keeps unpublished rows of any age.
- [ ] With the full stack up (`make up`), `realtime-smoke.js` passes against Reverb.
- [ ] Adding a hypothetical `OutboxTopic` case without a `ChannelMap` arm fails `make stan` (demonstrate locally, do not commit).
- [ ] Gates: `make ci`, `make arch`, `RealtimeContractTest`.

## Required tests

- Feature: `ChannelMapTest` (dataset over all 8 topics), `PublishOutboxBatchTest` (publish, idempotent re-run, ordering, failure/retry, rollback produces nothing), `ChannelAuthTest`, `PruneOutboxTest`.
- Concurrency: 20 parallel `PublishOutboxBatch` workers over 500 rows → each row broadcast exactly once (count fake dispatches per `outbox_id`), all published.
- Idempotency: re-running the publisher after success publishes nothing.
- `tests/Arch/Contracts/RealtimeContractTest.php`.

## Eval agent will check

- The §6 channel table reproduced exactly (channels, events, audiences).
- "Events leave through the outbox": `grep` that no module calls `broadcast(`/`Broadcast::` or dispatches `ShouldBroadcast` events directly; only Realtime does.
- Smoke script run live against the compose stack.
- Concurrency suite run 3 times.
