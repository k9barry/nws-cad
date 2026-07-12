#!/usr/bin/env bash
#
# deploy.sh — self-service / CI deploy of the nws-cad stack to a target release.
#
# Automates docs/deployment/RUNBOOK.md end to end:
#   pre-flight -> DB backup -> checkout target tag -> build -> DB up + wait ->
#   apply migrations -> bring stack up -> verify health -> roll back on failure.
#
# Idempotent: re-running against the currently-deployed commit is a no-op
# unless FORCE=1. Safe to run by hand on the host, or from the CD workflow
# (.github/workflows/deploy.yml) on a self-hosted runner operating the on-host
# clone (DEPLOY_DIR).
#
# Usage:
#   scripts/deploy.sh [TARGET_REF]        # default: latest v* tag on origin
#   scripts/deploy.sh v2.0.4
#   FORCE=1 scripts/deploy.sh v2.0.4      # redeploy even if already on it
#   SKIP_BACKUP=1 scripts/deploy.sh       # skip the pre-deploy DB backup
#   SKIP_MIGRATIONS=1 scripts/deploy.sh   # bring the stack up without migrating
#
# Env knobs: FORCE, SKIP_BACKUP, SKIP_MIGRATIONS, HEALTH_TIMEOUT (default 180s).

set -euo pipefail

# --- Re-exec from a private copy --------------------------------------------
# A mid-run `git checkout` rewrites this file in the working tree; bash reads
# scripts lazily, so replacing $0 under a running shell can corrupt execution.
# Copy ourselves to a tempfile and exec that, immune to the checkout below.
if [ -z "${_DEPLOY_REEXEC:-}" ]; then
    _orig="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
    _copy="$(mktemp "${TMPDIR:-/tmp}/nws-deploy.XXXXXX.sh")"
    cp "$_orig" "$_copy"
    export _DEPLOY_REEXEC=1 _DEPLOY_ORIG="$_orig" _DEPLOY_COPY="$_copy"
    exec bash "$_copy" "$@"
fi

# Repo root is the parent of scripts/ (resolved from the ORIGINAL path, not the
# tempfile copy), so compose finds docker-compose.yml/.env.
REPO_ROOT="$(cd "$(dirname "${_DEPLOY_ORIG:-${BASH_SOURCE[0]}}")/.." && pwd)"
cd "$REPO_ROOT"

# --- Output helpers ---------------------------------------------------------
if [ -t 1 ]; then
    GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; RED='\033[0;31m'; NC='\033[0m'
else
    GREEN=''; YELLOW=''; CYAN=''; RED=''; NC=''
fi
say()  { printf '%b%s%b\n' "$CYAN"   "$*" "$NC"; }
ok()   { printf '%b%s%b\n' "$GREEN"  "$*" "$NC"; }
warn() { printf '%b%s%b\n' "$YELLOW" "$*" "$NC" >&2; }
err()  { printf '%b%s%b\n' "$RED"    "$*" "$NC" >&2; }

# --- .env reader (last match wins; strips surrounding quotes) ----------------
read_env() {
    local key="$1" def="${2:-}" val=""
    if [ -f .env ]; then
        val="$(grep -E "^[[:space:]]*${key}=" .env | tail -n1 | cut -d= -f2- \
            | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
                  -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" || true)"
    fi
    printf '%s' "${val:-$def}"
}

# --- Resolve DB profile / compose command -----------------------------------
DB_TYPE="${DB_TYPE:-$(read_env DB_TYPE mysql)}"
case "$DB_TYPE" in
    mysql|pgsql) ;;
    *) err "DB_TYPE must be 'mysql' or 'pgsql' (got '$DB_TYPE')"; exit 1 ;;
esac
export COMPOSE_PROFILES="$DB_TYPE"
if [ "$DB_TYPE" = pgsql ]; then DB_SVC=postgres; else DB_SVC=mysql; fi

if docker compose version >/dev/null 2>&1; then
    DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    DC=(docker-compose)
else
    err "neither 'docker compose' nor 'docker-compose' is available."; exit 1
fi

# --- Config -----------------------------------------------------------------
TARGET_REF="${1:-}"
FORCE="${FORCE:-0}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
SKIP_MIGRATIONS="${SKIP_MIGRATIONS:-0}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"
BACKUP_DIR="$(read_env BACKUP_DIR ./var/backups)"

DEPLOY_STARTED=0
DEPLOY_OK=0

# --- Functions --------------------------------------------------------------
wait_for_db() {
    local deadline=$(( $(date +%s) + 120 ))
    if [ "$DB_TYPE" = mysql ]; then
        local rootpw; rootpw="$(read_env MYSQL_ROOT_PASSWORD root_password)"
        until docker exec nws-cad-mysql mysqladmin ping -u root -p"$rootpw" --silent >/dev/null 2>&1; do
            [ "$(date +%s)" -lt "$deadline" ] || { err "MySQL not ready after 120s"; return 1; }
            echo "  waiting for mysql..."; sleep 2
        done
    else
        local pguser; pguser="$(read_env POSTGRES_USER nws_user)"
        until docker exec nws-cad-postgres pg_isready -U "$pguser" >/dev/null 2>&1; do
            [ "$(date +%s)" -lt "$deadline" ] || { err "Postgres not ready after 120s"; return 1; }
            echo "  waiting for postgres..."; sleep 2
        done
    fi
    ok "Database is accepting connections."
}

apply_migrations() {
    local dir="database/migrations" f
    [ -d "$dir" ] || { warn "No $dir directory; skipping migrations."; return 0; }
    if [ "$DB_TYPE" = mysql ]; then
        local rootpw db
        rootpw="$(read_env MYSQL_ROOT_PASSWORD root_password)"
        db="$(read_env MYSQL_DATABASE nws_cad)"
        # MySQL migrations are *.sql, excluding the *.pgsql.sql Postgres variants.
        for f in $(ls "$dir"/*.sql 2>/dev/null | grep -v '\.pgsql\.sql$' | sort); do
            say "  migration: $f"
            docker exec -i nws-cad-mysql mysql -u root -p"$rootpw" "$db" < "$f" \
                || { err "Migration failed: $f"; return 1; }
        done
    else
        local pguser pgdb
        pguser="$(read_env POSTGRES_USER nws_user)"
        pgdb="$(read_env POSTGRES_DB nws_cad)"
        for f in $(ls "$dir"/*.pgsql.sql 2>/dev/null | sort); do
            say "  migration: $f"
            docker exec -i nws-cad-postgres psql -U "$pguser" -d "$pgdb" -v ON_ERROR_STOP=1 -f - < "$f" \
                || { err "Migration failed: $f"; return 1; }
        done
    fi
    ok "Migrations applied."
}

verify_health() {
    local deadline=$(( $(date +%s) + HEALTH_TIMEOUT ))
    # Hit the API from inside its own container so this is independent of the
    # host port binding (BIND_ADDR) and reverse proxy.
    until docker exec nws-cad-api curl -fsS -o /dev/null http://localhost:8080/api/health >/dev/null 2>&1; do
        [ "$(date +%s)" -lt "$deadline" ] || { err "API health did not pass within ${HEALTH_TIMEOUT}s"; return 1; }
        echo "  waiting for API health..."; sleep 3
    done
    ok "API health OK."
    local unhealthy
    unhealthy="$(docker ps --filter health=unhealthy --format '{{.Names}}' | grep '^nws-cad-' || true)"
    [ -z "$unhealthy" ] || warn "Containers reporting unhealthy: $unhealthy"
}

rollback() {
    err "Deploy failed — rolling back to ${PREV_DESC:-$PREV_SHA} ($PREV_SHA)."
    git checkout --force "$PREV_SHA" >/dev/null 2>&1 || warn "  git rollback checkout failed."
    "${DC[@]}" build >/dev/null 2>&1                 || warn "  rollback build failed."
    "${DC[@]}" up -d --remove-orphans >/dev/null 2>&1 || warn "  rollback 'up' failed."
    err "Rolled back to ${PREV_DESC:-$PREV_SHA}. Investigate before retrying."
    err "Pre-deploy DB backup (if taken) is under: $BACKUP_DIR"
}

cleanup() {
    local rc=$?
    set +e
    if [ "$DEPLOY_STARTED" = "1" ] && [ "$DEPLOY_OK" != "1" ]; then
        rollback
    fi
    [ -n "${_DEPLOY_COPY:-}" ] && rm -f "$_DEPLOY_COPY"
    exit "$rc"
}
trap cleanup EXIT

# --- Pre-flight -------------------------------------------------------------
command -v git >/dev/null    || { err "git not found"; exit 1; }
command -v docker >/dev/null || { err "docker not found"; exit 1; }
[ -f .env ]               || { err ".env not found in $REPO_ROOT"; exit 1; }
[ -f docker-compose.yml ] || { err "docker-compose.yml not found in $REPO_ROOT"; exit 1; }
[ -d .git ]               || { err "$REPO_ROOT is not a git clone"; exit 1; }

# --- Resolve target ---------------------------------------------------------
say "Fetching tags from origin..."
git fetch --tags --prune origin

if [ -z "$TARGET_REF" ]; then
    TARGET_REF="$(git describe --tags --abbrev=0 --match 'v*' 2>/dev/null || true)"
    [ -n "$TARGET_REF" ] || { err "No target ref given and no v* tag found."; exit 1; }
fi
git rev-parse --verify --quiet "${TARGET_REF}^{commit}" >/dev/null \
    || { err "Ref '$TARGET_REF' not found."; exit 1; }

PREV_SHA="$(git rev-parse --short HEAD)"
PREV_DESC="$(git describe --tags --always 2>/dev/null || echo "$PREV_SHA")"
CURRENT_FULL="$(git rev-parse HEAD)"
TARGET_FULL="$(git rev-parse "${TARGET_REF}^{commit}")"
TARGET_SHA="$(git rev-parse --short "$TARGET_FULL")"

if [ "$CURRENT_FULL" = "$TARGET_FULL" ] && [ "$FORCE" != "1" ]; then
    ok "Already deployed at $TARGET_REF ($TARGET_SHA). Nothing to do (set FORCE=1 to redeploy)."
    exit 0
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    warn "Working tree has uncommitted changes; checkout may fail. Commit/stash on the host if so."
fi

say "Deploying ${PREV_DESC} (${PREV_SHA}) -> ${TARGET_REF} (${TARGET_SHA})   [DB=${DB_TYPE}]"

# --- Backup (only if a DB container is already running) ---------------------
if [ "$SKIP_BACKUP" != "1" ]; then
    if docker ps --format '{{.Names}}' | grep -q "^nws-cad-${DB_SVC}$"; then
        say "Backing up database before deploy..."
        if [ -x scripts/backup-database.sh ]; then
            scripts/backup-database.sh || { err "Backup failed; aborting deploy (nothing changed yet)."; exit 1; }
        else
            warn "scripts/backup-database.sh missing/not executable; skipping backup."
        fi
    else
        warn "DB container nws-cad-${DB_SVC} not running; skipping backup (fresh install?)."
    fi
fi

# --- Deploy (rollback-guarded from here) ------------------------------------
DEPLOY_STARTED=1

say "Checking out ${TARGET_REF}..."
git checkout --force "$TARGET_REF"

say "Building images (with --pull)..."
"${DC[@]}" build --pull

say "Starting database (${DB_SVC}) and waiting for readiness..."
"${DC[@]}" up -d "$DB_SVC"
wait_for_db

if [ "$SKIP_MIGRATIONS" != "1" ]; then
    say "Applying migrations..."
    apply_migrations
else
    warn "SKIP_MIGRATIONS=1 — not applying migrations."
fi

say "Bringing up the full stack..."
"${DC[@]}" up -d --force-recreate --remove-orphans

say "Verifying health (timeout ${HEALTH_TIMEOUT}s)..."
verify_health

DEPLOY_OK=1
ok "Deploy complete — now running ${TARGET_REF} (${TARGET_SHA})."
"${DC[@]}" ps
