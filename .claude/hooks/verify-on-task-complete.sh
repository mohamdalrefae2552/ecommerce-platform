#!/usr/bin/env bash
# TaskCompleted: a task may only be marked completed when
#   1) its worktree has no uncommitted changes, and
#   2) `make verify-<component>` passes for every component touched on the branch.
# Exit 2 = refuse completion; stderr is fed back to the agent.
#
# Which worktree? The Lead writes a line `worktree: /abs/path` in every task description
# (see KICKOFF.md). Fallback: the hook's cwd.
source "$(dirname "$0")/lib.sh"
require_jq
hook_read_input

desc="$(hook_field '.task_description')"
subject="$(hook_field '.task_subject')"
wt="$(printf '%s\n' "$desc" | sed -n 's/^[[:space:]-]*worktree:[[:space:]]*\(\/[^[:space:]]*\).*$/\1/p' | head -n1)"
[[ -z "$wt" ]] && wt="$(hook_field '.cwd')"
[[ -z "$wt" || ! -d "$wt" ]] && exit 0

root="$(repo_root_of "$wt")"
[[ -z "$root" ]] && exit 0

# Review/research tasks for the Lead run on the main checkout and change nothing: allow.
if ! is_linked_worktree "$root"; then
  exit 0
fi

if [[ -n "$(git -C "$root" status --porcelain)" ]]; then
  {
    echo "Task \"$subject\" cannot be completed: uncommitted changes in $root"
    git -C "$root" status --short | head -n 30
    echo "Commit them (Conventional Commits) or revert them, then mark the task completed again."
  } >&2
  exit 2
fi

components="$(components_changed "$root")"
[[ -z "$components" ]] && exit 0

for c in $components; do
  target="verify-$c"
  [[ "$c" == "contracts" ]] && target="lint-contracts"
  if ! make -C "$root" -n "$target" >/dev/null 2>&1; then
    continue
  fi
  set +e
  out="$(make -C "$root" --no-print-directory "$target" 2>&1)"
  rc=$?
  set -e
  if [[ $rc -ne 0 ]]; then
    {
      echo "Task \"$subject\" cannot be completed: 'make $target' failed (rc=$rc) in $root"
      echo "----- last 60 lines -----"
      echo "$out" | tail -n 60
      echo "Fix the first error, re-run 'make $target', commit, then complete the task again."
    } >&2
    exit 2
  fi
done

exit 0
