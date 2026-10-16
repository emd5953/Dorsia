#!/usr/bin/env node
// PreToolUse guard for agent sessions. Denies a fixed list of destructive or
// out-of-bounds actions; everything else passes through untouched.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const REPO = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', '..');
const HOME = os.homedir();

function allowedRoots() {
  const roots = [REPO, '/private/tmp/claude-501', '/tmp/claude-501',
    path.join(HOME, '.claude', 'projects', '-Users-enrinjr-Documents-GitHub-Dorsia')];
  try {
    const out = execFileSync('git', ['-C', REPO, 'worktree', 'list', '--porcelain'], { encoding: 'utf8' });
    for (const line of out.split('\n')) if (line.startsWith('worktree ')) roots.push(line.slice(9));
  } catch {}
  return roots;
}

function inside(p, roots) {
  const abs = path.resolve(p);
  return roots.some((r) => abs === r || abs.startsWith(r + path.sep));
}

function deny(reason) {
  process.stdout.write(JSON.stringify({
    hookSpecificOutput: {
      hookEventName: 'PreToolUse',
      permissionDecision: 'deny',
      permissionDecisionReason: `Blocked by project guard: ${reason}. Do it another way or ask the orchestrator.`,
    },
  }));
  process.exit(0);
}

function tokenize(segment) {
  return (segment.match(/"[^"]*"|'[^']*'|\S+/g) || []).map((t) => t.replace(/^["']|["']$/g, ''));
}

function checkBash(cmd, cwd) {
  if (/\b(curl|wget)\b[^|;&]*\|\s*(sudo\s+)?(ba|z|da)?sh\b/.test(cmd)) deny('piping a download into a shell');

  const roots = allowedRoots();
  for (const segment of cmd.split(/&&|\|\||;|\||\n/)) {
    const t = tokenize(segment.trim());
    while (t.length && /^(sudo|env|command|\w+=\S*)$/.test(t[0])) t.shift();
    if (!t.length) continue;
    const [bin, ...args] = t;

    if (bin === 'git' && args.includes('push')) {
      const rest = args.slice(args.indexOf('push') + 1);
      if (rest.some((a) => /^(-f|--force|--force-with-lease|--force-if-includes)(=|$)/.test(a) || /^\+/.test(a))) deny('force push');
      if (rest.some((a) => /(^|:)(refs\/heads\/)?main$/.test(a))) deny('push to main');
    }

    if (bin === 'rm') {
      const flags = args.filter((a) => a.startsWith('-')).join('');
      const recursive = /r|R|--recursive/.test(flags);
      if (recursive) {
        for (const target of args.filter((a) => !a.startsWith('-'))) {
          if (/[$`]/.test(target)) deny(`rm -r on an unexpanded path (${target}); use a literal path`);
          const abs = path.resolve(cwd, target.replace(/^~(?=\/|$)/, HOME));
          if (abs === REPO || !inside(abs, roots)) deny(`rm -r outside the repo, worktrees or scratchpad (${abs})`);
        }
      }
    }

    if (bin === 'docker') {
      const sub = args.filter((a) => !a.startsWith('-'));
      if (sub[0] === 'system' && sub[1] === 'prune') deny('docker system prune');
      if (sub[0] === 'volume' && sub[1] === 'prune') deny('docker volume prune');
      if (sub[0] === 'volume' && (sub[1] === 'rm' || sub[1] === 'remove')) {
        const bad = sub.slice(2).filter((v) => !/^dorsia/i.test(v));
        if (bad.length) deny(`docker volume rm on non-project volumes (${bad.join(', ')})`);
      }
    }
  }
}

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const tool = input.tool_name;
const ti = input.tool_input || {};
const cwd = input.cwd || process.cwd();

if (tool === 'Bash') checkBash(ti.command || '', cwd);
if (['Write', 'Edit', 'NotebookEdit'].includes(tool)) {
  const file = ti.file_path || ti.notebook_path;
  if (file && !inside(path.resolve(cwd, file), allowedRoots())) deny(`writing outside the repo, worktrees or scratchpad (${file})`);
}
