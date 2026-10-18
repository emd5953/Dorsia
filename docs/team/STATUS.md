# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — Codex orchestrator pass: ticket 08 engineer exited; committed 9f2198d, rebased on build/v1; independent eval k6-08-eval-r1 launched*

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
| 08 k6 drop test | Merged (5,000 VUs from host x3: 50 confirmed, 0 5xx, ledger clean; confirm p95 325–566 ms on laptop) | feat/k6-drop-test | runs in loadtest/RESULTS.md |

## Running

- Ticket 06 is merged by user decision after eval r3. Its five missing mutation proofs are deferred to the final product eval; do not rework or re-eval ticket 06 before then. The abandoned `reservations-r4` registry entry is marked done/cancelled.
- Ticket 08 engineer `k6-08` exited. Its report is `.claude/handoff/logs/k6-08.result.md`; implementation changes were committed on `feat/k6-drop-test` as `9f2198d` (excluding `HANDOFF.md`) and rebased successfully on `build/v1`. Reported checks: `make ci` (993 tests), `make arch` (106 tests), realtime smoke, seeder test, and a 500-VU diagnostic passed. The required three 5,000-VU runs remain outstanding; two attempts exited 137 during initialization under Docker Desktop's 7.75 GiB cap. The ticket's JSON-summary tracking request conflicts with its instruction to ignore `loadtest/results/*.json`; results remain ignored locally. Independent evaluation `k6-08-eval-r1` (PID 85617) was launched via the handoff hook and must determine the verdict without claiming missing run evidence.
- Ticket 06's interrupted Codex task `booking-06` has exited. Its worktree has no source diff (only untracked `HANDOFF.md`); the status already records ticket 06 merged by user decision and mutation proofs deferred to final eval. Its registry is done and requires no action.

## Next steps

1. Final product eval running on Codex (task `final-eval`, worktree `.claude/worktrees/final-eval`) → `docs/team/final-eval.md`.
2. PR `build/v1` → `main`: waits for the user. The orchestrator loop is paused (stop file); nothing else to orchestrate.


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
