# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-17 12:23 EDT — switched to Codex at 96% usage*

## Tickets

| Ticket | State | Branch | Evals |
| --- | --- | --- | --- |
| 01 Platform | Merged (+2 hardening batches) | feat/platform-skeleton | 86, 92 |
| 04 Pricing | Merged (+2 hardening batches) | feat/pricing | 94 |
| 03 Ledger | Merged | feat/ledger | 89, 88, 91 (gate fail), 93 |
| 05 Payments | Merged | feat/payments-gateway | 86, 85, 93 |
| 02 Inventory/holds | Round-3 rework running (Codex) | feat/inventory-holds | 87, 83 (fail) |
| 07 Real-time | Engineer complete; round-1 eval running (Codex) | feat/realtime-outbox | pending |
| 06 Booking confirm/cancel | Not started; needs 02 merged | – | – |
| 08 k6 drop test | Not started; needs 06 and 07 | – | – |

## Running

- `inventory-r3` (Codex engineer, PID 24126) in `.claude/worktrees/agent-a6f64a444268d98a6`.
- `realtime-eval-r1` (Codex eval, PID 24143) in `.claude/worktrees/realtime-07`.

## Recent decisions and handoff notes

- Inventory round 2 failed at 83. Its eval report is `docs/team/evals/inventory-r2.md`; round 3 addresses the deadlock, refresh guard test coverage, guard expiry, MISSING result, and bounded rebuild wait.
- The inventory-r3 registry brief still mentions pending/generation mechanisms, but architect-updated `contracts.md` C§6.1 and ticket 02 explicitly prohibit both. The engineer must follow the current contract/ticket: only counter, lock, and refresh keys; no pending/generation keys.
- Ticket 07 engineer finished with a clean feature worktree except untracked `HANDOFF.md`. Its result is `.claude/handoff/logs/realtime-07.result.md`. The live k6 smoke remains dependent on ticket 02 and a seeded available slot.
- Realtime round-1 eval is running against the uncommitted feature branch; its brief requires the report at `docs/team/evals/realtime-r1.md`.

## Next steps

1. When inventory-r3 exits, read its result, inspect the worktree/diff, commit implementation files only (exclude `HANDOFF.md`) on `feat/inventory-holds`, then run a fresh separate Codex eval against current `build/v1` criteria. PASS requires every hard gate and score ≥90. On PASS merge with `git merge --no-ff`; on FAIL write a round-4 rework task and launch it with `codex-handoff.sh <id>`.
2. When realtime-eval-r1 exits, read its report. On PASS commit ticket 07 changes excluding `HANDOFF.md`, rebase onto current `build/v1`, rerun full CI if the rebase changes code, and merge with `git merge --no-ff`. On FAIL write a rework task and launch it with `codex-handoff.sh <id>`.
3. Once inventory merges, run the pending ticket 07 live smoke command against a seeded slot, then start ticket 06 in a worktree from `build/v1` (`bin/worktree-env 06`). Ticket 06 includes `HoldView::$quote` populated with `QuoteData`, `HoldReleaseReason::ConfirmFailed`, nullable `CancellationRefund::$transactionId`, and release-hold-on-confirm-failure per C§6.2.
4. After tickets 06 and 07 merge, start ticket 08, then perform the final product eval in `process.md`.
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
