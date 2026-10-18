# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — Reservations eval r3 failed; mutation-proof rework launched*

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

- Reservations eval round 3 exited and failed at 88/100 because the five required mutation proofs were not completed. `make test-module M=Reservations` (102 tests), `make arch`, `make ci` (992 tests), and three concurrency repetitions all passed; no implementation defect was established. Report: `docs/team/evals/reservations-r3.md`.
- Dispatched `reservations-r4` to complete/fix the five mutation-proof requirements in a disposable copy (post-authorization cleanup, decline/timeout release, exact cancellation boundary, replay, concurrency), proving each test fails under the mutation and passes after revert. Ticket 06 implementation changes, if needed, must stay within ticket-allowed files. No merge until a fresh independent eval passes all gates and scores >=90.
- Ticket 08 remains gated on ticket 06 merge. Real-time live smoke is also pending after 06 merges.

## Next steps (for the Codex orchestrator)

0. Docker: if `docker info` fails, `osascript -e 'quit app "Docker"'; sleep 10; open -a Docker`, wait, then `make up` in the worktree.
1. Ticket 06 is MERGED. Do not rework or re-eval it. Its missing mutation proofs go into the final eval.
2. Ticket 08 is running as task `k6-08` (worktree `.claude/worktrees/k6-08`). When it exits: commit its changes on `feat/k6-drop-test`, then launch an eval task (via codex-handoff.sh, never codex exec directly). Bar for 08: all hard gates pass and the §9 checks hold (exactly 50, 0 double bookings, 0 duplicate charges, ledger 0, no 5xx). If only p95 misses on Docker Desktop with a clean profile, that is a PASS with the latency caveat recorded. PASS → merge into build/v1.
3. After 08 merges: final product eval per process.md (also covering ticket 06 mutation proofs) → `docs/team/final-eval.md`.
4. Do NOT open the PR. Never push, never touch main.


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
