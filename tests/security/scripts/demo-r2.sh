#!/usr/bin/env bash
# Interview demo for requirement 2 (`make demo-r2`): docs/05 section 4.
#   1. PROOF-R2-1: concurrent orders on the last unit → exactly one 201
#      (runs the same flow as `make race-test`; N customers, default 20,
#      `N=50 make demo-r2` for the full race)
#   2. PROOF-R2-2 / R2-E2: inventory link cut   → 503 INVENTORY_UNAVAILABLE at once
#   3. R2-E2: inventory 3000 ms slow            → 503 INVENTORY_TIMEOUT after ~3 s, not forever
#   4. recovery: chaos reset                    → orders work again, nothing leaked
# The inventory link is ALWAYS restored when the script ends (trap).
# Exit code: 0 only when every check passed.
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

require_tools
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD PROJECT

CHAOS="$(dirname "${BASH_SOURCE[0]}")/../../../infra/scripts/chaos.sh"
chaos() { PROJECT="$PROJECT" bash "$CHAOS" "$@" 2>&1 | grep -v ' Container ' | sed 's/^/  /'; }
restore_link() { PROJECT="$PROJECT" bash "$CHAOS" reset >/dev/null 2>&1 || true; }
on_exit restore_link

printf '%sRequirement 2 — inter-service demo against %s%s\n' "$_bold" "$EDGE_URL" "$_reset"

# ------------------------------------------------------------------ race --
section "1. PROOF-R2-1 — the race / سباق على قطعة واحدة"
note "عدة زبائن يطلبون آخر قطعة في اللحظة نفسها (k6 بحاجز بدء مشترك)؛ ينجح طلب واحد فقط والباقي 409"
race_log="$_tmp/race.log"
# EDGE_URL is unset: inside the k6 container the edge listens on port 80, not on the host port.
if env -u EDGE_URL N="${N:-20}" make --no-print-directory race-test >"$race_log" 2>&1; then race_rc=0; else race_rc=$?; fi
# keep the proof lines, drop the k6 progress bars and docker noise
grep -vE '^( Container |running \(|race +\[|Run +\[|docker compose|\s*$)|execution:|script:|output:|scenarios:|\* race:|/\|\/|\| \||___|^ /|^    /|^   /' "$race_log" \
  | sed -n '/=== PROOF-R2-1/,$p' | grep -vE '^running|^race ✓' || true
expect_eq "make race-test (k6 thresholds + final-state check)" 0 "$race_rc"
if ((race_rc != 0)); then tail -n 25 "$race_log"; fi

# ----------------------------------------------------------- set-up chaos --
section "2. Set-up: a product with stock 5 and a customer"
login_admin
ADMIN="$TOKEN"
login_customer 1
CUSTOMER="$TOKEN"
create_product "$ADMIN" "DEMO R2 $(date +%H:%M:%S)" 5
P="$PRODUCT_ID"
note "product $P"
expect_eq "inventory answers: available" 5 "$(product_available "$P")"
request GET /orders -H "Authorization: Bearer $CUSTOMER"
orders_before="$(json_body .meta.total)"

cleanup() {
  [[ -n "${P:-}" && -n "${ADMIN:-}" ]] || return 0
  printf '\n%sCleaning up demo data%s\n' "$_dim" "$_reset"
  request GET /orders -H "Authorization: Bearer $CUSTOMER" >/dev/null
  local id left
  for id in $(jq -r --arg p "$P" '.data[]? | select(.status == "pending" or .status == "confirmed") | .id' <<<"$BODY"); do
    request GET "/orders/$id" -H "Authorization: Bearer $ADMIN" >/dev/null
    if jq -e --arg p "$P" 'any(.data.items[]; .product_id == $p)' <<<"$BODY" >/dev/null; then
      request_json PATCH "/orders/$id/status" '{"status":"cancelled","note":"demo clean-up"}' -H "Authorization: Bearer $ADMIN" >/dev/null
    fi
  done
  left="$(product_available "$P")"
  if [[ "$left" =~ ^[1-9][0-9]*$ ]]; then adjust_stock "$ADMIN" "$P" "-$left" >/dev/null; fi
}
on_exit cleanup

# ------------------------------------------------------------------ down --
section "3. PROOF-R2-2 — inventory service stopped / إيقاف خدمة المستودع"
note "نقطع الرابط بين التطبيق والمستودع؛ المستخدم يجب أن يحصل فوراً على رد واضح لا انتظار بلا نهاية"
chaos down
create_order "$CUSTOMER" "$P" 1
expect_problem "order while the inventory is down → 503" 503 INVENTORY_UNAVAILABLE
expect_lt "…answered at once (the gRPC call fails fast), ms" 2000 "$TIME_MS"
expect_match "Retry-After tells the client when to try again" '^[0-9]+$' "$(header retry-after)"
note "the Arabic message the user sees: $(json_body .detail)"

request GET "/products/$P"
expect_eq "product page still works: 200, stock unknown" "200 unknown" "$STATUS $(json_body .data.stock.status)"
request GET '/products?per_page=5'
expect_eq "product list still works: 200, meta.degraded = [inventory]" '200 ["inventory"]' "$STATUS $(jq -c '.meta.degraded' <<<"$BODY")"

# --------------------------------------------------------------- latency --
section "4. R2-E2 — inventory answers 3000 ms late / مستودع بطيء: مهلة محددة"
note "نؤخر ردود المستودع 3 ثوانٍ؛ المهلة (Deadline) تقطع الانتظار ونرد 503 بدل التعليق"
chaos reset
chaos latency 3000
create_order "$CUSTOMER" "$P" 1
expect_problem "order while the inventory is slow → 503" 503 INVENTORY_TIMEOUT
expect_gt "…waited for the deadline, not less (ms)" 2000 "$TIME_MS"
expect_lt "…and not much more than the 3 s budget (ms)" 4500 "$TIME_MS"
request GET "/products/$P"
expect_eq "product page: 200 with stock unknown (500 ms stock deadline)" "200 unknown" "$STATUS $(json_body .data.stock.status)"
expect_lt "…and fast (ms)" 1500 "$TIME_MS"

# -------------------------------------------------------------- recovery --
section "5. Recovery / عودة الخدمة: لا طلب حُفظ ولا كمية ضاعت"
chaos reset
expect_eq "available is back to 5: no reservation leaked (R2-E1)" 5 "$(product_available "$P")"
request GET /orders -H "Authorization: Bearer $CUSTOMER"
expect_eq "no order was saved during the outage (R2-F1)" "$orders_before" "$(json_body .meta.total)"
create_order "$CUSTOMER" "$P" 1
expect_status "order after recovery → 201" 201
expect_eq "available is now 4" 4 "$(product_available "$P")"

finish
