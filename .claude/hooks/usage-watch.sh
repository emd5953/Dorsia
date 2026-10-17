#!/bin/bash
# Called from the statusline with its JSON payload on stdin. When the 5-hour or
# 7-day plan usage reaches the threshold, switches all work to Codex once per
# reset window. Only acts for sessions in the Dorsia repo.
set -u
REPO=/Users/enrinjr/Documents/GitHub/Dorsia
THRESHOLD=${CODEX_SWITCH_AT:-95}
payload=$(cat)

cwd=$(printf '%s' "$payload" | jq -r '.workspace.project_dir // .cwd // empty')
case "$cwd" in "$REPO"*) ;; *) exit 0 ;; esac

read -r pct resets < <(printf '%s' "$payload" | jq -r '
  [.rate_limits.five_hour, .rate_limits.seven_day] | map(select(. != null))
  | if length == 0 then "0 0" else (max_by(.used_percentage) | "\(.used_percentage) \(.resets_at)") end')
[ "${pct%.*}" -ge "$THRESHOLD" ] 2>/dev/null || exit 0

mkdir -p "$REPO/.claude/handoff/logs"
lock="$REPO/.claude/handoff/switched-$resets"
[ -e "$lock" ] && exit 0
touch "$lock"
transcript=$(printf '%s' "$payload" | jq -r '.transcript_path // empty')
echo "$(date -u +%FT%TZ) usage ${pct}% >= ${THRESHOLD}%: switching to Codex" >> "$REPO/.claude/handoff/logs/hook.log"
nohup "$REPO/.claude/hooks/codex-handoff.sh" --switch "$transcript" >> "$REPO/.claude/handoff/logs/hook.log" 2>&1 &
exit 0
