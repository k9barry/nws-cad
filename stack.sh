#!/usr/bin/env bash
#
# stack.sh — manage the nws-cad docker stack with the correct DB profile.
#
# CLAUDE.md note: `docker compose up -d` without selecting a profile starts
# *no* database, and `app`/`api` then block on `service_healthy`. This wrapper
# reads DB_TYPE from .env (mysql|pgsql) and sets COMPOSE_PROFILES accordingly,
# so you never have to remember.
#
# Usage: ./stack.sh <command>
#
# Run with no args (or -h / --help) for the command list.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

# --- Resolve DB_TYPE -> COMPOSE_PROFILES ------------------------------------

DB_TYPE_FROM_ENV=""
if [ -f .env ]; then
    # Last DB_TYPE= line wins; strip optional surrounding quotes.
    DB_TYPE_FROM_ENV="$(
        grep -E '^[[:space:]]*DB_TYPE=' .env \
            | tail -n1 \
            | cut -d= -f2- \
            | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
                  -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" \
            || true
    )"
fi
DB_TYPE="${DB_TYPE:-${DB_TYPE_FROM_ENV:-mysql}}"

case "$DB_TYPE" in
    mysql|pgsql) ;;
    *)
        echo "ERROR: DB_TYPE must be 'mysql' or 'pgsql' (got '$DB_TYPE')" >&2
        echo "       Set DB_TYPE in .env, or export it before running this script." >&2
        exit 1
        ;;
esac

export COMPOSE_PROFILES="$DB_TYPE"

# --- Pick docker compose vs docker-compose ----------------------------------

if docker compose version >/dev/null 2>&1; then
    DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    DC=(docker-compose)
else
    echo "ERROR: neither 'docker compose' nor 'docker-compose' is available." >&2
    exit 1
fi

# --- Color helpers ----------------------------------------------------------

if [ -t 1 ]; then
    GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
else
    GREEN=''; YELLOW=''; CYAN=''; NC=''
fi
say()  { printf '%b%s%b\n' "$CYAN"   "$*" "$NC"; }
ok()   { printf '%b%s%b\n' "$GREEN"  "$*" "$NC"; }
warn() { printf '%b%s%b\n' "$YELLOW" "$*" "$NC" >&2; }

# --- Commands ---------------------------------------------------------------

usage() {
    cat <<EOF
stack.sh — manage the nws-cad docker stack

Usage: $(basename "$0") <command>

Commands:
  start       Bring the stack up in the background.
  stop        Bring the stack down (data volumes are preserved).
  restart     stop + start.
  pull        Pull the latest images for every service.
  rebuild     Rebuild locally-built images (with --pull) and recreate
              containers. Use this after changing Dockerfile or compose.
  status      Show container status (docker compose ps).
  logs [svc]  Tail logs from all services, or one named service.
              Ctrl-C to exit.
  health      Print healthcheck status for every container.

Profile: COMPOSE_PROFILES=$COMPOSE_PROFILES   (driven by DB_TYPE in .env)

Tips:
  - Switching DB engines? Change DB_TYPE in .env, then \`./stack.sh restart\`.
    --remove-orphans will clean up the previously-active DB container.
  - To override the DB engine for one invocation: DB_TYPE=pgsql ./stack.sh start
EOF
}

cmd_start() {
    say "Starting stack (profile: $COMPOSE_PROFILES)..."
    "${DC[@]}" up -d --remove-orphans
    "${DC[@]}" ps
    ok "Stack is up."
}

cmd_stop() {
    say "Stopping stack..."
    "${DC[@]}" down --remove-orphans
    ok "Stack is down."
}

cmd_restart() {
    cmd_stop
    cmd_start
}

cmd_pull() {
    say "Pulling latest images..."
    "${DC[@]}" pull
    ok "Pull complete. Run './stack.sh restart' (or 'rebuild') to apply."
}

cmd_rebuild() {
    say "Rebuilding local images (with --pull) and recreating containers..."
    "${DC[@]}" build --pull
    "${DC[@]}" up -d --force-recreate --remove-orphans
    "${DC[@]}" ps
    ok "Rebuild complete."
}

cmd_status() {
    "${DC[@]}" ps
}

cmd_logs() {
    local svc="${1:-}"
    if [ -n "$svc" ]; then
        "${DC[@]}" logs -f --tail=200 "$svc"
    else
        "${DC[@]}" logs -f --tail=100
    fi
}

cmd_health() {
    local names
    names="$("${DC[@]}" ps --format '{{.Name}}')"
    if [ -z "$names" ]; then
        warn "No containers are running."
        return 0
    fi
    printf '%-30s %s\n' 'CONTAINER' 'HEALTH'
    while IFS= read -r name; do
        [ -z "$name" ] && continue
        local h
        h="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}n/a{{end}}' "$name" 2>/dev/null || echo '?')"
        printf '%-30s %s\n' "$name" "$h"
    done <<<"$names"
}

# --- Dispatch ---------------------------------------------------------------

cmd="${1:-}"
shift || true
case "$cmd" in
    start)   cmd_start "$@" ;;
    stop)    cmd_stop "$@" ;;
    restart) cmd_restart "$@" ;;
    pull)    cmd_pull "$@" ;;
    rebuild) cmd_rebuild "$@" ;;
    status|ps) cmd_status "$@" ;;
    logs)    cmd_logs "$@" ;;
    health)  cmd_health "$@" ;;
    -h|--help|help)
        usage
        ;;
    "")
        usage
        exit 1
        ;;
    *)
        echo "Unknown command: $cmd" >&2
        echo
        usage
        exit 1
        ;;
esac
