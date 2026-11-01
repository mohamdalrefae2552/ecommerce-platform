#!/usr/bin/env bash
# Format a single file. Called by `make fmt-file FILE=<abs path>`, which the
# PostToolUse hook (.claude/hooks/format-file.sh) runs after every edit.
#
# Output contract read by the hook:
#   exit 0                            formatted, or nothing to do
#   exit 1 + a line "FMT_INVALID: …"  the file itself is invalid
#   exit 1 without the marker         infrastructure problem (non-blocking)
# Tools run in already-running containers only; when the container or image is
# not there the file is skipped silently (formatting happens in verify-* too).
set -uo pipefail

file="${1:-}"
[[ -n "$file" && -f "$file" ]] || exit 0
command -v docker >/dev/null 2>&1 || exit 0

root="$(git -C "$(dirname "$file")" rev-parse --show-toplevel 2>/dev/null)" || exit 0
abs="$(cd "$(dirname "$file")" && pwd -P)/$(basename "$file")"
root="$(cd "$root" && pwd -P)"
rel="${abs#"$root"/}"
compose=(docker compose -p "${PROJECT:?}" --project-directory "$root")

running() { [[ -n "$("${compose[@]}" ps --status running -q "$1" 2>/dev/null)" ]]; }

# run_or_invalid <cmd...>: on failure print the FMT_INVALID marker.
run_or_invalid() {
  local out
  if ! out="$("$@" 2>&1)"; then
    echo "FMT_INVALID: $rel"
    echo "$out" | tail -n 30
    exit 1
  fi
}

case "$rel" in
  backend/api/generated/*) exit 0 ;;
  backend/api/*.php)
    running api || exit 0
    run_or_invalid "${compose[@]}" exec -T api vendor/bin/pint --quiet "${rel#backend/api/}"
    ;;
  backend/inventory/*.java)
    # Spotless on a single file costs a Gradle start-up (> hook timeout);
    # `make verify-inventory` runs spotlessCheck on the whole module instead.
    exit 0
    ;;
  frontend/src/api/schema.d.ts) exit 0 ;;
  frontend/*.ts | frontend/*.tsx | frontend/*.js | frontend/*.mjs | frontend/*.css | frontend/*.json | frontend/*.md)
    running web-dev || exit 0
    run_or_invalid "${compose[@]}" exec -T web-dev pnpm exec prettier --write --log-level warn "${rel#frontend/}"
    ;;
  contracts/proto/*.proto)
    docker image inspect "${PROJECT}-protoc" >/dev/null 2>&1 || exit 0
    run_or_invalid "${compose[@]}" --profile tools run --rm --no-deps -T -w /work/contracts/proto \
      protoc buf format -w "${rel#contracts/proto/}"
    ;;
  *) exit 0 ;;
esac
exit 0
