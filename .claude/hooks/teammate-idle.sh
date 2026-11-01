#!/usr/bin/env bash
# TeammateIdle: keep a teammate working if it is about to go idle with uncommitted work
# in its own linked worktree. Exit 2 = keep working (stderr is the feedback).
# Loop guard: after 2 consecutive refusals for the same worktree we let it idle and
# rely on the Lead, so a stuck teammate never spins forever.
source "$(dirname "$0")/lib.sh"
require_jq
hook_read_input

cwd="$(hook_field '.cwd')"
[[ -z "$cwd" || ! -d "$cwd" ]] && exit 0
root="$(repo_root_of "$cwd")"
[[ -z "$root" ]] && exit 0
is_linked_worktree "$root" || exit 0

state_dir="${TMPDIR:-/tmp}/claude-teammate-idle"
mkdir -p "$state_dir"
key="$(printf '%s' "$root" | cksum | cut -d' ' -f1)"
counter="$state_dir/$key"

if [[ -z "$(git -C "$root" status --porcelain)" ]]; then
  rm -f "$counter"
  exit 0
fi

n="$(cat "$counter" 2>/dev/null || echo 0)"
if (( n >= 2 )); then
  rm -f "$counter"
  echo "teammate-idle: allowing idle with uncommitted changes in $root after 2 reminders; Lead must follow up." >&2
  exit 0
fi
echo $((n + 1)) > "$counter"

{
  echo "You have uncommitted changes in $root:"
  git -C "$root" status --short | head -n 20
  echo "Before going idle: run the component's 'make verify-*', commit with a Conventional Commit message,"
  echo "update your task status, and send a [HANDOFF] or [BLOCKED] message to the Lead."
} >&2
exit 2
