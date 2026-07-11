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

- [ ] Start just the database:
  ```bash
  docker compose --profile "$DB_TYPE" up -d "$DB_TYPE"
  # wait for health:
  docker compose exec "$DB_TYPE" sh -c 'exit 0'   # container up; give it ~15s to become healthy
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

The app binds to `127.0.0.1:8080` and rejects requests whose `REMOTE_ADDR` is
outside `TRUSTED_PROXY_CIDRS`. Put Caddy or nginx in front (samples in
`docs/deployment/caddy.example` / `nginx.example`). The proxy must:
- Terminate TLS and run HTTP Basic auth.
- **Strip any inbound `X-Auth-User`** before auth, then set it to the authenticated user.
- Reverse-proxy to `127.0.0.1:8080`.

## 7. Verify

- [ ] API health (through the proxy, with valid creds):
  ```bash
  curl -fsS -u <user>:<pass> https://<host>/api/health   # → {"success":true,...,"db":"ok"}
  ```
- [ ] Direct loopback still guarded:
  ```bash
  curl -i http://127.0.0.1:8080/api/health                # 200 from host; connection refused / 403 from elsewhere
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
- Keep `WATCHER_INTERVAL` ≤ 30s (default 5s) or the 60s heartbeat healthcheck flaps.
- `COMPOSE_PROFILES` must match `DB_TYPE`, or no database starts and `app`/`api` block on `service_healthy`.
- This runbook supersedes the need for draft PR #38 (auto-migrate on startup): Step 4 applies the same migrations manually. If you prefer self-healing startup instead, that PR is the place to revive it.
