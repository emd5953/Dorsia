#!/bin/bash
# Re-runs the one-shot Codex orchestrator every 2 minutes until the Claude limit resets
# (resets_at epoch in $1) or .claude/handoff/stop-orchestrator exists.
REPO=/Users/enrinjr/Documents/GitHub/Dorsia; D=$REPO/.claude/handoff; UNTIL=${1:-0}
while [ ! -e "$D/stop-orchestrator" ] && [ "$(date +%s)" -lt "$UNTIL" ]; do
  codex exec -C "$REPO" -s danger-full-access -o "$D/logs/orchestrator.result.md" \
    "$(cat "$D/orchestrator-prompt.txt") This is one pass: act on everything that is ready now (commit finished tasks, launch evals, merge PASSes, launch reworks and next tickets with $REPO/.claude/hooks/codex-handoff.sh), update docs/team/STATUS.md, then exit. You will be re-run in 2 minutes." </dev/null >> "$D/logs/orchestrator.log" 2>&1
  sleep 120
done
