# Build Status

Orchestrator state for the steps 1–8 build. Updated at every milestone so another orchestrator can take over. Process: [process.md](process.md). Tasks: `.claude/handoff/tasks/`.

*Last updated: 2026-10-18 — Codex orchestrator pass: ticket 08 eval r3 exited FAIL 90/100. `make ci`, `make arch`, and mutation probes passed. Linux acceptance remains unmet: run 39136564789 exited 137 before requests. Local 5,000-VU diagnostic completed with 50 confirmed, 0 errors, ledger reconciliation clean, but confirm p95 was 477.90 ms. Full report: `docs/team/evals/k6-r3.md`.*

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
| 08 k6 drop test | Round-3 rework committed; independent eval pending | feat/k6-drop-test | 78, 88 (Linux hard criterion incomplete) |

## Running

- Ticket 06 is merged by user decision after eval r3. Its five missing mutation proofs are deferred to the final product eval; do not rework or re-eval ticket 06 before then. The abandoned `reservations-r4` registry entry is marked done/cancelled.
- Ticket 08 round-3 eval exited FAIL 90/100. Report `docs/team/evals/k6-r3.md` records passing local CI/arch and mutation probes, but Linux acceptance remains outstanding and local confirm p95 is 477.90 ms. The failed GitHub run produced no booking or latency evidence. No Linux result is inferred. Round-4 task `k6-08-r4` is active as PID 4119 in `.claude/worktrees/k6-08`, launched through `.claude/hooks/codex-handoff.sh`; it will diagnose confirm latency within ticket 08 ownership and document cross-module findings without editing application code.
- Ticket 06's interrupted Codex task `booking-06` has exited. Its worktree has no source diff (only untracked `HANDOFF.md`); the status already records ticket 06 merged by user decision and mutation proofs deferred to final eval. Its registry is done and requires no action.

## Next steps (for the Codex orchestrator)

All 8 ticket implementations are merged. Ticket 08 remains FAIL pending performance work and external Linux evidence; final eval remains NOT READY.

1. Watch `k6-08-r4` (PID 4119). When it exits, read `.claude/handoff/logs/k6-08-r4.result.md`; commit allowed worktree changes only, never `HANDOFF.md`, then launch independent eval through the hook.
2. Ticket 08 requires three successful Linux 5,000-VU runs, each with 50 confirmed, 0 5xx, passing verifier/reconcile, and confirm p95 <300 ms. The latest run, 39136564789, exited 137 before requests. The user must push `build/v1:drop-test` to rerun GitHub CI or provide an adequately provisioned Linux host; this orchestrator must not push.
3. After round-4 rework, launch an independent eval. Merge only on PASS. If Linux evidence is still unavailable, keep the final product eval NOT READY and record the exact external dependency.
4. Once acceptance passes, update `docs/team/final-eval.md`, create `docs/team/pr-description.md`, and commit on `build/v1`. Do not open a PR.
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
