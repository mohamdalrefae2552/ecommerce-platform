#!/usr/bin/env bash
# Final-state check after tests/load/race.js (`make race-test`, PROOF-R2-1).
# Black box, through the API as the admin sees it:
#   1. the last unit is gone: stock.available == 0 (inventory source of truth)
#   2. exactly ONE order contains that product (no oversell, no lost update)
# Finds the newest product named "DEMO race last unit …".
# Exit code: 0 only when every check passed.
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

require_tools
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD

section "PROOF-R2-1 — final state after the race / الحالة النهائية بعد السباق"
note "بعد أن تسابق الجميع على قطعة واحدة: يجب أن يبقى صفر في المخزون وطلب واحد فقط يحوي المنتج"
login_admin
ADMIN="$TOKEN"
find_race_product "$ADMIN"
note "product $PRODUCT_ID (created $PRODUCT_CREATED_AT)"

request GET "/products/$PRODUCT_ID"
expect_eq "stock.available after the race" 0 "$(json_body .data.stock.available)"
expect_eq "stock.status after the race" out_of_stock "$(json_body .data.stock.status)"

count_orders_for_product "$ADMIN" "$PRODUCT_ID" "$PRODUCT_CREATED_AT"
note "$ORDERS_SCANNED orders created since the product existed were inspected"
expect_eq "orders containing the product (as the admin sees them)" 1 "$ORDERS_ON_PRODUCT"

finish
