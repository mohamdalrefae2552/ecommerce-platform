#!/usr/bin/env bash
# `make demo-r5` — interview demo of requirement 5 (scalability and load
# distribution) on the running stack:
#   R5-1  3 api replicas behind edge (nginx), least_conn, a 4th joins live;
#   R5-2  3 mail workers share one queue, each e-mail sent once;
#   R5-3  the system keeps working while a replica is stopped;
#   R5-4  state that must be shared really is (tokens, idempotency, limits).
# The 500-request proof is `make scale-load` and the before/after of every
# hidden problem is `make scale-problems` (docs/11-scaling.md).
# Leaves the stack as it found it (3 api replicas, 3 mail workers).
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
export MSYS_NO_PATHCONV=1

require_tools docker
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD
: "${COMPOSE:?COMPOSE is not set (run through make demo-r5)}"
PACE=0.3 # the general limit is 120 requests per minute per user or IP

scale() { # scale <service=n...>
  local args=() pair
  for pair in "$@"; do args+=(--scale "$pair"); done
  # shellcheck disable=SC2086 # COMPOSE is a command line with options.
  $COMPOSE up -d --no-recreate --wait "${args[@]}" api worker-mail >/dev/null 2>&1
}
mail_to_mailpit() { # the 3 workers send to the local test inbox for this demo
  export MAIL_HOST=mailpit MAIL_PORT=1025 MAIL_USERNAME= MAIL_PASSWORD= MAIL_FROM_ADDRESS=no-reply@ecommerce.local
  # shellcheck disable=SC2086
  $COMPOSE up -d --no-deps --force-recreate --wait --scale worker-mail=3 worker-mail >/dev/null 2>&1
}
restore() {
  local recreate=''
  [[ -n "${MAIL_HOST:-}" ]] && recreate=1
  unset MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS
  docker start "${PROJECT}-api-2" >/dev/null 2>&1 || true
  # shellcheck disable=SC2086
  [[ -n "$recreate" ]] && $COMPOSE up -d --no-deps --force-recreate --wait --scale worker-mail=3 worker-mail >/dev/null 2>&1
  scale api=3 worker-mail=3
}
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
running() { $COMPOSE ps --status running --format '{{.Name}}' "$1" | grep -c . || true; }
replica_of() {
  local c
  for c in $($COMPOSE ps --format '{{.Name}}' api); do
    [[ "$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$c")" == "$1" ]] && { echo "${c#"${PROJECT}-"}"; return; }
  done
  echo "?"
}
served_by() { # served_by <request-id prefix>: "api-N count" lines
  $COMPOSE logs --no-log-prefix --since 3m edge 2>/dev/null | grep "\"request_id\":\"$1-" |
    sed -n 's/.*"upstream":"\([0-9.]*\):9000".*/\1/p' | sort | uniq -c | while read -r n ip; do echo "$(replica_of "$ip") $n"; done | sort
}
fire() { # fire <prefix> <count> [curl args]: paced GETs of the catalogue
  local prefix="$1" count="$2" i
  shift 2
  for i in $(seq 1 "$count"); do
    request GET "/products?page=$((i % 9 + 1))" -H "X-Request-Id: $prefix-$i" "$@" >/dev/null
    printf '%s\n' "$STATUS"
    sleep "$PACE"
  done
}

run="r5d-$(date +%s)"
scale api=3 worker-mail=3

# ---------------------------------------------------------------------------
section "R5-1 — 3 api replicas behind edge, least_conn / النسخ وموزّع الحمل"
expect_eq "api replicas running" 3 "$(running api)"
note "edge (nginx) — the api pool as configured:"
compose exec -T edge nginx -T 2>/dev/null | sed -n '/^upstream api_pool/,/^}/p' | sed 's/^/        /'
login_customer 1
CUSTOMER="$TOKEN"
fire "$run-spread" 30 -H "Authorization: Bearer $CUSTOMER" >"$_tmp/spread"
expect_eq "30 requests answered 200 (same JWT, any replica)" 30 "$(grep -c '^200$' "$_tmp/spread")"
sleep 1
served_by "$run-spread" >"$_tmp/served"
sed 's/^/        /' "$_tmp/served"
expect_eq "replicas that answered" 3 "$(grep -c . "$_tmp/served")"

note "a 4th replica joins without restarting edge:"
scale api=4
sleep 7 # edge asks Docker's DNS again every 5 s
fire "$run-grow" 24 >/dev/null
sleep 1
expect_match "api-4 receives requests" '^api-4 [0-9]+' "$(served_by "$run-grow" | grep '^api-4' || true)"
scale api=3

# ---------------------------------------------------------------------------
section "R5-3 — a replica stops, the system keeps working / الاستمرارية"
fire "$run-stop" 60 >"$_tmp/stop" &
load=$!
sleep 5
docker stop -t 1 "${PROJECT}-api-2" >/dev/null
note "${PROJECT}-api-2 stopped while the requests were running"
wait "$load"
expect_eq "60 requests answered 200 while a replica went away" 60 "$(grep -c '^200$' "$_tmp/stop")"
expect_eq "replicas still running" 2 "$(running api)"
docker start "${PROJECT}-api-2" >/dev/null
note "api-2 started again; edge sends it requests within 10 s (fail_timeout)."

# ---------------------------------------------------------------------------
section "R5-2 — 3 mail workers share one queue / ثلاثة عمّال على طابور واحد"
note "for this check the workers send to the local test inbox (Mailpit), so the seeded test addresses are accepted"
mail_to_mailpit
expect_eq "worker-mail containers running" 3 "$(running worker-mail)"
consumers() { compose exec -T rabbitmq rabbitmqctl -q list_queues name consumers | tr -d '\r' | awk '$1 == "notifications.mail" { print $2 }'; }
three_consumers() { [[ "$(consumers)" == 3 ]]; }
wait_until 30 three_consumers || true
expect_eq "consumers on notifications.mail" 3 "$(consumers)"

login_admin
ADMIN="$TOKEN"
request GET '/products?per_page=50'
PRODUCT_ID="$(jq -r '[.data[] | select(.stock.status == "in_stock")][0].id // empty' <<<"$BODY")"
adjust_stock "$ADMIN" "$PRODUCT_ID" 12 >/dev/null
since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
orders=()
cancel_orders() {
  local id
  for id in "${orders[@]+"${orders[@]}"}"; do
    request_json PATCH "/orders/$id/status" '{"status":"cancelled"}' -H "Authorization: Bearer $ADMIN" >/dev/null
  done
}
on_exit cancel_orders
for _ in $(seq 1 9); do
  create_order "$CUSTOMER" "$PRODUCT_ID" 1
  orders+=("$ORDER_ID")
done
ids="$(printf "'%s'," "${orders[@]}")"
ids="${ids%,}"
nine_mailed() { [[ "$(sql "select count(*) from notifications where order_id in ($ids) and mailed_at is not null")" == 9 ]]; }
wait_until 40 nine_mailed || true
expect_eq "9 orders -> 9 e-mails sent" 9 "$(sql "select count(*) from notifications where order_id in ($ids) and mailed_at is not null")"
expect_eq "no e-mail sent twice (one notification per order)" 9 "$(sql "select count(*) from notifications where order_id in ($ids)")"
note "who handled them (worker logs since $since):"
handled=0
for c in $($COMPOSE ps --format '{{.Name}}' worker-mail | sort); do
  n="$(docker logs --since "$since" "$c" 2>&1 | grep -c 'message handled' || true)"
  handled=$((handled + n))
  printf '        %-14s %s message(s)\n' "${c#"${PROJECT}-"}" "$n"
done
expect_eq "messages handled in total" 9 "$handled"

# ---------------------------------------------------------------------------
section "R5-4 — state shared by every replica / الحالة المشتركة"
key="$(uuid)"
body="$(jq -nc --arg p "$PRODUCT_ID" '{items: [{product_id: $p, quantity: 1}]}')"
for n in 1 2 3; do
  curl -sS -o "$_tmp/idem-$n" --max-time "$CURL_MAX_TIME" -X POST "$API/orders" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' -H "Authorization: Bearer $CUSTOMER" \
    -H "Idempotency-Key: $key" -H "X-Request-Id: $run-idem-$n" --data-binary "$body" &
done
wait
same="$(cat "$_tmp"/idem-* | jq -r '.data.id // empty' | sort -u)"
orders+=("$same")
expect_eq "same Idempotency-Key sent 3 times at once -> one order" 1 "$(grep -c . <<<"$same")"
note "the lock and the limiter counters live in Redis, the JWT keys and images on shared volumes;"
note "make scale-problems shows what happens when they do not."

finish
