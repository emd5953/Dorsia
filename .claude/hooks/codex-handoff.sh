#!/bin/bash
# Hands work to Codex. Each task is .claude/handoff/tasks/<id>.json:
#   {"id","worktree","status","brief","engine","claude_agent_id"}
# status "active" = running (on Claude or Codex) and not yet finished.
#
# Usage:
#   codex-handoff.sh <id>                  launch one task on Codex now (normal dispatch)
#   codex-handoff.sh --switch <transcript> switch everything to Codex: context pack,
#                                          every active Claude task, plus a Codex orchestrator
#   codex-handoff.sh --hook                StopFailure hook: JSON on stdin; switch on a usage limit
set -u
REPO=/Users/enrinjr/Documents/GitHub/Dorsia
DIR=$REPO/.claude/handoff
LOGS=$DIR/logs
CTX=$DIR/context
mkdir -p "$DIR/tasks" "$LOGS"
log() { echo "$(date -u +%FT%TZ) $*" >> "$LOGS/hook.log"; }

launch() {
  local f=$1 id wt brief extra=""
  [ -n "${CODEX_HANDOFF_DRY:-}" ] && { log "DRY launch $(jq -r .id "$f")"; return; }
  id=$(jq -r .id "$f"); wt=$(jq -r .worktree "$f"); brief=$(jq -r .brief "$f")
  [ -d "$wt" ] || { log "skip $id: no worktree $wt"; return; }
  [ -f "$CTX/$id.md" ] && extra="
A Claude agent was working on this when Claude usage ran out. Its brief and last messages: $CTX/$id.md
The orchestrator's context: $CTX/main.md"
  local prompt="You are a Codex agent on the Dorsia rebuild. Read AGENTS.md at the repo root first; its rules apply.

Context lives in files. Before doing anything:
1. Read HANDOFF.md at the worktree root if it exists.
2. Run git log --oneline -10, git status and git diff to see what is already done.
3. Read the ticket, docs/team/contracts.md and any docs/team/evals/ reports named below.$extra

Continue from where the work stopped; don't redo finished work.

TASK:
$brief"
  nohup codex exec -C "$wt" -s workspace-write -c sandbox_workspace_write.network_access=true \
    -o "$LOGS/$id.result.md" "$prompt" > "$LOGS/$id.log" 2>&1 &
  jq --arg pid "$!" --arg at "$(date -u +%FT%TZ)" '.engine="codex" | .codex_pid=$pid | .codex_started=$at' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
  log "launched codex for $id (pid $!)"
}

switch() {
  local transcript=$1
  [ -n "${CODEX_HANDOFF_DRY:-}" ] && { log "DRY switch transcript=$transcript"; "$REPO/.claude/hooks/build-context-pack.sh" "$transcript" > /dev/null; for f in "$DIR"/tasks/*.json; do [ "$(jq -r .status "$f")" = active ] && [ "$(jq -r '.engine // "claude"' "$f")" = claude ] && launch "$f"; done; log "DRY orchestrator"; return; }
  "$REPO/.claude/hooks/build-context-pack.sh" "$transcript" > /dev/null
  for f in "$DIR"/tasks/*.json; do
    [ -e "$f" ] || continue
    [ "$(jq -r .status "$f")" = "active" ] && [ "$(jq -r '.engine // "claude"' "$f")" = "claude" ] && launch "$f"
  done
  [ -e "$DIR/orchestrator.pid" ] && kill -0 "$(cat "$DIR/orchestrator.pid")" 2>/dev/null && { log "orchestrator already running"; return; }
  local prompt="You are taking over as orchestrator of the Dorsia multi-agent build. The Claude orchestrator is out of usage; you now run the build until the user returns.

Read, in order: AGENTS.md, docs/team/process.md, docs/team/STATUS.md (current phase, running work, pending decisions, next steps), $CTX/main.md (the user's requests and the recent orchestrator conversation; the full transcript path is inside), and every file in $CTX/ and $DIR/tasks/.

Your job, following process.md:
- Tasks in $DIR/tasks/ with status active and engine codex are already running as Codex processes (codex_pid). Watch them (kill -0 pid). When one exits, read $LOGS/<id>.result.md, commit its worktree changes on its feature branch (never commit HANDOFF.md), and rebase it on build/v1.
- Every finished engineer task needs an eval: run a separate codex exec as the eval agent with the module eval rubric from process.md (gates, mutation testing, score, PASS at all gates + >= 90). Write the report to docs/team/evals/<module>-r<N>.md.
- PASS: merge the branch into build/v1 with git merge --no-ff and mark the task done. FAIL: write a rework task file and launch it with $REPO/.claude/hooks/codex-handoff.sh <id>.
- Start the next tickets when their dependencies are merged, per process.md Order.
- Update docs/team/STATUS.md after every step, so the Claude orchestrator can take back over.
Never push, never touch main, never merge to main, never open a PR. Contract questions: write them into docs/team/STATUS.md under Open decisions and take the conservative option."
  printf "%s" "$prompt" > "$DIR/orchestrator-prompt.txt"
  rm -f "$DIR/stop-orchestrator"
  nohup "$REPO/.claude/hooks/orchestrator-loop.sh" "$(( $(date +%s) + 5*3600 ))" > /dev/null 2>&1 &
  echo $! > "$DIR/orchestrator.pid"
  log "launched codex orchestrator (pid $!)"
}

case "${1:-}" in
  --hook)
    payload=$(cat)
    log "stopfailure: $(printf '%s' "$payload" | head -c 500)"
    printf '%s' "$payload" | grep -qiE 'rate.?limit|usage limit|session limit|"429"' || exit 0
    switch "$(printf '%s' "$payload" | jq -r '.transcript_path // empty')"
    ;;
  --switch) switch "${2:-}" ;;
  "") echo "usage: codex-handoff.sh <id> | --switch <transcript> | --hook" >&2; exit 1 ;;
  *) launch "$DIR/tasks/$1.json" ;;
esac
exit 0
