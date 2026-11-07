#!/usr/bin/env bash
# Shared helpers for the interview demo scripts (demo-r1.sh, demo-r2.sh,
# race-verify.sh). Source it:
#   source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
#
# Every check prints the request, the expected result, the actual result and
# OK/FAIL; `finish` exits non-zero when any check failed (docs/06, P1-QA-01).
# Needs bash, curl and jq on the host. The Makefile sets EDGE_URL and PROJECT
# and passes the dev/test accounts (D-04, D-05) as environment variables.
set -euo pipefail

# jq installed with winget is on the Windows PATH but not always on make's.
if ! command -v jq >/dev/null 2>&1; then
  export PATH="$PATH:$HOME/AppData/Local/Microsoft/WinGet/Packages/jqlang.jq_Microsoft.Winget.Source_8wekyb3d8bbwe"
fi

EDGE_URL="${EDGE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL%/}"
API="$EDGE_URL/api/v1"
# Explicit deadline for every request.
CURL_MAX_TIME="${CURL_MAX_TIME:-15}"

_failures=0
_steps=0
_skips=0
_tmp="$(mktemp -d)"
if command -v cygpath >/dev/null 2>&1; then _tmp="$(cygpath -m "$_tmp")"; fi # native curl needs C:/ paths
_cleanup_hooks=()
_on_exit() {
  local hook
  for hook in "${_cleanup_hooks[@]+"${_cleanup_hooks[@]}"}"; do
    "$hook" || true
  done
  rm -rf "$_tmp"
}
trap _on_exit EXIT

# on_exit <function>: run <function> when the script ends, even on failure.
on_exit() { _cleanup_hooks+=("$1"); }

if [[ -t 1 ]]; then
  _green=$'\e[32m' _red=$'\e[31m' _yellow=$'\e[33m' _bold=$'\e[1m' _dim=$'\e[2m' _reset=$'\e[0m'
else
  _green='' _red='' _yellow='' _bold='' _dim='' _reset=''
fi

die() {
  printf '%s✗ %s%s\n' "$_red" "$*" "$_reset" >&2
  exit 2
}

require_tools() {
  local tool
  for tool in curl jq "$@"; do
    command -v "$tool" >/dev/null 2>&1 || die "'$tool' is required (see README → Prerequisites)"
  done
}

# require_env <NAME...>: fail clearly when a variable the Makefile must pass is empty.
require_env() {
  local name
  for name in "$@"; do
    [[ -n "${!name:-}" ]] || die "$name is not set (it comes from .env through the Makefile — run make bootstrap)"
  done
}

# compose <args...>: docker compose for this project (PROJECT is set by the Makefile).
compose() {
  docker compose -p "${PROJECT:?PROJECT is not set}" "$@"
}

# section <title>: heading for one demo step.
section() {
  printf '\n%s== %s ==%s\n' "$_bold" "$*" "$_reset"
}

# note <text>: an explanatory line for the examiner.
note() {
  printf '  %s%s%s\n' "$_dim" "$*" "$_reset"
}

# request <method> <path> [curl args...]
# Sets STATUS (000 when no answer), BODY, HEADERS and TIME_MS.
request() {
  local method="$1" path="$2"
  shift 2
  local out
  rm -f "$_tmp/headers" "$_tmp/body"
  printf '  %s→ %s /api/v1%s%s\n' "$_dim" "$method" "$path" "$_reset"
  out="$(curl -sS -X "$method" --max-time "$CURL_MAX_TIME" \
    -D "$_tmp/headers" -o "$_tmp/body" \
    -w '%{http_code} %{time_total}' \
    -H 'Accept: application/json' "$@" "$API$path" 2>"$_tmp/curl-error")" || true
  [[ "$out" =~ ^[0-9]{3}\ [0-9.]+$ ]] || out="000 0"
  STATUS="${out%% *}"
  TIME_MS="$(awk -v t="${out##* }" 'BEGIN { printf "%d", t * 1000 }')"
  BODY="$(cat "$_tmp/body" 2>/dev/null || true)"
  HEADERS="$(tr -d '\r' <"$_tmp/headers" 2>/dev/null || true)"
}

# request_json <method> <path> <json body> [curl args...]
request_json() {
  local method="$1" path="$2" body="$3"
  shift 3
  request "$method" "$path" -H 'Content-Type: application/json' --data-binary "$body" "$@"
}

# json_body <jq filter>: read a field of the last BODY ("" when absent).
json_body() {
  jq -r "$1 // empty" <<<"$BODY" 2>/dev/null || true
}

# header <name>: value of a response header of the last request (case-insensitive).
header() {
  awk -v name="$(tr '[:upper:]' '[:lower:]' <<<"$1")" \
    'index(tolower($0), name ": ") == 1 { sub(/^[^:]*: /, ""); print; exit }' <<<"$HEADERS"
}

_pass() {
  _steps=$((_steps + 1))
  printf '  %s✅ OK%s    %s  (expected: %s, actual: %s)\n' "$_green" "$_reset" "$1" "$2" "$3"
}

_fail() {
  _steps=$((_steps + 1))
  _failures=$((_failures + 1))
  printf '  %s❌ FAIL%s  %s  (expected: %s, actual: %s)\n' "$_red" "$_reset" "$1" "$2" "$3"
  if [[ -n "${BODY:-}" ]]; then
    printf '          body: %s\n' "$(head -c 300 <<<"$BODY")"
  fi
}

# expect_eq <description> <expected> <actual>
expect_eq() {
  if [[ "$2" == "$3" ]]; then _pass "$@"; else _fail "$@"; fi
}

# expect_status <description> <expected http code>: checks the last request.
expect_status() {
  expect_eq "$1" "$2" "$STATUS"
}

# expect_problem <description> <status> <code>: status and problem `code` of the last request.
expect_problem() {
  local actual
  actual="$STATUS $(json_body .code)"
  expect_eq "$1" "$2 $3" "${actual% }"
}

# expect_match <description> <regex> <actual>
expect_match() {
  if [[ "$3" =~ $2 ]]; then _pass "$1" "/$2/" "$3"; else _fail "$1" "/$2/" "${3:-<empty>}"; fi
}

# expect_lt <description> <limit> <actual number>: actual < limit.
expect_lt() {
  if (("$3" < "$2")); then _pass "$1" "< $2" "$3"; else _fail "$1" "< $2" "$3"; fi
}

# expect_gt <description> <limit> <actual number>: actual > limit.
expect_gt() {
  if (("$3" > "$2")); then _pass "$1" "> $2" "$3"; else _fail "$1" "> $2" "$3"; fi
}

# skip <description> <reason>: a check that could not run, shown (never silent).
skip() {
  _skips=$((_skips + 1))
  printf '  %s⏭  SKIP%s  %s  (%s)\n' "$_yellow" "$_reset" "$1" "$2"
}

# uuid: random RFC 9562 version-4 UUID (Idempotency-Key, adjustment ids).
uuid() {
  local h
  h="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
  printf '%s-%s-4%s-%x%s-%s\n' "${h:0:8}" "${h:8:4}" "${h:13:3}" "$((0x${h:16:1} & 0x3 | 0x8))" "${h:17:3}" "${h:20:12}"
}

# b64url_encode / b64url_decode: base64url without padding (JWT segments).
b64url_encode() { base64 | tr -d '\n=' | tr '+/' '-_'; }
b64url_decode() {
  local s
  s="$(tr '_-' '/+' <<<"$1")"
  while ((${#s} % 4)); do s+='='; done
  base64 -d <<<"$s"
}

# login <email> <password>: sets TOKEN (access token) and REFRESH (refresh cookie value).
# Logins are limited to 20/min per IP (docs/04 section 3): on 429 wait for
# Retry-After and try again, so a demo run right after another one still works.
login() {
  local attempt wait
  for attempt in 1 2 3 4 5; do
    request_json POST /auth/login "$(jq -nc --arg e "$1" --arg p "$2" '{email: $e, password: $p}')"
    [[ "$STATUS" == 429 ]] || break
    wait="$(header retry-after)"
    note "429 on login (per-IP limit) — waiting $((${wait:-60} + 1))s as Retry-After says"
    sleep "$((${wait:-60} + 1))"
  done
  [[ "$STATUS" == 200 ]] || die "login as $1 failed: HTTP $STATUS $(json_body .code) $(json_body .detail)"
  TOKEN="$(json_body .data.access_token)"
  REFRESH="$(header set-cookie | sed -n 's/^refresh_token=\([^;]*\).*/\1/p')"
}

# login_admin / login_customer <n>: the dev admin (D-05) and seeded customer{n} (D-04).
login_admin() {
  require_env DEV_ADMIN_EMAIL DEV_ADMIN_PASSWORD
  login "$DEV_ADMIN_EMAIL" "$DEV_ADMIN_PASSWORD"
}
customer_email() { printf 'customer%d@example.test' "$1"; }
login_customer() {
  require_env SEED_CUSTOMER_PASSWORD
  login "$(customer_email "$1")" "$SEED_CUSTOMER_PASSWORD"
}

# native_path <path>: the path as curl sees it (Git Bash on Windows ships a
# native curl that does not understand /tmp inside -F "file=@…").
native_path() {
  if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi
}

# A valid 8x8 PNG, used as the product image (re-encoded to WebP by the API).
_DEMO_PNG='iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAaklEQVR4nAXBgQAAQQgAsMc5iCCCCCKIIIIIYli/feKJEClKtBix4j75ZMiUJVuOXHmfeipUqlKtRq26Tz8dOnXp1qNX32eeCZOmTJsxa+6zz4ZNW7bt2LX3uefCpSvXbty6+3gESdEMy/0yoG4Bl9QeDQAAAABJRU5ErkJggg=='

# create_product <admin token> <name> <initial stock>: sets PRODUCT_ID.
create_product() {
  local token="$1" name="$2" stock="$3" category
  request GET /categories
  category="$(json_body '.data[0].id')"
  [[ -n "$category" ]] || die "GET /categories returned no category (HTTP $STATUS) — are the seeders run?"
  base64 -d <<<"$_DEMO_PNG" >"$_tmp/demo.png"
  request POST /products -H "Authorization: Bearer $token" \
    -F "name=$name" -F 'description=Demo product created by the interview scripts.' \
    -F 'price_minor=100000' -F "category_id=$category" -F "initial_stock=$stock" \
    -F "image=@$(native_path "$_tmp/demo.png");type=image/png"
  [[ "$STATUS" == 201 ]] || die "create product failed: HTTP $STATUS $(json_body .code) $(json_body .detail)"
  PRODUCT_ID="$(json_body .data.id)"
}

# adjust_stock <admin token> <product id> <delta>
adjust_stock() {
  request_json PUT "/products/$2/stock" "$(jq -nc --argjson d "$3" '{delta: $d, note: "demo"}')" \
    -H "Authorization: Bearer $1" -H "Idempotency-Key: $(uuid)"
}

# product_available <product id>: prints stock.available as the API reports it ("" when unknown).
product_available() {
  request GET "/products/$1" >/dev/null
  json_body .data.stock.available
}

# create_order <token> <product id> <quantity>: sets STATUS/BODY; ORDER_ID on 201.
create_order() {
  request_json POST /orders "$(jq -nc --arg p "$2" --argjson q "$3" '{items: [{product_id: $p, quantity: $q}]}')" \
    -H "Authorization: Bearer $1" -H "Idempotency-Key: $(uuid)"
  ORDER_ID="$(json_body .data.id)"
}

# show_json: pretty-print the last BODY (indented) for the examiner.
show_json() {
  jq . <<<"$BODY" 2>/dev/null | sed 's/^/        /' || true
}

# find_race_product <admin token>: sets PRODUCT_ID and PRODUCT_CREATED_AT to the
# newest product made by tests/load/race.js (its name starts with "DEMO race").
find_race_product() {
  request GET '/products?q=DEMO%20race&per_page=100' -H "Authorization: Bearer $1"
  PRODUCT_ID="$(jq -r '[.data[] | select(.name | startswith("DEMO race"))] | max_by(.created_at) | .id // empty' <<<"$BODY")"
  PRODUCT_CREATED_AT="$(jq -r '[.data[] | select(.name | startswith("DEMO race"))] | max_by(.created_at) | .created_at // empty' <<<"$BODY")"
  [[ -n "$PRODUCT_ID" ]] || die "no 'DEMO race' product found — run tests/load/race.js first (make race-test)"
}

# count_orders_for_product <admin token> <product id> <created_at floor>: sets
# ORDERS_ON_PRODUCT. The admin list has no line items, so every order created
# since the product existed is opened and its lines are inspected.
count_orders_for_product() {
  local token="$1" product="$2" floor="$3" page=1 last=1 ids id older n=0
  ORDERS_SCANNED=0
  while ((page <= last)); do
    request GET "/orders?per_page=100&page=$page" -H "Authorization: Bearer $token" >/dev/null
    last="$(json_body .meta.last_page)"
    ids="$(jq -r --arg f "$floor" '.data[] | select(.created_at >= $f) | .id' <<<"$BODY")"
    older="$(jq -r --arg f "$floor" 'any(.data[]; .created_at < $f)' <<<"$BODY")"
    for id in $ids; do
      ORDERS_SCANNED=$((ORDERS_SCANNED + 1))
      request GET "/orders/$id" -H "Authorization: Bearer $token" >/dev/null
      if jq -e --arg p "$product" 'any(.data.items[]; .product_id == $p)' <<<"$BODY" >/dev/null; then n=$((n + 1)); fi
    done
    # newest first: stop once a page reaches orders older than the product
    [[ "$older" == true ]] && break
    page=$((page + 1))
  done
  ORDERS_ON_PRODUCT=$n
}

# finish: summary line and exit code (0 only when every check passed).
finish() {
  local skipped=''
  ((_skips == 0)) || skipped=", $_skips skipped"
  if ((_failures == 0)); then
    printf '\n%s%s✅ %d/%d checks passed%s%s\n' "$_bold" "$_green" "$_steps" "$_steps" "$skipped" "$_reset"
  else
    printf '\n%s%s❌ %d/%d checks passed%s%s\n' "$_bold" "$_red" "$((_steps - _failures))" "$_steps" "$skipped" "$_reset"
  fi
  ((_failures == 0))
}
