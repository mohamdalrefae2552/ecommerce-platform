#!/usr/bin/env bash
# `make scale-load` (requirement 5, PROOF-R5-1 and PROOF-R5-2): sends
# REQUESTS requests (500) through edge and shows, from the edge access log,
# how many each api replica answered. With STOP=<replica number> that replica
# is stopped STOP_AFTER seconds into the run and started again at the end:
# the run must still have no failed request.
#   PROJECT=... COMPOSE="docker compose ..." scale-load.sh
set -euo pipefail

: "${PROJECT:?}" "${COMPOSE:?}"
REQUESTS="${REQUESTS:-500}"
RATE="${RATE:-25}"
STOP="${STOP:-}"
STOP_AFTER="${STOP_AFTER:-6}"
RUN="r5-$(date +%s)"
tokens=".cache/perf/tokens.json"

step() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }
cleanup() {
  rm -f "$tokens"
  if [[ -n "$STOP" ]]; then docker start "${PROJECT}-api-${STOP}" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT

replicas="$($COMPOSE ps --status running --format '{{.Name}}' api | sort)"
[[ -n "$replicas" ]] || { echo "✗ no api replica is running (make up)" >&2; exit 1; }
step "api replicas behind edge: $(wc -l <<<"$replicas" | tr -d ' ')"
declare -A name_of
while IFS= read -r container; do
  ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$container")"
  name_of["$ip"]="${container#"${PROJECT}-"}"
  printf '  %-8s %s\n' "${container#"${PROJECT}-"}" "$ip"
done <<<"$replicas"

mkdir -p .cache/perf
$COMPOSE exec -T api php artisan app:perf-tokens 60 | tr -d '\r' >"$tokens"

step "$REQUESTS requests at $RATE/s (run $RUN)$([[ -n "$STOP" ]] && echo ", api-$STOP stopped after ${STOP_AFTER}s")"
if [[ -n "$STOP" ]]; then
  ( sleep "$STOP_AFTER"; docker stop -t 1 "${PROJECT}-api-${STOP}" >/dev/null && printf '  ⏹ api-%s stopped at %ss\n' "$STOP" "$STOP_AFTER" ) &
fi
# shellcheck disable=SC2086 # COMPOSE is a command line with options.
$COMPOSE --profile tools run --rm -v "$PWD/.cache/perf:/work/tokens" \
  -e TOKENS_IN=/work/tokens/tokens.json -e REQUESTS="$REQUESTS" -e RATE="$RATE" -e RUN="$RUN" \
  k6 run --quiet /work/tests/load/scale.js 2>&1 | grep -vE '^\s*$|level=(info|warning)' || status=$?
wait

step "who answered — from the edge access log (field upstream)"
sleep 2
log="$($COMPOSE logs --no-log-prefix --since 10m edge 2>/dev/null | grep "\"request_id\":\"${RUN}-" || true)"
total="$(grep -c . <<<"$log" || true)"
printf '  %-10s %8s %7s\n' replica requests share
while IFS= read -r ip; do
  [[ -n "$ip" ]] || continue
  count="$(grep -c "\"upstream\":\"${ip}:9000" <<<"$log" || true)"
  printf '  %-10s %8s %6s%%\n' "${name_of[$ip]:-$ip}" "$count" "$((count * 100 / (total > 0 ? total : 1)))"
done < <(for ip in "${!name_of[@]}"; do echo "$ip"; done | sort)
retried="$(grep -c '"upstream":"[^"]*, ' <<<"$log" || true)"
printf '  %-10s %8s\n' total "$total"
printf '  sent to another replica after a failed connection (failover): %s\n' "$retried"
printf '  answers by status: %s\n' "$(grep -o '"status":[0-9]*' <<<"$log" | sort | uniq -c | awk '{printf "%s×%s  ", $2, $1}' | sed 's/"status"://g')"
exit "${status:-0}"
