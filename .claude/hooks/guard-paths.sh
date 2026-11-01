#!/usr/bin/env bash
# PreToolUse (Write|Edit|NotebookEdit): block edits to generated code, secrets and keys.
# Exit 2 = block; stderr is shown to Claude as the reason.
source "$(dirname "$0")/lib.sh"
require_jq
hook_read_input

path="$(hook_field '.tool_input.file_path')"
[[ -z "$path" ]] && path="$(hook_field '.tool_input.notebook_path')"
[[ -z "$path" ]] && exit 0

base="$(basename "$path")"

block() {
  echo "BLOCKED: $1" >&2
  echo "path: $path" >&2
  exit 2
}

case "$path" in
  */backend/api/generated/*|backend/api/generated/*)
    block "generated gRPC stubs are never edited by hand. Change contracts/proto (Lead only) and run 'make gen'." ;;
  */frontend/src/api/schema.d.ts|frontend/src/api/schema.d.ts)
    block "schema.d.ts is generated from contracts/openapi. Ask the Lead for a contract change, then 'make gen'." ;;
  */storage/keys/*|storage/keys/*)
    block "JWT keys are generated at runtime by 'php artisan jwt:keys' and must never be written by an agent." ;;
esac

if [[ "$base" == ".env" || ( "$base" == .env.* && "$base" != ".env.example" ) ]]; then
  block "real .env files hold secrets. Edit .env.example instead and document the variable."
fi

exit 0
