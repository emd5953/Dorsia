# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — Codex orchestrator handoff reconciled; k6-08 still running*

## Tickets

| Ticket | State | Branch | Evals |
| --- | --- | --- | --- |
| 01 Platform | Merged (+2 hardening batches) | feat/platform-skeleton | 86, 92 |
| 04 Pricing | Merged (+2 hardening batches) | feat/pricing | 94 |
| 03 Ledger | Merged | feat/ledger | 89, 88, 91 (gate fail), 93 |
| 05 Payments | Merged | feat/payments-gateway | 86, 85, 93 |
| 02 Inventory/holds | Merged (architect takeover) | feat/inventory-holds | 87, 83, 87, 89, 93 |
| 07 Real-time | Merged (eval r3: 91, pass) | feat/realtime-outbox | 79, 90, 91 |
| 06 Booking confirm/cancel | Merged (user decision after eval r3: all gates pass, no defects; mutation proofs deferred to final eval) | feat/booking-confirm-cancel | 93*, fail, 88 |
| 08 k6 drop test | Building (Codex task k6-08) | feat/k6-drop-test | – |

## Running

- Ticket 06 is merged by user decision after eval r3. Its five missing mutation proofs are deferred to the final product eval; do not rework or re-eval ticket 06 before then. The abandoned `reservations-r4` registry entry is marked done/cancelled.
- Ticket 08 (`k6-08`, PID 83284) is active on `.claude/worktrees/k6-08`, branch `feat/k6-drop-test`; `kill -0 83284` confirmed it was alive during this handoff reconciliation. Its log shows `make setup` completed and implementation/validation work is in progress. No result file exists yet; no commit or eval can be started until it exits.
- Ticket 07 live smoke remains to be demonstrated; it is included in the ticket 08 engineer brief.

## Next steps (for the Codex orchestrator)

0. Continue watching PID 83284 with `kill -0`. When it exits, read `.claude/handoff/logs/k6-08.result.md`, commit only its worktree changes on `feat/k6-drop-test` (never `HANDOFF.md`), then launch the independent ticket 08 eval via `.claude/hooks/codex-handoff.sh`.
1. Ticket 08 eval PASS requires all hard gates and §9 checks: exactly 50 confirmed, zero double bookings, zero duplicate charges, ledger sum zero, clean sold-out responses and no 5xx. If only p95 misses on Docker Desktop with a clean profile, report the measured number and latency caveat; PASS → merge into `build/v1` with `git merge --no-ff`.
2. After 08 merges: run final product eval per process.md (including ticket 06 mutation proofs and the realtime live smoke) → `docs/team/final-eval.md`.
3. Do NOT open the PR. Never push, never touch main.


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
