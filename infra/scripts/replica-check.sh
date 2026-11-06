#!/usr/bin/env bash
# `make replica-check` (requirement 3, R3-4, ADR-0014): proves on the running
# stack that
#   1. db-app-replica is a streaming, read-only copy of db-app,
#   2. a customer who just placed an order sees it on the very next requests
#      (read-your-writes), although reads normally go to the replica,
#   3. how long that order took to become visible ON THE REPLICA (lag).
# Cleans up after itself: the order is cancelled, which releases its stock.
source "$(dirname "${BASH_SOURCE[0]}")/../../tests/security/scripts/lib/common.sh"

require_tools
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD

# sql <service> <query>: one value from psql inside a database container.
sql() {
  compose exec -T "$1" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtq -c "$0"' "$2" | tr -d '\r'
}

section "R3-4 (1) — db-app-replica is a streaming read-only copy / نسخة قراءة متزامنة"
note "على الأصل: pg_stat_replication يجب أن يُظهر النسخة في حالة streaming"
expect_eq "replication connection (application_name|state)" "db-app-replica|streaming" \
  "$(sql db-app "select application_name || '|' || state from pg_stat_replication where application_name = 'db-app-replica'")"
expect_eq "replica is in recovery (hot standby)" t "$(sql db-app-replica 'select pg_is_in_recovery()')"
expect_match "a write on the replica is refused" "read-only transaction" \
  "$(compose exec -T db-app-replica sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtq -c "create table replica_probe(x int)" 2>&1' | tr -d '\r')"
expect_eq "same number of orders on both" "$(sql db-app 'select count(*) from orders')" "$(sql db-app-replica 'select count(*) from orders')"

section "R3-4 (2) — the customer sees the order just created / يرى ما أنشأه للتو"
login_admin
ADMIN="$TOKEN"
login_customer 1
CUSTOMER="$TOKEN"

request GET '/products?per_page=50'
PRODUCT_ID="$(jq -r '[.data[] | select(.stock.status == "in_stock")][0].id // empty' <<<"$BODY")"
if [[ -z "$PRODUCT_ID" ]]; then
  PRODUCT_ID="$(json_body '.data[0].id')"
  adjust_stock "$ADMIN" "$PRODUCT_ID" 1
  note "no product in stock on page 1: added 1 unit to $PRODUCT_ID"
fi

# Watcher on the replica, started BEFORE the write: one plpgsql loop (each
# statement takes a fresh snapshot of what the replica has replayed) waits
# for the new order and reports how long after its created_at it appeared.
# All containers share the Docker VM clock, so the difference is the lag.
lag_file="$(mktemp)"
remove_lag_file() { rm -f "$lag_file"; }
on_exit remove_lag_file
compose exec -T db-app-replica sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -XAtq' >"$lag_file" 2>&1 <<'SQL' &
DO $$
DECLARE
  started timestamptz := clock_timestamp();
  created timestamptz;
BEGIN
  LOOP
    SELECT created_at INTO created FROM orders WHERE created_at > started ORDER BY created_at LIMIT 1;
    EXIT WHEN created IS NOT NULL OR clock_timestamp() > started + interval '15 seconds';
    PERFORM pg_sleep(0.001);
  END LOOP;
  RAISE NOTICE 'lag_ms=%', coalesce(round((extract(epoch FROM clock_timestamp() - created) * 1000)::numeric, 1)::text, 'timeout');
END
$$;
SQL
watcher=$!
sleep 2

create_order "$CUSTOMER" "$PRODUCT_ID" 1
expect_status "POST /orders (written on db-app)" 201
request GET "/orders/$ORDER_ID" -H "Authorization: Bearer $CUSTOMER"
expect_status "GET /orders/{id} right after (next request, same customer)" 200
request GET '/orders?per_page=5' -H "Authorization: Bearer $CUSTOMER"
expect_eq "GET /orders right after: newest order is the new one" "$ORDER_ID" "$(json_body '.data[0].id')"

section "R3-4 (3) — replication lag of that write / زمن وصول الكتابة إلى النسخة"
wait "$watcher" || true
lag="$(sed -n 's/.*lag_ms=\([0-9.]*\|timeout\).*/\1/p' "$lag_file" | head -n1)"
expect_match "the order became visible on the replica" '^[0-9.]+$' "$lag"
note "lag measured on the replica: ${lag} ms after the order's created_at (includes the api's own commit)."
note "the read-your-writes window (DB_READ_YOUR_WRITES_SECONDS=5 s) is far above this lag."
note "replay_lag reported by db-app now: $(sql db-app "select coalesce(replay_lag::text, 'caught up (idle)') from pg_stat_replication where application_name = 'db-app-replica'")"

request_json PATCH "/orders/$ORDER_ID/status" '{"status":"cancelled","note":"replica-check clean-up"}' -H "Authorization: Bearer $ADMIN"
expect_status "clean-up: the admin cancels the order (stock released)" 200

finish
