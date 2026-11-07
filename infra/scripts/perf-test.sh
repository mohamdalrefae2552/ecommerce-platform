#!/usr/bin/env bash
# `make perf-test` (requirement 3, R3-7): runs tests/load/perf.js in the k6
# tools container and, from the moment k6 finishes its setup (logins) until
# the end, samples the containers' CPU/memory (perf-stats.sh).
# Results: docs/performance/<OUT>.json, <OUT>.txt (k6) and <OUT>-stats.txt.
#   PROJECT=... COMPOSE="docker compose ..." OUT=baseline perf-test.sh
set -euo pipefail

: "${PROJECT:?}" "${COMPOSE:?}" "${OUT:?}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
out_dir="docs/performance"
stop="$(mktemp -u)"
status_file="$(mktemp)"
trap 'rm -f "$stop" "$status_file"' EXIT
mkdir -p "$out_dir" .cache/perf
sampler=""

run_k6() {
  # .cache/perf (git-ignored): access tokens given by perf-compare (TOKENS_IN).
  # shellcheck disable=SC2086 # COMPOSE is a command line with options.
  $COMPOSE --profile tools run --rm -v "$PWD/$out_dir:/work/out" -v "$PWD/.cache/perf:/work/tokens" \
    -e OUT="$OUT" -e DURATION="${DURATION:-60s}" -e SCALE="${SCALE:-1}" -e CUSTOMERS="${CUSTOMERS:-}" \
    -e TOKENS_IN="${TOKENS_IN:-}" \
    k6 run --quiet /work/tests/load/perf.js 2>&1 && echo 0 >"$status_file" || echo $? >"$status_file"
}

while IFS= read -r line; do
  printf '%s\n' "$line"
  if [[ -z "$sampler" && "$line" == *"setup: "* ]]; then
    PROJECT="$PROJECT" STOP="$stop" bash "$here/perf-stats.sh" >"$out_dir/$OUT-stats.txt" &
    sampler=$!
  fi
done < <(run_k6)

touch "$stop"
[[ -n "$sampler" ]] && wait "$sampler"
if [[ -s "$out_dir/$OUT-stats.txt" ]]; then
  echo "--- containers during the load ($out_dir/$OUT-stats.txt)"
  cat "$out_dir/$OUT-stats.txt"
fi
exit "$(cat "$status_file")"
