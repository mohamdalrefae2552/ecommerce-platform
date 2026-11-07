#!/usr/bin/env bash
# `make perf-compare` (requirement 3, live before/after demo): measures the
# SAME load twice on the running stack and prints one comparison table.
#   before: every requirement 3 optimisation switched off — catalogue cache
#           off, ETag off, no read replica (DB_READ_HOST=db-app), rate-limit
#           store back in Postgres (API_CACHE_STORE=database), orders index
#           dropped (app:perf-index off).
#   after:  the defaults (all on), index recreated, catalogue cache warmed.
# The optimised mode is ALWAYS restored at the end, even on failure or
# Ctrl+C (trap). Both runs share one set of logins (tokens in .cache/perf),
# so the per-IP login limit is paid once.
#   PROJECT=... COMPOSE="docker compose ..." HOST_URL=... perf-compare.sh
set -euo pipefail

# HOST_URL, not EDGE_URL: EDGE_URL would leak into the k6 container (which
# reaches edge as http://localhost inside its network namespace).
: "${PROJECT:?}" "${COMPOSE:?}" "${HOST_URL:?}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
out_dir="docs/performance"
duration="${DURATION:-45s}"
# 19 customers + the admin = 20 logins, exactly the per-IP limit of a minute.
# 91.5 x 0.35 = 32 req/s, i.e. 1.7 req/s per customer (limit: 2/s = 120/min).
customers="${CUSTOMERS:-19}"
scale="${SCALE:-0.35}"
tokens=".cache/perf/tokens.json"
mode=optimised

step() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }
artisan() { $COMPOSE exec -T api php artisan "$@"; }

# wait_edge: edge resolves `api` every 10 s; wait until it reaches the new
# container (3 healthy answers in a row).
wait_edge() {
  local ok=0
  for _ in $(seq 1 60); do
    if [[ "$(curl -s -o /dev/null -m 3 -w '%{http_code}' "$HOST_URL/api/v1/health")" == 200 ]]; then
      ok=$((ok + 1))
      ((ok >= 3)) && return 0
    else
      ok=0
    fi
    sleep 1
  done
  echo "✗ edge does not reach the api" >&2
  return 1
}

# switch <before|after>: recreate api + scheduler with that configuration.
switch() {
  # Exported, not just prefixed: the k6 run (`compose run`) re-checks its
  # dependencies (edge -> api) and would otherwise recreate the api with the
  # default configuration in the middle of the "before" run.
  if [[ "$1" == before ]]; then
    export API_CACHE_STORE=database CATALOG_CACHE_ENABLED=false HTTP_ETAG_ENABLED=false DB_READ_HOST=db-app
    mode=before
  else
    unset API_CACHE_STORE CATALOG_CACHE_ENABLED HTTP_ETAG_ENABLED DB_READ_HOST
    mode=optimised
  fi
  $COMPOSE up -d --no-deps --force-recreate --wait api scheduler
  artisan app:perf-index "$([[ "$1" == before ]] && echo off || echo on)"
  # Start from an empty catalogue cache: writes made while it was off did
  # not invalidate it, and the "before" run must not find warm entries.
  artisan cache:clear redis
  wait_edge
}

restore() {
  local status=$?
  rm -f "$tokens"
  if [[ "$mode" != optimised ]]; then
    step "restoring the optimised mode"
    switch after || true
  fi
  exit "$status"
}
trap restore EXIT

run() {
  PROJECT="$PROJECT" COMPOSE="$COMPOSE" OUT="$1" DURATION="$duration" SCALE="$scale" CUSTOMERS="$customers" \
    TOKENS_IN="${2:-}" TOKENS_OUT="${3:-}" bash "$here/perf-test.sh" | grep -E 'R3 performance|endpoint|_list|_details|categories|_orders|http requests|result:|level=error|failed thresholds|  - '
}

step "BEFORE — requirement 3 switched off (no cache, no ETag, no replica, rate limits in Postgres, no orders index)"
switch before
run live-before "" /work/tokens/tokens.json

step "AFTER — optimised (cache, ETag, replica, Redis, index)"
switch after
# Warm-up: the first reads fill the catalogue cache, as in normal operation.
for page in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null -m 5 "$HOST_URL/api/v1/products?page=$page"; done
curl -s -o /dev/null -m 5 "$HOST_URL/api/v1/categories"
run live-after /work/tokens/tokens.json ""

step "RESULT — same load ($customers customers, $(awk "BEGIN{printf \"%.0f\", 91.5*$scale}") req/s, $duration each), latency in ms"
jq -r -s '
  def f: if . == null then "-" else (. * 10 | round / 10 | tostring) end;
  def x(a; b): if (a // 0) > 0 and (b // 0) > 0 then ((a / b) * 10 | round / 10 | tostring) + "x" else "-" end;
  (.[0].endpoints | map({key: .endpoint, value: .}) | from_entries) as $b
  | (.[1].endpoints | map({key: .endpoint, value: .}) | from_entries) as $a
  | ["endpoint", "p50 before->after", "p95 before->after", "p99 before->after", "p95 speed-up", "304 after"],
    (($b | keys_unsorted[]) as $k
      | [$k,
         "\($b[$k].p50 | f) -> \($a[$k].p50 | f)",
         "\($b[$k].p95 | f) -> \($a[$k].p95 | f)",
         "\($b[$k].p99 | f) -> \($a[$k].p99 | f)",
         x($b[$k].p95; $a[$k].p95),
         "\(($a[$k].not_modified_rate // 0) * 100 | round)%"])
  | @tsv' "$out_dir/live-before.json" "$out_dir/live-after.json" | column -t -s $'\t'

cpu() { awk -v c="$1" '$1 == c { print $2 "%" }' "$out_dir/$2-stats.txt"; }
printf '\ncontainer CPU (avg, 100%% = one core): db-app %s -> %s | db-app-replica - -> %s | api %s -> %s\n' \
  "$(cpu db-app live-before)" "$(cpu db-app live-after)" "$(cpu db-app-replica live-after)" "$(cpu api live-before)" "$(cpu api live-after)"
printf 'files: %s/live-before.{txt,json} %s/live-after.{txt,json} and *-stats.txt\n' "$out_dir" "$out_dir"
