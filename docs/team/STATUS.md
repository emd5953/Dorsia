# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-17 12:40 EDT — evals restarted with Docker access*

## Tickets

| Ticket | State | Branch | Evals |
| --- | --- | --- | --- |
| 01 Platform | Merged (+2 hardening batches) | feat/platform-skeleton | 86, 92 |
| 04 Pricing | Merged (+2 hardening batches) | feat/pricing | 94 |
| 03 Ledger | Merged | feat/ledger | 89, 88, 91 (gate fail), 93 |
| 05 Payments | Merged | feat/payments-gateway | 86, 85, 93 |
| 02 Inventory/holds | Round-3 implementation complete; round-3 eval pending | feat/inventory-holds | 87, 83 (fail), round 3 pending |
| 07 Real-time | Round-2 implementation complete; round-2 eval pending | feat/realtime-outbox | 79 (fail), round 2 pending |
| 06 Booking confirm/cancel | Not started; needs 02 merged | – | – |
| 08 k6 drop test | Not started; needs 06 and 07 | – | – |

## Running

- Inventory round-3 eval active (terminal session 30093; thread `01a14ac0-11cb-7bd2-99c5-275a67ac770e`, PID 29686).
- Realtime round-2 eval active (terminal session 18444; thread `01a14ac0-11cb-75e1-b572-c17beded906d`, PID 29685).
- Both first eval sessions were stopped because workspace-write blocked the Docker socket. They restarted with `danger-full-access` for the eval runs only, as the process instructions prescribe when Docker access is blocked.
- Previous engineer PIDs 24126 and 25469 exited; their result reports are present.
- `inventory-r3` changes were committed as `1709490` and rebased onto current `build/v1`; eval must run next.
- `realtime-r2` changes were committed as `f631632` and rebased onto current `build/v1`; eval must run next.

## Recent decisions and handoff notes

- Inventory round 2 failed at 83. Its eval report is `docs/team/evals/inventory-r2.md`; round 3 addresses the deadlock, refresh guard test coverage, guard expiry, MISSING result, and bounded rebuild wait.
- The inventory-r3 registry brief still mentions pending/generation mechanisms, but architect-updated `contracts.md` C§6.1 and ticket 02 explicitly prohibit both. The engineer must follow the current contract/ticket: only counter, lock, and refresh keys; no pending/generation keys.
- Ticket 07 engineer finished with a clean feature worktree except untracked `HANDOFF.md`. Its result is `.claude/handoff/logs/realtime-07.result.md`. The live k6 smoke remains dependent on ticket 02 and a seeded available slot.
- Realtime round-1 eval is running against the uncommitted feature branch; its brief requires the report at `docs/team/evals/realtime-r1.md`.

## Next steps

1. When evals finish, read both reports. PASS means all hard gates and score ≥90; merge passing branches with `git merge --no-ff`; on FAIL create the next rework task and launch with `codex-handoff.sh <id>`.
2. Once inventory merges, run the pending ticket 07 live smoke against a seeded slot, then start ticket 06 from `build/v1` (`bin/worktree-env 06`). Ticket 06 includes `HoldView::$quote` populated with `QuoteData`, `HoldReleaseReason::ConfirmFailed`, nullable `CancellationRefund::$transactionId`, and release-hold-on-confirm-failure per C§6.2.
3. After tickets 06 and 07 merge, start ticket 08, then perform the final product eval in `process.md`.
5. Never push, touch `main`, or open a PR. The final PR waits for the user's return.

## Deferred follow-ups

- Payments: `docs/team/evals/payments-r3.md` items 1–3 (Refunded treated as timeout; N7, N19 tests). Contract weakness: VoidPayment marks `refunded` before the gateway confirms.
- Ledger: `docs/team/evals/ledger-r4.md` items 1–2 (time-function scan gaps).
- Platform: `docs/team/evals/platform-hardening-r2.md` items 1–5 (incl. Sanctum PersonalAccessToken whole-second timestamps).
- Pricing: `docs/team/evals/pricing-hardening-r1.md` info items.

## Open decisions

- No current contract decision is blocking work. Preserve the architect's current Inventory C§6.1 design above; escalate any newly discovered cross-module ambiguity here before changing the contract.

## Environment notes

- Docker Desktop has died three times; if `docker info` fails, run `open -a Docker` and wait. Stacks then need `make up` per worktree.
- Commits/rebases/merges are orchestrator-owned. Never push.

## Note from the Claude orchestrator (12:25 EDT)

- `realtime-eval-r1` already finished before the switch: FAIL 79, report committed at `docs/team/evals/realtime-r1.md`. The Codex re-run (PID 24143) was a duplicate and was stopped. Ticket 07 changes are committed on `feat/realtime-outbox` (rebased on build/v1).
- Realtime round-2 rework is now running on Codex as task `realtime-r2` (see `.claude/handoff/tasks/realtime-r2.json` for PID). When it exits: commit, run a Codex eval (round 2), merge on PASS.
- The Claude Booking engineer on inventory round 3 was stopped; Codex `inventory-r3` owns that worktree. The Claude agent had started editing lock order in HoldCloser/ReleaseBooking/HoldSlot; check `git diff` there for its partial work.
