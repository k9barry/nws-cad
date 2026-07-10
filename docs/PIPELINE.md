# Message Pipeline — Operator Runbook

This document traces a single CAD XML file from the moment it lands in the watch folder to the moment a notification arrives on an operator's phone. It is **operator-focused**: each stage answers "what does it do, where does its state live, what does failure look like, and where do I look first?"

For architectural deep-dives see the design specs under [`docs/superpowers/specs/`](superpowers/specs/). For day-to-day issues see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

---

## End-to-end view

```
                                          ┌────────────────────────┐
   var/watch/*.xml                             │  notification_outbox   │
       │                                   │  (per-channel rows)    │
       ▼                                   └────────────┬───────────┘
   ┌─────────┐  parse+commit  ┌──────────┐  event  ┌───┴────┐  tick (loop)
   │FileWatch│───────────────▶│  calls   │────────▶│Outbox  │────┐
   │  loop   │                │  + 12    │         │Writer  │    │
   └─────────┘                │ children │         └────────┘    │
       │                      └──────────┘                       │
       │  heartbeat                                              ▼
       ▼                                                  ┌───────────┐
  var/log/.watcher-heartbeat                                 │ Outbox    │
                                                          │ Processor │
                                                          └─────┬─────┘
                                                                │  send()
                                                                ▼
                          ┌─────────────────────────────────────────────┐
                          │  NtfyChannel  │  PushoverChannel  │  Webhook │
                          └────┬──────────┴────────┬──────────┴────┬─────┘
                               │                   │               │
                               ▼                   ▼               ▼
                          ntfy.sh          api.pushover.net    operator's
                                                              webhook URL
                               │                   │               │
                               └─────────┬─────────┴───────────────┘
                                         ▼
                                ┌────────────────────┐
                                │ notification_send_ │
                                │       log          │
                                └────────────────────┘
                                         ▲
                                         │  (per-channel last error
                                         │   also written to
                                         │   notification_channels)
                                ┌────────┴───────┐
                                │  Notifications │
                                │  dashboard +   │
                                │  outbox card   │
                                └────────────────┘
```

The watcher is a single long-lived PHP process (`php src/watcher.php`) running inside the `app` container. It does three things per loop iteration (default 5s):
1. Scans `var/watch/` for new `*.xml` files and feeds them to `AegisXmlParser`
2. Touches `var/log/.watcher-heartbeat` for Docker's healthcheck
3. Calls `OutboxProcessor::tick()` to drain the notification queue

The REST API runs in a separate container (`api`) and only reads from the DB plus exposes operator actions. The dashboard is server-rendered HTML served from `api`.

---

## Stage 1 — File arrival

**What it does.** A CAD XML file lands in `var/watch/`. The watcher scans the folder once per `WATCHER_INTERVAL` seconds (default 5).

**State.** Filename + content on the local FS (or CIFS-mounted share, see `docker-compose.yml` `watchfolder` volume).

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| Files in `var/watch/` are never picked up | Watcher process is down | `docker compose ps app` — should be `Up (healthy)` |
| Watcher reports unhealthy | Heartbeat file is stale (>60s) | `ls -l var/log/.watcher-heartbeat` — mtime should be recent. `docker compose logs app` for the underlying cause |
| Files appear but vanish without DB rows | XML rejected — see Stage 2 | `logs/error.log` for parse errors |

**Operator check.** If a file isn't being processed:
```bash
docker compose logs --tail=200 app | grep -i "Processing XML file"
ls -la var/watch/
```

---

## Stage 2 — XML parsing

**What it does.** `AegisXmlParser::loadXmlFile()` strips a BOM if present, then loads the XML with `LIBXML_NONET` (no network access, defense against XXE).

**State.** In-memory `SimpleXMLElement` for the rest of the request.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| `Failed to load XML file` | Malformed XML, bad encoding, missing root | `logs/error.log` — full libxml error list is logged |
| File appears as "processed" but call_id is 0 | Newer XML for the same call already ingested (stale file rejected via `isFilenameStaleForCall`) | `SELECT * FROM processed_files WHERE filename = '<name>'` — the row gets call_id=0 |

**Operator check.** When a single file misbehaves:
```bash
# Validate XML manually
xmllint --noout var/watch/PROBLEM_FILE.xml
# See what the watcher logged
docker compose logs app | grep PROBLEM_FILE
```

---

## Stage 3 — DB transaction (parser → 13 tables)

**What it does.** `AegisXmlParser::processFile()` opens a transaction, calls `insertCall()` and its child helpers across the 13 call-related tables (`calls`, `agency_contexts`, `locations`, `incidents`, `narratives`, `persons`, `vehicles`, `call_dispositions`, `units`, `unit_personnel`, `unit_logs`, `unit_dispositions`, `processed_files`), and commits. Reopen detection runs before the insert; intent resolution runs after.

**State.** Rows in the 13 tables; `processed_files` row marks the filename as ingested.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| Parser logs `Rolling back transaction` | Exception during insert (FK violation, constraint check, etc.) | `logs/error.log` — full exception + stack trace |
| File processed but no notification | See Stage 4: intent may have resolved to `null` (no material change) or `Closed` | `SELECT * FROM processed_files WHERE filename = '<name>'`; note that even successful processing doesn't always fire an event |

**Operator check.** Verify the call landed:
```sql
SELECT id, call_id, call_number, create_datetime, closed_flag, reopened_flag
FROM calls WHERE call_number = '<your-call>';
```

---

## Stage 4 — Intent resolution

**What it does.** `IntentResolver::resolve()` compares the pre-insert snapshot with the post-insert state and returns one of three intents (or null):
- **Created** — `call_id` is new
- **Updated** — existing row, with a list of `changedFields` and any `addedTopics` (new units/jurisdictions)
- **Closed** — `ClosedFlag=true` for the first time
- **null** — no material change (no event fired)

**State.** None persisted — the intent is passed into the `CallProcessedEvent` constructor and consumed by `OutboxWriter`.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| No notification for what looked like a meaningful update | `changedFields` didn't include `call_type`, `full_address`, or `alarm_level`, AND `addedTopics` was empty → outbox row gets no topics | `SELECT * FROM notification_outbox WHERE db_call_id = <id>` — row exists but `resend_all=0` and `added_topics_json='[]'` |

The "resend-all" trigger fields are deliberately narrow (`call_type` / `full_address` / `alarm_level`) — change them in `src/Notifications/TopicResolver.php` if the policy needs adjusting.

---

## Stage 5 — Event dispatch & outbox write

**What it does.** After commit, the parser calls `EventDispatcher::dispatch(new CallProcessedEvent(...))`. The `OutboxWriter` subscriber (registered in `src/watcher.php`) applies two gates:
1. **Closed gate** — Closed intents are dropped (no notification on close)
2. **Delta-time gate** — events older than `NOTIFICATION_DELTA_SECONDS` (default 900s) are dropped (prevents backlog replay from paging the world)

Surviving events get one row per enabled channel inserted into `notification_outbox`.

**State.** Rows in `notification_outbox` with `status='pending'`.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| No outbox rows for a Created event | All channels disabled, OR event dropped by delta-time gate | `var/log/app.log` — look for `Outbox writer: no enabled channels` or `delta-time gate dropped event` |
| Outbox rows exist but stay `pending` indefinitely | OutboxProcessor not running (watcher down) or `next_attempt_at` is set far in the future | See Stage 7 |

**Operator check.**
```sql
-- All recent outbox rows for a call:
SELECT id, channel_id, intent, status, attempts, next_attempt_at, last_error, created_at
FROM notification_outbox
WHERE db_call_id = <call_id>
ORDER BY id DESC;
```

---

## Stage 6 — Outbox queue (steady state)

**What it does.** `notification_outbox` rows sit in `status='pending'` until the next watcher tick claims them. Each row is bound to one `(call, channel)` pair and survives process crashes.

**State.** `notification_outbox` table. Lifecycle: `pending` → `in_flight` → `done` / `failed`. Backoff between attempts: `[30s, 2m, 10m, 30m, 2h]` indexed by `attempts - 1`. After `OUTBOX_MAX_ATTEMPTS` (default 5) a row is marked `failed`.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| Queue growing | OutboxProcessor not draining, OR enabled channels all permanently failing | Dashboard "Outbox queue" card; `SELECT status, COUNT(*) FROM notification_outbox GROUP BY status` |
| `in_flight` rows never transition | Worker died mid-tick; orphans get reset on next worker boot via `resetOrphans()` | Check `claimed_by` matches current `gethostname():getmypid():start_ts`; if not, next tick fixes it |

**Operator check.** Use the dashboard's Outbox queue card (Notifications page → bottom card). Filter by status, retry/dismiss/clear from the UI.

---

## Stage 7 — OutboxProcessor tick

**What it does.** Once per watcher loop iteration, `OutboxProcessor::tick()`:
1. Prunes `done` rows older than 7 days (one `DELETE`)
2. Resets `in_flight` rows whose `claimed_by` doesn't match this worker (crash recovery)
3. Claims up to `OUTBOX_BATCH_SIZE` (default 10) `pending` rows whose `next_attempt_at <= NOW()` (or NULL)
4. For each claimed row: loads `IncidentDto`, resolves topics, invokes the channel's `send()`, records each `SendResult` in `notification_send_log`, marks the outbox row `done` (any success) or schedules a retry (all failures)

**State.** Mutates `notification_outbox`; writes to `notification_send_log`; updates `notification_channels.last_error_*` on failure.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| Tick raises | Caught at row level — error logged, row scheduled for retry | `var/log/app.log` — `Outbox tick: processRow threw` |
| Housekeeping (prune/resetOrphans) fails | Caught at the tick level — logged but tick continues | `var/log/app.log` — `Outbox tick: housekeeping failed` |

The tick is wrapped in `FileWatcher::start()`'s outer try/catch, so a thrown tick won't kill the watcher loop — but the heartbeat may go stale if the tick blocks for >60s. Channel-level retries inside `send()` have their own 1s/3s/9s backoff, so a single slow tick can take ~13s × N channels.

---

## Stage 8 — Channel send

**What it does.** Each `NotificationChannel::send()` is a separate implementation:
- **`NtfyChannel`** — one HTTP PUT per topic, returns one `SendResult` per topic
- **`PushoverChannel`** — one HTTP POST to `api.pushover.net`, returns one `SendResult`
- **`WebhookChannel`** — one HTTP POST per event (topics passed as JSON array), returns one `SendResult`

All three use the same retry contract: up to 3 attempts with `[1s, 3s, 9s]` backoff. 2xx → success. 4xx → permanent failure (logged, returned immediately). 5xx and network errors → retried.

**State.** Per-attempt result returned to `OutboxProcessor::processRow()`. No channel-internal persistence.

**Failure modes.**
| Symptom | Root cause | Where to look |
|---|---|---|
| Channel keeps failing with HTTP 4xx | Bad URL, expired token, malformed payload, sanitizer producing empty topic | Dashboard channel card → "Last error" banner; `SELECT last_error_message FROM notification_channels WHERE name = '<name>'` |
| Channel timing out / 5xx → outbox retries | Upstream provider degraded | Outbox row's `attempts` will climb; next_attempt_at is set with backoff. Check provider status page. |
| ntfy: topic strings have suspicious underscores | Pipe-joined GROUP_CONCAT not split properly | `TopicSanitizer::clean()` collapses `[^A-Za-z0-9_-]` to `_`; if you see `IN048_IN048_IN048` topics, look at `TopicResolver::splitPipe()` — pipe-joined CAD strings should split before sanitization. |

**Operator check.**
```sql
-- Per-channel recent results:
SELECT s.id, s.intent, s.ok, s.http_status, s.duration_ms, s.error, s.created_at
FROM notification_send_log s
WHERE s.channel_id = (SELECT id FROM notification_channels WHERE name = '<name>')
ORDER BY s.id DESC LIMIT 20;
```

---

## Stage 9 — Result recording

**What it does.** `OutboxProcessor::processRow()` writes one `notification_send_log` row per `SendResult`. If any result is `ok`, the outbox row is marked `done`. If all are failures, the first failure's message is recorded and the row is scheduled for retry (or marked `failed` at max attempts). Each failing result also updates `notification_channels.last_error_at` and `last_error_message` so the dashboard channel card surfaces it.

**State.** `notification_send_log` (auto-pruned to 100 rows per channel); `notification_channels.last_error_*` (latest only); `notification_outbox.status` and `last_error`.

**Failure modes.** This stage is internal bookkeeping — failures here are caught by `OutboxProcessor`'s `try/catch` around `processRow()` and surface as outbox retries.

---

## Stage 10 — Operator visibility

**Dashboard surfaces.**
- **Notifications page** (`/notifications`): one card per channel, with toggle, "Send test" button, last 10 send-log entries, sticky last-error banner.
- **Outbox queue card** (same page, below channel cards): status filter tabs (Pending/In flight/Failed/Done/All), per-row Retry (failed only) and Dismiss buttons, bulk "Clear all done/failed".

**API surfaces.**
- `GET /api/notifications/channels` — list channels + state
- `GET /api/notifications/log?channel=<id|name>` — send log
- `GET /api/notifications/outbox?status=<filter>` — outbox queue
- `POST /api/notifications/outbox/{id}/retry` — reset a row to pending
- `DELETE /api/notifications/outbox/{id}` — dismiss a row
- `POST /api/notifications/outbox/clear?status=done|failed` — bulk delete

**Log files** (under `logs/`):
| File | Source | Use for |
|---|---|---|
| `app-YYYY-MM-DD.log` | Both `app` and `api` containers (Monolog `RotatingFileHandler`, 7-day retention) | All log output: parser activity, outbox tick, channel sends, HTTP requests |
| `.watcher-heartbeat` | Watcher heartbeat | Docker healthcheck (touched every loop iteration) |

`LOG_LEVEL=debug` in `.env` raises verbosity. `APP_DEBUG=true` enables stdout mirroring (visible via `docker compose logs -f app`).

---

## Common failure runbooks

### "A call was ingested but no notification fired"

Walk the chain backwards:

```sql
-- 1) Did the call land?
SELECT id, call_number, create_datetime FROM calls WHERE call_number = '<n>';

-- 2) Was an outbox row queued? (No row = OutboxWriter dropped the event)
SELECT * FROM notification_outbox WHERE db_call_id = <id> ORDER BY id DESC;
```

If step 2 returns nothing:
- **Closed intent** — by design, no notification.
- **Delta-time gate** — check `var/log/app.log` for `Outbox writer: delta-time gate dropped event` near the call's `create_datetime`.
- **No enabled channels** — `SELECT name, enabled FROM notification_channels` should show ≥1 enabled.
- **`Updated` intent with no `changedFields` and no `addedTopics`** — IntentResolver returned null. Check the prior call's snapshot vs the new XML.

If step 2 returns rows:
```sql
-- 3) What's their status?
SELECT id, channel_id, status, attempts, last_error, next_attempt_at FROM notification_outbox WHERE db_call_id = <id>;
```
- `pending` → next tick will pick it up (or `next_attempt_at` is in the future)
- `in_flight` → currently being processed
- `failed` → operator retry needed; check `last_error`
- `done` → it fired; check `notification_send_log` for `ok=1`

### "Channel is failing every send"

```sql
-- Most recent failures for the channel:
SELECT created_at, http_status, error FROM notification_send_log
WHERE channel_id = (SELECT id FROM notification_channels WHERE name = '<n>')
  AND ok = 0
ORDER BY id DESC LIMIT 10;
```

Common causes:
- **4xx 401/403** — token expired or wrong; check `Config::secret($env)` resolves to the right env var
- **4xx 404** — `base_url` is wrong, or ntfy topic sanitized to empty
- **5xx persistently** — provider outage; outbox retries will keep firing until `OUTBOX_MAX_ATTEMPTS`
- **`curl error: ...`** — DNS/network/TLS issue

After fixing root cause: use the dashboard's "Clear failed" button on the channel card, then "Retry" on failed outbox rows. Or:
```sql
UPDATE notification_channels SET last_error_at=NULL, last_error_message=NULL WHERE name='<n>';
UPDATE notification_outbox SET status='pending', attempts=0, next_attempt_at=NULL, last_error=NULL
WHERE channel_id=(SELECT id FROM notification_channels WHERE name='<n>') AND status='failed';
```

### "Outbox queue is growing unboundedly"

Symptoms: `SELECT COUNT(*) FROM notification_outbox WHERE status IN ('pending','in_flight')` returns hundreds+.

Most likely causes (in order):
1. **Watcher is down or unhealthy** — no one is calling `OutboxProcessor::tick()`. Check `docker compose ps app`.
2. **`OUTBOX_BATCH_SIZE` too small for arrival rate** — raise it. Default 10 × 1 tick per 5s = 2 rows/sec drain.
3. **Every channel is in 5xx retry** — rows churn `pending → in_flight → pending` without reaching `done`. Check `last_error` on rows.

### "Watcher heartbeat is stale (unhealthy container)"

Heartbeat is touched at the top of every `FileWatcher::start()` loop iteration. If older than 60s, Docker flags the container unhealthy.

Diagnose:
```bash
docker compose logs --tail=200 app
ls -l var/log/.watcher-heartbeat
```

If the heartbeat is recent but the container still flagged: confirm `WATCHER_INTERVAL` in `.env` is ≤30s (CLAUDE.md notes that values >~30s cause flapping with the 60s threshold).

If logs show the watcher is alive but stuck: most likely a slow channel `send()` blocking the tick. Confirm via timestamps in `app.log` between consecutive `Outbox processRow` lines.

---

## Configuration knobs (where each affects the pipeline)

| Env var | Default | Stage | Effect |
|---|---|---|---|
| `WATCHER_INTERVAL` | 5 | 1 | Seconds between file-scan + outbox-tick iterations |
| `WATCHER_FILE_PATTERN` | `*.xml` | 1 | Glob for files to ingest |
| `NOTIFICATION_DELTA_SECONDS` | 900 | 5 | Drop events older than this at outbox-write time |
| `OUTBOX_BATCH_SIZE` | 10 | 7 | Max outbox rows claimed per tick |
| `OUTBOX_MAX_ATTEMPTS` | 5 | 7 | Permanent-failure threshold |
| `NTFY_AUTH_TOKEN` / `NTFY_BASE_URL` | — | 8 | Required when ntfy channel enabled (referenced by name from `notification_channels.config_json`) |
| `PUSHOVER_TOKEN` / `PUSHOVER_USER` / `PUSHOVER_BASE_URL` | — | 8 | Same pattern for Pushover |
| `STALE_OPEN_CALL_HOURS` | 72 | (API only) | Reclassifies long-open calls as closed in API stats — doesn't affect the pipeline itself |

---

## Related docs

- [NOTIFICATIONS.md](NOTIFICATIONS.md) — channel config, intent rules, webhook templates
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) — quick diagnostics for common breakage
- [API.md](API.md) — full REST endpoint reference
- [DASHBOARD.md](DASHBOARD.md) — dashboard user guide
- [docs/superpowers/specs/2026-05-12-outbox-async-worker-design.md](superpowers/specs/2026-05-12-outbox-async-worker-design.md) — outbox design rationale
