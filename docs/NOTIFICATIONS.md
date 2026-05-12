# Notifications

The notifications module turns parsed CAD calls into ntfy.sh / Pushover pushes. It runs in-process inside the file-watcher daemon: after `AegisXmlParser` commits, a `CallProcessedEvent` is dispatched, `NotificationDispatcher` decides whether to send (delta-time gate + intent rules), and each enabled channel posts the alert.

## Quick start

1. Add the relevant secrets to `.env`:

   ```
   NTFY_AUTH_TOKEN=Bearer tk_xxx
   NTFY_BASE_URL=https://ntfy.your-domain.com
   PUSHOVER_TOKEN=...
   PUSHOVER_USER=...
   NOTIFICATION_DELTA_SECONDS=900
   ```

2. Enable a channel:

   ```bash
   docker-compose exec app php /var/www/bin/notifications.php enable ntfy
   docker-compose exec app php /var/www/bin/notifications.php enable pushover
   docker-compose exec app php /var/www/bin/notifications.php list
   ```

3. Visit the dashboard at `/notifications` to see channel status and recent send results.

## Architecture

```
AegisXmlParser ──commit──► CallProcessedEvent
                            │
                            ▼
                  EventDispatcher
                            │
                            ▼
                  NotificationDispatcher
                ┌───────────┼───────────┐
                ▼           ▼           ▼
          NtfyChannel  PushoverChannel  …
                            │
                            ▼
                notification_send_log
```

## Intent rules

| Intent     | Trigger                                                              | Notification behavior                          |
|------------|----------------------------------------------------------------------|------------------------------------------------|
| Created    | `call_id` is new                                                     | Send to all derived topics (Agency/Jurisdiction/Unit). |
| Updated    | Existing row + any of `{call_type,full_address,alarm_level}` changed | Resend to **all** topics.                      |
| Updated    | Existing row + only new units/jurisdictions added                    | Send to **only the new** topics.               |
| Closed     | `ClosedFlag=true`                                                    | No notification (logged only).                 |
| (no event) | Existing row + no material change                                    | No event fired.                                |

## Delta-time gate

Events older than `NOTIFICATION_DELTA_SECONDS` (default 900) are dropped before any channel runs. This prevents a backlog replay from paging the world.

## Channels and config

A channel is a row in `notification_channels`:

| Column          | Meaning                                                       |
|-----------------|---------------------------------------------------------------|
| `name`          | Unique channel identifier (e.g. `ntfy_primary`).              |
| `type`          | `ntfy` or `pushover`.                                         |
| `enabled`       | Whether the dispatcher should consider this channel.          |
| `base_url`      | ntfy server URL or Pushover endpoint.                         |
| `config_json`   | Non-secret per-channel knobs. References env-var **names**, not values. |
| `last_error_*`  | Updated on permanent failure; surfaces on the dashboard.      |

`config_json` for ntfy:

```json
{
  "auth_token_env": "NTFY_AUTH_TOKEN",
  "alarm_priority_map": {"1": 3, "2": 4, "3": 5},
  "agency_tag_map": {"Fire": "fire_engine", "Police": "police_car"}
}
```

`config_json` for Pushover:

```json
{
  "token_env": "PUSHOVER_TOKEN",
  "user_env": "PUSHOVER_USER",
  "sound": "bike"
}
```

Secrets never live in `config_json` — only env-var names. The actual values are read from the environment via `Config::secret()` and registered with `SecretRegistry`, which the global `RedactingProcessor` uses to scrub them out of every log record.

## Send log

Every send attempt that produces a permanent outcome writes a `notification_send_log` row with `{ok, http_status, duration_ms, error?}`. Rows are pruned to the most recent 100 per channel as part of the same transaction.

## Operational tasks

| Task                          | Command                                                                       |
|-------------------------------|-------------------------------------------------------------------------------|
| List channels                 | `php bin/notifications.php list`                                              |
| Enable channel                | `php bin/notifications.php enable ntfy --base-url=https://ntfy.example`        |
| Disable channel               | `php bin/notifications.php disable ntfy`                                      |
| Inspect channel state in DB   | `SELECT * FROM notification_channels;`                                        |
| Inspect recent sends in DB    | `SELECT * FROM notification_send_log ORDER BY id DESC LIMIT 20;`              |

## Webhook channel

The `webhook` channel emits one HTTP POST per `CallProcessedEvent`. The payload is a JSON object built from a per-row template stored in `notification_channels.config_json`. Covers Slack, Discord, Mattermost, Home Assistant, and similar incoming-webhook integrations via configuration alone — no PHP changes are needed to add a new platform.

### Enable a webhook channel

CLI:

```bash
php bin/notifications.php enable webhook --base-url=https://hooks.slack.com/services/...
```

API equivalent:

```http
POST /api/notifications/webhook/enable
Content-Type: application/json

{ "base_url": "https://hooks.slack.com/services/..." }
```

The channel is created with a sensible default `template`, but you will usually want to edit `config_json` afterward to customize it for your platform.

### Template syntax

Two kinds of placeholder are supported:

- `{name}` — **string substitution**. The placeholder is replaced inline within any string-valued leaf of the template. Available tokens: `{intent}`, `{call_id}`, `{call_number}`, `{call_type}`, `{full_address}`, `{create_datetime}`, `{alarm_level}`, `{narrative}`, `{agency_type}`, `{jurisdiction}`, `{units}`, `{topics}` (comma-joined).
- `"${name}"` — **raw JSON array**. Must appear as the entire string value of a JSON field (surrounding quotes included). After substitution the quoted placeholder becomes a real JSON array literal. Available tokens: `${topics}`, `${units}`, `${jurisdiction}`.

Unknown placeholders pass through literally; they are not an error.

### Slack example

```json
{
  "template": {
    "text": ":rotating_light: *{call_type}* at {full_address}",
    "attachments": [
      {
        "fields": [
          {"title": "Intent",  "value": "{intent}", "short": true},
          {"title": "Topics",  "value": "{topics}", "short": true},
          {"title": "Units",   "value": "{units}",  "short": false}
        ]
      }
    ]
  }
}
```

Apply via SQL:

```sql
UPDATE notification_channels
SET config_json = '{ "template": { ... } }'
WHERE name = 'webhook_slack';
```

Or edit the row through the admin UI or `bin/notifications.php`.

### Discord example

```json
{
  "template": {
    "content": "**{call_type}** — {full_address}\nIntent: {intent}\nTopics: {topics}"
  }
}
```

### Authentication

If the receiver requires a bearer token or API key, add two keys to `config_json`:

- `auth_header` — the header name (e.g., `Authorization`, `X-Api-Key`).
- `auth_token_env` — the env-var **name** holding the token (e.g., `WEBHOOK_AUTH_TOKEN`).

At send time the channel reads the secret via `Config::secret($auth_token_env)`, registers it with `SecretRegistry` so the global `RedactingProcessor` scrubs it from logs, and sets the header. Tokens containing CR or LF are rejected at channel construction.

### Retry semantics

Same as ntfy and Pushover: 4 attempts total (initial + 3 retries, 1 s / 3 s / 9 s backoff). 2xx = success; 4xx = permanent failure (no retry); 5xx and network errors are retried. After exhaustion the channel's `last_error` is updated and a single `SendResult::fail` is recorded in `notification_send_log`.

## Adding a new channel type

Register a class that implements `NwsCad\Notifications\NotificationChannel` and add a `descriptor()` static method returning a `ChannelDescriptor`. Then add one line in `src/Notifications/registerChannels.php` to register it with `ChannelRegistry`. Add a unit test under `tests/Unit/Notifications/Channels/`.
