#!/usr/bin/env bash
# Isolated git worktrees, each with its own stack. Called by `make wt` / `make wt-rm`.
#   wt.sh add     NAME BRANCH [BASE] [MEMBER_NAME MEMBER_EMAIL]   (via env)
#   wt.sh remove  NAME
# Worktrees live next to the main checkout: <parent>/<repo>-wt/<NAME>.
# Each gets its own .env with a unique COMPOSE_PROJECT_NAME and EDGE_PORT so
# several stacks can run side by side.
set -euo pipefail

die() { echo "✗ $*" >&2; exit 1; }

common_dir="$(git rev-parse --path-format=absolute --git-common-dir)"
main_root="$(dirname "$common_dir")"
repo_name="$(basename "$main_root")"
wt_root="$(dirname "$main_root")/${repo_name}-wt"

[[ "${NAME:-}" =~ ^[a-z][a-z0-9-]{0,20}$ ]] \
  || die "NAME must be lower-case letters, digits or '-' (got '${NAME:-}')"
dir="$wt_root/$NAME"
project="${repo_name}-${NAME}"

# set_env <file> <key> <value>: replace or append KEY=value.
set_env() {
  local file="$1" key="$2" value="$3"
  if grep -q "^${key}=" "$file"; then
    sed -i "s#^${key}=.*#${key}=${value}#" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >>"$file"
  fi
}

# Lowest EDGE_PORT in 8081..8099 not used by another worktree.
free_edge_port() {
  local used port
  used="$(cat "$wt_root"/*/.env 2>/dev/null | sed -n 's/^EDGE_PORT=//p' || true)"
  for port in $(seq 8081 8099); do
    grep -qx "$port" <<<"$used" || { echo "$port"; return; }
  done
  die "no free EDGE_PORT between 8081 and 8099"
}

add() {
  [[ -n "${BRANCH:-}" ]] || die "usage: make wt NAME=<name> BRANCH=<branch> [BASE=main]"
  [[ ! -e "$dir" ]] || die "$dir already exists"
  mkdir -p "$wt_root"

  if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
    git worktree add "$dir" "$BRANCH"
  else
    git worktree add -b "$BRANCH" "$dir" "${BASE:-main}"
  fi

  local port mail_port
  port="$(free_edge_port)"
  mail_port=$((port - 8080 + 8025))
  cp "$main_root/.env.example" "$dir/.env"
  set_env "$dir/.env" COMPOSE_PROJECT_NAME "$project"
  set_env "$dir/.env" EDGE_PORT "$port"
  set_env "$dir/.env" MAILPIT_UI_PORT "$mail_port"
  set_env "$dir/.env" APP_URL "http://localhost:$port"
  set_env "$dir/.env" JWT_ISSUER "http://localhost:$port"

  # Commit author for this worktree only (optional).
  if [[ -n "${MEMBER_NAME:-}" || -n "${MEMBER_EMAIL:-}" ]]; then
    [[ -n "${MEMBER_NAME:-}" && -n "${MEMBER_EMAIL:-}" ]] \
      || die "give both MEMBER_NAME and MEMBER_EMAIL, or neither"
    git -C "$dir" config extensions.worktreeConfig true
    git -C "$dir" config --worktree user.name "$MEMBER_NAME"
    git -C "$dir" config --worktree user.email "$MEMBER_EMAIL"
  fi

  echo "✓ worktree   $dir"
  echo "  branch     $BRANCH"
  echo "  compose    $project  (edge http://localhost:$port, mailpit :$mail_port)"
  echo "  author     $(git -C "$dir" config user.name) <$(git -C "$dir" config user.email)>"
}

remove() {
  [[ -d "$dir" ]] || die "$dir does not exist"
  if command -v docker >/dev/null 2>&1; then
    docker compose -p "$project" down --remove-orphans --volumes >/dev/null 2>&1 || true
  fi
  # No --force: refuses when the worktree has uncommitted changes.
  git worktree remove "$dir"
  git worktree prune
  echo "✓ removed $dir (branch kept)"
}

case "${1:-}" in
  add) add ;;
  remove) remove ;;
  *) die "usage: wt.sh add|remove" ;;
esac
