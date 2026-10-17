# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator (Codex, if Claude runs out) can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-17 12:10 EDT*

## Tickets

| Ticket | State | Branch | Evals |
| --- | --- | --- | --- |
| 01 Platform | Merged (+2 hardening batches) | feat/platform-skeleton | 86, 92 |
| 04 Pricing | Merged (+2 hardening batches) | feat/pricing | 94 |
| 03 Ledger | Merged | feat/ledger | 89, 88, 91 (gate fail), 93 |
| 05 Payments | Merged | feat/payments-gateway | 86, 85, 93 |
| 02 Inventory/holds | Round-2 eval running (Claude) | feat/inventory-holds | 87 |
| 07 Real-time | Building (Codex) | feat/realtime-outbox | – |
| 06 Booking confirm/cancel | Not started; needs 02 merged | – | – |
| 08 k6 drop test | Not started; needs 06 and 07 | – | – |

## Running

- `inventory-eval-r2` (Claude eval) on `.claude/worktrees/agent-a6f64a444268d98a6`
- `realtime-07` (Codex engineer, started before the Claude-first rule; finishes on Codex) on `.claude/worktrees/realtime-07`. Its k6 smoke test needs ticket 02 merged before it can run.

## Next steps

1. Inventory eval r2: PASS → merge `feat/inventory-holds`; FAIL → Claude engineer rework round 3.
2. After 02 merges: start ticket 06 on a Claude engineer in a new worktree off `build/v1` (`bin/worktree-env 06`). Ticket 06 also carries: `HoldView::$quote` filled with `QuoteData`, `HoldReleaseReason::ConfirmFailed`, null `CancellationRefund::$transactionId` handling, release-hold-on-confirm-failure (C§6.2 step 6).
3. Realtime-07 done → eval → merge; then run its k6 smoke test once 02 is merged.
4. After 06 and 07 merge: ticket 08 (k6 drop test).
5. Final product eval (process.md), then a PR `build/v1` → `main`. The PR waits for the user.

## Deferred follow-ups

- Payments: `docs/team/evals/payments-r3.md` items 1–3 (Refunded treated as timeout; N7, N19 tests). Contract weakness: VoidPayment marks `refunded` before the gateway confirms.
- Ledger: `docs/team/evals/ledger-r4.md` items 1–2 (time-function scan gaps).
- Platform: `docs/team/evals/platform-hardening-r2.md` items 1–5 (incl. Sanctum PersonalAccessToken whole-second timestamps).
- Pricing: `docs/team/evals/pricing-hardening-r1.md` info items.

## Rules

- Engineers and evals run on Claude (Agent tool, `implementer` and `reviewer`), each registered in `.claude/handoff/tasks/` with `engine: claude` and its agent id. Codex only after the 95% switch.

## Open decisions for the user

- Codex model and effort for engineering (currently the `~/.codex/config.toml` default: gpt-6-luna, medium).

## Environment notes

- Docker Desktop has died three times; if `docker info` fails, run `open -a Docker` and wait. Stacks then need `make up` per worktree.
- Codex can't commit (`.git` read-only in its sandbox); the orchestrator commits.
