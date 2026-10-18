#!/bin/bash
# Builds a Codex-readable context pack from Claude transcripts.
# Usage: build-context-pack.sh <main-session-transcript.jsonl>
# Writes .claude/handoff/context/{main.md,<task-id>.md}.
set -u
REPO=/Users/enrinjr/Documents/GitHub/Dorsia
OUT=$REPO/.claude/handoff/context
MAIN=${1:-}
mkdir -p "$OUT"

# Conversation text only: user prompts and assistant replies, no tool calls, results or thinking.
dialogue() {
  jq -r '
    if .type == "user" and (.message.content | type) == "string" then "USER: " + .message.content
    elif .type == "user" then (.message.content[]? | select(.type == "text") | "USER: " + .text)
    elif .type == "assistant" then (.message.content[]? | select(.type == "text") | "ASSISTANT: " + .text)
    else empty end' "$1" 2>/dev/null | grep -v '^USER: <system-reminder>\|^USER: <task-notification>'
}

if [ -f "$MAIN" ]; then
  {
    echo "# Main orchestrator session"
    echo
    echo "Full transcript (JSONL, large; grep it for details): $MAIN"
    echo
    echo "## Opening request"
    echo
    dialogue "$MAIN" | head -c 6000
    echo
    echo "## Most recent conversation"
    echo
    dialogue "$MAIN" | tail -c 30000
  } > "$OUT/main.md"
fi

SUB=$(dirname "$MAIN")/$(basename "$MAIN" .jsonl)/subagents
for f in "$REPO"/.claude/handoff/tasks/*.json; do
  [ -e "$f" ] || continue
  [ "$(jq -r .status "$f")" = "active" ] || continue
  id=$(jq -r .id "$f"); agent=$(jq -r '.claude_agent_id // empty' "$f")
  {
    echo "# Task $id"
    echo
    echo "## Brief"
    echo
    jq -r .brief "$f"
    if [ -n "$agent" ] && [ -f "$SUB/agent-$agent.jsonl" ]; then
      echo
      echo "## Last messages from the Claude agent working on it"
      echo
      echo "Full transcript: $SUB/agent-$agent.jsonl"
      echo
      dialogue "$SUB/agent-$agent.jsonl" | grep '^ASSISTANT:' | tail -c 12000
    fi
  } > "$OUT/$id.md"
done
echo "$OUT"
