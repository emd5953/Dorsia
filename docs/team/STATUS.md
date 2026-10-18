# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 01:15 EDT — Reservations rework relaunched*

## Tickets

| Ticket | State | Branch | Evals |
| --- | --- | --- | --- |
| 01 Platform | Merged (+2 hardening batches) | feat/platform-skeleton | 86, 92 |
| 04 Pricing | Merged (+2 hardening batches) | feat/pricing | 94 |
| 03 Ledger | Merged | feat/ledger | 89, 88, 91 (gate fail), 93 |
| 05 Payments | Merged | feat/payments-gateway | 86, 85, 93 |
| 02 Inventory/holds | Merged (architect takeover) | feat/inventory-holds | 87, 83, 87, 89, 93 |
| 07 Real-time | Merged (eval r3: 91, pass) | feat/realtime-outbox | 79, 90, 91 |
| 06 Booking confirm/cancel | Rework round 2 running | feat/booking-confirm-cancel | 93 (r1 fail: gates blocked) |
| 08 k6 drop test | Not started; needs 06 and 07 | – | – |

## Running

Nothing. Handed to the Codex orchestrator at 07:55 EDT Oct 18 because Claude's weekly limit is at 94% (resets Mon Oct 19 12:00 EDT).

## Recent decisions and handoff notes

- Inventory round 2 failed at 83. Its eval report is `docs/team/evals/inventory-r2.md`; round 3 addresses the deadlock, refresh guard test coverage, guard expiry, MISSING result, and bounded rebuild wait.
- The inventory-r3 registry brief still mentions pending/generation mechanisms, but architect-updated `contracts.md` C§6.1 and ticket 02 explicitly prohibit both. The engineer must follow the current contract/ticket: only counter, lock, and refresh keys; no pending/generation keys.
- Ticket 07 engineer finished with a clean feature worktree except untracked `HANDOFF.md`. Its result is `.claude/handoff/logs/realtime-07.result.md`. The live k6 smoke remains dependent on ticket 02 and a seeded available slot.
- Realtime round-1 eval is running against the uncommitted feature branch; its brief requires the report at `docs/team/evals/realtime-r1.md`.

## Next steps (for the Codex orchestrator)

0. Docker Desktop dies often. Before any run: `docker info`; if it fails, `osascript -e 'quit app "Docker"'; sleep 10; open -a Docker`, wait until `docker info` works, then `bin/worktree-env NN && make up` in the worktree you need.
1. Ticket 06: the implementation is committed on `feat/booking-confirm-cancel` (worktree `.claude/worktrees/booking-06`, project dorsia-06). Eval r1 scored 93 statically but its gates couldn't run (Docker down). Run eval round 2 as a separate `codex exec`: `make ci` from a fresh `make up`, every ticket 06 acceptance criterion, mutation testing, 20 parallel confirms → one reservation and one charge, `wallet:reconcile` clean. Report to `docs/team/evals/reservations-r2.md`. PASS (all gates + ≥ 90) → `git merge --no-ff` into build/v1. FAIL → rework task, then re-eval.
2. After 06 merges: run ticket 07's live smoke (`docker compose --profile loadtest run --rm k6 run /loadtest/realtime-smoke.js`) and record the result in STATUS.
3. Ticket 08 (k6 drop test, `docs/team/tickets/08-performance-k6.md`) in a new worktree off build/v1 (`bin/worktree-env 08`), then its eval. Report p95 honestly; if only latency misses on Docker Desktop, say so (process.md latency caveat).
4. Final product eval per process.md, written to `docs/team/final-eval.md`.
5. Do NOT open the PR. Never push, never touch main.


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
