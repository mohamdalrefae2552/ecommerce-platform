#!/usr/bin/env bash
# `make mail-setup` — send real mail through an external SMTP service
# instead of Mailpit (requirement 4, R4-2; docs/10-async-realtime.md §6).
# Asks for the provider and its credentials (the password is typed hidden,
# never shown and stored only in the git-ignored .env), then writes the five
# MAIL_* values. `make mail-setup RESET=1` goes back to Mailpit.
set -euo pipefail

[[ -f .env ]] || { echo "✗ .env does not exist: run make bootstrap first" >&2; exit 1; }

# set_env <KEY> <value>: replace the line of KEY in .env, or append it.
set_env() {
  local key="$1" value="$2" line found=0 tmp
  tmp="$(mktemp)"
  while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" == "$key="* ]]; then
      # Keep one line per key: a duplicate further down would win otherwise.
      ((found)) || printf '%s=%s\n' "$key" "$value" >>"$tmp"
      found=1
    else
      printf '%s\n' "$line" >>"$tmp"
    fi
  done <.env
  ((found)) || printf '%s=%s\n' "$key" "$value" >>"$tmp"
  cat "$tmp" >.env
  rm -f "$tmp"
}

is_email() { [[ "$1" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; }

if [[ -n "${RESET:-}" ]]; then
  set_env MAIL_HOST mailpit
  set_env MAIL_PORT 1025
  set_env MAIL_USERNAME ''
  set_env MAIL_PASSWORD ''
  set_env MAIL_FROM_ADDRESS no-reply@ecommerce.local
  echo "✓ back to Mailpit. Next: make up"
  exit 0
fi

echo "Provider:  1) Gmail (needs an App Password)   2) other SMTP service (Mailtrap, Brevo, ...)"
read -r -p "Choose 1 or 2: " provider

case "$provider" in
  1)
    read -r -p "Gmail address: " username
    is_email "$username" || { echo "✗ that is not an e-mail address" >&2; exit 1; }
    read -r -s -p "App Password (16 letters, hidden; paste with right click): " password
    echo
    password="${password// /}"
    [[ "$password" =~ ^[A-Za-z]{16}$ ]] || { echo "✗ an App Password is 16 letters (spaces are ignored); this is not your normal Gmail password" >&2; exit 1; }
    host=smtp.gmail.com port=587 from="$username"
    ;;
  2)
    read -r -p "SMTP host (e.g. sandbox.smtp.mailtrap.io): " host
    [[ "$host" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "✗ that is not a host name" >&2; exit 1; }
    read -r -p "SMTP port [587]: " port
    port="${port:-587}"
    [[ "$port" =~ ^[0-9]{2,5}$ ]] || { echo "✗ the port is a number" >&2; exit 1; }
    read -r -p "SMTP username: " username
    [[ -n "$username" && "$username" != *[[:space:]]* ]] || { echo "✗ the username is empty or has spaces" >&2; exit 1; }
    read -r -s -p "SMTP password (hidden; paste with right click): " password
    echo
    [[ -n "$password" && "$password" != *[[:space:]]* ]] || { echo "✗ the password is empty or has spaces" >&2; exit 1; }
    read -r -p "Sender address shown to customers (e.g. store@example.com): " from
    is_email "$from" || { echo "✗ that is not an e-mail address" >&2; exit 1; }
    ;;
  *)
    echo "✗ choose 1 or 2" >&2
    exit 1
    ;;
esac

set_env MAIL_HOST "$host"
set_env MAIL_PORT "$port"
set_env MAIL_USERNAME "$username"
set_env MAIL_PASSWORD "$password"
set_env MAIL_FROM_ADDRESS "$from"

echo "✓ SMTP $host:$port is configured in .env. Next: make up"
