#!/usr/bin/env bash
# `make scale-problems` — requirement 5 (R5-4, PROOF-R5-3): the problems that
# appear when one api becomes several, each shown live BEFORE the fix (the
# old setting is put back for the test) and AFTER it. Docs: docs/11-scaling.md.
#
#   P1  a stopped replica makes requests fail       (edge: no failover)
#   P2  maintenance mode stops one replica only     (flag in a local file)
#   P3  login limit is per replica: 3x the attempts (cache in local files)
#   P4  an edited product stays stale on replicas   (cache in local files)
#   P5  live updates get lost with 2 WebSocket servers (no Redis pub/sub)
#
# Everything is put back to the normal configuration at the end, even on
# failure. Needs the stack running with 3 api replicas (make up).
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
export MSYS_NO_PATHCONV=1

require_tools docker
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD
: "${COMPOSE:?COMPOSE is not set (run through make scale-problems)}"

REVERB_APP_KEY="${REVERB_APP_KEY:-ecommerce-web}"
NODE_IMAGE="node:24.22.2-alpine3.24"
K6_IMAGE="grafana/k6:2.3.0"
NET="${PROJECT}_app-net"
probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/lib" && { pwd -W 2>/dev/null || pwd; })"
work="$(pwd)/.cache/scale"
mkdir -p "$work"
declare -A BEFORE AFTER

# configure <before|after>: recreate api and reverb with the old (local)
# settings or the normal ones; edge and everything else stay as they are.
configure() {
  if [[ "$1" == before ]]; then
    export API_CACHE_STORE=file APP_MAINTENANCE_DRIVER=file REVERB_SCALING_ENABLED=false
  else
    unset API_CACHE_STORE APP_MAINTENANCE_DRIVER REVERB_SCALING_ENABLED
  fi
  # shellcheck disable=SC2086 # COMPOSE is a command line with options.
  $COMPOSE up -d --no-deps --force-recreate --wait --scale api=3 --scale reverb=2 api reverb >/dev/null 2>&1
  sleep 7 # edge re-reads the replicas' addresses every 5 s
}
restore() {
  docker rm -f "${PROJECT}-edge-before" >/dev/null 2>&1 || true
  if [[ -n "${API_CACHE_STORE:-}${APP_MAINTENANCE_DRIVER:-}${REVERB_SCALING_ENABLED:-}" ]]; then
    docker exec "${PROJECT}-api-1" php artisan up >/dev/null 2>&1 || true
    configure after
  fi
  # shellcheck disable=SC2086
  $COMPOSE up -d --wait >/dev/null 2>&1 || true
}
on_exit restore

replica_of() { # replica_of <ip> -> api-N
  local c
  for c in $($COMPOSE ps --format '{{.Name}}' api); do
    [[ "$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$c")" == "$1" ]] && { echo "${c#"${PROJECT}-"}"; return; }
  done
  echo "$1"
}
edge_log() { # edge_log <request-id prefix> -> "status replica" per request
  local line
  $COMPOSE logs --no-log-prefix --since 3m edge 2>/dev/null | grep "\"request_id\":\"$1-" | while IFS= read -r line; do
    printf '%s %s\n' "$(sed -n 's/.*"status":\([0-9]*\).*/\1/p' <<<"$line")" "$(replica_of "$(sed -n 's/.*"upstream":"\([0-9.]*\):.*/\1/p' <<<"$line")")"
  done
}

login_admin
ADMIN="$TOKEN"
login_customer 1
CUSTOMER="$TOKEN"
request GET '/products?per_page=50'
PRODUCT_ID="$(jq -r '[.data[] | select(.stock.status == "in_stock")][0].id // empty' <<<"$BODY")"
[[ -n "$PRODUCT_ID" ]] || PRODUCT_ID="$(json_body '.data[0].id')"
request GET "/products/$PRODUCT_ID"
ORIGINAL_NAME="$(json_body .data.name)"
put_name_back() { request_json PATCH "/products/$PRODUCT_ID" "$(jq -nc --arg n "$ORIGINAL_NAME" '{name: $n}')" -H "Authorization: Bearer $ADMIN" >/dev/null; }
on_exit put_name_back

# --- the checks: each prints one result line and sets RESULT --------------------

check_maintenance() { # P2
  docker exec "${PROJECT}-api-1" php artisan down --retry=60 >/dev/null 2>&1
  local i codes=''
  for i in $(seq 1 9); do
    request GET /categories -H "X-Request-Id: p2-$1-$i" >/dev/null
    codes+="$STATUS "
  done
  docker exec "${PROJECT}-api-1" php artisan up >/dev/null 2>&1
  local down
  down="$(tr ' ' '\n' <<<"$codes" | grep -c '^503$' || true)"
  note "artisan down on api-1 only, then 9 requests: $codes"
  RESULT="${down}/9 answered 503 (maintenance)"
}

check_login_limit() { # P3: the login limit is 5 per minute per e-mail
  local email n first=''
  email="$(customer_email "$((40 + RANDOM % 15))")"
  for n in $(seq 1 18); do
    request_json POST /auth/login "$(jq -nc --arg e "$email" '{email: $e, password: "wrong-password-r5"}')" >/dev/null
    if [[ "$STATUS" == 429 ]]; then first=$n; break; fi
  done
  note "wrong password for $email, until the first 429"
  RESULT="first 429 at attempt ${first:-none of 18}"
}

check_stale_product() { # P4
  local i stale=0 name="R5 check $(date +%s)"
  for i in $(seq 1 9); do request GET "/products/$PRODUCT_ID" -H "X-Request-Id: p4w-$1-$i" >/dev/null; done # every replica caches it
  request_json PATCH "/products/$PRODUCT_ID" "$(jq -nc --arg n "$name" '{name: $n}')" -H "Authorization: Bearer $ADMIN"
  [[ "$STATUS" == 200 ]] || die "PATCH /products failed: HTTP $STATUS"
  for i in $(seq 1 9); do
    request GET "/products/$PRODUCT_ID" -H "X-Request-Id: p4-$1-$i"
    [[ "$(json_body .data.name)" == "$name" ]] || stale=$((stale + 1))
  done
  put_name_back
  note "renamed the product, then read it 9 times"
  RESULT="$((9 - stale))/9 showed the new name"
}

check_live_updates() { # P5: one probe on each Reverb instance, 5 new orders
  local ips ip out n i got=''
  ips="$($COMPOSE ps --format '{{.Name}}' reverb | xargs -I{} docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' {})"
  n=0
  for ip in $ips; do
    n=$((n + 1))
    docker run --rm --network "$NET" -v "$probe_dir:/probe:ro" \
      -e WS_URL="ws://$ip:8080/app/$REVERB_APP_KEY" -e API_URL="http://edge/api/v1" -e ORIGIN="http://localhost" -e TOKEN="$ADMIN" \
      "$NODE_IMAGE" node /probe/ws-count.mjs private-admin.orders order.placed 20000 >"$work/probe-$n.out" 2>&1 &
  done
  sleep 6
  local orders=()
  for i in 1 2 3 4 5; do
    create_order "$CUSTOMER" "$PRODUCT_ID" 1
    [[ "$STATUS" == 201 ]] && orders+=("$ORDER_ID")
    sleep 1
  done
  wait
  for id in "${orders[@]}"; do request_json PATCH "/orders/$id/status" '{"status":"cancelled"}' -H "Authorization: Bearer $ADMIN" >/dev/null; done
  for i in $(seq 1 "$n"); do
    got+="$(sed -n 's/.*"count":\([0-9]*\).*/\1/p' "$work/probe-$i.out")/5 "
  done
  note "an admin dashboard connected to each of the $n WebSocket servers, 5 new orders"
  RESULT="events received per server: ${got% }"
}

check_failover() { # P1, on the edge given as $2 (container name)
  local edge="$2" run="p1-$1-$(date +%s)" out
  $COMPOSE exec -T api php artisan app:perf-tokens 60 | tr -d '\r' >"$work/tokens.json"
  ( sleep 4; docker stop -t 1 "${PROJECT}-api-2" >/dev/null ) &
  out="$(docker run --rm --network "container:$edge" -v "$(cd tests && { pwd -W 2>/dev/null || pwd; }):/work/tests:ro" -v "$(cd "$work" && { pwd -W 2>/dev/null || pwd; }):/work/tokens:ro" \
    -e EDGE_URL=http://localhost -e TOKENS_IN=/work/tokens/tokens.json -e REQUESTS=300 -e RATE=25 -e RUN="$run" \
    "$K6_IMAGE" run --quiet /work/tests/load/scale.js 2>&1 || true)"
  wait
  docker start "${PROJECT}-api-2" >/dev/null
  rm -f "$work/tokens.json"
  local failed slowest
  failed="$(sed -n 's/.*failed (not 200\/304): \([0-9]*\).*/\1/p' <<<"$out")"
  slowest="$(sed -n 's/.*max \([0-9.]*\).*/\1/p' <<<"$out")"
  note "300 requests through $edge, api-2 stopped after 4 s"
  RESULT="${failed:-?} failed of 300, slowest ${slowest:-?} ms"
  # shellcheck disable=SC2086
  $COMPOSE up -d --wait --scale api=3 api >/dev/null 2>&1
  sleep 7
}

# --- BEFORE ------------------------------------------------------------------
section "BEFORE the fixes — the old settings, put back for the test / قبل الإصلاح"
note "api: cache and maintenance flag in local files · reverb: 2 servers without Redis · edge: the requirement-4 configuration"
configure before
check_maintenance before; BEFORE[P2]="$RESULT"; note "→ $RESULT"
check_login_limit before; BEFORE[P3]="$RESULT"; note "→ $RESULT"
check_stale_product before; BEFORE[P4]="$RESULT"; note "→ $RESULT"
check_live_updates before; BEFORE[P5]="$RESULT"; note "→ $RESULT"
git show fdf8d48:infra/nginx/conf.d/edge.conf >"$work/edge-before.conf"
docker rm -f "${PROJECT}-edge-before" >/dev/null 2>&1 || true
docker run -d --name "${PROJECT}-edge-before" --network "$NET" \
  -v "$(cd "$work" && { pwd -W 2>/dev/null || pwd; })/edge-before.conf:/etc/nginx/conf.d/edge.conf:ro" \
  "${PROJECT}-edge" >/dev/null
sleep 3
check_failover before "${PROJECT}-edge-before"; BEFORE[P1]="$RESULT"; note "→ $RESULT"
docker rm -f "${PROJECT}-edge-before" >/dev/null

# --- AFTER -------------------------------------------------------------------
section "AFTER the fixes — the normal configuration / بعد الإصلاح"
configure after
check_maintenance after; AFTER[P2]="$RESULT"; note "→ $RESULT"
check_login_limit after; AFTER[P3]="$RESULT"; note "→ $RESULT"
check_stale_product after; AFTER[P4]="$RESULT"; note "→ $RESULT"
check_live_updates after; AFTER[P5]="$RESULT"; note "→ $RESULT"
check_failover after "${PROJECT}-edge-1"; AFTER[P1]="$RESULT"; note "→ $RESULT"

# --- summary -----------------------------------------------------------------
section "Summary / الخلاصة"
printf '  %-4s %-44s %-34s %s\n' '' 'problem' 'before' 'after'
printf '  %-4s %-44s %-34s %s\n' P1 'a replica stops under load' "${BEFORE[P1]}" "${AFTER[P1]}"
printf '  %-4s %-44s %-34s %s\n' P2 'maintenance mode on one replica' "${BEFORE[P2]}" "${AFTER[P2]}"
printf '  %-4s %-44s %-34s %s\n' P3 'login limit (5 per minute)' "${BEFORE[P3]}" "${AFTER[P3]}"
printf '  %-4s %-44s %-34s %s\n' P4 'product edited, read 9 times' "${BEFORE[P4]}" "${AFTER[P4]}"
printf '  %-4s %-44s %-34s %s\n' P5 'live updates, 2 WebSocket servers' "${BEFORE[P5]}" "${AFTER[P5]}"

expect_match "P1 after: no request failed" '^0 failed' "${AFTER[P1]}"
expect_eq "P2 after: every replica in maintenance" "9/9 answered 503 (maintenance)" "${AFTER[P2]}"
expect_eq "P3 after: limit shared by the replicas" "first 429 at attempt 6" "${AFTER[P3]}"
expect_eq "P4 after: every replica shows the edit" "9/9 showed the new name" "${AFTER[P4]}"
expect_eq "P5 after: every server got every event" "events received per server: 5/5 5/5" "${AFTER[P5]}"
finish
