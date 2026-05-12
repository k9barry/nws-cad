# Security hardening — design

**Date:** 2026-05-12
**Scope:** First slice of the codebase audit dated 2026-05-12. Covers audit
findings S1, S2, S3, S6, S7, plus the dead-code removals enabled by them
(`Security/RateLimiter`, the `Dashboard\Router` import in `public/index.php`,
the hardcoded CORS block in `public/api.php`).
**Out of scope:** notification outbox / async worker (workstream 2),
ChannelRegistry refactor (workstream 3), CSP tightening beyond wiring up the
existing policy, dashboard login UI.

---

## Problem statement

The HTTP surface — both `public/api.php` (REST) and `public/index.php`
(dashboard) — has three structural defects:

1. **Write endpoints are unauthenticated.** Any caller that can reach the API
   host can enable, disable, test, or wipe notification channels and audit
   logs.
2. **CORS is hardcoded to `*`** in `public/api.php:22-24`, shadowing the
   carefully-written origin allowlist in `Security/SecurityHeaders` (which is
   never called from any entry point).
3. **`Security/SecurityHeaders`, `Security/RateLimiter`, and
   `Security/InputValidator` are defined but unused.** CSP, X-Frame-Options,
   HSTS, Referrer-Policy, Permissions-Policy are not on any response. Input
   validation helpers exist for URLs and emails but nothing calls them. Rate
   limiting is documented and never wired.

Two additional channel-level bugs were found and are folded into this slice
because they share the same touch points:

4. **`NtfyChannel` blindly trusts `NTFY_AUTH_TOKEN`** — newline-laced values
   would produce CRLF-injected headers and break the wire protocol.
5. **`NotificationsController::enable()` accepts arbitrary `{TYPE}_BASE_URL`**
   without scheme, format, or allowlist checks — a server-side request
   forgery vector if the operator's environment is ever attacker-influenced.

## Goals

- Authentication and TLS pushed to a reverse proxy (Caddy or nginx) chosen by
  the operator; the app trusts the proxy and reads operator identity from a
  configured header for audit logging.
- Defense in depth: the app refuses to honour identity headers unless
  `REMOTE_ADDR` is in a configured trusted-proxy CIDR. The compose stack
  binds the API to `127.0.0.1` so direct access from outside the host is
  impossible by default.
- Standard browser security headers (CSP, HSTS when HTTPS, X-Frame-Options,
  Referrer-Policy, Permissions-Policy, X-Content-Type-Options) on every
  response.
- CORS allowlist sourced from `ALLOWED_ORIGINS` env var; empty allowlist =
  same-origin only.
- `enable()` validates `base_url` (https-only, allowlist, no CR/LF) before
  writing to the database.
- `NtfyChannel` rejects CR/LF in its auth token and auto-prefixes `Bearer `
  when missing.
- Audit columns (`notification_channels.last_updated_actor`,
  `notification_send_log.actor`) record which operator made each change.

## Non-goals

- A login UI in the dashboard. The dashboard inherits the proxy's session.
- Per-user roles or RBAC.
- Replacing the audit-log architecture (covered by workstream 2 — outbox).
- CSP tightening beyond removing `'unsafe-eval'` if trivially achievable;
  inline-script removal is deferred.

## Constraints

- Single operator, public-ish deployment (audit Q1 answer).
- Auth handled by reverse proxy chosen by operator; no in-app login
  (audit Q2 answer).
- Identity-aware audit required (audit Q3 answer).
- Direct API access prevented by binding to loopback + trust-proxy CIDR
  check (audit Q4 answer).
- The three CLAUDE.md-mandated schema files (`database/schema.sql`,
  `database/mysql/init.sql`, `database/postgres/init.sql`) must all be
  updated together, plus a numbered migration file.
- Every new test class needs `@covers <Class>` and the documented
  transitive `@uses` set; controller tests must call
  `Response::resetForTesting()` in `setUp()`.

---

## Architecture

```
                Internet
                   │
                   ▼
           ┌─────────────────┐
           │ Reverse proxy   │   Caddy or nginx — operator's choice.
           │ - HTTP Basic    │   Strips inbound X-Auth-User from clients,
           │ - rate limit    │   then sets X-Auth-User to the authenticated
           │ - TLS           │   username and X-Forwarded-Proto.
           └────────┬────────┘
                    │  loopback only
                    ▼
           ┌─────────────────┐
           │ public/api.php  │   bound to 127.0.0.1:8080 by compose
           │ public/index.php│   (no host port mapping, or 127.0.0.1:8080:8080)
           └────────┬────────┘
                    │
                    ▼
           ┌──────────────────────────┐
           │ src/bootstrap.php        │   required by every public/*.php
           │  1. SecurityHeaders::setAll(includeHsts: $isHttps)
           │  2. CorsPolicy::apply(Config)
           │  3. TrustedProxy::guard(Config)      ← 403 if REMOTE_ADDR untrusted
           │  4. Identity::extract(Config)        ← reads X-Auth-User
           └──────────────┬───────────┘
                          │
                          ▼
                   Router::dispatch()
                          │
              ┌───────────┴────────────┐
              ▼                        ▼
       Read controllers          Write controllers
       (no identity needed)      (record Identity::current()->user in
                                  notification_channels.last_updated_actor
                                  and notification_send_log.actor)
```

The watcher process (`src/watcher.php`) does **not** require the bootstrap.
It has no HTTP surface and runs in its own container.

---

## Components

### `src/bootstrap.php`

Top-level sequencing file. Not a class — just the four-step boot.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Config;
use NwsCad\Security\{SecurityHeaders, CorsPolicy, TrustedProxy, Identity};

$config = Config::getInstance();

$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
SecurityHeaders::setAll(includeHsts: $isHttps);

CorsPolicy::apply($config);
TrustedProxy::guard($config);          // exits 403 if untrusted
$GLOBALS['__identity'] = Identity::extract($config);
```

Required by `public/api.php` and `public/index.php`. **Order matters**:
headers first (so even error responses carry them), then CORS (so OPTIONS
preflights succeed before trust-proxy runs), then trust-proxy, then identity.

### `Security/CorsPolicy`

Wraps the existing `SecurityHeaders::setCorsHeaders()`:

```php
final class CorsPolicy {
  public static function apply(Config $cfg): void {
    $allowed = $cfg->get('cors.allowed_origins', []);     // array<string>
    SecurityHeaders::setCorsHeaders(
      allowedOrigins:   $allowed,
      allowedMethods:   ['GET','POST','DELETE','OPTIONS'],
      allowedHeaders:   ['Content-Type', 'X-Auth-User'],
      allowCredentials: false,
      maxAge:           86400,
    );
    if (Router::getMethod() === 'OPTIONS') {
      http_response_code(204); exit;
    }
  }
}
```

Empty `ALLOWED_ORIGINS` → no `Access-Control-Allow-Origin` header → browser
enforces same-origin.

### `Security/TrustedProxy`

```php
final class TrustedProxy {
  public static function guard(Config $cfg): void {
    $cidrs = $cfg->get('proxy.trusted_cidrs', ['127.0.0.1/32', '::1/128']);
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (! self::inAny($remote, $cidrs)) {
      Response::forbidden('Direct access not permitted');
    }
  }
  /** Pure function — fully unit-testable. Supports IPv4 and IPv6 CIDR. */
  public static function inAny(string $ip, array $cidrs): bool { /* ... */ }
}
```

`inAny` is pure (no globals), so coverage of mask arithmetic and IPv6
edge cases lives in `TrustedProxyTest::testInAny*`. `guard` is the IO shell.

### `Security/Identity`

```php
final class Identity {
  private function __construct(public readonly ?string $user) {}

  public static function extract(Config $cfg): self {
    // Only honoured after TrustedProxy::guard() passed, so REMOTE_ADDR is trusted.
    $header = $cfg->get('proxy.identity_header', 'X-Auth-User');
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    $raw = $_SERVER[$serverKey] ?? null;
    if ($raw === null) return new self(null);
    if (! preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $raw)) return new self(null);
    return new self($raw);
  }

  public static function current(): self {
    return $GLOBALS['__identity'] ?? new self(null);
  }
}
```

The regex defends against header smuggling even when the source is trusted.
A malformed identity header is treated as anonymous (`user=null`) and logged
at WARNING — the operator should notice their proxy is misconfigured.

### `Security/UrlValidator`

```php
final class UrlValidator {
  /** @return array{ok:true}|array{ok:false,reason:string} */
  public static function validateChannelBaseUrl(string $url, Config $cfg): array {
    if (preg_match('/[\r\n]/', $url))                      return ['ok'=>false,'reason'=>'crlf'];
    if (InputValidator::validateUrl($url) === null)        return ['ok'=>false,'reason'=>'malformed'];
    $parts = parse_url($url);
    $host  = $parts['host']   ?? '';
    $scheme= $parts['scheme'] ?? '';
    if ($scheme !== 'https') {
      if ($scheme !== 'http' || ! self::hostIsPrivate($host)
          || ! $cfg->get('notifications.allow_http_for_private', false)) {
        return ['ok'=>false,'reason'=>'scheme'];
      }
    }
    $allow = $cfg->get('notifications.base_url_allowlist', []);
    if ($allow !== [] && ! in_array($host, $allow, true))  return ['ok'=>false,'reason'=>'host'];
    return ['ok'=>true];
  }
}
```

Reuses the previously-dead `InputValidator::validateUrl()`. `hostIsPrivate`
covers RFC1918 plus `localhost`, `127.0.0.0/8`, `::1`, and link-local.

### `NtfyChannel` hardening (S6)

Constructor head, before any field assignment:

```php
if (preg_match('/[\r\n]/', $authToken)) {
    throw new InvalidArgumentException('NTFY auth token contains CR/LF');
}
if (! preg_match('/^(Bearer|Basic) /', $authToken)) {
    $authToken = 'Bearer ' . $authToken;
}
```

The `InvalidArgumentException` propagates to
`NotificationDispatcher::handle()`'s `channelFactory` try-catch, which already
calls `channelRepo->markFailure()` and continues with the next channel.

### `NotificationsController::enable()` hardening (S7)

Before INSERT/UPDATE, run `UrlValidator::validateChannelBaseUrl($baseUrl)`.
On any non-ok result, return `Response::error('Invalid base_url: ' . $reason, 422)`.

The CLI `bin/notifications.php enable` calls the same validator before
running its own UPDATE.

### Identity wired into controllers

The following methods read `Identity::current()->user` and pass it as the
last bound parameter of their existing UPDATE/INSERT statement:

- `NotificationsController::enable` → `notification_channels.last_updated_actor`
- `NotificationsController::disable` → same
- `NotificationsController::test` → records into `notification_send_log.actor`
- `NotificationsController::clearChannelError` → `last_updated_actor`
- `NotificationsController::clearFailed` → no row write (DELETE only); not affected
- `NotificationsController::dismissLogEntry` → no audit row (DELETE only); not affected
- `LogsController::cleanup` → no DB rows; not affected

The watcher's dispatcher continues to write `actor = NULL` (no operator on
that code path) — `ChannelRepository::recordSend()` keeps its current shape;
the new column defaults to NULL.

### Schema migration

```sql
-- database/migrations/2026-05-12-identity-audit.sql
ALTER TABLE notification_channels ADD COLUMN last_updated_actor VARCHAR(64) NULL;
ALTER TABLE notification_send_log ADD COLUMN actor              VARCHAR(64) NULL;
```

Mirrored into `database/schema.sql`, `database/mysql/init.sql`,
`database/postgres/init.sql` per CLAUDE.md.

### Config additions (`src/Config.php`)

```php
'cors'  => ['allowed_origins' => self::csv($this->env('ALLOWED_ORIGINS', ''))],
'proxy' => [
  'trusted_cidrs'    => self::csv($this->env('TRUSTED_PROXY_CIDRS', '127.0.0.1/32,::1/128')),
  'identity_header'  => $this->env('PROXY_IDENTITY_HEADER', 'X-Auth-User'),
],
'notifications' => [
  // delta_seconds stays as-is
  'base_url_allowlist'     => self::csv($this->env('NOTIFICATION_BASE_URL_ALLOWLIST', '')),
  'allow_http_for_private' => $this->env('NOTIFICATION_ALLOW_HTTP_PRIVATE', 'false') === 'true',
],
```

New `Config::csv()` helper splits and trims a comma-separated env value.
No breaking change to existing keys.

### Deletes

- `src/Security/RateLimiter.php` — proxy concern; existing implementation is
  per-process and would not survive the two-process watcher+api setup.
- Lines 22-30 of `public/api.php` — the hardcoded CORS block and inline
  OPTIONS handler. Replaced by `CorsPolicy::apply`.
- `use NwsCad\Dashboard\Router;` at `public/index.php:12` — refers to a
  non-existent class; routing is done inline by the `$routes` array.

### Reverse-proxy sample configs

Two new files under `docs/deployment/`:

- `caddy.example` — `basicauth` directive, sets
  `X-Auth-User {http.auth.user.id}`, sets `X-Forwarded-Proto`, reverse-proxies
  to `127.0.0.1:8080`. **Strips** any inbound `X-Auth-User` before basicauth
  runs.
- `nginx.example` — same idea using `auth_basic` and
  `proxy_set_header X-Auth-User $remote_user`. Includes
  `proxy_set_header X-Auth-User ""` first (to clear any inbound value) per
  nginx semantics.

A short `docs/deployment/README.md` documents the threat model and the
manual verification curl checks.

---

## Data flow

### Authenticated read

```
Browser GET /api/notifications/channels
  → Proxy (basicauth ok, sets X-Auth-User: k9barry, strips inbound version)
  → 127.0.0.1:8080
  → bootstrap.php:
      SecurityHeaders::setAll(true)
      CorsPolicy::apply()             (empty allowlist → no CORS header)
      TrustedProxy::guard()           (REMOTE_ADDR=127.0.0.1 ∈ trusted → continue)
      Identity::extract()             (Identity("k9barry"))
  → Router::dispatch('GET', '/api/notifications/channels')
  → NotificationsController::channels()  (read-only, identity not used)
  → Response::success([...])
```

### Identity-aware write

```
POST /api/notifications/channels/ntfy/enable
  → bootstrap.php as above
  → NotificationsController::enable('ntfy'):
      validateType('ntfy')                              → ok
      UrlValidator::validateChannelBaseUrl($baseUrl)    → {ok:true} | 422
      $actor = Identity::current()->user                → "k9barry"
      INSERT/UPDATE notification_channels
        SET enabled=1, base_url=?, last_updated_actor=?
      Response::success(row)
```

### Direct-access forgery

```
Attacker → POST http://your-host:8080/api/notifications/channels/ntfy/disable
                X-Auth-User: admin
  → Compose binds 127.0.0.1:8080:8080 — connection refused.
  → If the operator diverges and binds publicly:
      bootstrap.php → TrustedProxy::guard():
        REMOTE_ADDR = <attacker IP> ∉ 127.0.0.1/32
      → Response::forbidden('Direct access not permitted')
      → 403, exit, request never reaches controller.
```

---

## Error handling

| Condition | HTTP | Body | Log level |
|---|---|---|---|
| `TrustedProxy::guard` miss | 403 | `{"success":false,"error":"Direct access not permitted"}` | WARNING (REMOTE_ADDR is logged raw — useful for incident response) |
| Identity header malformed | continue | `Identity(null)`; request proceeds anonymously | WARNING |
| Identity header missing | continue | `Identity(null)` | DEBUG |
| `validateChannelBaseUrl` reject | 422 | `{"success":false,"error":"Invalid base_url: <reason>"}` | INFO |
| `NtfyChannel` ctor CR/LF | (factory throws) | `markFailure(channelId, "NTFY auth token contains CR/LF")` | ERROR |
| CORS origin not in allowlist | continue | no `Access-Control-Allow-Origin` header set; browser blocks | nothing to log |
| OPTIONS preflight | 204 | empty | nothing |

Cross-cutting:

- **403s short-circuit via `exit`** (matches existing `Response::forbidden`
  in production; tests rely on the `Response::resetForTesting()` shim).
- **The OPTIONS short-circuit is intentional and documented**: a preflight
  from a non-proxied attacker will receive 204 because it runs before the
  trust-proxy gate. This is safe because the preflight carries no body, no
  identity, and the follow-up request still hits `TrustedProxy::guard()`.
- **Identity is never embedded in log messages** with PII labels; it appears
  only in dedicated audit columns. The `RedactingProcessor` is unchanged.
- **Schema migration is forward-only and nullable**, so rollback is `DROP
  COLUMN` and rolling deploys are safe.

---

## Testing

Layout follows the existing `tests/{Unit,Integration,Performance,Security}`
split.

```
tests/Unit/Security/
  TrustedProxyTest.php           — CIDR membership (v4, v6, mask edges, malformed)
  IdentityTest.php               — valid, missing, malformed, oversize, CR/LF
  CorsPolicyTest.php             — empty allowlist, exact match, mismatch, 'null'
                                   origin, OPTIONS short-circuit
  UrlValidatorTest.php           — https only, http+private, allowlist match/miss,
                                   CR/LF, SSRF tricks (link-local, percent-host)
tests/Unit/Notifications/Channels/
  NtfyChannelTokenTest.php       — CR/LF rejected, "Bearer " auto-prefix,
                                   already-prefixed pass-through
tests/Integration/Security/
  BootstrapTrustGuardTest.php    — simulate REMOTE_ADDR; /api/health → 403 vs 200
  IdentityRoundtripTest.php      — POST enable with X-Auth-User; assert
                                   notification_channels.last_updated_actor
tests/Integration/Api/
  NotificationsControllerEnableTest.php
                                  — extend existing: URL validation + actor recording
tests/Security/
  CorsExploitTest.php            — Origin attacks: null, file://, embedded newline,
                                   case-fold tricks
  DirectAccessForgeryTest.php    — untrusted REMOTE_ADDR + forged X-Auth-User → 403,
                                   zero DB rows changed
```

### Coverage hygiene (CLAUDE.md requirements)

- Every new test class declares `@covers \NwsCad\Security\<Class>` at class
  level.
- Standard transitive `@uses` set on controller integration tests
  (`Response`, `Database`, `Config`, `Logger`, `RedactingProcessor`,
  `SecretRegistry`).
- Controller tests call `Response::resetForTesting()` in `setUp()`.

### Invariants pinned

1. **Direct access cannot be forged.** `DirectAccessForgeryTest` sets
   `REMOTE_ADDR=203.0.113.1` + `HTTP_X_AUTH_USER=admin`. Expects 403; expects
   zero rows in `notification_channels` changed.
2. **Identity never persists across requests.** `IdentityRoundtripTest`
   runs the bootstrap twice — first with header, then without — and asserts
   the second request's audit column is NULL. Defends against the `$GLOBALS`
   stash going stale.
3. **CR/LF guard fires pre-HTTP.** `NtfyChannelTokenTest` constructs the
   channel with a poisoned token; test fails if `HttpPut::put()` is ever
   reached.
4. **URL validator rejects same-host SSRF.** `UrlValidatorTest` covers
   `http://169.254.169.254/`, `http://localhost/`,
   `https://attacker.example/@10.0.0.1/`, percent-encoded host. All reject
   unless `allow_http_for_private=true` and host is in allowlist.
5. **OPTIONS short-circuit is intentional, not a bug.** `CorsPolicyTest`
   pins the order: `OPTIONS` returns 204 before `TrustedProxy::guard` runs.

### Tests that change rather than break

- Existing `NotificationsControllerTest::testEnable*` gains an HTTPS fixture;
  the previously-implicit "any URL works" path becomes the negative case.
- `RouterTest`, `ResponseTest` — unchanged (no middleware refactor).

### Out of scope for automated CI

- Reverse-proxy behaviour (Caddy/nginx config). Ship docs + manual smoke
  steps; do not test third-party software in CI.
- HSTS browser behaviour. Header presence is asserted; the browser's
  internal cache is its own concern.
- Rate limiting (deleted).

### Manual verification checklist

1. Boot stack. `curl -i http://127.0.0.1:8080/api/health` → 200 (loopback trusted).
2. From another host, `curl -i http://<host>:8080/api/health` → connection
   refused (compose binding) **or** 403 (defense in depth) if the operator
   diverged.
3. Through the proxy with bad credentials → 401 (from the proxy).
4. Through the proxy with good credentials → 200; response includes
   `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options`,
   `Referrer-Policy`.
5. POST `/api/notifications/channels/ntfy/enable` through proxy → row
   written with `last_updated_actor = <basicauth user>`.
6. POST same with `base_url=http://evil.example` → 422 `{"reason":"scheme"}`.
7. Set `NTFY_AUTH_TOKEN=$'tk_abc\nInjected: header'`. Trigger an event;
   channel boot rejects with logged ERROR, no notification sent.

---

## Migration / rollout

1. Land schema migration (forward-only, nullable). Existing rows unaffected.
2. Land the bootstrap, security classes, and bootstrap inclusions in
   `public/api.php` and `public/index.php`. `TRUSTED_PROXY_CIDRS` default
   covers single-host operator who has not yet stood up a proxy — the API
   continues to work from localhost.
3. Land `NtfyChannel` constructor hardening and `enable()` URL validation.
4. Operator stands up a reverse proxy out-of-band using the example configs
   and re-points DNS.
5. Operator sets `ALLOWED_ORIGINS` (if any browser-side cross-origin
   integrations exist) and `PROXY_IDENTITY_HEADER` (defaults to `X-Auth-User`).
6. Delete `src/Security/RateLimiter.php` and the dead `Dashboard\Router`
   import in a follow-up cleanup commit, only after step 2 is in
   production. Keeping them separate makes the security PR's diff easier
   to review.

## Open questions

None blocking. Two minor decisions deferred to implementation:

- Whether to log identity-extraction WARNINGs at `warning` or `notice`. The
  signal-to-noise trade is operational, not architectural.
- Whether to extend identity capture to `dismissLogEntry`/`clearFailed`
  (currently no audit row to attach it to). A follow-up could add a small
  `notification_audit_log` table; not part of this slice.

---

## References

- CLAUDE.md — coding conventions, the three-way schema sync requirement,
  test conventions (load-bearing for CI).
- The 2026-05-12 codebase audit (this conversation's first response) —
  source of findings S1–S11, M1–M9, P1–P10.
- `src/Security/SecurityHeaders.php` — existing implementation, currently
  unused, wired up by this design.
- `src/Security/InputValidator.php` — existing implementation, currently
  unused, consumed by `UrlValidator`.
