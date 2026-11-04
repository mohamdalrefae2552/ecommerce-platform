#!/usr/bin/env bash
# Interview demo for requirement 1 (`make demo-r1`): docs/04 section 8.
#   1. Security headers on API and SPA responses                    (R1-SEC3)
#   2. JWT: none / garbage / tampered role / alg:none / expired → 401 (R1-SEC1)
#   3. BOLA/IDOR: customer B opens customer A's order → 404         (PROOF-R1-1, D-02)
#   4. Customers cannot change an order's status → 403, admin → 200 (PROOF-R1-2)
#   5. Validation → 422 and the one problem+json shape              (R1-A2, R1-A3)
#   6. Refresh-token rotation: re-using an old refresh token → 401
#   7. Login rate limit: 6th attempt → 429 + Retry-After            (R1-SEC2)
# The rate-limit step runs LAST: it burns the per-IP login budget (20/min) and
# uses a throw-away email so no seeded customer gets locked out.
# Demo data: one product ("DEMO R1 …") and one order, created here; at the end
# the admin cancels the order and writes the stock off.
# Exit code: 0 only when every check passed.
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

require_tools
require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD SEED_CUSTOMER_PASSWORD

printf '%sRequirement 1 — API security demo against %s%s\n' "$_bold" "$EDGE_URL" "$_reset"

# --------------------------------------------------------------- set-up --
section "0. Set-up / التحضير: admin creates a product, customer A places an order"
note "نتحقق أن النظام يعمل، ثم نجهز منتجاً وطلباً لزبون A لنجرّب عليهما الهجمات"
request GET /health
expect_status "API is up (GET /health)" 200

login_admin
ADMIN="$TOKEN"
login_customer 1
A="$TOKEN"
login_customer 2
B="$TOKEN"
note "customer A = $(customer_email 1), customer B = $(customer_email 2)"

create_product "$ADMIN" "DEMO R1 $(date +%H:%M:%S)" 5
note "product $PRODUCT_ID created with stock 5"

create_order "$A" "$PRODUCT_ID" 1
expect_status "customer A creates an order" 201
ORDER_A="$ORDER_ID"
[[ -n "$ORDER_A" ]] || die "no order id in the response; cannot continue"
note "A's order id: $ORDER_A"

cleanup() {
  [[ -n "${ORDER_A:-}" && -n "${ADMIN:-}" ]] || return 0
  printf '\n%sCleaning up demo data%s\n' "$_dim" "$_reset"
  request_json PATCH "/orders/$ORDER_A/status" '{"status":"cancelled","note":"demo clean-up"}' \
    -H "Authorization: Bearer $ADMIN" >/dev/null
  local left
  left="$(product_available "$PRODUCT_ID")"
  if [[ "$left" =~ ^[1-9][0-9]*$ ]]; then adjust_stock "$ADMIN" "$PRODUCT_ID" "-$left" >/dev/null; fi
}
on_exit cleanup

# -------------------------------------------------------------- headers --
section "1. Security headers / ترويسات الأمان (R1-SEC3)"
note "كل رد من الـ API يحمل ترويسات تمنع التخمين والتضمين والتخزين المؤقت للبيانات الحساسة"
request GET /auth/me -H "Authorization: Bearer $A"
note "API response (GET /api/v1/auth/me):"
expect_eq "X-Content-Type-Options" nosniff "$(header x-content-type-options)"
expect_eq "X-Frame-Options" DENY "$(header x-frame-options)"
expect_match "Content-Security-Policy" "default-src 'none'" "$(header content-security-policy)"
expect_eq "Referrer-Policy" no-referrer "$(header referrer-policy)"
expect_match "Permissions-Policy" 'camera=\(\)' "$(header permissions-policy)"
expect_eq "Cross-Origin-Opener-Policy" same-origin "$(header cross-origin-opener-policy)"
expect_eq "Cross-Origin-Resource-Policy" same-origin "$(header cross-origin-resource-policy)"
expect_match "Cache-Control on an authenticated answer" 'no-store' "$(header cache-control)"
expect_eq "no X-Powered-By" "" "$(header x-powered-by)"
expect_match "Server header without a version" '^[^0-9]*$' "$(header server)"

note "SPA response (GET /):"
spa_headers="$(curl -sS -I --max-time "$CURL_MAX_TIME" "$EDGE_URL/" | tr -d '\r' || true)"
HEADERS="$spa_headers"
expect_match "Content-Security-Policy (SPA)" "script-src 'self'" "$(header content-security-policy)"
expect_eq "X-Frame-Options (SPA)" DENY "$(header x-frame-options)"
expect_eq "X-Content-Type-Options (SPA)" nosniff "$(header x-content-type-options)"

# ------------------------------------------------------------------ JWT --
section "2. JWT / رموز مزوّرة تُرفض (R1-SEC1)"
note "الخادم لا يقبل إلا رمزاً موقّعاً بمفتاحنا وغير منتهٍ؛ نجرّب خمس طرق للتزوير"
request GET /auth/me -H "Authorization: Bearer $A"
expect_eq "valid token → 200, role customer" "200 customer" "$STATUS $(json_body .data.role)"

request GET /auth/me
expect_problem "no token → 401" 401 UNAUTHENTICATED

request GET /auth/me -H 'Authorization: Bearer not.a.jwt'
expect_problem "garbage token → 401" 401 UNAUTHENTICATED

IFS=. read -r jwt_header jwt_payload jwt_signature <<<"$A"
forged_payload="$(b64url_decode "$jwt_payload" | jq -c '.role = "admin"' | b64url_encode)"
note "tampered payload: $(b64url_decode "$forged_payload")"
request_json PATCH "/orders/$ORDER_A/status" '{"status":"shipped"}' \
  -H "Authorization: Bearer $jwt_header.$forged_payload.$jwt_signature"
expect_problem "payload changed to role=admin (old signature) → 401" 401 UNAUTHENTICATED

none_header="$(printf '{"alg":"none","typ":"JWT"}' | b64url_encode)"
request GET /auth/me -H "Authorization: Bearer $none_header.$jwt_payload."
expect_problem 'alg "none" (unsigned) → 401' 401 UNAUTHENTICATED

expired="${EXPIRED_TOKEN:-}"
if [[ -z "$expired" ]]; then
  expired="$(compose exec -T api php artisan jwt:issue-expired "$(customer_email 1)" 2>/dev/null | tr -d '\r\n' || true)"
fi
if [[ -n "$expired" ]]; then
  request GET /auth/me -H "Authorization: Bearer $expired"
  expect_problem "expired token → 401 TOKEN_EXPIRED" 401 TOKEN_EXPIRED
else
  skip "expired token → 401 TOKEN_EXPIRED" "no EXPIRED_TOKEN and 'php artisan jwt:issue-expired' failed in the api container"
fi

# ----------------------------------------------------------------- IDOR --
section "3. BOLA/IDOR / زبون يفتح طلب زبون آخر (PROOF-R1-1)"
note "B يغيّر الرقم في الرابط إلى طلب A؛ يجب أن يُرفض، والرد مطابق لطلب غير موجود فلا نكشف وجوده"
request GET "/orders/$ORDER_A" -H "Authorization: Bearer $A"
expect_status "A opens her own order" 200

request GET "/orders/$ORDER_A" -H "Authorization: Bearer $B"
expect_problem "B opens A's order → 404 (D-02: existence is not revealed)" 404 NOT_FOUND
idor_body="$(jq -c '{status, code}' <<<"$BODY")"

request GET "/orders/01JC0000000000000000000000" -H "Authorization: Bearer $B"
expect_eq "…same answer as an order that does not exist" "$idor_body" "$(jq -c '{status, code}' <<<"$BODY")"

request GET "/orders?per_page=100" -H "Authorization: Bearer $B"
expect_eq "A's order is not in B's order list" "absent" \
  "$(jq -r --arg id "$ORDER_A" 'if any(.data[]; .id == $id) then "present" else "absent" end' <<<"$BODY")"

# --------------------------------------------------------- status change --
section "4. Only the admin changes status / المسؤول وحده يغيّر الحالة (PROOF-R1-2)"
note "الزبون (حتى صاحب الطلب) يُرفض 403؛ المسؤول ينجح 200"
request_json PATCH "/orders/$ORDER_A/status" '{"status":"confirmed"}' -H "Authorization: Bearer $B"
expect_problem "B changes A's order status → 403" 403 FORBIDDEN

request_json PATCH "/orders/$ORDER_A/status" '{"status":"confirmed"}' -H "Authorization: Bearer $A"
expect_problem "A changes her OWN order status → 403" 403 FORBIDDEN

request GET "/orders/$ORDER_A" -H "Authorization: Bearer $A"
expect_eq "the order is still pending" pending "$(json_body .data.status)"

request_json PATCH "/orders/$ORDER_A/status" '{"status":"confirmed","note":"demo"}' -H "Authorization: Bearer $ADMIN"
expect_eq "the admin confirms it → 200 confirmed" "200 confirmed" "$STATUS $(json_body .data.status)"

# ----------------------------------------------------------- validation --
section "5. Validation and the one error shape / 422 وصيغة الأخطاء الموحدة (R1-A2, R1-A3)"
note "كل الأخطاء بصيغة RFC 9457 نفسها: type/title/status/detail/code/trace_id، والتفاصيل بالعربية للمستخدم"
request_json POST /orders '{"items":[]}' -H "Authorization: Bearer $A" -H "Idempotency-Key: $(uuid)"
expect_problem "order with no items → 422 VALIDATION_FAILED" 422 VALIDATION_FAILED
expect_match "Content-Type is problem+json" '^application/problem\+json' "$(header content-type)"
note "the full error body, once:"
show_json

# -------------------------------------------------------------- refresh --
section "6. Refresh-token rotation / دوران رمز التجديد وكشف السرقة (ADR-0003)"
note "كل تجديد يُصدر رمزاً جديداً؛ إعادة استعمال القديم (سارق) تُلغي العائلة كلها"
login_customer 4
old_refresh="$REFRESH"
cookie_flags="$(header set-cookie | tr 'A-Z' 'a-z' | grep -oE 'httponly|secure|samesite=strict|path=/api/v1/auth' | sort | tr '
' ' ' | sed 's/ $//')"
expect_eq "login sets the refresh cookie as HttpOnly, Secure, SameSite=Strict, scoped path (value not shown)"   "httponly path=/api/v1/auth samesite=strict secure" "$cookie_flags"

request POST /auth/refresh -H "Origin: $EDGE_URL" -H "Cookie: refresh_token=$old_refresh"
expect_problem "refresh without X-Requested-With (CSRF guard) → 403" 403 FORBIDDEN

refresh_headers=(-H 'X-Requested-With: fetch' -H "Origin: $EDGE_URL")
request POST /auth/refresh "${refresh_headers[@]}" -H "Cookie: refresh_token=$old_refresh"
expect_status "refresh with the cookie → 200 (the 403 above rotated nothing)" 200
new_refresh="$(header set-cookie | sed -n 's/^refresh_token=\([^;]*\).*/\1/p')"
expect_eq "a NEW refresh token is issued" yes "$([[ -n "$new_refresh" && "$new_refresh" != "$old_refresh" ]] && echo yes || echo no)"

request POST /auth/refresh "${refresh_headers[@]}" -H "Cookie: refresh_token=$old_refresh"
expect_problem "re-using the OLD refresh token (as a thief would) → 401" 401 REFRESH_TOKEN_INVALID

request POST /auth/refresh "${refresh_headers[@]}" -H "Cookie: refresh_token=$new_refresh"
expect_problem "…and the whole family is revoked: the new one fails too" 401 REFRESH_TOKEN_INVALID

# ----------------------------------------------------------- rate limit --
section "7. Login rate limit / تحديد محاولات الدخول — 5 per minute per (email, IP) (R1-SEC2)"
victim="ratelimit-$(uuid | cut -c1-8)@example.test"
note "نخمّن كلمة المرور لبريد وهمي 5 مرات ثم السادسة تُحظر 429 مع Retry-After (لا نقفل أي زبون حقيقي)"
for i in 1 2 3 4 5; do
  request_json POST /auth/login "$(jq -nc --arg e "$victim" '{email: $e, password: "wrong-password-123"}')"
  expect_problem "wrong password #$i → 401" 401 INVALID_CREDENTIALS
done
request_json POST /auth/login "$(jq -nc --arg e "$victim" '{email: $e, password: "wrong-password-123"}')"
expect_problem "6th attempt → 429" 429 RATE_LIMITED
expect_match "Retry-After header (seconds)" '^[0-9]+$' "$(header retry-after)"
expect_match "retry_after in the problem body" '^[0-9]+$' "$(json_body .retry_after)"

finish
