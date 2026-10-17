# Build Process

How the team builds steps 1–8 of the [build plan](../build_plan.md). The build plan says what to build; this doc says who builds it and how it is judged.

## Scope

Build order steps 1–8: skeleton, inventory, hold and confirm, wallet ledger, pricing, fake payment gateway, real-time, k6 drop test. Done means the drop test in §9 passes.

Out of scope for this run: memberships and coupon grants (step 9), admin API (step 10), the Expo app, real Stripe, and production deploy. The `coupon` ledger account kind exists; nothing grants it yet.

## Team

| Role | Owns | Steps |
| --- | --- | --- |
| Orchestrator (main session) | Dispatch, worktrees, merges into `build/v1`, escalations, final PR | — |
| Architect / PM | [contracts.md](contracts.md): module public actions, events, table ownership, HTTP API, error codes. One ticket per engineer in `tickets/`. Integration review at each merge | — |
| Platform | Laravel skeleton, Docker Compose, CI, Pest arch tests, `app/Shared` (Money, idempotency middleware, outbox writer) | 1 |
| Booking | Inventory (venues, slots, holds, Redis gate) and Reservations (state machine, confirm, cancel, 8-hour rule) | 2, 3 |
| Ledger | Wallet: double-entry ledger, zero-sum trigger, balance projection, spend order, `wallet:reconcile` | 4 |
| Pricing | Search events, rule-based quote, demand-signals job, party bands | 5 |
| Payments & real-time | `PaymentGateway` and `FakeGateway`, webhooks; outbox publisher and Reverb channels | 6, 7 |
| Performance | k6 drop script, drop seeder, results; hands fixes to the owning engineer | 8 |

- Each engineer works in its own git worktree on a feature branch, with its own `COMPOSE_PROJECT_NAME` and port offsets: `bin/worktree-env <NN>` with NN = ticket number (`00` is the `build/v1` integration checkout). See contracts C§14.
- Engineers edit only the files listed in their ticket. Cross-module needs go to the architect as a contract change.
- Platform owns every shared root file (`composer.json`, `bootstrap/`, `config/`, `docker-compose.yml`, `Makefile`, `tests/Pest.php`, CI) plus `app/Shared`, which includes members and auth. Tickets name the few exceptions (one compose line for 07, the `drop*` Makefile targets for 08).

## Decisions and escalation

- Engineers and eval agents send design or scope questions to the architect and build to its answer. Only scope changes reach the user.
- A project hook (`.claude/hooks/guard.mjs`) blocks force pushes, pushes to `main`, `rm -r` outside the repo, worktrees or scratchpad, `docker system prune`, removing non-project Docker volumes, `curl | sh`, and file writes outside those roots. A blocked agent works around it or asks the orchestrator.

## Codex split and handoff

- **Who does what.** From ticket 06 on, and for all rework rounds, engineers run on Codex (`codex exec`). Claude keeps the architect and the eval agents.
- **Context.** Codex can't see Claude conversations. Every engineer keeps `HANDOFF.md` at its worktree root (uncommitted), updated at each commit: done and remaining checklist, decisions not yet in the contract, failing tests, exact next step.
- **Commits.** Codex's sandbox makes `.git` read-only, so Codex leaves changes uncommitted; the orchestrator commits them after the eval.
- **Automatic handoff.** Every running task is registered in `.claude/handoff/tasks/<id>.json` (worktree, brief, status). A `StopFailure` hook runs `.claude/hooks/codex-handoff.sh --hook`; on a usage-limit failure it starts `codex exec` for every task still marked `active`. Logs and results go to `.claude/handoff/logs/`.

## Fallback when Claude runs out

If any agent stops on a usage or rate limit, the orchestrator reruns the same brief with Codex CLI in the agent's worktree:

`codex exec -C <worktree> -s workspace-write -o <scratchpad>/<role>-codex.md "<same brief>"`

It runs as a background terminal process, with its log in the scratchpad. Codex output goes through the same eval loop as any engineer's. The Claude guard hook doesn't cover Codex; the `workspace-write` sandbox keeps its writes inside the worktree. If that sandbox blocks the Docker socket, the orchestrator widens it for that run only.

## Order

1. Architect: contracts and tickets.
2. Platform skeleton (ticket 01). Everything else depends on it.
3. In parallel: Inventory and holds (02), Ledger (03), Pricing (04), Payments gateway (05).
4. Booking confirm and cancel (06), plus real-time outbox (07).
5. k6 drop test (08).
6. Final product eval, then PR `build/v1` → `main`.

Tickets live in [tickets/](tickets/). After any merge into `build/v1`, every in-flight branch rebases onto `build/v1` before its next eval round, so the full-suite gate runs against what is actually merged.

## Module eval

Every engineer is paired with an eval agent. Each round writes `evals/<module>-r<N>.md`.

**Hard gates.** Any failure fails the round.

1. Pest green for the module and the full suite, on real Postgres.
2. PHPStan level 8 and Pint clean.
3. Arch test: no imports of another module's internals.
4. Every contract item for the module exists with the agreed signature, checked by `tests/Arch/Contracts/<Module>ContractTest.php` plus a read of contracts.md.

**Score (100).**

| Area | Points | Earned by |
| --- | --- | --- |
| Correctness | 40 | Each rule in the relevant build plan section traced to code and a test |
| Edge cases | 25 | Errors, expiry, retries, timezones, boundaries such as exactly 8 hours and capacity = booked |
| Concurrency and idempotency | 20 | Parallel-request tests on shared state; replay tests on every write |
| Code quality | 15 | Build plan §8 conventions: thin controllers, one action per use case, no dead code |

**Bar:** all gates pass and score ≥ 90. Below the bar, the engineer fixes the listed defects and a fresh round runs. After 4 failed rounds, the architect steps in; if that fails, the user decides.

## Final product eval

Runs on the merged `build/v1`. Results go in `final-eval.md`.

1. Fresh clone, `docker compose up`, migrate and seed from zero.
2. Full Pest suite, PHPStan, Pint, CI green.
3. k6 drop test, 3 runs, each passing every check in build plan §9.
4. `wallet:reconcile` reports zero drift after the drop.
5. End-to-end: search, hold, confirm (wallet plus card), cancel early, cancel late; balances match build plan §5.
6. Every in-scope rule in the build plan maps to a passing test.
7. Adversarial review of the full diff: security, races, money.

Failures go back to the owning engineer through the module loop, then the final eval reruns from step 1.

**Latency caveat.** Docker Desktop on macOS may cap p95 below what the code can do. If the code is clean and only latency misses locally, the CI run on Linux is the reference.
