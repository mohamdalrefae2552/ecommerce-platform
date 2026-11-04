#!/usr/bin/env bash
# Shared helpers for Claude Code hooks. Sourced, never executed directly.
# Hooks read a single JSON object on stdin; see https://code.claude.com/docs/en/hooks
set -euo pipefail

HOOK_INPUT=""

hook_read_input() {
  HOOK_INPUT="$(cat)"
}

# hook_field <jq-path>  → prints the value or empty string.
hook_field() {
  printf '%s' "$HOOK_INPUT" | jq -r "$1 // empty" 2>/dev/null || true
}

require_jq() {
  if ! command -v jq >/dev/null 2>&1; then
    echo "hook: jq is not installed; skipping $(basename "$0")" >&2
    exit 0
  fi
}

# repo_root_of <path> → top-level of the (work)tree containing path, or empty.
repo_root_of() {
  local p="$1" d
  if [[ -d "$p" ]]; then d="$p"; else d="$(dirname "$p")"; fi
  while [[ ! -d "$d" && "$d" != "/" ]]; do d="$(dirname "$d")"; done
  git -C "$d" rev-parse --show-toplevel 2>/dev/null || true
}

# components_changed <repo_root> → space separated list among: api inventory web e2e contracts
components_changed() {
  local root="$1" files
  files="$(
    {
      git -C "$root" diff --name-only "origin/main...HEAD" 2>/dev/null || true
      git -C "$root" diff --name-only HEAD 2>/dev/null || true
      git -C "$root" ls-files --others --exclude-standard 2>/dev/null || true
    } | sort -u
  )"
  local found=()
  grep -qE '^backend/api/'            <<<"$files" && found+=(api)
  grep -qE '^backend/inventory/'  <<<"$files" && found+=(inventory)
  grep -qE '^frontend/'            <<<"$files" && found+=(web)
  grep -qE '^tests/(e2e|load|security)/' <<<"$files" && found+=(e2e)
  grep -qE '^contracts/'           <<<"$files" && found+=(contracts)
  echo "${found[*]:-}"
}

is_linked_worktree() {
  local root="$1" gd cd
  gd="$(git -C "$root" rev-parse --absolute-git-dir 2>/dev/null || true)"
  cd="$(cd "$root" && git rev-parse --path-format=absolute --git-common-dir 2>/dev/null || true)"
  [[ -n "$gd" && -n "$cd" && "$gd" != "$cd" ]]
}
