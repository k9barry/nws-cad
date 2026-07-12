# Deployment — Reverse proxy + loopback binding

The NWS CAD HTTP surface is designed to sit behind a reverse proxy that
handles authentication and TLS termination. The application binds to
`127.0.0.1:8080` (via `docker-compose.yml`) and refuses requests whose
`REMOTE_ADDR` is not in the configured trusted-proxy CIDR list.

## Threat model

1. **Direct API access bypassing auth.** Mitigated by the loopback binding
   plus `TrustedProxy::guard()` (`TRUSTED_PROXY_CIDRS` defaults to
   `127.0.0.1/32,::1/128`).
2. **Identity-header spoofing.** Identity is read from a configurable header
   (default `X-Auth-User`) only after the trust-proxy check passes. The
   proxy must strip any inbound `X-Auth-User` from clients before its own
   `auth_basic` directive runs.
3. **Open CORS.** `ALLOWED_ORIGINS` defaults to empty (same-origin only).

## Environment variables introduced by this workstream

| Var | Default | Purpose |
|---|---|---|
| `ALLOWED_ORIGINS` | (empty) | Comma-separated CORS allowlist |
| `TRUSTED_PROXY_CIDRS` | `127.0.0.1/32,::1/128` | Comma-separated CIDR(s) trusted to inject identity headers |
| `PROXY_IDENTITY_HEADER` | `X-Auth-User` | Header read for the operator's username |
| `NOTIFICATION_BASE_URL_ALLOWLIST` | (empty) | Comma-separated hostnames permitted as channel base URLs |
| `NOTIFICATION_ALLOW_HTTP_PRIVATE` | `false` | Set `true` to permit `http://` for RFC1918 + loopback hosts only (testing) |
| `BIND_ADDR` | `0.0.0.0` | Host interface the published ports (API, DB, Dozzle) bind to. Set `127.0.0.1` in production to publish loopback-only so only a co-located reverse proxy can reach them. |

## Self-healing (autoheal)

The compose stack runs a `willfarrell/autoheal` sidecar that restarts any
container labeled `autoheal=true` (`app`, `api`, `mysql`, `postgres`) when
Docker reports its healthcheck **unhealthy**. `restart: unless-stopped` only
reacts to a process *exit*; autoheal covers the case where a process lingers
but its healthcheck fails — a wedged watcher whose heartbeat goes stale, or a
DB whose ping fails while the daemon hangs. Tunables: `AUTOHEAL_INTERVAL`
(poll seconds), `AUTOHEAL_START_PERIOD` (post-start grace), and
`AUTOHEAL_DEFAULT_STOP_TIMEOUT` (clean-stop window before SIGKILL).

## Continuous deployment

`scripts/deploy.sh` automates the runbook (backup → checkout tag → build →
migrate → up → verify → rollback-on-failure) and can be run by hand on the host
or driven by GitHub Actions. See **[CD.md](CD.md)** for the push-to-deploy setup
(self-hosted runner + a `production` approval gate).

## Sample configurations

See `caddy.example` and `nginx.example`. Both:

- Terminate TLS.
- Run HTTP Basic auth against a single shared credential set.
- Strip any inbound `X-Auth-User` header BEFORE auth runs.
- After successful auth, set `X-Auth-User` to the authenticated username.
- Reverse-proxy to `127.0.0.1:8080`.

## Manual verification

After deploying:

```bash
# Loopback: direct curl from the host succeeds (REMOTE_ADDR = 127.0.0.1)
curl -i http://127.0.0.1:8080/api/health
# → 200 OK; response carries CSP, X-Frame-Options, Referrer-Policy, etc.

# Direct curl from another machine — with loopback binding this should
# fail to even connect. If the operator diverged from compose and bound
# publicly, the trust-proxy guard returns 403.
curl -i http://<your-host>:8080/api/health
# → connection refused, OR 403 "Direct access not permitted"

# Through the proxy with bad credentials
curl -i -u baduser:badpass https://<your-host>/api/health
# → 401 (from proxy)

# Through the proxy with valid credentials
curl -i -u k9barry:<password> https://<your-host>/api/health
# → 200; row in notification_send_log.actor = "k9barry" on subsequent writes

# Forged identity header — proxy must strip it
curl -i -u k9barry:<password> -H 'X-Auth-User: admin' https://<your-host>/api/notifications/channels/ntfy/enable
# → 200; last_updated_actor must be "k9barry" (proxy-set), NOT "admin" (client-set)
```

If the last check shows `admin` as the actor, the proxy configuration is
not stripping the inbound header before basicauth runs. Fix the proxy
config; this is the most important verification.
