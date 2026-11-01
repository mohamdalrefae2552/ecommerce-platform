#!/usr/bin/env bash
# PostToolUse (Write|Edit): format the edited file through the repo's own Makefile.
# Contract with the Makefile (implemented in task P0-LEAD-01):
#   make fmt-file FILE=<abs path>
#     success            → formatted, or nothing to do (unknown type / container not running)
#     failure + a line starting with "FMT_INVALID:" in the output
#                        → the file itself is invalid (syntax error, formatter rejected it)
#                          → surfaced to Claude (exit 2)
#     any other failure  → infrastructure problem → non-blocking notice (exit 1)
# (make itself always exits 2 when a recipe fails, so the marker — not the exit code — decides.)
source "$(dirname "$0")/lib.sh"
require_jq
hook_read_input

path="$(hook_field '.tool_input.file_path')"
[[ -z "$path" || ! -f "$path" ]] && exit 0

case "$path" in
  *.php|*.java|*.ts|*.tsx|*.js|*.mjs|*.css|*.json|*.yaml|*.yml|*.proto|*.md) ;;
  *) exit 0 ;;
esac

root="$(repo_root_of "$path")"
[[ -z "$root" || ! -f "$root/Makefile" ]] && exit 0

# Before P0 the target may not exist yet: skip quietly.
if ! make -C "$root" -n fmt-file FILE="$path" >/dev/null 2>&1; then
  exit 0
fi

set +e
out="$(make -C "$root" --no-print-directory fmt-file FILE="$path" 2>&1)"
rc=$?
set -e

if [[ $rc -eq 0 ]]; then
  exit 0
fi
if grep -q '^FMT_INVALID:' <<<"$out"; then
  { echo "Formatter rejected $path:"; echo "$out" | tail -n 40; } >&2
  exit 2
fi
{ echo "fmt-file infrastructure error (rc=$rc) for $path:"; echo "$out" | tail -n 20; } >&2
exit 1
