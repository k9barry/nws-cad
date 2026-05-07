# Consolidating nws-endpoints into nws-cad

| Field | Value |
|---|---|
| Date | 2026-05-07 |
| Status | Draft — pending user review |
| Target repo | `k9barry/nws-cad` |
| Retired repo | `k9barry/nws-endpoints` |

## Problem

`nws-cad` and `nws-endpoints` independently watch a CIFS-mounted folder for the **same** New World Systems Aegis CAD XML export, parse it twice with different code paths, and write to different databases. `nws-cad` produces a normalized 13-table schema, REST API, and dashboard; `nws-endpoints` produces a flat SQLite `incidents` table whose only purpose is to drive ntfy.sh and Pushover notifications. The duplication causes:

- **Two parsers to maintain.** `nws-cad`'s `AegisXmlParser` is namespaced, XXE-protected, transactional, and tested. `nws-endpoints`'s `fcn_13_recordReceived` uses `simplexml_load_file(...) or die(...)`, no XXE protection, and is wholly untested.
- **Two ingest pipelines, one CIFS mount.** Two daemons read the same files, with no coordination guarantee.
- **Two stores of truth for incident state.** Incident lifecycle (created / updated / closed) is computed twice, against different schemas.
- **Notification secrets in committed config.** `nws-endpoints` keeps `ntfyAuthToken`, `pushoverToken`, `pushoverUser` in `src/config.php` (gitignored only by convention).

## Goals

1. **One parse, one source of truth.** `AegisXmlParser` is the only XML parser; the normalized 13-table schema in MySQL/PostgreSQL is the only incident store.
2. **Preserve the unique behavior of `nws-endpoints`** — incident-lifecycle reactions, delta-time gating, change-detection-driven resends, hierarchical ntfy topics, ntfy + Pushover delivery — by porting it into a `Notifications` module inside `nws-cad`.
3. **Improve security posture during the consolidation** rather than after: env-var secrets, topic sanitization, explicit DTO mapping (no `extract($row)`), redacted logging, and structured outbound errors.
4. **Read-only operational visibility** of channel health in the dashboard. No new write surface, no new auth requirements.
5. **Retire `nws-endpoints` cleanly** with a final commit pointing to `nws-cad` and a GitHub repo archive. No data migration.

## Non-goals (explicitly out of scope)

- Importing historical incidents from the existing `nws-endpoints` `db.sqlite`.
- Editable channel configuration in the dashboard (no auth/CSRF/edit forms in this iteration).
- Per-jurisdiction subscription editing UI, on-call rotations, or operator-driven silencing.
- A background queue / worker process for notification delivery.
- New notification channels beyond ntfy and Pushover (the interface supports them; no implementations).
- Replacing the legacy CloudFront icon URL referenced by ntfy notifications (flag only).

## Decisions (locked in via brainstorming)

| Decision | Choice |
|---|---|
| Consolidation shape | **Notify module inside nws-cad.** `AegisXmlParser` stays the single parser; it dispatches a `CallProcessedEvent` after commit; a `NotificationDispatcher` subscribes and fans out to channels. Drop SQLite + the procedural `fcn_*.php` pipeline. |
| Migration | **Archive `nws-endpoints` repo, no data migration.** Final commit points to `nws-cad`; SQLite remains in the archived repo for historical reference. |
| Dispatch wiring | **In-process event dispatcher** after `AegisXmlParser::commit()`. Synchronous; no queue. |
| Channel config | **Env vars (secrets) + DB-backed channel rows.** A new `notification_channels` table holds non-secret per-channel config; secrets are referenced by env-var name and read at send time. |
| Watcher | **Single `nws-cad` watcher container.** The existing `php src/watcher.php` daemon is the only watcher. |
| Operator UI | **Option B — read-only dashboard view.** A new `/notifications` page lists channels, their `enabled` flag, `last_error_at`, and the last 10 send-log entries per channel. Toggling channels happens via CLI / SQL. |
| Delta-time gate | `NOTIFICATION_DELTA_SECONDS` env var, default 900. |
| Docker stack | Unchanged — no new services. |

## Architecture

```
public/index.php  ─┐
public/api.php    ─┼─→ MySQL/PostgreSQL
src/watcher.php   ─┤   (existing 13-table schema
  └─ FileWatcher  ─┤    + notification_channels
     └─ AegisXmlParser──┐    + notification_send_log)
                        │
                        └─ on commit: dispatch CallProcessedEvent
                                       │
                                       ▼
                            NotificationDispatcher
                                       │
                                ┌──────┼──────┐
                                ▼      ▼      ▼
                          NtfyChannel  PushoverChannel  (future channels)
```

The watcher container's external interface is unchanged. The dashboard and REST API are unchanged except for one new read-only route.

### Component inventory

All new code lives under `src/Notifications/` with namespace `NwsCad\Notifications\*`.

| Component | File | Responsibility |
|---|---|---|
| `Events\CallProcessedEvent` | `src/Notifications/Events/CallProcessedEvent.php` | Immutable: `int $dbCallId`, `Intent $intent`, `string[] $changedFields`, `DateTimeImmutable $createDateTime`. |
| `Events\Intent` | `src/Notifications/Events/Intent.php` | Enum: `Created`, `Updated`, `Closed`. |
| `EventDispatcher` | `src/Notifications/EventDispatcher.php` | Tiny in-process pub/sub. One event class, one subscriber list. No framework dependency. |
| `NotificationDispatcher` | `src/Notifications/NotificationDispatcher.php` | Subscribes to `CallProcessedEvent`. Loads enabled channels, applies delta-time gate + intent rules, builds DTO, fans out to channels, writes send log. |
| `IncidentDto` | `src/Notifications/IncidentDto.php` | Typed payload. Constructed via `IncidentDto::fromRow(array $row): self` — explicit field-by-field hydration. Never `extract()`. |
| `TopicSanitizer` | `src/Notifications/TopicSanitizer.php` | `clean(string $segment): ?string`. Whitelists `[A-Za-z0-9_-]`, replaces other chars with `_`, collapses runs, trims, returns `null` if empty. ntfy URL builder also `rawurlencode`s as second pass. |
| `Channels\NotificationChannel` | `src/Notifications/Channels/NotificationChannel.php` | Interface: `send(IncidentDto $i, NotificationContext $ctx): SendResult`. |
| `Channels\NtfyChannel` | `src/Notifications/Channels/NtfyChannel.php` | cURL PUT per topic with bounded retry. Replaces `@file_get_contents`. |
| `Channels\PushoverChannel` | `src/Notifications/Channels/PushoverChannel.php` | cURL POST with bounded retry. Port of the existing logic minus secrets in code. |
| `SendResult` | `src/Notifications/SendResult.php` | `{ok: bool, httpStatus: ?int, durationMs: int, error: ?string, topic: ?string}`. |
| `Logging\RedactingProcessor` | `src/Logging/RedactingProcessor.php` | Monolog processor. Captures every `Config::secret()` value at construction; replaces literal occurrences in any record's `message`/`context`/`extra` with `***`. Registered globally. |
| `Cli\NotificationsCommand` | `bin/notifications.php` | Tiny CLI: `list`, `enable <type>`, `disable <type>`, `test <type>`. ~50 LOC. |
| `Api\Controllers\NotificationsController` | `src/Api/Controllers/NotificationsController.php` | Read-only endpoints: `GET /api/notifications/channels`, `GET /api/notifications/log?channel=&limit=`. |
| `Dashboard\Views\notifications.php` | `src/Dashboard/Views/notifications.php` | Read-only "Notifications" page: per-channel cards with name, type, enabled flag, `last_error_at` / `last_error_message`, and last 10 send-log rows. |

### Database changes

Two new tables. Both schemas are written for MySQL and PostgreSQL with the same column set; the migration files in `database/` follow the existing dual-driver pattern.

```sql
CREATE TABLE notification_channels (
    id              SERIAL PRIMARY KEY,           -- INTEGER AUTO_INCREMENT on MySQL
    name            VARCHAR(64) UNIQUE NOT NULL,  -- e.g. 'ntfy_primary'
    type            VARCHAR(32) NOT NULL,         -- 'ntfy' | 'pushover'
    enabled         BOOLEAN NOT NULL DEFAULT FALSE,
    base_url        VARCHAR(512) NOT NULL,
    config_json     TEXT NOT NULL DEFAULT '{}',   -- non-secret config; references env-var names
    last_error_at   TIMESTAMP NULL,
    last_error_message TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notification_send_log (
    id              SERIAL PRIMARY KEY,
    channel_id      INTEGER NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    call_id         INTEGER NULL REFERENCES calls(id) ON DELETE SET NULL,  -- NULL for test sends
    intent          VARCHAR(16) NULL,             -- 'Created' | 'Updated' | 'Closed' | NULL (test)
    topic           VARCHAR(256) NULL,            -- ntfy only
    ok              BOOLEAN NOT NULL,
    http_status     INTEGER NULL,
    duration_ms     INTEGER NOT NULL,
    error           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_send_log_channel_created ON notification_send_log (channel_id, created_at DESC);
```

`config_json` shape (example):

```json
{
  "auth_token_env": "NTFY_AUTH_TOKEN",
  "default_priority": 3,
  "alarm_priority_map": {"1": 3, "2": 4, "3": 5},
  "agency_tag_map": {"Fire": "fire_engine", "Police": "police_car"}
}
```

Pruning: when inserting a send-log row, the dispatcher deletes log rows beyond the most recent 100 per channel in the same transaction. Bounded growth, no cron required.

## Data flow

1. `FileWatcher` picks up `*.xml` from `watch/` (CIFS-mounted).
2. `AegisXmlParser::processFile()` runs as today: BOM strip, XXE-safe load with `LIBXML_NONET`, transactional inserts/upserts into the 13-table schema.
3. **New (pre-commit):** parser computes `Intent`:
   - `Created` if no prior row for `xml.CallId`.
   - `Closed` if prior row exists and `xml.ClosedFlag === true`.
   - `Updated` if prior row exists and any of `{call_type, full_address, alarm_level, assigned_units, jurisdictions, agencies}` differ. `changedFields` records which.
   - No event if none of the above (still write XML data).
4. `commit()` succeeds → parser fires `CallProcessedEvent($dbCallId, $intent, $changedFields, $createDateTime)`.
5. `NotificationDispatcher::handle($event)` (in a top-level try/catch — never propagates to the parser):
   - **Delta-time gate:** drop event if `now - createDateTime > NOTIFICATION_DELTA_SECONDS`. Logged at `info`.
   - **Intent rules:**
     - `Created` → notify all derived topics.
     - `Closed` → no notification (matches existing behavior); log only.
     - `Updated` → if `changedFields ∩ {call_type, full_address, alarm_level} ≠ ∅`, notify all topics ("resend-all"). Otherwise notify only newly-added agencies/jurisdictions/units.
   - Builds `IncidentDto` via one explicit `SELECT` joining `calls`, `agency_contexts`, `locations`, `units` for the row.
   - Loops enabled channels, calls `$channel->send($dto, $ctx)`.
6. Each channel sanitizes topics, sends, returns `SendResult`. Dispatcher writes one `notification_send_log` row per attempt (final attempt only — retries are accounted for in `duration_ms`).

## Error handling

| Failure | Behavior |
|---|---|
| Parse error / XXE attempt / malformed XML | Existing `markFileAsFailed`; no event fired. |
| Transaction rollback | No event fired. |
| Dispatcher exception (e.g., DTO read fails) | Caught at dispatch boundary; logged with `error`; parser unaffected. |
| Channel HTTP 5xx / network error | Retry with bounded backoff: 3 attempts at ~1 s / 3 s / 9 s. Final failure updates `notification_channels.last_error_at` + `last_error_message`. **Other channels still run.** |
| Channel HTTP 4xx | Treat as permanent. No retry. Logged at `warning` with status + redacted body snippet (≤ 500 chars). |
| Topic sanitizes to empty | Skip topic, log at `info`. Don't fail the whole call. |
| Required env-var missing for an enabled channel | Channel disables itself for the request, logs at `warning`, dispatcher continues with other channels. |
| `notification_send_log` insert fails | Logged; not fatal — the actual send result is preserved in logs. |

No circuit breaker / dead-letter queue. `last_error_at` on the channel + send-log history are sufficient signal; revisit if the failure surface grows.

**Idempotency / replay safety:** `processed_files` tracking already short-circuits duplicate file processing. The dispatcher itself is stateless. Re-running the watcher cannot duplicate notifications for already-processed files.

## Security improvements (in scope)

### 1. Env-var-only secrets

New env vars, read by `Config::secret(string $name)`:

- `NTFY_AUTH_TOKEN` — required when an `ntfy` channel is enabled.
- `PUSHOVER_TOKEN` — required when a `pushover` channel is enabled.
- `PUSHOVER_USER` — required when a `pushover` channel is enabled.
- `NTFY_BASE_URL` — optional default (channel rows can override).
- `PUSHOVER_BASE_URL` — optional, defaults to `https://api.pushover.net/1/messages.json`.

`Config::secret()` throws `MissingSecretException` if a required secret is unset. `.env.example` is updated; `.env` is already gitignored. Secrets never appear in committed code, in DB rows, or in log records.

### 2. Topic sanitization

`TopicSanitizer::clean(string $segment): ?string`:

```
1. Trim.
2. Replace any character outside [A-Za-z0-9_-] with '_'.
3. Collapse runs of '_'.
4. Trim leading/trailing '_'.
5. Return null if length is 0 after the above.
```

ntfy URL builder additionally `rawurlencode`s each segment as a defense-in-depth second pass. Unit-tested against `../`, `?token=`, CRLF, multi-byte, and empty-after-sanitize cases.

### 3. Explicit DTO mapping

`IncidentDto::fromRow(array $row): self` reads exactly the columns it declares. `extract()` is forbidden in new code; a CI grep test (`grep -rE "\\bextract\\(" src/Notifications/` returns empty) enforces this. Future schema changes cannot silently shadow locals.

### 4. Redacted logging + structured outbound errors

- A Monolog `RedactingProcessor` is registered globally on logger construction. On its own construction it captures every value returned by `Config::secret()` and replaces literal occurrences in any record's `message`, `context`, or `extra` with `***`.
- `NtfyChannel` uses cURL with `CURLOPT_FAILONERROR=false` and explicit status checking. No more `@file_get_contents`.
- Both channels emit structured error logs: `{channel, topic?, http_status, attempt, duration_ms, error}`.

## Read-only dashboard view

Route: `GET /notifications` (mobile detection reuses existing pattern; same view, responsive).

Page contents:

```
┌─ ntfy_primary  [enabled]  ──────────────────────┐
│  type: ntfy        base_url: https://ntfy...    │
│  last_error_at: 2026-05-07 14:31  ⚠             │
│  last_error_message: HTTP 502                   │
│                                                  │
│  Recent sends (last 10):                        │
│  ✓ 14:35  Call 1234  Created  topic=Fire/MCFD/E1│
│  ✗ 14:31  Call 1233  Updated  HTTP 502          │
│  ...                                            │
└──────────────────────────────────────────────────┘
```

API endpoints (read-only, paginated, follow existing `Response::success` shape):

- `GET /api/notifications/channels` — list all channels with status fields.
- `GET /api/notifications/log?channel=<id|name>&limit=10` — recent send-log rows.

No write endpoints. No new auth surface. Same access controls as the rest of the dashboard.

## Testing plan

Slots into the existing 4-suite layout in `phpunit.xml`. Coverage target stays at 80%.

| Suite | New tests |
|---|---|
| **Unit** | `IncidentDtoTest`, `TopicSanitizerTest` (`/`, `?`, whitespace, unicode, empty-after-sanitize), `NotificationDispatcherTest` (full intent × delta-time matrix, mocked channels), `RedactingProcessorTest`, `EventDispatcherTest`, `NtfyChannelTest` (cURL mocked; asserts URL encoding + retry), `PushoverChannelTest`. |
| **Integration** | `AegisXmlParserDispatchesEventTest` (fixtures for new / changed-call-type / new-units-only / closed). `NotificationsApiTest` against the read-only endpoints. End-to-end XML → DB → channel-send (channels mocked). |
| **Security** | `TopicInjectionTest` (CAD field with `../`, `?token=`, CRLF — must produce safe path or skip). `SecretRedactionTest` (loads real env, logs `$config` dump, asserts tokens absent). `MissingSecretTest`. |
| **Performance** | Existing parser perf tests reused. One dispatcher benchmark: `< 10 ms` added on happy path with channels mocked. |

CI grep test enforces no `extract(` in `src/Notifications/`.

## Phasing (PRs against `nws-cad`, mergeable independently)

| # | PR | Acceptance criteria |
|---|---|---|
| 1 | Scaffolding: `Notifications/` namespace, `EventDispatcher`, `IncidentDto`, `TopicSanitizer`, `RedactingProcessor`, `Config::secret()` + `MissingSecretException`, `.env.example` updates, migrations for `notification_channels` + `notification_send_log` (MySQL + PostgreSQL). | Empty channel list; parser still works; secrets-redaction test green; new unit tests pass; coverage ≥ 80%. |
| 2 | `NtfyChannel` + `PushoverChannel` + `NotificationDispatcher` + `bin/notifications.php` CLI. Channels disabled by default. Uses `Config::secret()` from PR #1. | Channels send correctly when manually enabled; retries + structured error logs verified; existing suite green. |
| 3 | Read-only dashboard view + `NotificationsController` + `/api/notifications/*` endpoints. | Page renders for empty + populated channel lists; API tests pass. |
| 4 | Wire `AegisXmlParser` to fire `CallProcessedEvent` after commit; intent computation + change diff. | Integration fixtures prove correct intent + `changedFields`; parser behavior identical when no channels enabled. |
| 5 | Docs: `docs/NOTIFICATIONS.md` (operator + developer reference), update `README.md`, `CLAUDE.md`, `CHANGELOG.md`. | One canonical place describing the pipeline. |
| 6 | `nws-endpoints` final commit (README "superseded by nws-cad" notice) + GitHub repo archive. | Repo archived; nws-cad README links it for history. |

## Open assumptions (locked in unless flagged)

- The dashboard does not need editable channel config in this iteration; toggling is CLI/SQL.
- `timeAdjust` becomes `NOTIFICATION_DELTA_SECONDS` env var, default 900.
- The `nws-cad` Docker compose stack (mysql/postgres/dbeaver/app/api) is unchanged — no new services.
- Send-log pruning is per-channel last 100, performed transactionally with each insert.

## References

- nws-cad: `src/AegisXmlParser.php`, `src/Database.php`, `src/Api/Controllers/`, `phpunit.xml`, `database/`.
- nws-endpoints: `src/run`, `src/functions/fcn_13_recordReceived.php`, `src/functions/fcn_21_sendMessage.php`, `src/functions/fcn_20_DeltaTime.php`.
- Existing repo guidance: `nws-cad/CLAUDE.md`, `nws-cad/.github/copilot-instructions.md`.
