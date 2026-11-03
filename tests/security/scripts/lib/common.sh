#!/usr/bin/env bash
# Shared helpers for the interview demo scripts (demo-r1.sh, demo-r2.sh, ...).
# Source it:  source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
#
# Every step prints the request, the expected result, the actual result and
# OK/FAIL; `finish` exits non-zero when any step failed (docs/06, P1-QA-01).
# Needs curl and jq on the host. EDGE_URL is set by the Makefile.
set -euo pipefail

EDGE_URL="${EDGE_URL:-http://localhost:8080}"
EDGE_URL="${EDGE_URL%/}"
API="$EDGE_URL/api/v1"
# Explicit deadline for every request (CLAUDE.md rule 6).
CURL_MAX_TIME="${CURL_MAX_TIME:-15}"

_failures=0
_steps=0
_tmp="$(mktemp -d)"
trap 'rm -rf "$_tmp"' EXIT

if [[ -t 1 ]]; then
  _green=$'\e[32m' _red=$'\e[31m' _bold=$'\e[1m' _dim=$'\e[2m' _reset=$'\e[0m'
else
  _green='' _red='' _bold='' _dim='' _reset=''
fi

require_tools() {
  local tool
  for tool in curl jq "$@"; do
    command -v "$tool" >/dev/null 2>&1 || { echo "✗ '$tool' is required (see README → Prerequisites)" >&2; exit 2; }
  done
}

# section <title>: a numbered heading for one demo step.
section() {
  printf '\n%s== %s ==%s\n' "$_bold" "$*" "$_reset"
}

# request <method> <path> [curl args...]
# Sets STATUS, BODY (raw body), HEADERS (raw response headers) and TIME_MS.
request() {
  local method="$1" path="$2"
  shift 2
  local out
  out="$(curl -sS -X "$method" --max-time "$CURL_MAX_TIME" \
    -D "$_tmp/headers" -o "$_tmp/body" \
    -w '%{http_code} %{time_total}' \
    -H 'Accept: application/json' "$@" "$API$path")" || out="000 0"
  STATUS="${out%% *}"
  TIME_MS="$(awk -v t="${out##* }" 'BEGIN { printf "%d", t * 1000 }')"
  BODY="$(cat "$_tmp/body" 2>/dev/null || true)"
  HEADERS="$(tr -d '\r' <"$_tmp/headers" 2>/dev/null || true)"
  printf '%s→ %s %s%s\n' "$_dim" "$method" "/api/v1$path" "$_reset"
}

# json_body <jq filter>: read a field of the last BODY.
json_body() {
  jq -r "$1" <<<"$BODY" 2>/dev/null || true
}

# header <name>: value of a response header of the last request (case-insensitive).
header() {
  awk -v name="$(tr '[:upper:]' '[:lower:]' <<<"$1")" -F': ' \
    'tolower($1) == name { sub(/^[^:]*: /, ""); print; exit }' <<<"$HEADERS"
}

# expect_eq <description> <expected> <actual>
expect_eq() {
  local description="$1" expected="$2" actual="$3"
  _steps=$((_steps + 1))
  if [[ "$expected" == "$actual" ]]; then
    printf '  %s✅ OK%s   %s  (expected: %s, actual: %s)\n' "$_green" "$_reset" "$description" "$expected" "$actual"
  else
    _failures=$((_failures + 1))
    printf '  %s❌ FAIL%s %s  (expected: %s, actual: %s)\n' "$_red" "$_reset" "$description" "$expected" "$actual"
    [[ -n "${BODY:-}" ]] && printf '         body: %s\n' "$(head -c 300 <<<"$BODY")"
  fi
}

# expect_status <description> <expected http code>: checks the last request.
expect_status() {
  expect_eq "$1" "$2" "$STATUS"
}

# unique_email <label>: an address that has never been registered.
unique_email() {
  printf 'demo-%s-%s-%s@example.test' "$1" "$(date +%s)" "$RANDOM"
}

# finish: summary line and exit code (0 only when every step passed).
finish() {
  printf '\n%s%d/%d checks passed%s\n' "$_bold" "$((_steps - _failures))" "$_steps" "$_reset"
  [[ "$_failures" -eq 0 ]]
}
