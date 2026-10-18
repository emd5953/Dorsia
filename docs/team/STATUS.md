# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — Codex orchestrator pass: verified `k6-08-r3` PID 99148 is still alive (elapsed 7:55 at this pass). Its worktree `.claude/worktrees/k6-08` has pending edits to `loadtest/drop.js` and `loadtest/RESULTS.md`, plus untracked `HANDOFF.md` and `docs/team/evals/k6-r2.md`; HEAD remains `8c1cc98`. `.claude/handoff/logs/k6-08-r3.result.md` does not exist yet. No commit/eval launch is ready while the engineer is active. Linux 5,000-VU acceptance remains outstanding.*

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
| 08 k6 drop test | Merged; eval r2 FAIL 88; rework r3 in progress | feat/k6-drop-test | 78, 88 (Linux hard criterion incomplete) |

## Running

- Ticket 06 is merged by user decision after eval r3. Its five missing mutation proofs are deferred to the final product eval; do not rework or re-eval ticket 06 before then. The abandoned `reservations-r4` registry entry is marked done/cancelled.
- Ticket 08 eval r2 exited with FAIL 88; the full report `docs/team/evals/k6-r2.md` is committed on `build/v1` as `684dfcd`. It found the rework fixed repeat-run FakeGateway state and committed exactly three compact host summaries, with `make ci` (993 tests) and `make arch` (106 tests) passing. The mandatory Linux gate is still missing: run 39136564789 exited 137 during VU initialization (~3,700/5,000) before requests; verifiers found 0/50 confirmed. Rework `k6-08-r3` is now active as PID 99148 in the existing ticket worktree, diagnosing whether ticket-allowed script/Makefile tuning can prevent the runner kill while preserving acceptance semantics. No Linux booking, error-rate or latency results can be claimed.
- Ticket 06's interrupted Codex task `booking-06` has exited. Its worktree has no source diff (only untracked `HANDOFF.md`); the status already records ticket 06 merged by user decision and mutation proofs deferred to final eval. Its registry is done and requires no action.

## Next steps (for the Codex orchestrator)

All 8 ticket implementations are merged, but ticket 08 has failed its first independent eval and is in rework. Final eval (`docs/team/final-eval.md`) is NOT READY pending ticket 08 re-evaluation and successful Linux reference runs.

Ticket 08 rework r2 committed at `8c1cc98`; its independent eval r2 is committed at `684dfcd` and FAIL 88. Rework r3 is active (PID 99148), launched through `.claude/hooks/codex-handoff.sh`. Its worktree handoff records a ticket-allowed k6 `SharedArray` change to avoid each VU retaining a separate 5,000-token array. The process was alive at this checkpoint and validation is pending. Wait for it to exit, then commit only its allowed changes (never `HANDOFF.md`) and launch an independent eval. The Linux acceptance evidence is still required; run 39136564789 on `drop-test` failed: all three runs exited 137 during VU initialization at approximately 3,733/5,000; each verifier got 0 confirmed reservations (expected 50). Artifacts are downloaded under `/tmp/dorsia-run-39136564789/drop-test-results/`. No latency or correctness result can be claimed from these failed runs.

1. Wait for `k6-08-r3` to finish and inspect `.claude/handoff/logs/k6-08-r3.result.md`. If it exits, commit only ticket-allowed worktree changes (never `HANDOFF.md`), then launch an independent eval through `codex-handoff.sh`; the ticket cannot pass without successful Linux evidence.
2. Run 39136564789 has finished and failed as recorded above. After ticket 08 rework and eval, a successful Linux workflow run is still required; record all three actual summaries, verifying 50 bookings, 0 5xx, passing verifier and confirm p95 <300 ms each. Do not infer Linux results from local diagnostics.
3. If all three runs pass, update `docs/team/final-eval.md` with the Linux reference, set READY FOR PR, create `docs/team/pr-description.md`, and commit on build/v1. Do not open the PR.
4. If correctness passes but Linux confirm p95 is >=300 ms, launch a profiler/fix task through `codex-handoff.sh`, evaluate and merge it, then note that the user must push `build/v1:drop-test` to rerun CI. If setup fails, inspect failed logs, fix on build/v1, and note that the user must push.
5. Never push, never touch main, never open a PR.


## Deferred follow-ups

- Payments: `docs/team/evals/payments-r3.md` items 1–3 (Refunded treated as timeout; N7, N19 tests). Contract weakness: VoidPayment marks `refunded` before the gateway confirms.
- Ledger: `docs/team/evals/ledger-r4.md` items 1–2 (time-function scan gaps).
- Platform: `docs/team/evals/platform-hardening-r2.md` items 1–5 (incl. Sanctum PersonalAccessToken whole-second timestamps).
- Pricing: `docs/team/evals/pricing-hardening-r1.md` info items.

## Open decisions

- Ticket 08 conflicts: it asks to ignore `loadtest/results/*.json` while also requiring compact JSON summaries committed there. Proposed conservative resolution: keep the ignore rule for generated artifacts, explicitly force-add only the three small summary JSON files required by acceptance; do not add raw metrics. No cross-module contract change is implicated.

## Environment notes

- Docker Desktop has died three times; if `docker info` fails, run `open -a Docker` and wait. Stacks then need `make up` per worktree.
- Commits/rebases/merges are orchestrator-owned. Never push.

## Note from the Claude orchestrator (12:25 EDT)

- `realtime-eval-r1` already finished before the switch: FAIL 79, report committed at `docs/team/evals/realtime-r1.md`. The Codex re-run (PID 24143) was a duplicate and was stopped. Ticket 07 changes are committed on `feat/realtime-outbox` (rebased on build/v1).
- Realtime round-2 rework is now running on Codex as task `realtime-r2` (see `.claude/handoff/tasks/realtime-r2.json` for PID). When it exits: commit, run a Codex eval (round 2), merge on PASS.
- The Claude Booking engineer on inventory round 3 was stopped; Codex `inventory-r3` owns that worktree. The Claude agent had started editing lock order in HoldCloser/ReleaseBooking/HoldSlot; check `git diff` there for its partial work.
