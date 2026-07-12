# Deploy runbook — v2.0.2

Step-by-step deployment of the NWS CAD stack (file watcher + API + dashboard)
behind a reverse proxy. Written for an operator running it on the target host;
run each command yourself and confirm the checks before moving on.

> **Scope of this release (since v2.0.0):**
> - v2.0.1 — importer decomposition (`AegisXmlParser` → `NwsCad\Import\*`), behavior-preserving.
> - v2.0.2 — DB/API performance: composite indexes, N+1 consolidation, outbox claim, targeted cache invalidation.
>
> Both are behavior-preserving. The schema changes ship as **idempotent migrations** (Step 4), which also resolve **issue #37** (the `last_updated_actor` "Column not found" error when enabling a notification channel) on deployments that predate that column.

---

## 0. Pre-flight

- [ ] Decide the driver: `DB_TYPE` is `mysql` or `pgsql`. All commands below assume you export it first:
  ```bash
  export DB_TYPE=mysql          # or: pgsql
  export COMPOSE_PROFILES=$DB_TYPE
  # Compose *service* name for the DB (differs from the profile for Postgres):
  export DB_SVC=$([ "$DB_TYPE" = pgsql ] && echo postgres || echo mysql)
  ```
- [ ] Confirm `.env` exists and is complete (never commit it). Minimum keys — see `docs/deployment/README.md` for the security-related ones:
  ```
  DB_TYPE=mysql
  COMPOSE_PROFILES=mysql
  MYSQL_ROOT_PASSWORD=... MYSQL_DATABASE=nws_cad MYSQL_USER=nws_user MYSQL_PASSWORD=...
  # or for pgsql: POSTGRES_DB=nws_cad POSTGRES_USER=nws_user POSTGRES_PASSWORD=...
  TZ=UTC
  # CIFS watch-folder share (production):
  CIFS_USERNAME=... CIFS_PASSWORD=... CIFS_DOMAIN=... SHARED_FOLDER_PATH=//host/share
  # Reverse-proxy trust (see Step 6 / docs/deployment/README.md):
  TRUSTED_PROXY_CIDRS=127.0.0.1/32,::1/128
  PROXY_IDENTITY_HEADER=X-Auth-User
  ALLOWED_ORIGINS=
  ```

## 1. Back up the database (existing deployments)

- [ ] **MySQL**
  ```bash
  docker compose exec -T mysql \
    mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
    > backup-$(date +%Y%m%d-%H%M%S).sql
  ```
- [ ] **PostgreSQL**
  ```bash
  docker compose exec -T postgres \
    pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" \
    > backup-$(date +%Y%m%d-%H%M%S).sql
  ```
See `docs/BACKUP_GUIDE.md` for the full backup/restore procedure.

## 2. Get the release

- [ ] Fetch v2.0.2:
  ```bash
  git fetch --tags origin && git checkout v2.0.2
  ```

## 3. Build images

- [ ] ```bash
  docker compose --profile "$DB_TYPE" build
  ```

## 4. Apply pending migrations (idempotent — resolves #37)

The migration files are safe to re-run; applying all of them brings any older
schema fully up to date, including `notification_channels.last_updated_actor`
(#37) and the v2.0.2 indexes / `notification_outbox.next_attempt_at` change.
Bring the DB up first (without the app), then apply:

> **⚠ Existing deployments — relocate the database data directory first (one-time).**
> v2.0.x stores the DB datadir at `${NWS_VAR_DIR:-./var}/data/$DB_SVC` (i.e. `./var/data/mysql`
> or `./var/data/postgres`). Deployments created before this used `./data/$DB_SVC`. If yours
> still has data at the old path, the DB container will start on an **empty** directory, re-run
> `init.sql`, and come up blank — your real data is left untouched at `./data/$DB_SVC`, but the
> app gets pointed at nothing. With the stack **stopped**, move the datadir into place. This is a
> no-op on fresh installs and already-migrated hosts (no `./data/$DB_SVC`), and it aborts rather
> than overwrite a destination that already holds a larger (real) datadir:
>
> ```bash
> docker compose --profile "$DB_TYPE" down          # datadir can't move while the DB holds it
> TS=$(date +%Y%m%d-%H%M%S)
> DEST_BASE="${NWS_VAR_DIR:-./var}"                  # resolve the SAME base docker-compose.yml mounts
> mkdir -p "$DEST_BASE/data"
> docker run --rm -e TS="$TS" -e SVC="$DB_SVC" \
>   -v "$PWD:/proj" -v "$(cd "$DEST_BASE/data" && pwd):/new" alpine sh -c '
>   if [ ! -d "/proj/data/$SVC" ]; then echo "no ./data/$SVC — nothing to relocate"; exit 0; fi
>   srcsz=$(du -sk "/proj/data/$SVC" | cut -f1)
>   if [ -d "/new/$SVC" ] && [ -n "$(ls -A "/new/$SVC" 2>/dev/null)" ]; then
>     dstsz=$(du -sk "/new/$SVC" | cut -f1)
>     if [ "$dstsz" -ge "$srcsz" ]; then echo "ABORT: destination $SVC (${dstsz}KiB) is non-empty and >= source (${srcsz}KiB) — inspect manually"; exit 1; fi
>   fi
>   if [ -e "/new/$SVC" ]; then mv "/new/$SVC" "/new/$SVC.freshinit-$TS"; fi
>   mv "/proj/data/$SVC" "/new/$SVC"
>   echo "relocated ./data/$SVC -> $DEST_BASE/data/$SVC ($(du -sk /new/$SVC | cut -f1)KiB)"
> '
> ```
> Resolving `$DEST_BASE` from `NWS_VAR_DIR` (via `cd … && pwd`) keeps the destination identical to
> the compose bind even when it's overridden to an absolute path. The size guard uses KiB and only
> aborts when the destination is **non-empty and ≥ the source**, so a small fresh init never
> false-aborts. On a single filesystem you can skip the container and just
> `mv ./data/$DB_SVC "$DEST_BASE/data/$DB_SVC"` for an instant rename.

- [ ] Start just the database (service name is `$DB_SVC` — `mysql` or `postgres`) and wait until it actually accepts connections:
  ```bash
  docker compose --profile "$DB_TYPE" up -d "$DB_SVC"

  # MySQL — wait for real readiness:
  until docker compose exec -T mysql \
    mysqladmin ping -u root -p"$MYSQL_ROOT_PASSWORD" --silent >/dev/null 2>&1; do
    echo "waiting for mysql..."; sleep 2; done

  # PostgreSQL — wait for real readiness:
  until docker compose exec -T postgres \
    pg_isready -U "$POSTGRES_USER" >/dev/null 2>&1; do
    echo "waiting for postgres..."; sleep 2; done
  ```
- [ ] **MySQL** — apply every `*.sql` migration in order (the mysql client handles the `DELIMITER` blocks):
  ```bash
  for f in $(ls database/migrations/*.sql | grep -v '\.pgsql\.sql$' | sort); do
    echo "==> $f"
    docker compose exec -T mysql \
      mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < "$f" || { echo "FAILED: $f"; break; }
  done
  ```
- [ ] **PostgreSQL** — apply every `*.pgsql.sql` migration in order:
  ```bash
  for f in $(ls database/migrations/*.pgsql.sql | sort); do
    echo "==> $f"
    docker compose exec -T postgres \
      psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -f - < "$f" || { echo "FAILED: $f"; break; }
  done
  ```
- [ ] Spot-check the #37 column exists:
  ```bash
  # MySQL
  docker compose exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" \
    -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME='notification_channels' AND COLUMN_NAME='last_updated_actor';"
  ```

> Fresh installs skip Step 4 — `database/mysql/init.sql` / `postgres/init.sql` already contain the full current schema.

## 5. Start the stack

- [ ] ```bash
  docker compose --profile "$DB_TYPE" up -d
  ```
- [ ] Watch it settle:
  ```bash
  docker compose ps
  docker compose logs -f app | head -n 40   # look for "File Watcher Started"
  ```

## 6. Reverse proxy (TLS + auth)

By default `docker-compose.yml` publishes the API on `${API_PORT:-8080}:8080`,
which binds **all** host interfaces — access is not restricted at the socket.
Enforcement is in the app: `TrustedProxy::guard()` rejects any request whose
`REMOTE_ADDR` is outside `TRUSTED_PROXY_CIDRS` (default `127.0.0.1/32,::1/128`).
For defense in depth, also restrict the published ports to loopback by setting
`BIND_ADDR=127.0.0.1` in `.env` — this moves the API, DB, and Dozzle ports to
`127.0.0.1` only, so just the co-located proxy (and SSH tunnels) can reach
them. Put Caddy or nginx in front (samples in
`docs/deployment/caddy.example` / `nginx.example`). The proxy must:
- Terminate TLS and run HTTP Basic auth.
- **Strip any inbound `X-Auth-User`** before auth, then set it to the authenticated user.
- Reverse-proxy to `127.0.0.1:8080`.

## 7. Verify

- [ ] API health (through the proxy, with valid creds):
  ```bash
  curl -fsS -u <user>:<pass> https://<host>/api/health   # → {"success":true,...,"db":"ok"}
  ```
- [ ] Access control holds:
  ```bash
  curl -i http://127.0.0.1:8080/api/health   # from the host: 200
  # From another machine: 403 "Direct access not permitted" (trust-proxy guard),
  # or connection refused if you restricted the port to 127.0.0.1 in Step 6.
  ```
- [ ] Container health: `docker compose ps` shows `app` and `api` **healthy** (watcher heartbeat < 60s; `/api/health` 200).
- [ ] **#37 regression check** — enable a Pushover/ntfy channel via the dashboard or:
  ```bash
  php bin/notifications.php enable pushover
  # Must NOT return: "Column not found: 1054 Unknown column 'last_updated_actor'"
  ```
- [ ] Drop an XML into the watch folder and confirm it moves to `var/watch/processed/` and a `calls` row appears.

## 8. Rollback

- [ ] `git checkout v2.0.0 && docker compose --profile "$DB_TYPE" up -d --build`.
- [ ] The migrations are **additive and idempotent** (new indexes, a nullable→NOT-NULL column with a default, new columns/tables). They don't need to be reversed to run the older app; if you must, restore the Step-1 backup.

---

### Notes
- **Self-healing:** the stack runs a `willfarrell/autoheal` sidecar that restarts any
  container whose healthcheck flips to **unhealthy** (`app`, `api`, `mysql`, `postgres`
  are labeled `autoheal=true`). This complements `restart: unless-stopped`, which only
  reacts to a process *exit*. See `docs/deployment/README.md`.
- Keep `WATCHER_INTERVAL` ≤ 30s (default 5s) or the 60s heartbeat healthcheck flaps.
- `COMPOSE_PROFILES` must match `DB_TYPE`, or no database starts and `app`/`api` block on `service_healthy`.
- This runbook supersedes the need for draft PR #38 (auto-migrate on startup): Step 4 applies the same migrations manually. If you prefer self-healing startup instead, that PR is the place to revive it.
