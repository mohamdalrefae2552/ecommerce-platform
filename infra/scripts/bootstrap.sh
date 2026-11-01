#!/usr/bin/env bash
# First-run setup, idempotent (`make bootstrap`):
#   .env from .env.example → images → APP_KEY → JWT keys → migrations + seeds.
# Secrets are generated locally and never committed (CLAUDE.md rule 7).
set -euo pipefail

compose=(docker compose -p "${PROJECT:?}")
step() { printf '\n▶ %s\n' "$*"; }

[[ -d backend/api ]] || { echo "✗ backend/api does not exist yet (task P0-API-01)" >&2; exit 1; }

if [[ ! -f .env ]]; then
  step "creating .env from .env.example"
  cp .env.example .env
fi

step "building images"
"${compose[@]}" build

step "starting the application database"
"${compose[@]}" up -d --wait db-app

if ! grep -qE '^APP_KEY=.+' .env; then
  step "generating APP_KEY"
  key="$("${compose[@]}" run --rm --no-deps -T api php artisan key:generate --show | tr -d '\r')"
  [[ "$key" == base64:* ]] || { echo "✗ unexpected key:generate output: $key" >&2; exit 1; }
  sed -i "s#^APP_KEY=.*#APP_KEY=${key}#" .env
else
  echo "• APP_KEY already set"
fi

artisan=("${compose[@]}" run --rm -T api php artisan)

if "${artisan[@]}" list --raw | grep -q '^jwt:keys'; then
  step "generating JWT signing keys (kept if they already exist)"
  "${artisan[@]}" jwt:keys
else
  echo "• jwt:keys is not available yet (added by P1-API-03)"
fi

step "running migrations and seeders"
"${artisan[@]}" migrate --force
"${artisan[@]}" db:seed --force

printf '\n✓ bootstrap done. Next: make up\n'
