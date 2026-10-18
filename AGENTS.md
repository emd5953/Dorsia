# Agent rules for this repo

Standing rules for any coding agent (Codex or Claude) working on the Dorsia rebuild. A task brief can narrow these; it never loosens them.

## Source of truth

- `docs/build_plan.md` says what to build. `docs/team/contracts.md` is binding: table ownership, public action signatures, events, error codes, config keys, timestamp rules. Your ticket in `docs/team/tickets/` lists the only files you may touch.
- If the contract is ambiguous or wrong, don't guess on anything that affects another module. Write the question and your proposed answer at the top of your report, take the conservative option that keeps the contract intact, and continue.
- Eval reports in `docs/team/evals/` list the defects you are fixing. Read the latest one for your module before starting.

## Environment

- No host PHP or Composer. Run everything through Docker via the `Makefile`: `make ci` (Pint, PHPStan level 8, full suite), `make test`, `make test-module M=<Module>`, `make arch`.
- Each worktree has its own Docker project: run `bin/worktree-env <NN>` (your ticket names NN), then `make up`. If the Docker daemon is down, run `open -a Docker` and wait.
- Tests run on real Postgres, never SQLite. Parallel tests use `tests/Support/Concurrency`.

## Rules

- Never push. Never force anything. Never edit another module's directory or `app/Shared` unless your ticket says so.
- Codex sessions cannot commit (`.git` is read-only in the sandbox). Leave changes uncommitted; the orchestrator commits after the eval.
- Keep `HANDOFF.md` at the worktree root (never commit it), updated after each meaningful step: done and remaining checklist, decisions not yet in the contract, failing tests, exact next step. Another agent must be able to continue from it alone.
- Microsecond timestamps everywhere: `timestampsTz(6)`, `$dateFormat = Timestamps::FORMAT`, `Timestamps::bind()` for query bindings.
- Don't write another module's table name after from/join/into/update anywhere, including messages; the arch test scans string literals.

## Definition of done

- `make ci` fully green.
- Every acceptance criterion in your ticket checked, with the command that proved it.
- When fixing an eval defect or mutation survivor, prove the test bites: apply the mutation, watch the test fail, revert.
- End with a report: what changed, each criterion with its proving command, `make ci` result, anything left, questions for the architect.
