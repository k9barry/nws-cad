# Notifications Dashboard UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dashboard UI for enabling/disabling ntfy + pushover channels and dispatching synthetic test notifications, replacing the CLI-only workflow.

**Architecture:** Three new POST endpoints on `NotificationsController` (`enable`, `disable`, `test`), backed by a new `Notifications\ChannelFactory` shared with the watcher. The existing read-only view in `src/Dashboard/Views/notifications.php` is rewritten to render always-visible cards for both channel types with toggle switches and a test button driven by the new endpoints.

**Tech Stack:** PHP 8.3, PHPUnit 10, MySQL 8 / PostgreSQL 16 (via `DB_TYPE`), Bootstrap 5, vanilla JS (no framework).

**Spec:** [`docs/superpowers/specs/2026-05-08-notifications-dashboard-ui-design.md`](../specs/2026-05-08-notifications-dashboard-ui-design.md)

---

## Test execution context

All test commands run **inside the `nws-cad-app` container** so they pick up the right env, PHP version, and PDO drivers. The host doesn't have PHP installed. Wrap commands with:

```bash
docker exec -i nws-cad-app sh -c '<command>'
```

`cleanTestDatabase()` and the `<env>` block in `phpunit.xml` provide a clean DB state per test. Inside the container, the test DB user differs from the docker default — `phpunit.xml`'s `<env>` tags only apply when the env var isn't already set, so the container's pre-baked `MYSQL_USER`/`MYSQL_PASSWORD` win. Existing tests already work this way; do not touch the test bootstrap.

The DB schema must be in sync across `database/mysql/init.sql`, `database/postgres/init.sql`, and `database/schema.sql`. **No schema changes in this plan**, so all three stay untouched.

---

## File structure

| File | Status | Responsibility |
|---|---|---|
| `src/Notifications/ChannelFactory.php` | **new** | Construct `NtfyChannel` / `PushoverChannel` from a DB row. Replaces the inline closure currently in `src/watcher.php`. Injectable `Config` dependency. |
| `tests/Unit/Notifications/ChannelFactoryTest.php` | **new** | Cover ntfy + pushover instantiation, unknown-type error path, env-var name resolution. |
| `src/watcher.php` | modify (lines 62–84) | Replace inline `$channelFactory = function(...)` closure with a `ChannelFactory` instance and pass `[$factory, 'create']` as the dispatcher's factory callable. |
| `src/Api/Controllers/NotificationsController.php` | modify | Add `enable()`, `disable()`, `test()`, and a private `validateType()` helper. |
| `tests/Integration/NotificationsApiTest.php` | modify | Add tests for the three new endpoints. Follow the existing `setUp` / `Response::resetForTesting()` pattern. |
| `public/api.php` | modify | Register three new POST routes. |
| `src/Dashboard/Views/notifications.php` | rewrite IIFE + add modals | Render fixed ntfy/pushover cards, toggle switches, test buttons, confirm + result modals. |
| `CHANGELOG.md` | modify | Add a `[Unreleased]` entry under "Added". |

---

## Task 1: ChannelFactory class (TDD)

**Files:**
- Create: `tests/Unit/Notifications/ChannelFactoryTest.php`
- Create: `src/Notifications/ChannelFactory.php`

- [ ] **Step 1.1: Write the failing tests**

Create `tests/Unit/Notifications/ChannelFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\ChannelFactory
 * @uses \NwsCad\Config
 * @uses \NwsCad\Notifications\Channels\NtfyChannel
 * @uses \NwsCad\Notifications\Channels\PushoverChannel
 * @uses \NwsCad\Logging\SecretRegistry
 */
class ChannelFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['NTFY_AUTH_TOKEN']  = 'ntfy-token';
        $_ENV['PUSHOVER_TOKEN']   = 'pushover-token';
        $_ENV['PUSHOVER_USER']    = 'pushover-user';
    }

    protected function tearDown(): void
    {
        unset($_ENV['NTFY_AUTH_TOKEN'], $_ENV['PUSHOVER_TOKEN'], $_ENV['PUSHOVER_USER']);
    }

    public function testCreatesNtfyChannel(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'ntfy',
            'base_url'    => 'https://ntfy.example',
            'config_json' => '{"auth_token_env":"NTFY_AUTH_TOKEN"}',
        ]);
        $this->assertInstanceOf(NtfyChannel::class, $channel);
    }

    public function testCreatesPushoverChannel(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'pushover',
            'base_url'    => 'https://api.pushover.net',
            'config_json' => '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}',
        ]);
        $this->assertInstanceOf(PushoverChannel::class, $channel);
    }

    public function testThrowsOnUnknownType(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown channel type: webhook');
        $factory->create([
            'type'        => 'webhook',
            'base_url'    => 'https://x',
            'config_json' => '{}',
        ]);
    }

    public function testHandlesEmptyConfigJson(): void
    {
        $factory = new ChannelFactory(Config::getInstance());
        $channel = $factory->create([
            'type'        => 'ntfy',
            'base_url'    => 'https://ntfy.example',
            'config_json' => '',
        ]);
        $this->assertInstanceOf(NtfyChannel::class, $channel);
    }
}
```

- [ ] **Step 1.2: Run tests to verify they fail**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Unit/Notifications/ChannelFactoryTest.php'
```

Expected: errors with "Class NwsCad\Notifications\ChannelFactory not found".

- [ ] **Step 1.3: Implement ChannelFactory**

Create `src/Notifications/ChannelFactory.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;

class ChannelFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array{type:string,base_url:string,config_json:string} $row
     */
    public function create(array $row): NotificationChannel
    {
        $cfg = json_decode($row['config_json'] !== '' ? $row['config_json'] : '{}', true) ?: [];

        return match ($row['type']) {
            'ntfy' => new NtfyChannel(
                baseUrl: $row['base_url'],
                authToken: $this->config->secret($cfg['auth_token_env'] ?? 'NTFY_AUTH_TOKEN'),
                config: $cfg,
            ),
            'pushover' => new PushoverChannel(
                baseUrl: $row['base_url'],
                token: $this->config->secret($cfg['token_env'] ?? 'PUSHOVER_TOKEN'),
                user:  $this->config->secret($cfg['user_env']  ?? 'PUSHOVER_USER'),
                config: $cfg,
            ),
            default => throw new InvalidArgumentException("Unknown channel type: {$row['type']}"),
        };
    }
}
```

- [ ] **Step 1.4: Run tests to verify they pass**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Unit/Notifications/ChannelFactoryTest.php'
```

Expected: 4 tests pass.

- [ ] **Step 1.5: Commit**

```bash
git add src/Notifications/ChannelFactory.php tests/Unit/Notifications/ChannelFactoryTest.php
git commit -m "feat(notifications): add ChannelFactory for shared channel construction

Extracted from watcher.php's inline closure so the upcoming dashboard
test-send endpoint can construct channels the same way the dispatcher
does.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Refactor watcher to use ChannelFactory

**Files:**
- Modify: `src/watcher.php:62-84`

- [ ] **Step 2.1: Read current closure**

```bash
docker exec -i nws-cad-app sh -c 'sed -n "60,95p" /var/www/src/watcher.php'
```

Expected: see the inline `$channelFactory = function (array $row) use ($config): NotificationChannel { ... };` block.

- [ ] **Step 2.2: Replace closure with factory**

Replace lines 62–84 of `src/watcher.php` (the entire `$channelFactory = function ...;` block) with:

```php
    $channelFactoryInstance = new \NwsCad\Notifications\ChannelFactory($config);
    $channelFactory = [$channelFactoryInstance, 'create'];
```

After the change the surrounding context should read:

```php
    // ... incidentLoader closure above ...

    $channelFactoryInstance = new \NwsCad\Notifications\ChannelFactory($config);
    $channelFactory = [$channelFactoryInstance, 'create'];

    $notificationDispatcher = new NotificationDispatcher(
        channelRepo: new ChannelRepository(),
        incidentLoader: $incidentLoader,
        channelFactory: $channelFactory,
        deltaSeconds: $deltaSeconds,
    );
```

`$notificationDispatcher`'s constructor parameter is typed as `callable`, so passing the `[object, 'method']` array is valid.

- [ ] **Step 2.3: Verify watcher boots cleanly**

Restart the app container and check the heartbeat updates:

```bash
docker compose restart app
sleep 8
docker exec nws-cad-app sh -c 'stat -c "%Y" /var/www/logs/.watcher-heartbeat'
docker logs --tail 20 nws-cad-app
```

Expected: heartbeat mtime is recent (within last few seconds), and recent docker logs show no fatal errors. The watcher should resume processing files normally — file processing log lines should continue.

- [ ] **Step 2.4: Run the existing notifications test suite to confirm no regressions**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Unit/Notifications tests/Integration/NotificationsApiTest.php tests/Integration/NotificationChannelsTableTest.php tests/Integration/NotificationSendLogTableTest.php'
```

Expected: all green.

- [ ] **Step 2.5: Commit**

```bash
git add src/watcher.php
git commit -m "refactor(notifications): use ChannelFactory in watcher

Replaces the inline closure with the new shared factory. Behavior
unchanged; preparing for the controller endpoints to reuse the same
construction path.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Controller — `enable` endpoint (TDD)

**Files:**
- Modify: `tests/Integration/NotificationsApiTest.php`
- Modify: `src/Api/Controllers/NotificationsController.php`

- [ ] **Step 3.1: Write the failing tests**

Append to `tests/Integration/NotificationsApiTest.php` (inside the class, before the closing `}`):

```php
    public function testEnableInsertsRowWhenAbsent(): void
    {
        $_ENV['NTFY_BASE_URL']   = 'https://ntfy.example';
        $_ENV['NTFY_AUTH_TOKEN'] = 'token';

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertSame('ntfy_primary', $payload['data']['name']);
        $this->assertSame(1, (int) $payload['data']['enabled']);

        $row = self::$db->query("SELECT * FROM notification_channels WHERE name='ntfy_primary'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('https://ntfy.example', $row['base_url']);
        $this->assertStringContainsString('NTFY_AUTH_TOKEN', $row['config_json']);

        unset($_ENV['NTFY_BASE_URL'], $_ENV['NTFY_AUTH_TOKEN']);
    }

    public function testEnableFlipsExistingDisabledRow(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 0, 'https://existing', '{\"auth_token_env\":\"NTFY_AUTH_TOKEN\"}')");

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertSame(1, (int) $payload['data']['enabled']);
        $this->assertSame('https://existing', $payload['data']['base_url']);
    }

    public function testEnableReturns422WhenBaseUrlEnvMissing(): void
    {
        unset($_ENV['NTFY_BASE_URL']);
        putenv('NTFY_BASE_URL');

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('NTFY_BASE_URL', $payload['error']);
        $this->assertSame(0, (int) self::$db->query(
            "SELECT COUNT(*) FROM notification_channels WHERE name='ntfy_primary'"
        )->fetchColumn());
    }

    public function testEnableReturns404ForUnknownType(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->enable('webhook');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Unknown channel type', $payload['error']);
    }
```

- [ ] **Step 3.2: Run tests to verify they fail**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php --filter testEnable'
```

Expected: failures with "Method `enable` does not exist" or similar.

- [ ] **Step 3.3: Implement `enable()` and `validateType()`**

Append to `src/Api/Controllers/NotificationsController.php` (inside the class, after `log()`):

```php
    /** POST /api/notifications/channels/{type}/enable */
    public function enable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }

            $envKey  = strtoupper($type) . '_BASE_URL';
            $baseUrl = $_ENV[$envKey] ?? getenv($envKey) ?: '';

            $name = "{$type}_primary";

            $stmt = $this->db->prepare("SELECT id, base_url FROM notification_channels WHERE name = ?");
            $stmt->execute([$name]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing === false) {
                if ($baseUrl === '') {
                    Response::error("Missing env var: {$envKey}", 422);
                    return;
                }
                $defaultConfig = $type === 'ntfy'
                    ? '{"auth_token_env":"NTFY_AUTH_TOKEN","alarm_priority_map":{"1":3,"2":4,"3":5}}'
                    : '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}';

                $ins = $this->db->prepare(
                    "INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                     VALUES (?, ?, 1, ?, ?)"
                );
                $ins->execute([$name, $type, $baseUrl, $defaultConfig]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE notification_channels
                     SET enabled = 1, updated_at = CURRENT_TIMESTAMP
                     WHERE name = ?"
                );
                $upd->execute([$name]);
            }

            $row = $this->db->prepare(
                "SELECT id, name, type, enabled, base_url,
                        last_error_at, last_error_message, created_at, updated_at
                 FROM notification_channels WHERE name = ?"
            );
            $row->execute([$name]);
            Response::success($row->fetch(\PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            Response::error('Failed to enable channel: ' . $e->getMessage(), 500);
        }
    }

    private function validateType(string $type): bool
    {
        return in_array($type, ['ntfy', 'pushover'], true);
    }
```

- [ ] **Step 3.4: Run tests to verify they pass**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php --filter testEnable'
```

Expected: 4 tests pass.

- [ ] **Step 3.5: Commit**

```bash
git add src/Api/Controllers/NotificationsController.php tests/Integration/NotificationsApiTest.php
git commit -m "feat(notifications): add enable endpoint to NotificationsController

POST /api/notifications/channels/{type}/enable creates the row
(type whitelist: ntfy|pushover) using {TYPE}_BASE_URL from env, or
flips the enabled flag if a row already exists. Returns 422 with the
missing env var name when unconfigured.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Controller — `disable` endpoint (TDD)

**Files:**
- Modify: `tests/Integration/NotificationsApiTest.php`
- Modify: `src/Api/Controllers/NotificationsController.php`

- [ ] **Step 4.1: Write the failing tests**

Append to `tests/Integration/NotificationsApiTest.php`:

```php
    public function testDisableSetsEnabledToZero(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 1, 'u', '{}')");

        $controller = new NotificationsController();
        ob_start();
        $controller->disable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame(1, (int) $payload['data']['updated']);
        $this->assertSame(0, (int) self::$db->query(
            "SELECT enabled FROM notification_channels WHERE name='ntfy_primary'"
        )->fetchColumn());
    }

    public function testDisableIsIdempotentWhenNoRows(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->disable('pushover');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame(0, (int) $payload['data']['updated']);
    }

    public function testDisableReturns404ForUnknownType(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->disable('webhook');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
    }
```

- [ ] **Step 4.2: Run tests to verify they fail**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php --filter testDisable'
```

Expected: failures with "Method `disable` does not exist".

- [ ] **Step 4.3: Implement `disable()`**

Append to `src/Api/Controllers/NotificationsController.php` (inside the class, after `enable()`):

```php
    /** POST /api/notifications/channels/{type}/disable */
    public function disable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }
            $stmt = $this->db->prepare(
                "UPDATE notification_channels
                 SET enabled = 0, updated_at = CURRENT_TIMESTAMP
                 WHERE type = ?"
            );
            $stmt->execute([$type]);
            Response::success(['updated' => $stmt->rowCount()]);
        } catch (Exception $e) {
            Response::error('Failed to disable channel: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 4.4: Run tests to verify they pass**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php --filter testDisable'
```

Expected: 3 tests pass.

- [ ] **Step 4.5: Commit**

```bash
git add src/Api/Controllers/NotificationsController.php tests/Integration/NotificationsApiTest.php
git commit -m "feat(notifications): add disable endpoint to NotificationsController

POST /api/notifications/channels/{type}/disable sets enabled=0 for
all rows of {type}. Idempotent.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Controller — `test` endpoint (TDD)

**Files:**
- Modify: `tests/Integration/NotificationsApiTest.php`
- Modify: `src/Api/Controllers/NotificationsController.php`

This task uses **constructor injection** so the controller's channel factory can be replaced with a stub in tests, avoiding real HTTP calls.

- [ ] **Step 5.1: Add factory injection to the controller constructor**

Modify `src/Api/Controllers/NotificationsController.php`. Change the `use` block at the top:

```php
use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\Intent;
use NwsCad\Notifications\NotificationContext;
use DateTimeImmutable;
use PDO;
use Exception;
```

(Note: `Intent` lives at `NwsCad\Notifications\Events\Intent` per `src/Notifications/Events/CallProcessedEvent.php`. Verify the actual namespace by reading `src/Notifications/Events/Intent.php` before writing the use; adjust if it differs.)

Then change the constructor and add fields:

```php
    private PDO $db;
    private ChannelFactory $factory;
    private ChannelRepository $repo;

    public function __construct(?ChannelFactory $factory = null, ?ChannelRepository $repo = null)
    {
        $this->db = Database::getConnection();
        $this->factory = $factory ?? new ChannelFactory(Config::getInstance());
        $this->repo = $repo ?? new ChannelRepository($this->db);
    }
```

The optional args preserve the existing zero-arg construction used by `public/api.php` while letting tests inject stubs.

- [ ] **Step 5.2: Write the failing tests**

Append to `tests/Integration/NotificationsApiTest.php`:

```php
    public function testTestReturns422WhenChannelMissing(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->test('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('not found', $payload['error']);
    }

    public function testTestReturns422WhenChannelDisabled(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 0, 'u', '{}')");

        $controller = new NotificationsController();
        ob_start();
        $controller->test('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('disabled', $payload['error']);
    }

    public function testTestSendsAndLogsSuccess(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 1, 'https://ntfy.example', '{\"auth_token_env\":\"NTFY_AUTH_TOKEN\"}')");
        $channelId = (int) self::$db->lastInsertId();

        $stub = new class implements \NwsCad\Notifications\NotificationChannel {
            public static function type(): string { return 'ntfy'; }
            public function send(\NwsCad\Notifications\IncidentDto $i, \NwsCad\Notifications\NotificationContext $c): array {
                return [\NwsCad\Notifications\SendResult::ok(200, 12, 'test')];
            }
        };

        $factory = new class($stub) extends \NwsCad\Notifications\ChannelFactory {
            public function __construct(private $stub) {
                parent::__construct(\NwsCad\Config::getInstance());
            }
            public function create(array $row): \NwsCad\Notifications\NotificationChannel { return $this->stub; }
        };

        $controller = new NotificationsController($factory);
        ob_start();
        $controller->test('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertTrue((bool) $payload['data']['ok']);
        $this->assertSame(200, (int) $payload['data']['http_status']);

        $logged = self::$db->query(
            "SELECT intent, ok, topic FROM notification_send_log WHERE channel_id = {$channelId}"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('test', $logged['intent']);
        $this->assertSame(1, (int) $logged['ok']);
        $this->assertSame('test', $logged['topic']);
    }

    public function testTestLogsFailureWhenChannelReturnsFail(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 1, 'u', '{}')");

        $stub = new class implements \NwsCad\Notifications\NotificationChannel {
            public static function type(): string { return 'ntfy'; }
            public function send(\NwsCad\Notifications\IncidentDto $i, \NwsCad\Notifications\NotificationContext $c): array {
                return [\NwsCad\Notifications\SendResult::fail(503, 9, 'Service Unavailable', 'test')];
            }
        };
        $factory = new class($stub) extends \NwsCad\Notifications\ChannelFactory {
            public function __construct(private $stub) {
                parent::__construct(\NwsCad\Config::getInstance());
            }
            public function create(array $row): \NwsCad\Notifications\NotificationChannel { return $this->stub; }
        };

        $controller = new NotificationsController($factory);
        ob_start();
        $controller->test('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertFalse((bool) $payload['data']['ok']);
        $this->assertSame(503, (int) $payload['data']['http_status']);
        $this->assertSame('Service Unavailable', $payload['data']['error']);

        $logged = self::$db->query(
            "SELECT ok FROM notification_send_log ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertSame(0, (int) $logged);
    }

    public function testTestReturns404ForUnknownType(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->test('webhook');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
    }
```

Add the test class doc block `@uses` for the new transitive deps. Update the doc comment above `class NotificationsApiTest` to include:

```php
 * @uses \NwsCad\Notifications\ChannelFactory
 * @uses \NwsCad\Notifications\ChannelRepository
 * @uses \NwsCad\Notifications\IncidentDto
 * @uses \NwsCad\Notifications\NotificationContext
 * @uses \NwsCad\Notifications\SendResult
 * @uses \NwsCad\Notifications\Events\Intent
```

(If the `Intent` enum's namespace differs from the spec, fix the `@uses` line accordingly.)

- [ ] **Step 5.3: Run tests to verify they fail**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php --filter testTest'
```

Expected: failures with "Method `test` does not exist".

- [ ] **Step 5.4: Confirm Intent's namespace before implementing**

```bash
docker exec -i nws-cad-app sh -c 'grep -n "namespace" /var/www/src/Notifications/Events/Intent.php'
```

Expected: outputs the actual namespace (likely `NwsCad\Notifications\Events`). Use that exact namespace in the next step's `use` statement.

- [ ] **Step 5.5: Implement `test()`**

Append to `src/Api/Controllers/NotificationsController.php` (inside the class):

```php
    /** POST /api/notifications/channels/{type}/test */
    public function test(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT id, name, type, enabled, base_url, config_json
                 FROM notification_channels WHERE type = ? AND name = ?"
            );
            $stmt->execute([$type, "{$type}_primary"]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                Response::error('Channel not found. Enable it first.', 422);
                return;
            }
            if ((int) $row['enabled'] !== 1) {
                Response::error('Channel is disabled.', 422);
                return;
            }

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

            $channel = $this->factory->create($row);
            $results = $channel->send($dto, $ctx);

            if ($results === []) {
                Response::error('Channel returned no results', 500);
                return;
            }

            foreach ($results as $r) {
                $this->repo->recordSend((int) $row['id'], null, 'test', $r);
            }

            $first = $results[0];
            Response::success([
                'ok'          => $first->ok,
                'http_status' => $first->httpStatus,
                'duration_ms' => $first->durationMs,
                'error'       => $first->error,
                'topic'       => $first->topic,
            ]);
        } catch (Exception $e) {
            Response::error('Failed to send test: ' . $e->getMessage(), 500);
        }
    }
```

If Step 5.4 showed the `Intent` enum is at `NwsCad\Notifications\Events\Intent`, the `use` statement added in Step 5.1 needs to be `use NwsCad\Notifications\Events\Intent;` (not `use NwsCad\Notifications\Intent;`). Fix the `use` line if needed before running tests.

- [ ] **Step 5.6: Run tests to verify they pass**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Integration/NotificationsApiTest.php'
```

Expected: all tests in the file pass — the new test cases plus the original three (`testChannelsReturnsEmpty`, `testChannelsReturnsRows`, `testLogReturnsRecentRowsForChannel`) and the enable/disable cases from Tasks 3–4.

- [ ] **Step 5.7: Commit**

```bash
git add src/Api/Controllers/NotificationsController.php tests/Integration/NotificationsApiTest.php
git commit -m "feat(notifications): add test send endpoint to NotificationsController

POST /api/notifications/channels/{type}/test dispatches a synthetic
notification through the configured channel, writes the result to
notification_send_log with intent='test', and returns the SendResult.
Adds optional ChannelFactory/ChannelRepository constructor injection
so tests can stub the network layer.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Register routes

**Files:**
- Modify: `public/api.php` (after line 99)

- [ ] **Step 6.1: Add the three POST routes**

Edit `public/api.php`. After the existing two notifications routes (line 99 is `$router->get('/notifications/log', …)`), add:

```php
$router->post('/notifications/channels/{type}/enable',  [NotificationsController::class, 'enable']);
$router->post('/notifications/channels/{type}/disable', [NotificationsController::class, 'disable']);
$router->post('/notifications/channels/{type}/test',    [NotificationsController::class, 'test']);
```

The router already extracts `{type}` and passes it positionally to the handler.

- [ ] **Step 6.2: Smoke-test the routes from the host**

```bash
curl -s -X POST http://localhost:8080/api/notifications/channels/ntfy/enable | head -c 400; echo
curl -s -X POST http://localhost:8080/api/notifications/channels/ntfy/disable | head -c 400; echo
curl -s -X POST http://localhost:8080/api/notifications/channels/webhook/enable -w '\nHTTP %{http_code}\n'
```

(API container exposes 8080 per `docker-compose.yml`.)

Expected:
1. First call inserts the ntfy row (200 with the row JSON, or 422 with `Missing env var: NTFY_BASE_URL` if `.env` is not exporting that var into the container — set it in `.env` and `docker compose up -d` if so).
2. Second call returns `{"success":true,"data":{"updated":1}}`.
3. Third call returns 404 / unknown type error.

- [ ] **Step 6.3: Commit**

```bash
git add public/api.php
git commit -m "feat(api): register notification channel toggle + test routes

Three POST routes that drive the upcoming dashboard UI:
  /api/notifications/channels/{type}/enable
  /api/notifications/channels/{type}/disable
  /api/notifications/channels/{type}/test

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Rewrite the dashboard view

**Files:**
- Modify: `src/Dashboard/Views/notifications.php` (rewrite IIFE, add modals, update header copy)

This is one larger commit because the file is small (~100 lines) and the change is internally cohesive.

- [ ] **Step 7.1: Replace the file**

Overwrite `src/Dashboard/Views/notifications.php` with the new content below. Reads the same `Dashboard.apiRequest` wrapper used by the rest of the dashboard, follows the same `textContent`-only data-binding rule.

```php
<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<div class="row">
    <div class="col-12">
        <h2 class="mb-3"><i class="bi bi-bell"></i> Notifications</h2>
        <p class="text-muted">Manage notification channels. Add new channel types with <code>php bin/notifications.php enable &lt;type&gt;</code>.</p>
        <div id="notifications-channels-container" class="row g-3"></div>
    </div>
</div>

<template id="channel-card-template">
    <div class="col-md-6">
        <div class="card channel-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><strong class="channel-name"></strong>
                  <span class="badge bg-secondary channel-type ms-2"></span></span>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input channel-toggle" type="checkbox" role="switch">
                </div>
            </div>
            <div class="card-body">
                <p class="mb-1"><small>Base URL: <code class="channel-base-url"></code></small></p>
                <p class="channel-status mb-1 small text-muted"></p>
                <p class="channel-error mb-2 text-danger" hidden>
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="channel-error-time"></span> —
                    <span class="channel-error-message"></span>
                </p>
                <div class="channel-inline-error alert alert-warning small py-2 mb-2" hidden></div>
                <button type="button" class="btn btn-sm btn-outline-primary channel-test-btn" disabled>
                    <i class="bi bi-send"></i> Send test
                </button>
                <h6 class="mt-3">Recent sends</h6>
                <ul class="list-group list-group-flush channel-log small"></ul>
            </div>
        </div>
    </div>
</template>

<div class="modal fade" id="disable-confirm-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Disable channel?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">Disable <strong id="disable-confirm-name"></strong>? Notifications will stop firing for this channel until it is re-enabled.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="disable-confirm-btn">Disable</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="test-result-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Test send result</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p><span id="test-result-icon"></span> <strong id="test-result-summary"></strong></p>
        <dl class="row small mb-0">
          <dt class="col-4">HTTP status</dt><dd class="col-8" id="test-result-status"></dd>
          <dt class="col-4">Duration</dt><dd class="col-8" id="test-result-duration"></dd>
          <dt class="col-4">Topic</dt><dd class="col-8" id="test-result-topic"></dd>
          <dt class="col-4">Error</dt><dd class="col-8" id="test-result-error"></dd>
        </dl>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
(function() {
    const apiBase = window.APP_CONFIG.apiBaseUrl;
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');
    const KNOWN = [
        { type: 'ntfy',     name: 'ntfy_primary' },
        { type: 'pushover', name: 'pushover_primary' },
    ];

    const apiCall = (path, options) => {
        if (window.Dashboard && Dashboard.apiRequest) {
            return Dashboard.apiRequest(path.replace(/^\/api/, ''), options);
        }
        const opts = Object.assign({ headers: { 'Accept': 'application/json' } }, options || {});
        return fetch(`${apiBase}${path.startsWith('/api') ? path.slice(4) : path}`, opts).then(r => r.json());
    };

    function setText(node, sel, value) {
        const el = node.querySelector(sel);
        if (el) el.textContent = value ?? '';
    }

    async function fetchAllChannels() {
        const resp = await apiCall('/notifications/channels');
        const byName = {};
        if (resp.success) for (const ch of resp.data.items) byName[ch.name] = ch;
        return byName;
    }

    async function fetchLog(channelId) {
        const resp = await apiCall(`/notifications/log?channel=${encodeURIComponent(channelId)}&limit=10`);
        return (resp.success && resp.data.items) ? resp.data.items : [];
    }

    function renderLog(ul, items) {
        ul.replaceChildren();
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = 'no recent sends';
            ul.appendChild(li);
            return;
        }
        for (const row of items) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between';
            const left = document.createElement('span');
            left.textContent = `${row.ok ? '✓' : '✗'} ${row.created_at ?? ''} ${row.intent ?? ''} ${row.topic ?? ''}`;
            li.appendChild(left);
            const right = document.createElement('span');
            right.className = 'text-muted';
            right.textContent = `${row.http_status ?? ''} (${row.duration_ms ?? 0}ms)`;
            li.appendChild(right);
            ul.appendChild(li);
        }
    }

    function showInlineError(card, message) {
        const div = card.querySelector('.channel-inline-error');
        div.textContent = message;
        div.hidden = false;
        setTimeout(() => { div.hidden = true; div.textContent = ''; }, 8000);
    }

    function showTestResult(payload) {
        const icon = document.getElementById('test-result-icon');
        const summary = document.getElementById('test-result-summary');
        if (payload.ok) {
            icon.textContent = '✓';
            icon.className = 'text-success fs-4';
            summary.textContent = 'Success';
        } else {
            icon.textContent = '✗';
            icon.className = 'text-danger fs-4';
            summary.textContent = 'Failed';
        }
        document.getElementById('test-result-status').textContent   = payload.http_status ?? '—';
        document.getElementById('test-result-duration').textContent = `${payload.duration_ms ?? 0} ms`;
        document.getElementById('test-result-topic').textContent    = payload.topic ?? '—';
        document.getElementById('test-result-error').textContent    = payload.error ?? '—';

        const modalEl = document.getElementById('test-result-modal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmDisable(name) {
        return new Promise((resolve) => {
            const modalEl = document.getElementById('disable-confirm-modal');
            document.getElementById('disable-confirm-name').textContent = name;
            const btn = document.getElementById('disable-confirm-btn');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const onConfirm = () => { cleanup(); modal.hide(); resolve(true); };
            const onHide = () => { cleanup(); resolve(false); };
            const cleanup = () => {
                btn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHide);
            };
            btn.addEventListener('click', onConfirm, { once: true });
            modalEl.addEventListener('hidden.bs.modal', onHide, { once: true });
            modal.show();
        });
    }

    async function renderCard(known, byName) {
        const node = tpl.content.cloneNode(true);
        const card = node.querySelector('.channel-card');
        const ch = byName[known.name] || null;

        setText(node, '.channel-name', known.name);
        setText(node, '.channel-type', known.type);
        setText(node, '.channel-base-url', ch ? ch.base_url : '(not configured)');
        setText(node, '.channel-status', ch ? '' : 'Not yet configured. Enable to create.');

        const toggle = node.querySelector('.channel-toggle');
        const testBtn = node.querySelector('.channel-test-btn');
        toggle.checked = !!(ch && Number(ch.enabled));
        testBtn.disabled = !toggle.checked;

        if (ch && ch.last_error_at) {
            const err = node.querySelector('.channel-error');
            err.hidden = false;
            setText(node, '.channel-error-time', ch.last_error_at);
            setText(node, '.channel-error-message', ch.last_error_message || '');
        }

        const ul = node.querySelector('.channel-log');
        if (ch) renderLog(ul, await fetchLog(ch.id));
        else renderLog(ul, []);

        toggle.addEventListener('change', async () => {
            const wantEnable = toggle.checked;
            if (wantEnable) {
                const resp = await apiCall(`/notifications/channels/${known.type}/enable`, { method: 'POST' });
                if (!resp.success) {
                    toggle.checked = false;
                    testBtn.disabled = true;
                    showInlineError(card, resp.error || 'Failed to enable channel');
                    return;
                }
                testBtn.disabled = false;
                await refreshCard(known, card);
            } else {
                const ok = await confirmDisable(known.name);
                if (!ok) {
                    toggle.checked = true;
                    return;
                }
                const resp = await apiCall(`/notifications/channels/${known.type}/disable`, { method: 'POST' });
                if (!resp.success) {
                    toggle.checked = true;
                    showInlineError(card, resp.error || 'Failed to disable channel');
                    return;
                }
                testBtn.disabled = true;
                await refreshCard(known, card);
            }
        });

        testBtn.addEventListener('click', async () => {
            testBtn.disabled = true;
            try {
                const resp = await apiCall(`/notifications/channels/${known.type}/test`, { method: 'POST' });
                if (!resp.success) {
                    showTestResult({ ok: false, error: resp.error });
                } else {
                    showTestResult(resp.data);
                }
                await refreshCard(known, card);
            } finally {
                testBtn.disabled = !toggle.checked;
            }
        });

        container.appendChild(node);
    }

    async function refreshCard(known, card) {
        const byName = await fetchAllChannels();
        const ch = byName[known.name] || null;
        card.querySelector('.channel-base-url').textContent = ch ? ch.base_url : '(not configured)';
        card.querySelector('.channel-status').textContent   = ch ? '' : 'Not yet configured. Enable to create.';
        const toggle = card.querySelector('.channel-toggle');
        const testBtn = card.querySelector('.channel-test-btn');
        toggle.checked = !!(ch && Number(ch.enabled));
        testBtn.disabled = !toggle.checked;
        const errEl = card.querySelector('.channel-error');
        if (ch && ch.last_error_at) {
            errEl.hidden = false;
            card.querySelector('.channel-error-time').textContent = ch.last_error_at;
            card.querySelector('.channel-error-message').textContent = ch.last_error_message || '';
        } else {
            errEl.hidden = true;
        }
        renderLog(card.querySelector('.channel-log'), ch ? await fetchLog(ch.id) : []);
    }

    (async function init() {
        const byName = await fetchAllChannels();
        for (const known of KNOWN) {
            await renderCard(known, byName);
        }
    })();
})();
</script>
```

- [ ] **Step 7.2: Manual smoke test in browser**

Open the dashboard's notifications page (`http://localhost:8080/notifications` or whichever path the dashboard uses — check `public/index.php` if unsure). Confirm:

1. Both ntfy and pushover cards render even when DB is empty.
2. Toggle on ntfy: row appears in DB, toggle stays on, no error.
3. Click "Send test": modal appears with success/failure detail, "Recent sends" list updates with the test entry.
4. Toggle off: confirm modal appears, on confirm the row's `enabled` flips to 0, test button disables.
5. Browser console: no errors.

Take note of any browser-console errors and fix them before committing.

- [ ] **Step 7.3: Commit**

```bash
git add src/Dashboard/Views/notifications.php
git commit -m "feat(dashboard): notifications channel toggle + test send UI

Replaces the read-only notifications view with always-visible cards
for ntfy and pushover. Includes a Bootstrap toggle switch (with a
confirm modal on disable), a 'Send test' button (with a result modal),
and the existing recent-sends log per channel. All data binding goes
through textContent.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Changelog + final regression sweep

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 8.1: Add changelog entry**

Read `CHANGELOG.md` to find the unreleased / next-version section pattern. Add an entry under "Added":

```markdown
- Notifications dashboard UI: enable/disable channels and dispatch synthetic test sends from `/notifications` without dropping into the shell. Backed by three new endpoints (`POST /api/notifications/channels/{type}/{enable|disable|test}`) and a shared `Notifications\ChannelFactory` for channel construction.
```

If there's no "Unreleased" section, add one above the most recent release block following the existing format.

- [ ] **Step 8.2: Run the full notification-related test suite**

```bash
docker exec -i nws-cad-app sh -c './vendor/bin/phpunit tests/Unit/Notifications tests/Integration/NotificationsApiTest.php tests/Integration/NotificationChannelsTableTest.php tests/Integration/NotificationSendLogTableTest.php'
```

Expected: all green.

- [ ] **Step 8.3: Run the full unit + integration suites for regression**

```bash
docker exec -i nws-cad-app sh -c 'composer test:unit && composer test:integration'
```

Expected: all green. If any pre-existing failure surfaces unrelated to this work, document it but don't fix it here — out of scope.

- [ ] **Step 8.4: Verify watcher is still healthy**

```bash
docker exec nws-cad-app sh -c 'stat -c "%Y" /var/www/logs/.watcher-heartbeat'
date +%s
docker logs --tail 30 nws-cad-app | grep -E "Processing file|Notification dispatch|Fatal|Error" | tail -20
```

Expected: heartbeat mtime is within ~30 seconds of `date +%s`. Recent docker logs show file processing continuing without fatal errors.

- [ ] **Step 8.5: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog entry for notifications dashboard UI

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Self-review

**Spec coverage:**
- ✅ Toggle on/off + test send: Tasks 3, 4, 5, 7
- ✅ First-time enable creates row from env: Task 3 (`testEnableInsertsRowWhenAbsent`)
- ✅ Confirm-on-disable, modal-on-test-result: Task 7 (`confirmDisable`, `showTestResult`)
- ✅ Inline error on missing env var: Task 7 (`showInlineError`) + Task 3 422 path
- ✅ `intent='test'` in send log: Task 5 (`testTestSendsAndLogsSuccess`)
- ✅ ChannelFactory shared with watcher: Tasks 1 + 2
- ✅ No schema changes — confirmed throughout
- ✅ `{type}` whitelist (ntfy|pushover): `validateType()` in Task 3, exercised in Tasks 3/4/5
- ✅ Synthetic IncidentDto via `fromRow`: Task 5 (`test()` body)
- ✅ Routes registered: Task 6
- ✅ Header copy update: Task 7

**Risks acknowledged in plan:**
- `notification_send_log.intent = 'test'` (4 chars, fits varchar(16)).
- `Intent` enum namespace verified in Step 5.4 before implementation.
- `phpunit.xml` strict-coverage gates — the existing `@uses` patterns are extended in Task 5.

**Out of scope (per spec):** custom names, multiple instances, base_url editing, auth, schema changes — none added.
