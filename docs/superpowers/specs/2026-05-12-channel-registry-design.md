# ChannelRegistry & WebhookChannel Design

**Date:** 2026-05-12
**Status:** Design draft (awaiting review)
**Origin:** Codebase audit, original ask "spec out a means of adding/modifying new transport channels."

## Goal

Localize all per-channel-type knowledge into a single class per channel, so adding a new transport (Slack, Discord, Mattermost, custom webhook) is one new file + one bootstrap line — not edits to `ChannelFactory`, `NotificationsController`, `bin/notifications.php`, and their tests.

Ship one new channel — `WebhookChannel` — as a real consumer of the new abstraction, covering Slack/Discord/Mattermost/Home-Assistant via JSON-template config rather than per-platform PHP classes.

## Current coupling

The string `'ntfy'` and/or `'pushover'` appears in at least these places today:

| Site | Coupling |
|---|---|
| `src/Notifications/ChannelFactory.php` | Hardcoded `match` on type with per-channel constructor arguments |
| `src/Api/Controllers/NotificationsController.php` | `validateType()` hardcoded allowlist; `enable()` default-config branch |
| `bin/notifications.php` | Validation list (3 sites), base-URL env var ternary, default-config ternary |
| Tests for the above | Mock the same hardcoded set |

Adding a third channel today means touching 5+ files. The `NotificationChannel::type()` static method already exists but isn't used for dispatch — `ChannelFactory` has its own hardcoded map.

## Architecture

A static `ChannelRegistry` holds one `ChannelDescriptor` per type. Every coupling site queries the registry instead of hardcoding the list. The DB schema is unchanged — `notification_channels.type` stays a free-string, and the registry is the runtime allowlist.

```
ChannelRegistry  ──registers──>  ChannelDescriptor (type, label, defaults, factory closure)
       │
       ├── used by  →  ChannelFactory::create($row)              // replaces the hardcoded match
       ├── used by  →  NotificationsController::validateType()
       └── used by  →  bin/notifications.php (help, validation, defaults)
```

`NotificationDispatcher` is unchanged — it never knew about types; it just calls `ChannelFactory::create($row)`.

## Components

### `src/Notifications/ChannelDescriptor.php` (new, `final` value object)

```php
final class ChannelDescriptor
{
    public function __construct(
        public readonly string $type,           // 'ntfy', 'pushover', 'webhook'
        public readonly string $label,          // human-readable
        public readonly string $baseUrlEnv,     // CLI/API default-base-url env var name
        public readonly array  $requiredEnvs,   // env vars checked at enable time
        public readonly array  $defaultConfig,  // becomes config_json on first enable
        /** @var \Closure(array,Config): NotificationChannel */
        public readonly \Closure $factory,
    ) {}
}
```

### `src/Notifications/ChannelRegistry.php` (new, static container)

```php
final class ChannelRegistry
{
    /** @var array<string, ChannelDescriptor> */
    private static array $by_type = [];

    public static function register(ChannelDescriptor $d): void { self::$by_type[$d->type] = $d; }
    public static function get(string $type): ?ChannelDescriptor { return self::$by_type[$type] ?? null; }
    public static function has(string $type): bool { return isset(self::$by_type[$type]); }
    public static function types(): array { return array_keys(self::$by_type); }
    public static function all(): array { return self::$by_type; }
    public static function clear(): void { self::$by_type = []; }  // tests only
}
```

### `src/Notifications/NotificationChannel.php` (interface change)

The interface gains `descriptor()` and drops the unused `type()`:

```php
interface NotificationChannel
{
    public static function descriptor(): ChannelDescriptor;   // NEW (replaces type())
    public function send(IncidentDto $incident, NotificationContext $context): array;
}
```

`NotificationChannel::type()` has zero callers in the codebase — it was a contract method nothing relied on. Removing it eliminates a redundancy (the type is in the descriptor) and is safe. `NtfyChannel`, `PushoverChannel`, and the new `WebhookChannel` implement `descriptor()`. Example for `NtfyChannel`:

```php
public static function descriptor(): ChannelDescriptor
{
    return new ChannelDescriptor(
        type:          'ntfy',
        label:         'ntfy.sh',
        baseUrlEnv:    'NTFY_BASE_URL',
        requiredEnvs:  ['NTFY_AUTH_TOKEN'],
        defaultConfig: ['auth_token_env' => 'NTFY_AUTH_TOKEN'],
        factory: function (array $row, Config $cfg): NotificationChannel {
            $config = json_decode($row['config_json'] !== '' ? $row['config_json'] : '{}', true) ?: [];
            return new self(
                baseUrl:   $row['base_url'],
                authToken: $cfg->secret($config['auth_token_env'] ?? 'NTFY_AUTH_TOKEN'),
                config:    $config,
            );
        },
    );
}
```

### `src/Notifications/registerChannels.php` (new, single source of truth)

```php
<?php
declare(strict_types=1);

use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\Channels\WebhookChannel;

ChannelRegistry::clear();   // idempotent — safe to include multiple times
ChannelRegistry::register(NtfyChannel::descriptor());
ChannelRegistry::register(PushoverChannel::descriptor());
ChannelRegistry::register(WebhookChannel::descriptor());
```

Included from `src/watcher.php` (watcher process) and `src/bootstrap.php` (HTTP process).

### `src/Notifications/ChannelFactory.php` (refactor)

```php
public function create(array $row): NotificationChannel
{
    $d = ChannelRegistry::get($row['type'])
        ?? throw new InvalidArgumentException("Unknown channel type: {$row['type']}");
    return ($d->factory)($row, $this->config);
}
```

### `src/Notifications/Channels/WebhookChannel.php` (new)

Generic HTTP POST channel. `config_json` shape:

```json
{
  "template": { "text": "{intent}: {call_type} at {full_address}", "topics": "${topics}" },
  "auth_header":   "Authorization",
  "auth_token_env": "WEBHOOK_AUTH_TOKEN",
  "timeout_sec":   10
}
```

Sends one HTTP POST per `CallProcessedEvent` (regardless of topic count). Returns a single `SendResult`.

#### Template substitution

Two-pass:

1. **String substitution** — walk the template; for every string leaf, replace `{placeholder}` occurrences with stringified values. Unknown placeholders pass through literally.
2. **Raw substitution** — after JSON-encoding, post-replace `"${name}"` (with the surrounding quotes) with the raw JSON value (e.g. `"${topics}"` → `["A","B","C"]`).

Authoring rule: raw placeholders must appear as quoted string values in the template so the template remains valid JSON during authoring.

**Available `{string}` placeholders:**
`{intent}`, `{call_id}`, `{call_number}`, `{call_type}`, `{call_type_description}`, `{full_address}`, `{create_datetime}`, `{alarm_level}`, `{narrative}`, `{agency_type}`, `{jurisdiction}`, `{units}`, `{topics}` (comma-joined).

**Available `${array}` raw placeholders:**
`${topics}`, `${units}`, `${jurisdiction}`.

#### Slack example template

```json
{
  "text": ":rotating_light: *{call_type}* at {full_address}",
  "attachments": [
    { "fields": [
        {"title": "Intent",  "value": "{intent}",        "short": true},
        {"title": "Topics",  "value": "{topics}",        "short": true},
        {"title": "Units",   "value": "{units}",         "short": false}
    ]}
  ]
}
```

#### Auth handling

If `auth_header` and `auth_token_env` are both set in `config_json`, the channel reads the secret via `Config::secret($auth_token_env)` and adds `$auth_header: <value>` to the request headers. Secrets are registered with `SecretRegistry` so `RedactingProcessor` scrubs them from logs. CR/LF in token values is rejected at channel construction (same guard as `NtfyChannel`).

#### Retry semantics

Identical to ntfy/pushover: 3 attempts with 1s/3s/9s backoff. 4xx is permanent (no retry). 5xx and network failures retry. Configurable per-row via `config_json.timeout_sec` (default 10).

### `src/Notifications/Channels/HttpPost.php` (new method)

```php
public function postJson(string $url, array $payload, int $timeoutSec, array $headers = []): array
```

Adds a JSON-body sibling to the existing form-fields `post()`. Returns `['status' => int, 'body' => string]`. The existing `post()` is unchanged (used by `PushoverChannel`).

## Data flow

### Boot (HTTP request)

```
public/index.php / public/api.php
   require __DIR__ . '/../src/bootstrap.php'
        Config::getInstance()
        require __DIR__ . '/Notifications/registerChannels.php'
        SecurityHeaders::setAll(...)
        CorsPolicy::apply(...)
        TrustedProxy::guard(...)
        $GLOBALS['__identity'] = Identity::extract(...)
```

### Boot (watcher)

```
src/watcher.php
   Config::getInstance()
   require __DIR__ . '/Notifications/registerChannels.php'
   EventDispatcher::subscribe(NotificationDispatcher::handle(...))
   FileWatcher::start()
```

### Event delivery (unchanged behaviour — the whole point)

```
AegisXmlParser commits
   └─> EventDispatcher.dispatch(CallProcessedEvent)
        └─> NotificationDispatcher.handle()
             └─> for each row in channelRepo.listEnabled():
                  └─> ChannelFactory.create(row)
                       └─> ChannelRegistry.get(row.type).factory(row, config)
                  └─> channel.send(dto, context)  // → SendResult[]
                  └─> channelRepo.recordSend(...)
```

### CLI: `php bin/notifications.php enable webhook --base-url=https://hooks.slack.com/...`

```
1. parse args
2. d = ChannelRegistry::get('webhook')  → ChannelDescriptor or null (→ "Unknown type" error showing available)
3. base_url = --base-url ?? getenv(d.baseUrlEnv) ?? error
4. UrlValidator::validateChannelBaseUrl(base_url, ...)
5. for each env in d.requiredEnvs: fail if not set
6. INSERT notification_channels (name, type, enabled=1, base_url, config_json=JSON(d.defaultConfig))
```

### API: `POST /api/notifications/{type}/enable`

`NotificationsController::validateType($type)` becomes `ChannelRegistry::has($type)`. The hardcoded `defaultConfig` branch in `enable()` becomes `$descriptor->defaultConfig` lookup. Identity recording and URL validation are unchanged.

## Error handling

| Scenario | Behaviour |
|---|---|
| DB row with unknown `type` | `ChannelRegistry::get()` returns null, factory throws `InvalidArgumentException("Unknown channel type: X")`; `NotificationDispatcher` catches Throwable, marks row failed with the message |
| Closure-based factory throws (e.g. `MissingSecretException`) | Caught by existing try/catch in `NotificationDispatcher::handle()`; row marked failed |
| `WebhookChannel` `config_json` missing `template` | Factory throws `InvalidArgumentException('webhook: template required')`; row marked failed |
| `WebhookChannel` template contains `{unknown}` | Pass-through: placeholder stays literal in the payload. Operator sees it in the receiver. |
| `WebhookChannel` raw substitution finds `${unknown}` | Pass-through: stays literal. |
| `WebhookChannel` HTTP failure | Existing retry semantics; final result returned via `SendResult` |
| CLI: `enable badtype` | STDERR: `Unknown channel type: badtype. Available: ntfy, pushover, webhook` |
| API: `POST /api/notifications/badtype/enable` | 400 with `errors.available_types: [...]` in response body |
| Bootstrap order: registry queried before populated | Tests register channels in `setUp()`; production paths always call after `registerChannels.php`. Unit test guards: `RegisterChannelsBootTest` asserts include populates ≥1 descriptor. |

## Testing

### New unit tests

- `tests/Unit/Notifications/ChannelRegistryTest.php` — register/get/has/types/all/clear round-trip; duplicate-type overwrite
- `tests/Unit/Notifications/ChannelDescriptorTest.php` — immutability; closure invocation produces `NotificationChannel`
- `tests/Unit/Notifications/RegisterChannelsBootTest.php` — include `registerChannels.php`, assert `types()` returns exactly `['ntfy','pushover','webhook']`
- `tests/Unit/Notifications/Channels/WebhookChannelTest.php` — `{placeholder}` substitution, `${array}` substitution, unknown placeholder passes through, missing template throws, auth header injected
- `tests/Unit/Notifications/Channels/WebhookChannelHttpTest.php` — `HttpPost::postJson` mocked; 5xx retries (3), 4xx terminal, network failure retries, success returns one `SendResult`

### Refactored existing tests

- `tests/Unit/Notifications/ChannelFactoryTest.php` — uses `ChannelRegistry::register(NtfyChannel::descriptor())` in setUp, `clear()` in tearDown
- `tests/Integration/NotificationsApiTest.php` — adds `testEnableWebhookSucceeds`, `testEnableUnknownTypeReturnsAvailable`, `testEnableWebhookRequiresTemplate`

### New integration test

- `tests/Integration/Notifications/WebhookEndToEndTest.php` — spins a local PHP HTTP server on `127.0.0.1:0` via `proc_open`, enables a webhook channel pointing at it, dispatches a synthetic `CallProcessedEvent`, asserts one POST with the expected substituted body and `topics` JSON array

### Test-isolation convention

Every test that registers channels MUST call `ChannelRegistry::clear()` in `tearDown()`. Convention encoded in a `tests/Support/RegistryTestCase.php` base class; documented in `docs/TESTING.md`.

### Coverage tags

New classes get `@covers <Class>`. Test classes need `@uses` for the transitive set — `\NwsCad\Notifications\ChannelRegistry`, `\NwsCad\Notifications\ChannelDescriptor`, plus the standard `Database`, `Config`, `Response`, `Logger`, `Logging\RedactingProcessor`, `Logging\SecretRegistry`.

## Rollout

Zero data migration. Schema unchanged. Existing `ntfy`/`pushover` rows continue to work as soon as the registry is populated at boot.

### TDD-ordered tasks (implementation plan will expand each)

1. `ChannelDescriptor` value object + tests
2. `ChannelRegistry` static container + tests
3. `NotificationChannel` interface: add `descriptor()`, drop unused `type()`
4. `descriptor()` on `NtfyChannel` and `PushoverChannel` + tests
5. `src/Notifications/registerChannels.php` + boot test
6. `ChannelFactory::create()` refactored to use registry
7. `registerChannels.php` wired into `src/watcher.php` and `src/bootstrap.php`
8. `NotificationsController::validateType()` + `enable()` defaults refactored to use registry
9. `bin/notifications.php` (validation, help text, env defaults) refactored to use registry
10. `HttpPost::postJson($url, $payload, $timeout, $headers)` method + tests
11. `WebhookChannel` (descriptor, send, template substitution) + unit tests
12. `WebhookChannel` registered in `registerChannels.php`
13. Webhook end-to-end integration test
14. Docs: `docs/NOTIFICATIONS.md` (webhook + template syntax + Slack/Discord examples), `CHANGELOG.md`

Steps 3/4/6 land in the same commit (interface change forces all implementers to gain `descriptor()` together).

## Out of scope

- Async outbox / worker (separate audit item)
- Per-channel rate limiting
- Channel quotas / spend caps
- UI for adding/editing channels
- SMTP / non-HTTP transports

## Risk register

- **Static registry state leaks across tests** — mitigated by `RegistryTestCase::tearDown()` and explicit `clear()` discipline
- **`config_json.template` is operator-authored JSON** — bad JSON breaks delivery for that one channel only; we surface the error via `markFailure` so it lands in `last_error`
- **Webhook template placeholders pass through literally on typos** — accepted tradeoff for production resilience; operator sees the literal in the receiver
