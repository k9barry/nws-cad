#!/usr/bin/env bash
#
# migrate-to-var.sh — one-time migration for the var/ runtime layout.
#
# Moves the legacy top-level runtime directories into var/ so an existing
# deployment keeps its database data, logs, watch queue, and backups after
# upgrading to the release that relocated runtime state:
#
#   logs/            -> var/log/
#   watch/           -> var/watch/
#   data/mysql/      -> var/data/mysql/
#   data/postgres/   -> var/data/postgres/
#   backups/         -> var/backups/
#
# Run it from a stopped stack. It never overwrites an existing var/ target.
set -euo pipefail

# Run from the repository root regardless of where this script is invoked from.
REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$REPO_ROOT"

echo "==> nws-cad var/ layout migration"
echo "    Repository root: $REPO_ROOT"

if docker compose ps --quiet 2>/dev/null | grep -q .; then
    echo "ERROR: the stack appears to be running. Stop it first:" >&2
    echo "       docker compose down" >&2
    exit 1
fi

# legacy source  ->  var/ target
move() {
    local src="$1" dst="$2"
    if [ ! -e "$src" ]; then
        echo "    skip: $src (not present)"
        return
    fi
    mkdir -p "$(dirname "$dst")"
    if [ -e "$dst" ] && [ -n "$(ls -A "$dst" 2>/dev/null | grep -v '^\.gitkeep$' || true)" ]; then
        echo "ERROR: target $dst already exists and is not empty; refusing to overwrite." >&2
        exit 1
    fi
    rm -rf "$dst"
    echo "    move: $src -> $dst"
    mv "$src" "$dst"
}

move logs           var/log
move watch          var/watch
move data/mysql     var/data/mysql
move data/postgres  var/data/postgres
move backups        var/backups

# Restore the tracked placeholders so the empty dirs survive in git.
for d in var/log var/watch var/data/mysql var/data/postgres var/backups; do
    mkdir -p "$d"
    [ -e "$d/.gitkeep" ] || : > "$d/.gitkeep"
done

echo "==> Migration complete. Start the stack with: docker compose up -d"
echo "    (Or set NWS_VAR_DIR to point host mounts elsewhere.)"
