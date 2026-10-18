# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — takeover pass: PID 95434 remains alive; GitHub run 39136564789 completed and failed all three 5,000-VU runs (k6 exit 137; verifier found 0 confirmed reservations); active rework worktree left untouched*

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
| 08 k6 drop test | Merged; eval r1 FAIL 78; rework in progress | feat/k6-drop-test | 78 (hard criteria incomplete) |

## Running

- Ticket 06 is merged by user decision after eval r3. Its five missing mutation proofs are deferred to the final product eval; do not rework or re-eval ticket 06 before then. The abandoned `reservations-r4` registry entry is marked done/cancelled.
- Ticket 08 independent evaluation `k6-08-eval-r1` exited; report `docs/team/evals/k6-r1.md` is committed on `build/v1` and `feat/k6-drop-test` as `fb45598`. Verdict FAIL 78: local CI and arch pass, but full 5,000-VU evidence and tracked compact JSON summaries are missing, and repeat runs may compare a fresh database with a cumulative fake-gateway Redis hash. Takeover check: PID 95434 is alive as the Codex rework process, and `feat/k6-drop-test` has staged changes to `.gitignore`, `Makefile`, `loadtest/README.md`, `loadtest/RESULTS.md`, and three small `loadtest/results/host-run*.json` summaries, plus untracked `HANDOFF.md`. Leave its worktree untouched until it exits. GitHub drop-test run 39136564789 completed with failure. Downloaded artifacts are in `/tmp/dorsia-run-39136564789/drop-test-results/`; summary reports k6 exit 137 and verifier exit 2 for runs 1–3. Each run initialized only about 3,733 of 5,000 VUs before termination, and each verifier reported `expected 50 confirmed reservations, got 0`. This is no valid Linux performance evidence; do not claim booking, error-rate or latency results.
- Ticket 06's interrupted Codex task `booking-06` has exited. Its worktree has no source diff (only untracked `HANDOFF.md`); the status already records ticket 06 merged by user decision and mutation proofs deferred to final eval. Its registry is done and requires no action.

## Next steps (for the Codex orchestrator)

All 8 ticket implementations are merged, but ticket 08 has failed its first independent eval and is in rework. Final eval (`docs/team/final-eval.md`) is NOT READY pending ticket 08 re-evaluation and successful Linux reference runs.

Ticket 08 rework task `k6-08-r2` exited. Its allowed changes were committed on `feat/k6-drop-test` as `8c1cc98`, rebased on current `build/v1`; only untracked `HANDOFF.md` remains in that worktree and is excluded. Reported `make ci` (993 tests) and `make arch` (106 tests) passed. Independent eval `k6-08-eval-r2` is active (PID 98357), launched through `.claude/hooks/codex-handoff.sh`; it must write `docs/team/evals/k6-r2.md`. Do not merge or mark ticket 08 passed before that eval. GitHub run 39136564789 on `drop-test` is completed but failed: all three runs exited 137 during VU initialization at approximately 3,733/5,000; each verifier got 0 confirmed reservations (expected 50). Artifacts are downloaded under `/tmp/dorsia-run-39136564789/drop-test-results/`. No latency or correctness result can be claimed from these failed runs.

1. Wait for `k6-08-eval-r2` to finish, read its report, commit it to `build/v1`, then handle PASS/FAIL under process.md. The eval is required to reject the current missing Linux evidence.
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
