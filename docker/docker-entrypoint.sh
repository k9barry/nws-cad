#!/bin/sh
#
# Container entrypoint: normalize ownership of the runtime-writable paths, then
# drop privileges to www-data before running the app.
#
# The images run as a non-root user in production, but the dev docker-compose
# stack bind-mounts host directories (the source tree, logs/, watch/) which
# keep host ownership and are usually not writable by uid 33. Running the chown
# here — as root, before dropping privileges via gosu — lets composer install
# and the watcher's log/heartbeat writes succeed against host-owned mounts while
# the application process itself still runs as www-data.
set -e

# Ensure each writable path exists (an empty bind mount may not contain it) and
# is owned by www-data, so composer install can populate vendor/ and the watcher
# can write logs/heartbeat without needing write access to the mount root.
for dir in /var/www/var/log /var/www/var/watch /var/www/vendor; do
    mkdir -p "$dir" 2>/dev/null || true
    chown -R www-data:www-data "$dir" 2>/dev/null || true
done

exec gosu www-data "$@"
