#!/bin/bash
# Hands unfinished agent tasks to Codex. Each task is a JSON file in
# .claude/handoff/tasks/<id>.json: {"id","worktree","status","brief"}.
# Only tasks with status "active" are launched; each becomes "codex".
#
# Usage:
#   codex-handoff.sh            launch every active task
#   codex-handoff.sh <id>       launch one task regardless of status
#   codex-handoff.sh --hook     read StopFailure JSON on stdin; launch only on a usage limit
set -u
REPO=/Users/enrinjr/Documents/GitHub/Dorsia
DIR=$REPO/.claude/handoff
LOGS=$DIR/logs
mkdir -p "$DIR/tasks" "$LOGS"

if [ "${1:-}" = "--hook" ]; then
  payload=$(cat)
  echo "$(date -u +%FT%TZ) hook: $payload" >> "$LOGS/hook.log"
  echo "$payload" | grep -qiE 'rate.?limit|usage limit|session limit|"429"|overloaded' || exit 0
  shift
fi

launch() {
  local f=$1 id wt brief
  id=$(jq -r .id "$f"); wt=$(jq -r .worktree "$f"); brief=$(jq -r .brief "$f")
  [ -d "$wt" ] || { echo "skip $id: no worktree $wt" >> "$LOGS/hook.log"; return; }
  local prompt="You are taking over this task from a Claude agent that was cut off by a usage limit.

Context lives in files, not in that agent's memory. Before doing anything:
1. Read HANDOFF.md at the worktree root if it exists (the previous agent's progress, decisions, failing tests, next step).
2. Run git log --oneline -10, git status and git diff to see exactly what is already done.
3. Read the ticket, docs/team/contracts.md, docs/team/process.md and any docs/team/evals/ reports named below.

Then continue the task from where it stopped. Do not redo finished work.

Rules: you cannot commit (.git is read-only in your sandbox); leave all changes uncommitted and the orchestrator will commit them. Keep HANDOFF.md updated as you go. Run everything through Docker via the Makefile (no host PHP). Never push.

TASK:
$brief

When done, end with a report: what you changed, each acceptance item with the command that proved it, make ci result, anything left."
  nohup codex exec -C "$wt" -s workspace-write -c sandbox_workspace_write.network_access=true \
    -o "$LOGS/$id.result.md" "$prompt" > "$LOGS/$id.log" 2>&1 &
  jq --arg pid "$!" --arg at "$(date -u +%FT%TZ)" '.status="codex" | .codex_pid=$pid | .codex_started=$at' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
  echo "$(date -u +%FT%TZ) launched codex for $id (pid $!)" >> "$LOGS/hook.log"
}

if [ -n "${1:-}" ]; then
  launch "$DIR/tasks/$1.json"
else
  for f in "$DIR"/tasks/*.json; do
    [ -e "$f" ] || continue
    [ "$(jq -r .status "$f")" = "active" ] && launch "$f"
  done
fi
exit 0
