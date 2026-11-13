#!/usr/bin/env bash
# `make demo-r4` — interview demo of requirement 4 (asynchronous processing
# and real-time updates) on the running stack. Proves, in this order:
#   1. creating an order publishes an event and answers without waiting;
#   2. PROOF-R4-1: stopping the mail worker and starting it again loses no
#      e-mail (and sends none twice);
#   3. a failed e-mail is retried, and a message that can never be handled is
#      parked in the dead-letter queue;
#   4. PROOF-R4-2: a new order appears at once on the admin dashboard channel;
#   5. PROOF-R4-3: the admin's approval appears at once on the customer's
#      order channel;
#   6. nobody subscribes to a channel without the identity and the right.
# Cleans up after itself: its orders are cancelled (stock released).
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
export MSYS_NO_PATHCONV=1

require_tools docker
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD RABBITMQ_USER RABBITMQ_PASSWORD

REVERB_APP_KEY="${REVERB_APP_KEY:-ecommerce-web}"
RABBITMQ_UI="http://127.0.0.1:${RABBITMQ_UI_PORT:-15672}"
NODE_IMAGE="node:24.22.2-alpine3.24"
MAIL_QUEUE=notifications.mail
LIVE_QUEUE=realtime.orders
probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/lib" && { pwd -W 2>/dev/null || pwd; })"

# sql <query>: one value from the application database.
sql() {
  compose exec -T db-app sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtq -c "$0"' "$1" | tr -d '\r'
}

# queue_depth <queue>: messages currently in a RabbitMQ queue.
queue_depth() {
  compose exec -T rabbitmq rabbitmqctl -q list_queues name messages | tr -d '\r' | awk -v q="$1" '$1 == q { print $2 }'
}

# wait_until <seconds> <command...>: poll until the command succeeds.
wait_until() {
  local deadline=$((SECONDS + $1))
  shift
  until "$@"; do
    ((SECONDS < deadline)) || return 1
    sleep 0.5
  done
}

mailed_count() { sql "select count(*) from notifications where order_id in ($1) and mailed_at is not null"; }
mailed_is() { [[ "$(mailed_count "$1")" == "$2" ]]; }
depth_is() { [[ "$(queue_depth "$1")" == "$2" ]]; }
depth_at_least() { (("$(queue_depth "$1")" >= $2)); }

# listen <output file> <token> <channel> <event|-> <timeout ms> [forged auth]:
# starts the WebSocket probe in the background (inside edge's network, like a
# browser on http://localhost). Sets PROBE_PID.
listen() {
  docker run --rm --network "container:${PROJECT}-edge-1" -v "$probe_dir:/probe:ro" \
    -e WS_URL="ws://localhost/app/$REVERB_APP_KEY" -e API_URL="http://localhost/api/v1" \
    -e ORIGIN="http://localhost" -e TOKEN="$2" -e FORGED_AUTH="${6:-}" \
    "$NODE_IMAGE" node /probe/ws-listen.mjs "$3" "$4" "$5" >"$1" 2>&1 &
  PROBE_PID=$!
}
probe_said() { grep -q "\"step\":\"$2\"" "$1" 2>/dev/null; }
probe_settled() { probe_said "$1" ready || probe_said "$1" refused || probe_said "$1" timeout; }
probe_field() { grep "\"step\":\"$2\"" "$1" 2>/dev/null | head -n1 | jq -r "$3 // empty"; }

created_orders=()
cancel_orders() {
  local id
  for id in "${created_orders[@]+"${created_orders[@]}"}"; do
    request_json PATCH "/orders/$id/status" '{"status":"cancelled"}' -H "Authorization: Bearer $ADMIN" >/dev/null
  done
}
# start <service...>: start stopped containers and wait until they are
# healthy. `start`, not `up`: the containers keep the configuration `make up`
# gave them (published ports included).
is_healthy() { [[ "$(docker inspect -f '{{.State.Health.Status}}' "${PROJECT}-$1-1" 2>/dev/null)" == healthy ]]; }
start() {
  local service
  compose start "$@" >/dev/null 2>&1
  for service in "$@"; do
    wait_until 90 is_healthy "$service" || die "$service did not become healthy"
  done
}
workers_up() { start worker-mail worker-realtime mailpit; }

login_admin
ADMIN="$TOKEN"
login_customer 1
CUSTOMER="$TOKEN"
login_customer 2
OTHER="$TOKEN"
on_exit cancel_orders
on_exit workers_up

request GET '/products?per_page=50'
PRODUCT_ID="$(jq -r '[.data[] | select(.stock.status == "in_stock")][0].id // empty' <<<"$BODY")"
[[ -n "$PRODUCT_ID" ]] || { PRODUCT_ID="$(json_body '.data[0].id')"; adjust_stock "$ADMIN" "$PRODUCT_ID" 50; }
adjust_stock "$ADMIN" "$PRODUCT_ID" 20 >/dev/null

mail_host="$(compose exec -T worker-mail printenv MAIL_HOST | tr -d '\r')"
dlq_before="$(queue_depth "$MAIL_QUEUE.dlq")"

# ---------------------------------------------------------------------------
section "R4 (1) — the event is published, the answer does not wait / النشر دون انتظار"
note "الطلب يُحفظ، والحدث يُكتب في outbox بنفس المعاملة ثم يُنشر إلى RabbitMQ؛ البريد يُرسله العامل لاحقاً."
create_order "$CUSTOMER" "$PRODUCT_ID" 1
expect_status "POST /orders" 201
created_orders+=("$ORDER_ID")
expect_lt "the answer came back quickly (ms)" 1500 "$TIME_MS"
expect_eq "outbox row of this order is published" t \
  "$(sql "select published_at is not null from outbox_messages where payload->>'order_id' = '$ORDER_ID' and type = 'order.placed'")"
if wait_until 20 mailed_is "'$ORDER_ID'" 1; then
  expect_eq "the mail worker handled it shortly after" 1 "$(mailed_count "'$ORDER_ID'")"
else
  expect_eq "the mail worker handled it shortly after" 1 "$(mailed_count "'$ORDER_ID'")"
fi

# ---------------------------------------------------------------------------
section "PROOF-R4-1 — stop the worker, start it again: no e-mail is lost / إيقاف العامل ثم تشغيله"
compose stop worker-mail >/dev/null 2>&1
note "worker-mail متوقف الآن. ننشئ 3 طلبات."
batch=()
for _ in 1 2 3; do
  create_order "$CUSTOMER" "$PRODUCT_ID" 1
  expect_status "POST /orders while the worker is stopped" 201
  batch+=("$ORDER_ID")
  created_orders+=("$ORDER_ID")
done
ids="'${batch[0]}','${batch[1]}','${batch[2]}'"
wait_until 10 depth_is "$MAIL_QUEUE" 3 || true
expect_eq "messages waiting in $MAIL_QUEUE" 3 "$(queue_depth "$MAIL_QUEUE")"
expect_eq "e-mails sent for these orders while the worker is down" 0 "$(mailed_count "$ids")"

note "نشغّل worker-mail من جديد."
start worker-mail
wait_until 40 mailed_is "$ids" 3 || true
expect_eq "e-mails sent after the worker came back" 3 "$(mailed_count "$ids")"
expect_eq "exactly one notification per order (none twice)" 3 "$(sql "select count(*) from notifications where order_id in ($ids)")"
wait_until 10 depth_is "$MAIL_QUEUE" 0 || true
expect_eq "$MAIL_QUEUE is empty again" 0 "$(queue_depth "$MAIL_QUEUE")"
if [[ "$mail_host" == mailpit ]]; then
  in_mailpit="$(compose exec -T mailpit wget -qO- "http://127.0.0.1:8025/api/v1/search?query=${batch[2]}" 2>/dev/null | jq -r '.messages_count // empty')"
  expect_eq "the e-mail of the last order is in Mailpit" 1 "${in_mailpit:-0}"
else
  note "البريد يُرسل عبر $mail_host (خدمة خارجية): تحقق من صندوق بريد الزبون."
fi

# ---------------------------------------------------------------------------
section "R4 (3) — retries and the dead-letter queue / إعادة المحاولة و DLQ"
if [[ "$mail_host" == mailpit ]]; then
  compose stop mailpit >/dev/null 2>&1
  note "خادم البريد متوقف: الإرسال سيفشل ويُعاد لاحقاً."
  create_order "$CUSTOMER" "$PRODUCT_ID" 1
  expect_status "POST /orders while the mail server is down" 201
  created_orders+=("$ORDER_ID")
  retry_order="$ORDER_ID"
  wait_until 30 depth_at_least "$MAIL_QUEUE.retry" 1 || true
  expect_gt "failed message waits in $MAIL_QUEUE.retry" 0 "$(queue_depth "$MAIL_QUEUE.retry")"
  expect_eq "the in-app notification exists, the e-mail is still owed" "1|0" \
    "$(sql "select count(*) || '|' || count(mailed_at) from notifications where order_id = '$retry_order'")"
  start mailpit
  note "خادم البريد عاد: المحاولة التالية تنجح."
  wait_until 60 mailed_is "'$retry_order'" 1 || true
  expect_eq "the e-mail was sent on a later attempt" 1 "$(mailed_count "'$retry_order'")"
  expect_match "the worker logged the retry" "will be retried" \
    "$(compose logs --since 3m worker-mail 2>&1 | grep -m1 'will be retried' || true)"
else
  skip "retry after a mail failure" "needs the local Mailpit to simulate a mail outage; MAIL_HOST=$mail_host"
fi

note "رسالة تالفة (ليست JSON) تُنشر مباشرة إلى RabbitMQ: لا فائدة من إعادتها."
poison="$(jq -nc --arg id "poison-$(uuid)" '{properties: {message_id: $id, type: "order.placed", delivery_mode: 2}, routing_key: "order.placed", payload: "this is not json", payload_encoding: "string"}')"
routed="$(curl -sS --max-time "$CURL_MAX_TIME" -u "$RABBITMQ_USER:$RABBITMQ_PASSWORD" -H 'Content-Type: application/json' \
  -d "$poison" "$RABBITMQ_UI/api/exchanges/%2F/orders.events/publish" | jq -r '.routed // empty')"
expect_eq "RabbitMQ routed the poison message to the queues" true "$routed"
wait_until 20 depth_is "$MAIL_QUEUE.dlq" "$((dlq_before + 1))" || true
expect_eq "it is parked in $MAIL_QUEUE.dlq (not retried, not lost)" "$((dlq_before + 1))" "$(queue_depth "$MAIL_QUEUE.dlq")"
expect_eq "the worker is still running" running "$(compose ps --format '{{.State}}' worker-mail | tr -d '\r')"
if [[ "$dlq_before" == 0 ]]; then
  compose exec -T rabbitmq rabbitmqctl -q purge_queue "$MAIL_QUEUE.dlq" >/dev/null 2>&1 || true
  compose exec -T rabbitmq rabbitmqctl -q purge_queue "$LIVE_QUEUE.dlq" >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------------------
section "PROOF-R4-2 — a new order appears at once on the admin dashboard / لوحة المسؤول"
admin_out="$_tmp/admin.out"
listen "$admin_out" "$ADMIN" private-admin.orders order.placed 30000
wait_until 30 probe_settled "$admin_out" || true
expect_eq "admin subscribed to private-admin.orders" yes "$(probe_said "$admin_out" ready && echo yes || echo no)"
create_order "$CUSTOMER" "$PRODUCT_ID" 1
expect_status "POST /orders (customer)" 201
created_orders+=("$ORDER_ID")
live_order="$ORDER_ID"
wait "$PROBE_PID" 2>/dev/null || true
expect_eq "the dashboard received order.placed for this order" "$live_order" "$(probe_field "$admin_out" event .data.id)"

# ---------------------------------------------------------------------------
section "PROOF-R4-3 — the admin's approval appears at once on the customer's page / صفحة الزبون"
customer_out="$_tmp/customer.out"
listen "$customer_out" "$CUSTOMER" "private-orders.$live_order" order.status_changed 30000
wait_until 30 probe_settled "$customer_out" || true
expect_eq "the owner subscribed to private-orders.<id>" yes "$(probe_said "$customer_out" ready && echo yes || echo no)"
request_json PATCH "/orders/$live_order/status" '{"status":"confirmed"}' -H "Authorization: Bearer $ADMIN"
expect_status "PATCH /orders/{id}/status confirmed (admin)" 200
wait "$PROBE_PID" 2>/dev/null || true
expect_eq "the customer received order.status_changed (from|to)" "pending|confirmed" \
  "$(probe_field "$customer_out" event '.data.from + "|" + .data.to')"

# ---------------------------------------------------------------------------
section "R4 (6) — identity and permission before any subscription / الصلاحيات"
refused() { # refused <description> <expected API status or -> <token> <channel> [forged auth]
  local out="$_tmp/refused.out"
  listen "$out" "$3" "$4" - 15000 "${5:-}"
  wait "$PROBE_PID" 2>/dev/null || true
  [[ "$2" == - ]] || expect_eq "$1: POST /broadcasting/auth" "$2" "$(probe_field "$out" auth .status)"
  expect_eq "$1: subscription" refused "$(probe_said "$out" ready && echo SUBSCRIBED || echo refused)"
}
refused "another customer on this order's channel" 403 "$OTHER" "private-orders.$live_order"
refused "a customer on the admin dashboard channel" 403 "$CUSTOMER" private-admin.orders
refused "an admin on a customer's order channel" 403 "$ADMIN" "private-orders.$live_order"
refused "no token" 401 "" private-admin.orders
refused "a forged signature sent straight to the WebSocket server" - "" private-admin.orders "$REVERB_APP_KEY:$(printf '%064d' 0)"

finish
