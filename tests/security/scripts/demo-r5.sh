#!/usr/bin/env bash
# `make demo-r5` — scaling groundwork (requirement 5, PROVISIONAL: written
# before the requirement sheet was handed out; see docs/11-scaling-prep.md).
# Proves on the running stack that
#   1. edge spreads requests over several api replicas;
#   2. stopping one replica under load fails no request;
#   3. two mail workers share one queue and each e-mail is sent once.
# Leaves the stack as it found it: one api, one mail worker.
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
export MSYS_NO_PATHCONV=1

require_tools docker
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD
: "${COMPOSE:?COMPOSE is not set (run through make demo-r5)}"

REPLICAS=3
# The general limit is 120 requests a minute per IP: every loop is paced.
PACE=0.3

# scale <service=n...>: same compose files as `make up`, so nothing else changes.
scale() {
  local args=() pair
  for pair in "$@"; do args+=(--scale "$pair"); done
  # shellcheck disable=SC2086 # COMPOSE is a command line with options.
  $COMPOSE up -d --no-recreate --wait "${args[@]}" >/dev/null 2>&1
}
restore() { scale api=1 worker-mail=1; }
on_exit restore

sql() {
  compose exec -T db-app sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtq -c "$0"' "$1" | tr -d '\r'
}
wait_until() {
  local deadline=$((SECONDS + $1))
  shift
  until "$@"; do
    ((SECONDS < deadline)) || return 1
    sleep 0.5
  done
}
# upstreams <request-id prefix>: one line per request with the replica(s) edge used.
upstreams() {
  compose logs --since 5m edge 2>/dev/null | grep "\"request_id\":\"$1-" | grep -o '"upstream":"[^"]*"' | cut -d'"' -f4
}
# fire <prefix> <count>: paced GETs; prints one status code per line.
fire() {
  local i
  for i in $(seq 1 "$2"); do
    curl -s -o /dev/null --max-time "$CURL_MAX_TIME" -w '%{http_code}\n' \
      -H "X-Request-Id: $1-$i" "$API/products?page=$((i % 9 + 1))" || echo 000
    sleep "$PACE"
  done
}

run="r5-$(date +%s)"

# ---------------------------------------------------------------------------
section "R5 (1) — edge spreads requests over $REPLICAS api replicas / توزيع الحمل"
scale "api=$REPLICAS"
expect_eq "api replicas running" "$REPLICAS" "$(compose ps --status running --format '{{.Name}}' api | grep -c .)"
note "ننتظر 12 ثانية حتى يرى nginx النسخ الجديدة (DNS valid=10s)."
sleep 12
fire "$run-spread" 45 >"$_tmp/spread.codes"
expect_eq "requests answered 200" 45 "$(grep -c '^200$' "$_tmp/spread.codes")"
used="$(upstreams "$run-spread" | sort -u | grep -c .)"
expect_eq "different replicas that served them" "$REPLICAS" "$used"
upstreams "$run-spread" | sort | uniq -c | sed 's/^/        /'

# ---------------------------------------------------------------------------
section "R5 (2) — stopping one replica under load fails no request / إيقاف نسخة"
victim="${PROJECT}-api-2"
fire "$run-failover" 60 >"$_tmp/failover.codes" &
load=$!
sleep 5
docker stop -t 1 "$victim" >/dev/null
note "$victim أُوقف أثناء إرسال الطلبات."
wait "$load"
expect_eq "requests answered 200 while a replica went away" 60 "$(grep -c '^200$' "$_tmp/failover.codes")"
retried="$(upstreams "$run-failover" | grep -c ', ' || true)"
note "طلبات حوّلها edge تلقائياً إلى نسخة أخرى: ${retried}"
expect_eq "replicas still running" "$((REPLICAS - 1))" "$(compose ps --status running --format '{{.Name}}' api | grep -c .)"

# ---------------------------------------------------------------------------
section "R5 (3) — two mail workers, one queue, each e-mail once / عاملان على طابور واحد"
scale "api=$REPLICAS" worker-mail=2
consumers() { compose exec -T rabbitmq rabbitmqctl -q list_queues name consumers | tr -d '\r' | awk '$1 == "notifications.mail" { print $2 }'; }
two_consumers() { [[ "$(consumers)" == 2 ]]; }
wait_until 30 two_consumers || true
expect_eq "consumers on notifications.mail" 2 "$(consumers)"

login_admin
ADMIN="$TOKEN"
login_customer 1
CUSTOMER="$TOKEN"
request GET '/products?per_page=50'
PRODUCT_ID="$(jq -r '[.data[] | select(.stock.status == "in_stock")][0].id // empty' <<<"$BODY")"
[[ -n "$PRODUCT_ID" ]] || PRODUCT_ID="$(json_body '.data[0].id')"
adjust_stock "$ADMIN" "$PRODUCT_ID" 10 >/dev/null

orders=()
cancel_orders() {
  local id
  for id in "${orders[@]+"${orders[@]}"}"; do
    request_json PATCH "/orders/$id/status" '{"status":"cancelled"}' -H "Authorization: Bearer $ADMIN" >/dev/null
  done
}
on_exit cancel_orders
for _ in 1 2 3 4; do
  create_order "$CUSTOMER" "$PRODUCT_ID" 1
  expect_status "POST /orders (through any replica)" 201
  orders+=("$ORDER_ID")
done
ids="$(printf "'%s'," "${orders[@]}")"
ids="${ids%,}"
all_mailed() { [[ "$(sql "select count(*) from notifications where order_id in ($ids) and type = 'order.placed' and mailed_at is not null")" == 4 ]]; }
wait_until 40 all_mailed || true
expect_eq "e-mails sent (one per order)" 4 "$(sql "select count(*) from notifications where order_id in ($ids) and type = 'order.placed' and mailed_at is not null")"
expect_eq "notifications created (none twice)" 4 "$(sql "select count(*) from notifications where order_id in ($ids) and type = 'order.placed'")"

finish
