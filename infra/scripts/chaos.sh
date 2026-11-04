#!/usr/bin/env bash
# Fault injection between the app and the inventory (R2-E2, PROOF-R2-2).
# Drives Toxiproxy's admin API from the `toolbox` container, so no port is
# published on the host.
#   chaos.sh down | latency <ms> | reset | status
set -euo pipefail

compose=(docker compose -p "${PROJECT:?}" --profile tools)
api="http://toxiproxy:8474"
proxy="inventory"

# tp <curl args...>: call the Toxiproxy API, fail on HTTP errors.
tp() { "${compose[@]}" run --rm --no-deps -T toolbox curl -fsS "$@"; }

case "${1:-}" in
  down)
    tp -X POST -H 'Content-Type: application/json' -d '{"enabled":false}' "$api/proxies/$proxy" >/dev/null
    echo "✓ inventory link DOWN (gRPC calls now fail with UNAVAILABLE). Undo: make chaos-reset"
    ;;
  latency)
    ms="${2:-}"
    [[ "$ms" =~ ^[0-9]+$ ]] || { echo "✗ latency must be a whole number of ms" >&2; exit 1; }
    # Replace any previous latency toxic so the value is exactly what was asked.
    tp -X DELETE "$api/proxies/$proxy/toxics/latency" >/dev/null 2>&1 || true
    tp -X POST -H 'Content-Type: application/json' \
      -d "{\"name\":\"latency\",\"type\":\"latency\",\"stream\":\"downstream\",\"attributes\":{\"latency\":$ms,\"jitter\":0}}" \
      "$api/proxies/$proxy/toxics" >/dev/null
    echo "✓ inventory responses delayed by ${ms}ms. Undo: make chaos-reset"
    ;;
  reset)
    tp -X POST "$api/reset" >/dev/null
    echo "✓ toxiproxy reset: link enabled, no toxics"
    ;;
  status)
    tp "$api/proxies/$proxy" | "${compose[@]}" run --rm --no-deps -T toolbox jq '{name, listen, upstream, enabled, toxics}'
    ;;
  *)
    echo "usage: chaos.sh down | latency <ms> | reset | status" >&2
    exit 1
    ;;
esac
