# Notifications dashboard UI — channel toggle + test send

| Field | Value |
|---|---|
| Date | 2026-05-08 |
| Status | Draft — pending user review |
| Target repo | `k9barry/nws-cad` |
| Predecessor spec | [2026-05-07-nws-endpoints-consolidation-design.md](2026-05-07-nws-endpoints-consolidation-design.md) |

## Problem

The `nws-endpoints` consolidation (v1.2.0) shipped a notifications module with `notification_channels` rows controlling which channels fire, plus a deliberately read-only dashboard view at `/notifications`. The only way to enable a channel is the `php bin/notifications.php` CLI inside the app container, and `bin/notifications.php test` is unimplemented (`exit(1)` with a TODO referencing PR #4).

Operators expect to enable/disable channels and verify them from the dashboard. Today they can't, so:

- A fresh deployment with `NTFY_*` and `PUSHOVER_*` env vars set still fires zero notifications until someone runs the CLI. (Observed: `notification_channels` empty, `notification_send_log` empty, despite a populated `.env`.)
- There is no way to confirm the configured `auth_token` / base URL is correct without dispatching a real CAD event past the delta-time gate.

## Goals

1. **First-time enable from the dashboard.** Operators can flip ntfy or pushover from off → on without shell access; the row is created on first toggle using `*_BASE_URL` from env, matching CLI semantics.
2. **Reversible toggle.** Off → on is one click; on → off requires confirmation.
3. **Synthetic test send.** A "Send test" button per enabled channel dispatches one synthetic notification through the real channel implementation, writes the result to `notification_send_log`, and shows the operator the outcome (HTTP status, error, duration).
4. **Implementation parity between CLI and UI.** Channel instantiation logic moves out of `src/watcher.php`'s inline closure into a shared factory; both the watcher and the new API endpoints construct channels the same way.
5. **No new auth surface.** This iteration assumes the dashboard remains behind whatever access controls already gate it (network/proxy). No CSRF, no role checks, no per-user config.

## Non-goals (explicitly out of scope)

- Custom channel names. The system stays on the `{type}_primary` naming convention.
- Multiple instances of the same type (e.g. two ntfy servers).
- Editing `base_url` or `config_json` from the UI. Both stay env- and CLI-driven.
- New channel types beyond ntfy and pushover.
- Schema changes. `notification_channels` and `notification_send_log` are unchanged.
- Authentication, authorization, audit log of who toggled what.
- Replacing the existing CLI (`bin/notifications.php`). The CLI keeps working.
- Bulk operations (enable-all, disable-all).

## Decisions (locked in via brainstorming)

| Decision | Choice |
|---|---|
| Channel scope | Toggle on/off + test send, per channel **type**. No name/URL/config editing. |
| First-time enable | UI always renders cards for `ntfy` and `pushover`. First toggle on creates the row using env-derived `base_url` and the same default `config_json` the CLI uses. |
| Confirm UX | Off→on: no confirm. On→off: Bootstrap confirm modal. Test result: Bootstrap result modal. |
| Test send mechanism | Build a synthetic `IncidentDto` + `NotificationContext` in the controller, instantiate the channel directly via the new factory, call `send()`, write each `SendResult` to `notification_send_log` with `intent='test'`, return the first result to the caller. **Bypasses the dispatcher** (and therefore the delta-time gate). |
| Test logging | Test sends are written to `notification_send_log` with `intent='test'` so they appear in the existing "Recent sends" panel. The send-log auto-prune (100 rows per channel) applies to test rows. |
| Refactor | Extract the inline channel-factory closure from `src/watcher.php` into `NwsCad\Notifications\ChannelFactory`. Both the watcher and the controller use it. |
| Failure surfacing | Missing required env var on enable → 422 with which env var was missing. UI shows the message inline below the card and reverts the toggle. |
| Type whitelist | `{type}` parameter on the new endpoints is whitelisted to `ntfy|pushover`. Anything else → 404. |

## Architecture

```
Browser (notifications.php view)
   │
   ├─ GET  /api/notifications/channels        (existing)
   ├─ GET  /api/notifications/log?channel=…   (existing)
   │
   ├─ POST /api/notifications/channels/{type}/enable    (new)
   ├─ POST /api/notifications/channels/{type}/disable   (new)
   └─ POST /api/notifications/channels/{type}/test      (new)
                  │
                  ▼
        NotificationsController
                  │
                  ├─ enable/disable: writes notification_channels
                  └─ test:
                        ├─ ChannelFactory::create($row)  ← shared with watcher
                        ├─ NtfyChannel|PushoverChannel::send($syntheticDto, $ctx)
                        └─ ChannelRepository::recordSend(…, intent='test', $r)
```

`src/watcher.php` is unchanged in behavior; the inline channel-factory closure is replaced with `ChannelFactory::create($row)`.

## Backend

### New endpoints (in `NotificationsController`)

| Method | Path | Behavior | Returns |
|---|---|---|---|
| POST | `/api/notifications/channels/{type}/enable` | Insert `{type}_primary` if absent (using `{TYPE}_BASE_URL` from env + default `config_json`); else `UPDATE … SET enabled=1`. | 200 with the channel row, or 422 `{error: "Missing env var: NTFY_BASE_URL"}` |
| POST | `/api/notifications/channels/{type}/disable` | `UPDATE notification_channels SET enabled=0 WHERE type=?`. | 200 `{updated: <int>}` |
| POST | `/api/notifications/channels/{type}/test` | Loads enabled row of `{type}`; builds synthetic DTO + context; sends via `ChannelFactory::create()->send()`; records each `SendResult` in `notification_send_log` with `intent='test'`. | 200 `{ok: bool, http_status: int|null, duration_ms: int, error: string|null, log_id: int}` or 422 if no enabled row. |

`{type}` is validated against `['ntfy','pushover']` before any DB or env lookup; anything else returns 404 from the router (no row matches), which the dispatch logic must handle defensively.

### Default config_json (matches CLI)

```php
$defaultConfig = $type === 'ntfy'
    ? '{"auth_token_env":"NTFY_AUTH_TOKEN","alarm_priority_map":{"1":3,"2":4,"3":5}}'
    : '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}';
```

The exact same string the CLI inserts on `enable` (see `bin/notifications.php` lines 71–73).

### Synthetic test payload

`IncidentDto`'s constructor is private; the only constructor is the static `IncidentDto::fromRow(array $row)`. The test send builds the row inline:

```php
$dto = IncidentDto::fromRow([
    'id'              => 0,
    'call_id'         => 0,
    'call_number'     => 'TEST-' . date('YmdHis'),
    'call_type'       => 'TEST',
    'agency_type'     => 'TEST',
    'jurisdiction'    => null,
    'units'           => '',
    'nature_of_call'  => 'Notification test',
    'full_address'    => 'Dashboard self-test',
    'alarm_level'     => 1,
    'create_datetime' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
]);

$ctx = new NotificationContext(
    intent: Intent::Created,
    resendAll: true,
    topicsToNotify: ['test'],
    channelConfig: [],
);
```

Single topic (`'test'`) so each test send produces exactly one `notification_send_log` row.

### `ChannelFactory` (new class)

The watcher's existing inline closure (`src/watcher.php` lines 62–84) decodes `config_json`, looks up env-var names from it, calls `Config::secret(...)` for each, and constructs `NtfyChannel` or `PushoverChannel`. The factory is a thin wrapper around that logic:

```php
namespace NwsCad\Notifications;

use NwsCad\Config;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;

final class ChannelFactory
{
    public function __construct(private readonly Config $config) {}

    public function create(array $row): NotificationChannel
    {
        $cfg = json_decode($row['config_json'] ?: '{}', true) ?: [];
        return match ($row['type']) {
            'ntfy' => new NtfyChannel(
                baseUrl: $row['base_url'],
                authToken: $this->config->secret($cfg['auth_token_env'] ?? 'NTFY_AUTH_TOKEN'),
                config: $cfg,
            ),
            'pushover' => new PushoverChannel(
                baseUrl: $row['base_url'],
                token: $this->config->secret($cfg['token_env']  ?? 'PUSHOVER_TOKEN'),
                user:  $this->config->secret($cfg['user_env']   ?? 'PUSHOVER_USER'),
                config: $cfg,
            ),
            default => throw new \InvalidArgumentException("Unknown channel type: {$row['type']}"),
        };
    }
}
```

Instance method (not static) so `Config` is injected — keeps the factory unit-testable with a fake Config. `src/watcher.php` constructs one factory at boot and passes `[$factory, 'create']` (or a closure wrapping it) where the inline closure is today. The `NotificationsController` instantiates its own factory the same way. No behavior change to the watcher path; the closure is removed.

### Routes (`public/api.php`)

```php
$router->post('/notifications/channels/{type}/enable',  [NotificationsController::class, 'enable']);
$router->post('/notifications/channels/{type}/disable', [NotificationsController::class, 'disable']);
$router->post('/notifications/channels/{type}/test',    [NotificationsController::class, 'test']);
```

The existing `Router` already supports `{param}` extraction.

### CSRF / auth

This iteration matches the rest of the dashboard: no CSRF token, no auth check beyond what the network/proxy provides. If/when authentication is added to the dashboard, these endpoints get the same protection as every other write endpoint at that time.

## Frontend

`src/Dashboard/Views/notifications.php` is rewritten end-to-end (the existing IIFE is replaced; the file's overall structure stays).

### Card list

Always render two cards in a fixed order:

1. ntfy (`name=ntfy_primary`)
2. pushover (`name=pushover_primary`)

Card data comes from `GET /api/notifications/channels`. If a card has no matching row, render with:

- "Not configured" subtitle
- Toggle in OFF state
- Test button disabled
- Recent sends list shows "no recent sends"

### Toggle (Bootstrap `form-switch`)

| Transition | Action |
|---|---|
| OFF → ON | `POST /api/notifications/channels/{type}/enable`. On 422, surface `error` text inline below the card (`<div class="alert alert-warning small">`) and revert the toggle. |
| ON → OFF | Open `#disable-confirm-modal` with the channel name. On confirm: `POST .../disable`, refetch channel data on success. On cancel: revert toggle. |

### Test button

Disabled while channel is off or while a request is in flight.

On click:
- `POST /api/notifications/channels/{type}/test`
- Show `#test-result-modal` with: outcome icon (`✓` / `✗`), HTTP status, duration in ms, error message (if any).
- After modal closes, refetch the card's recent sends list — the test will appear with `intent="test"`.

### Modals

Two reusable Bootstrap 5 modals defined once at the bottom of the view:

- `#disable-confirm-modal` — title `"Disable {type}?"`, body asks for confirmation, buttons `Cancel` / `Disable`.
- `#test-result-modal` — title `"Test send result"`, body populated by JS via `textContent`.

### Header copy

Line 10 of the view changes from:

> Read-only view of notification channels and recent send results. Toggle channels via `php bin/notifications.php`.

to:

> Manage notification channels. Add new channel types with `php bin/notifications.php enable <type>`.

### Conventions preserved

- All untrusted strings rendered via `textContent`, never interpolated into HTML.
- All API calls go through `Dashboard.apiRequest()` (with the existing fallback to plain fetch).
- No new JS dependencies; uses Bootstrap 5 modals already on the page.

## Error handling

| Condition | HTTP | UI behavior |
|---|---|---|
| Unknown `{type}` | 404 (router) | Should never reach the UI — only `ntfy` and `pushover` are wired into JS. |
| Enable with missing env var | 422 `{error: "Missing env var: NTFY_BASE_URL"}` | Inline alert under card; revert toggle. |
| Disable with no rows of type | 200 `{updated: 0}` | Idempotent; UI just reflects the off state. |
| Test on a channel that doesn't exist | 422 `{error: "Channel not found"}` | Should never reach the UI (button disabled). Defensive case. |
| Test on a disabled channel | 422 `{error: "Channel is disabled"}` | Should never reach the UI (button disabled). |
| Test send fails (4xx/5xx/network) | 200 `{ok: false, http_status: …, error: …}` | Result modal shows ✗ + details. The send was attempted; the `notification_send_log` row records the failure. |
| Unexpected DB error | 500 | Generic error toast. |

## Testing

### Unit / controller tests
`tests/Unit/Api/Controllers/NotificationsControllerTest.php` — add cases:

- `enable` inserts a row when the type has no row, using env-derived base_url
- `enable` flips `enabled=1` on an existing disabled row, leaves base_url alone
- `enable` returns 422 when `{TYPE}_BASE_URL` is unset and no row exists
- `disable` sets `enabled=0` on all rows of the type, returns 0 when no rows existed
- `test` returns 422 when no enabled row of the type exists
- `test` constructs the synthetic DTO and writes one row to `notification_send_log` with `intent='test'`, then returns the SendResult — using a stub channel injected via the factory so no real HTTP calls happen
- `test` records a row with `ok=0` when the stub channel returns a failed result

### Factory tests
`tests/Unit/Notifications/ChannelFactoryTest.php` (new):

- `create()` returns `NtfyChannel` for `type=ntfy`
- `create()` returns `PushoverChannel` for `type=pushover`
- `create()` throws `InvalidArgumentException` for unknown type
- Watcher integration: a single test that asserts the factory produces a channel that is `instanceof NotificationChannel` (smoke test for constructor wiring)

### Manual verification (post-deploy)

1. With `notification_channels` empty: load `/notifications`, flip ntfy on, expect a row to appear in DB with `enabled=1`.
2. Click "Send test"; expect a 200 success modal and a new `intent='test'` row in `notification_send_log`. Verify ntfy.sh actually received the push.
3. Flip ntfy off; expect confirm modal; on confirm, `enabled=0` in DB; Test button disables.
4. Same flow for pushover.
5. Temporarily unset `NTFY_BASE_URL` and rebuild: enable should 422 with the env var name; UI shows inline alert and reverts.

## Files touched

| File | Change |
|---|---|
| `src/Api/Controllers/NotificationsController.php` | Add `enable`, `disable`, `test` methods. Add a small `validateType()` helper. |
| `public/api.php` | Register 3 new POST routes. |
| `src/Notifications/ChannelFactory.php` | New file. |
| `src/watcher.php` | Replace inline channel-factory closure with `ChannelFactory::create()`. |
| `src/Dashboard/Views/notifications.php` | Replace IIFE; add 2 modals; update header copy. |
| `tests/Unit/Api/Controllers/NotificationsControllerTest.php` | Add ~7 tests. |
| `tests/Unit/Notifications/ChannelFactoryTest.php` | New file. |
| `CHANGELOG.md` | Add entry under the next version. |

No schema changes. No new env vars. No new docker config.

## Risks

- **`notification_send_log.intent` is `varchar(16)`.** `'test'` (4 chars) fits. Consumers (the existing `log()` endpoint, the Recent Sends list) treat it as an opaque string, so no display-side changes needed beyond verifying nothing chokes on the new value.
- **Send-log auto-prune.** `recordSend` keeps the latest 100 rows per channel; test sends count toward that cap. Acceptable.
- **No CSRF.** Consistent with the rest of the dashboard. If/when auth is added, these endpoints get the same treatment as the other write endpoints (none yet exist).
- **Constructor signature drift.** The watcher's inline closure passes specific args to channel constructors today. The factory must replicate them exactly; any drift would surface as a constructor error at watcher boot. Covered by the smoke test above.

## Open questions

None remaining at design time. Surface during implementation if encountered.
