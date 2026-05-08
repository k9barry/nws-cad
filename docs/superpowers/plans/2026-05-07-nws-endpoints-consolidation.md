# nws-endpoints Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold the nws-endpoints notification pipeline (ntfy, Pushover) into nws-cad as an in-process Notifications module that subscribes to a `CallProcessedEvent` fired by `AegisXmlParser` after commit, with hardened secrets/topics/logging and a read-only dashboard view; archive nws-endpoints.

**Architecture:** Single-process. New namespace `NwsCad\Notifications\*`. After `AegisXmlParser` commits, it dispatches `CallProcessedEvent` through a tiny in-process `EventDispatcher`. `NotificationDispatcher` subscribes, applies a delta-time gate plus intent-based change-detection rules, builds a typed `IncidentDto`, fans out to enabled channels (`NtfyChannel`, `PushoverChannel`), and writes a per-attempt `notification_send_log` row. Secrets live in env vars surfaced via `Config::secret()` and are scrubbed from every log record by a global `RedactingProcessor`.

**Tech Stack:** PHP 8.3, PDO (MySQL 8.0 / PostgreSQL 16), Monolog 3, PHPUnit 10.5, cURL, Mockery 1.6.

**Spec reference:** `docs/superpowers/specs/2026-05-07-nws-endpoints-consolidation-design.md`

**Repos:**
- Target: `/home/jcleaver/nws-cad` (all PHP work)
- Companion (PR #6 only): `/home/jcleaver/nws-endpoints` (final commit + GitHub archive)

**Conventions to honor (already in repo):**
- Namespace `NwsCad\*` (PSR-4 from `src/`); tests `NwsCad\Tests\*` (PSR-4 from `tests/`).
- Every PHP file starts with `declare(strict_types=1);`.
- Singletons: `Config::getInstance()`, `Logger::getInstance()`, `Database::getConnection()`.
- Tests run under strict PHPUnit (`requireCoverageMetadata="true"`); every test class needs a `@covers` doc-block.
- Bootstrap helpers: `getTestDbConnection()`, `cleanTestDatabase()` (in `tests/bootstrap.php`). Extend the latter when adding tables.
- Migrations live in `database/mysql/init.sql` and `database/postgres/init.sql` (append at the end before the user-creation footer in MySQL).
- Composer scripts: `composer test`, `composer test:unit`, `composer test:integration`, `composer test:security`. Single test: `./vendor/bin/phpunit --filter testName tests/Path/File.php`.

**File structure (new files only):**

```
src/
  Config.php                                         # MODIFY: add secret()
  Exceptions/
    MissingSecretException.php                       # CREATE
  Logging/
    SecretRegistry.php                               # CREATE
    RedactingProcessor.php                           # CREATE
  Notifications/
    Events/
      Intent.php                                     # CREATE (enum)
      CallProcessedEvent.php                         # CREATE
    EventDispatcher.php                              # CREATE
    TopicSanitizer.php                               # CREATE
    IncidentDto.php                                  # CREATE
    SendResult.php                                   # CREATE
    NotificationContext.php                          # CREATE
    NotificationChannel.php                          # CREATE (interface)
    NotificationDispatcher.php                       # CREATE
    ChannelRepository.php                            # CREATE
    Channels/
      NtfyChannel.php                                # CREATE
      PushoverChannel.php                            # CREATE
  Api/Controllers/
    NotificationsController.php                      # CREATE
  Dashboard/Views/
    notifications.php                                # CREATE
    notifications-mobile.php                         # CREATE
  AegisXmlParser.php                                 # MODIFY: emit event after commit
  watcher.php                                        # MODIFY: register subscriber
  Logger.php                                         # MODIFY: register processor
bin/
  notifications.php                                  # CREATE (CLI)
database/
  mysql/init.sql                                     # MODIFY: append two tables
  postgres/init.sql                                  # MODIFY: append two tables
docs/
  NOTIFICATIONS.md                                   # CREATE
public/
  index.php                                          # MODIFY: add /notifications route
  api.php                                            # MODIFY: register controller
tests/
  bootstrap.php                                      # MODIFY: extend cleanTestDatabase()
  Unit/
    ConfigSecretTest.php                             # CREATE
    Logging/
      SecretRegistryTest.php                         # CREATE
      RedactingProcessorTest.php                     # CREATE
    Notifications/
      EventDispatcherTest.php                        # CREATE
      IncidentDtoTest.php                            # CREATE
      TopicSanitizerTest.php                         # CREATE
      NotificationDispatcherTest.php                 # CREATE
      Channels/
        NtfyChannelTest.php                          # CREATE
        PushoverChannelTest.php                      # CREATE
  Integration/
    NotificationChannelsTableTest.php                # CREATE
    NotificationSendLogTableTest.php                 # CREATE
    AegisXmlParserDispatchTest.php                   # CREATE
    NotificationsApiTest.php                         # CREATE
  Security/
    TopicInjectionTest.php                           # CREATE
    SecretRedactionTest.php                          # CREATE
.env.example                                         # MODIFY: notification vars
README.md                                            # MODIFY: notifications section
CLAUDE.md                                            # MODIFY: notifications section
CHANGELOG.md                                         # MODIFY: 1.2.0 entry
```

**Branching:** All work in `nws-cad/main` unless the engineer prefers per-PR feature branches. Each PR header below is a logical commit boundary; commit at every step that completes a green test cycle.

---

## PR #1 — Scaffolding: secrets plumbing, redacted logging, event bus, primitives, migrations

**Goal:** Land all infrastructure that PR #2 will plug into. The parser does not yet fire events; channels do not yet exist. Every new component is independently tested.

**Acceptance criteria:**
- All new unit tests pass (`composer test:unit`).
- New migrations applied cleanly to a freshly created `nws_cad_test` database (both MySQL and PostgreSQL drivers).
- Existing test suite stays green.
- Coverage stays ≥ 80 %.
- No occurrence of `extract(` in any new file under `src/Notifications/` or `src/Logging/`.

### Task 1.1: `MissingSecretException`

**Files:**
- Create: `src/Exceptions/MissingSecretException.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Exceptions/MissingSecretExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Exceptions;

use NwsCad\Exceptions\MissingSecretException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \NwsCad\Exceptions\MissingSecretException
 */
class MissingSecretExceptionTest extends TestCase
{
    public function testForKeyProducesPredictableMessage(): void
    {
        $e = MissingSecretException::forKey('NTFY_AUTH_TOKEN');

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame('Required secret "NTFY_AUTH_TOKEN" is not set in the environment.', $e->getMessage());
        $this->assertSame('NTFY_AUTH_TOKEN', $e->getKey());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter MissingSecretExceptionTest tests/Unit/Exceptions/MissingSecretExceptionTest.php`
Expected: FAIL with "Class NwsCad\Exceptions\MissingSecretException not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Exceptions/MissingSecretException.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use RuntimeException;

class MissingSecretException extends RuntimeException
{
    private string $key;

    public static function forKey(string $key): self
    {
        $e = new self(sprintf('Required secret "%s" is not set in the environment.', $key));
        $e->key = $key;
        return $e;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter MissingSecretExceptionTest tests/Unit/Exceptions/MissingSecretExceptionTest.php`
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
cd /home/jcleaver/nws-cad
git add src/Exceptions/MissingSecretException.php tests/Unit/Exceptions/MissingSecretExceptionTest.php
git commit -m "feat(notifications): add MissingSecretException"
```

---

### Task 1.2: `SecretRegistry`

A static registry of secret literal values. `Config::secret()` registers; `RedactingProcessor` reads.

**Files:**
- Create: `src/Logging/SecretRegistry.php`
- Test: `tests/Unit/Logging/SecretRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\SecretRegistry
 */
class SecretRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    public function testRegisterAndGetAll(): void
    {
        SecretRegistry::register('hunter2');
        SecretRegistry::register('s3cr3t');

        $values = SecretRegistry::getAll();

        sort($values);
        $this->assertSame(['hunter2', 's3cr3t'], $values);
    }

    public function testRegisterDeduplicates(): void
    {
        SecretRegistry::register('abc');
        SecretRegistry::register('abc');

        $this->assertCount(1, SecretRegistry::getAll());
    }

    public function testRegisterIgnoresEmptyAndShortValues(): void
    {
        SecretRegistry::register('');
        SecretRegistry::register('xy');

        $this->assertSame([], SecretRegistry::getAll());
    }

    public function testReset(): void
    {
        SecretRegistry::register('abc');
        SecretRegistry::reset();

        $this->assertSame([], SecretRegistry::getAll());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter SecretRegistryTest`
Expected: FAIL with "Class NwsCad\Logging\SecretRegistry not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Logging/SecretRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Logging;

/**
 * Static registry of secret literal values that must never appear in log output.
 * Populated by Config::secret() at the moment a secret is read; consumed by
 * RedactingProcessor on every log record.
 *
 * Values shorter than 3 characters are ignored — they would otherwise produce
 * spurious matches (e.g. a token of "ok" would redact every occurrence of "ok"
 * in normal log messages).
 */
final class SecretRegistry
{
    /** @var array<string,true> */
    private static array $values = [];

    public static function register(string $value): void
    {
        if (strlen($value) < 3) {
            return;
        }
        self::$values[$value] = true;
    }

    /** @return string[] */
    public static function getAll(): array
    {
        return array_keys(self::$values);
    }

    public static function reset(): void
    {
        self::$values = [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter SecretRegistryTest`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Logging/SecretRegistry.php tests/Unit/Logging/SecretRegistryTest.php
git commit -m "feat(notifications): add SecretRegistry"
```

---

### Task 1.3: `RedactingProcessor`

A Monolog processor that replaces literal occurrences of any registered secret with `***` in record `message`, `context`, and `extra`.

**Files:**
- Create: `src/Logging/RedactingProcessor.php`
- Test: `tests/Unit/Logging/RedactingProcessorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use NwsCad\Logging\RedactingProcessor;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\RedactingProcessor
 */
class RedactingProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    private function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function testRedactsSecretInMessage(): void
    {
        SecretRegistry::register('hunter2');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('login token=hunter2 ok'));

        $this->assertSame('login token=*** ok', $out->message);
    }

    public function testRedactsSecretInNestedContext(): void
    {
        SecretRegistry::register('topsecret');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('hello', ['headers' => ['Authorization' => 'Bearer topsecret']]));

        $this->assertSame('Bearer ***', $out->context['headers']['Authorization']);
    }

    public function testRedactsSecretInExtra(): void
    {
        SecretRegistry::register('xyzabc');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('m', [], ['raw' => 'value:xyzabc']));

        $this->assertSame('value:***', $out->extra['raw']);
    }

    public function testNoOpWhenNoSecretsRegistered(): void
    {
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('plain message', ['k' => 'v']));

        $this->assertSame('plain message', $out->message);
        $this->assertSame(['k' => 'v'], $out->context);
    }

    public function testHandlesNonStringScalarsWithoutModification(): void
    {
        SecretRegistry::register('abc');
        $proc = new RedactingProcessor();

        $out = ($proc)($this->record('m', ['count' => 42, 'flag' => true, 'pi' => 3.14, 'nil' => null]));

        $this->assertSame(42, $out->context['count']);
        $this->assertTrue($out->context['flag']);
        $this->assertSame(3.14, $out->context['pi']);
        $this->assertNull($out->context['nil']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter RedactingProcessorTest`
Expected: FAIL with "Class NwsCad\Logging\RedactingProcessor not found".

- [ ] **Step 3: Write minimal implementation**

Create `src/Logging/RedactingProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = SecretRegistry::getAll();
        if ($secrets === []) {
            return $record;
        }

        return $record->with(
            message: $this->scrubString($record->message, $secrets),
            context: $this->scrubArray($record->context, $secrets),
            extra: $this->scrubArray($record->extra, $secrets),
        );
    }

    /** @param string[] $secrets */
    private function scrubString(string $value, array $secrets): string
    {
        return str_replace($secrets, '***', $value);
    }

    /**
     * @param array<mixed> $value
     * @param string[] $secrets
     * @return array<mixed>
     */
    private function scrubArray(array $value, array $secrets): array
    {
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $value[$k] = $this->scrubString($v, $secrets);
            } elseif (is_array($v)) {
                $value[$k] = $this->scrubArray($v, $secrets);
            }
        }
        return $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter RedactingProcessorTest`
Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Logging/RedactingProcessor.php tests/Unit/Logging/RedactingProcessorTest.php
git commit -m "feat(notifications): add RedactingProcessor scrubbing registered secrets"
```

---

### Task 1.4: Wire `RedactingProcessor` into `Logger`

**Files:**
- Modify: `src/Logger.php` (push processor before handlers fire)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Logging/LoggerRedactionWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Logging;

use Monolog\Handler\TestHandler;
use NwsCad\Logger;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logger
 * @covers \NwsCad\Logging\RedactingProcessor
 */
class LoggerRedactionWiringTest extends TestCase
{
    public function testLoggerScrubsRegisteredSecretsBeforeHandlersSeeThem(): void
    {
        SecretRegistry::reset();
        SecretRegistry::register('topsecret-abc-123');

        $logger = Logger::getInstance();
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->info('auth header was Bearer topsecret-abc-123');

        $records = $handler->getRecords();
        $logger->popHandler();

        $this->assertNotEmpty($records);
        $last = end($records);
        $this->assertStringContainsString('***', $last->message);
        $this->assertStringNotContainsString('topsecret-abc-123', $last->message);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter LoggerRedactionWiringTest`
Expected: FAIL — secret string still appears in record.

- [ ] **Step 3: Modify `src/Logger.php`**

Replace the body of `createLogger()` to push the processor first thing on the logger. Edit:

```php
// Old (after the `$logger = new MonologLogger('nws-cad');` line):
//   (just builds handlers)
//
// New:
$logger = new MonologLogger('nws-cad');
$logger->pushProcessor(new \NwsCad\Logging\RedactingProcessor());

// ... existing handler-setup code unchanged ...
```

Concretely, in `src/Logger.php` at the top of `createLogger()`, locate the line:

```php
$logger = new MonologLogger('nws-cad');
```

and add immediately below it:

```php
$logger->pushProcessor(new RedactingProcessor());
```

Add the `use` statement at the top of the file:

```php
use NwsCad\Logging\RedactingProcessor;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter LoggerRedactionWiringTest`
Expected: 1 passed.

Run the full unit suite to confirm no regression: `composer test:unit`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Logger.php tests/Unit/Logging/LoggerRedactionWiringTest.php
git commit -m "feat(notifications): register RedactingProcessor on the global logger"
```

---

### Task 1.5: `Config::secret()` and `Config::secretOptional()`

**Files:**
- Modify: `src/Config.php`
- Test: `tests/Unit/ConfigSecretTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Config;
use NwsCad\Exceptions\MissingSecretException;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Config
 */
class ConfigSecretTest extends TestCase
{
    protected function setUp(): void
    {
        SecretRegistry::reset();
    }

    public function testSecretReturnsValueFromEnvAndRegistersIt(): void
    {
        $_ENV['TEST_SECRET_VALUE'] = 'tok-abcdefg';

        $value = Config::getInstance()->secret('TEST_SECRET_VALUE');

        $this->assertSame('tok-abcdefg', $value);
        $this->assertContains('tok-abcdefg', SecretRegistry::getAll());

        unset($_ENV['TEST_SECRET_VALUE']);
    }

    public function testSecretThrowsWhenMissing(): void
    {
        unset($_ENV['UNSET_SECRET_KEY']);
        putenv('UNSET_SECRET_KEY');

        $this->expectException(MissingSecretException::class);
        $this->expectExceptionMessage('Required secret "UNSET_SECRET_KEY"');

        Config::getInstance()->secret('UNSET_SECRET_KEY');
    }

    public function testSecretOptionalReturnsNullWhenMissing(): void
    {
        unset($_ENV['UNSET_OPTIONAL_KEY']);
        putenv('UNSET_OPTIONAL_KEY');

        $this->assertNull(Config::getInstance()->secretOptional('UNSET_OPTIONAL_KEY'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ConfigSecretTest`
Expected: FAIL — `Config::secret` does not exist.

- [ ] **Step 3: Modify `src/Config.php`**

Add `use` statements at the top:

```php
use NwsCad\Exceptions\MissingSecretException;
use NwsCad\Logging\SecretRegistry;
```

Add two public methods to the class:

```php
public function secret(string $key): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        throw MissingSecretException::forKey($key);
    }
    SecretRegistry::register($value);
    return $value;
}

public function secretOptional(string $key): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    SecretRegistry::register($value);
    return $value;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter ConfigSecretTest`
Expected: 3 passed.

Confirm full unit suite still green: `composer test:unit`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/Unit/ConfigSecretTest.php
git commit -m "feat(notifications): add Config::secret/secretOptional with SecretRegistry"
```

---

### Task 1.6: `Intent` enum

**Files:**
- Create: `src/Notifications/Events/Intent.php`
- Test: covered by event test below; no separate test file.

- [ ] **Step 1: Write the failing test**

(See Task 1.7 — `CallProcessedEventTest` exercises the enum.)

- [ ] **Step 2: Skip — implementation-only step**

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/Events/Intent.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Events;

enum Intent: string
{
    case Created = 'Created';
    case Updated = 'Updated';
    case Closed  = 'Closed';
}
```

- [ ] **Step 4: Confirm syntactically valid**

Run: `php -l src/Notifications/Events/Intent.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Events/Intent.php
git commit -m "feat(notifications): add Intent enum"
```

---

### Task 1.7: `CallProcessedEvent`

**Files:**
- Create: `src/Notifications/Events/CallProcessedEvent.php`
- Test: `tests/Unit/Notifications/Events/CallProcessedEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Events;

use DateTimeImmutable;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Events\CallProcessedEvent
 * @covers \NwsCad\Notifications\Events\Intent
 */
class CallProcessedEventTest extends TestCase
{
    public function testHoldsAllFields(): void
    {
        $now = new DateTimeImmutable('2026-05-07 12:34:56');

        $e = new CallProcessedEvent(
            dbCallId: 42,
            intent: Intent::Created,
            changedFields: ['call_type', 'alarm_level'],
            createDateTime: $now,
        );

        $this->assertSame(42, $e->dbCallId);
        $this->assertSame(Intent::Created, $e->intent);
        $this->assertSame(['call_type', 'alarm_level'], $e->changedFields);
        $this->assertSame($now, $e->createDateTime);
    }

    public function testChangedFieldsDefaultsToEmpty(): void
    {
        $e = new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Closed,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        );

        $this->assertSame([], $e->changedFields);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter CallProcessedEventTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/Events/CallProcessedEvent.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Events;

use DateTimeImmutable;

final class CallProcessedEvent
{
    /**
     * @param string[] $changedFields
     */
    public function __construct(
        public readonly int $dbCallId,
        public readonly Intent $intent,
        public readonly array $changedFields,
        public readonly DateTimeImmutable $createDateTime,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter CallProcessedEventTest`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Events/CallProcessedEvent.php tests/Unit/Notifications/Events/CallProcessedEventTest.php
git commit -m "feat(notifications): add CallProcessedEvent value object"
```

---

### Task 1.8: `EventDispatcher`

Tiny in-process pub/sub. Singleton; one event class, one subscriber list.

**Files:**
- Create: `src/Notifications/EventDispatcher.php`
- Test: `tests/Unit/Notifications/EventDispatcherTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use DateTimeImmutable;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \NwsCad\Notifications\EventDispatcher
 */
class EventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        EventDispatcher::reset();
    }

    public function testDispatchesToAllSubscribers(): void
    {
        $seen = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = "a:{$e->dbCallId}";
        });
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = "b:{$e->dbCallId}";
        });

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 7,
            intent: Intent::Created,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertSame(['a:7', 'b:7'], $seen);
    }

    public function testSubscriberExceptionIsCaughtAndOtherSubscribersStillRun(): void
    {
        $seen = [];
        EventDispatcher::subscribe(function (): void {
            throw new RuntimeException('boom');
        });
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$seen): void {
            $seen[] = $e->dbCallId;
        });

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 9,
            intent: Intent::Updated,
            changedFields: ['call_type'],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertSame([9], $seen);
    }

    public function testResetClearsSubscribers(): void
    {
        $seen = false;
        EventDispatcher::subscribe(function () use (&$seen): void {
            $seen = true;
        });

        EventDispatcher::reset();

        EventDispatcher::dispatch(new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Closed,
            changedFields: [],
            createDateTime: new DateTimeImmutable(),
        ));

        $this->assertFalse($seen);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter EventDispatcherTest`
Expected: FAIL.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/EventDispatcher.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Logger;
use NwsCad\Notifications\Events\CallProcessedEvent;
use Throwable;

final class EventDispatcher
{
    /** @var array<int, callable(CallProcessedEvent):void> */
    private static array $subscribers = [];

    /** @param callable(CallProcessedEvent):void $subscriber */
    public static function subscribe(callable $subscriber): void
    {
        self::$subscribers[] = $subscriber;
    }

    public static function dispatch(CallProcessedEvent $event): void
    {
        foreach (self::$subscribers as $subscriber) {
            try {
                $subscriber($event);
            } catch (Throwable $t) {
                Logger::getInstance()->error('Event subscriber threw', [
                    'event' => CallProcessedEvent::class,
                    'dbCallId' => $event->dbCallId,
                    'intent' => $event->intent->value,
                    'error' => $t->getMessage(),
                ]);
            }
        }
    }

    public static function reset(): void
    {
        self::$subscribers = [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter EventDispatcherTest`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/EventDispatcher.php tests/Unit/Notifications/EventDispatcherTest.php
git commit -m "feat(notifications): add in-process EventDispatcher with isolated error handling"
```

---

### Task 1.9: `TopicSanitizer`

**Files:**
- Create: `src/Notifications/TopicSanitizer.php`
- Test: `tests/Unit/Notifications/TopicSanitizerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\TopicSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\TopicSanitizer
 */
class TopicSanitizerTest extends TestCase
{
    public function testKeepsAlnumDashUnderscore(): void
    {
        $this->assertSame('Engine_1-A', TopicSanitizer::clean('Engine_1-A'));
    }

    public function testReplacesIllegalCharsWithUnderscore(): void
    {
        $this->assertSame('Fire_MCFD', TopicSanitizer::clean('Fire/MCFD'));
        $this->assertSame('A_B', TopicSanitizer::clean('A?B'));
    }

    public function testCollapsesRunsAndTrimsUnderscores(): void
    {
        $this->assertSame('A_B', TopicSanitizer::clean('  A//??B  '));
    }

    public function testReturnsNullWhenEmptyAfterClean(): void
    {
        $this->assertNull(TopicSanitizer::clean(''));
        $this->assertNull(TopicSanitizer::clean('  '));
        $this->assertNull(TopicSanitizer::clean('???'));
        $this->assertNull(TopicSanitizer::clean('___'));
    }

    public function testStripsCrlf(): void
    {
        $this->assertSame('A_B', TopicSanitizer::clean("A\r\nB"));
    }

    public function testHandlesMultibyte(): void
    {
        // Non-ASCII letters get replaced; an all-non-ASCII string returns null.
        $this->assertNull(TopicSanitizer::clean('café'));
        $this->assertSame('A', TopicSanitizer::clean('Aé'));
    }

    public function testRejectsPathTraversalSegment(): void
    {
        $this->assertNull(TopicSanitizer::clean('..'));
        $this->assertSame('a', TopicSanitizer::clean('../a'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter TopicSanitizerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/TopicSanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class TopicSanitizer
{
    public static function clean(string $segment): ?string
    {
        // Replace any character outside [A-Za-z0-9_-] with '_'.
        $cleaned = preg_replace('/[^A-Za-z0-9_-]/', '_', $segment) ?? '';
        // Collapse runs of '_' and trim leading/trailing '_'.
        $cleaned = trim((string) preg_replace('/_+/', '_', $cleaned), '_');
        return $cleaned === '' ? null : $cleaned;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter TopicSanitizerTest`
Expected: 7 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/TopicSanitizer.php tests/Unit/Notifications/TopicSanitizerTest.php
git commit -m "feat(notifications): add TopicSanitizer (whitelist + collapse)"
```

---

### Task 1.10: `IncidentDto`

Typed payload built from a single joined SELECT, replacing every `extract($row)` in the legacy code.

**Files:**
- Create: `src/Notifications/IncidentDto.php`
- Test: `tests/Unit/Notifications/IncidentDtoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\IncidentDto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\IncidentDto
 */
class IncidentDtoTest extends TestCase
{
    public function testFromRowMapsKnownFields(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 100,
            'call_id' => 12345,
            'call_number' => 'C-001',
            'call_type' => 'Structure Fire',
            'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD',
            'units' => 'ENGINE1|TRUCK1',
            'common_name' => 'Main St Hydrant',
            'full_address' => '123 Main St',
            'nearest_cross_streets' => 'Elm / Oak',
            'police_beat' => null,
            'fire_quadrant' => 'Q1',
            'nature_of_call' => 'Smoke from second floor',
            'narrative' => 'Caller reports flames',
            'alarm_level' => '2',
            'create_datetime' => '2026-05-07 12:34:56',
            'latitude' => 39.7,
            'longitude' => -86.1,
        ]);

        $this->assertSame(100, $dto->dbCallId);
        $this->assertSame(12345, $dto->callId);
        $this->assertSame('C-001', $dto->callNumber);
        $this->assertSame('Structure Fire', $dto->callType);
        $this->assertSame('Fire', $dto->agencyType);
        $this->assertSame('MCFD', $dto->jurisdiction);
        $this->assertSame('ENGINE1|TRUCK1', $dto->units);
        $this->assertSame('Main St Hydrant', $dto->commonName);
        $this->assertSame('123 Main St', $dto->fullAddress);
        $this->assertSame('Elm / Oak', $dto->nearestCrossStreets);
        $this->assertNull($dto->policeBeat);
        $this->assertSame('Q1', $dto->fireQuadrant);
        $this->assertSame('Smoke from second floor', $dto->natureOfCall);
        $this->assertSame('Caller reports flames', $dto->narrative);
        $this->assertSame(2, $dto->alarmLevel);
        $this->assertSame('2026-05-07 12:34:56', $dto->createDateTime);
        $this->assertSame(39.7, $dto->latitude);
        $this->assertSame(-86.1, $dto->longitude);
    }

    public function testFromRowToleratesMissingOptionalFields(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1,
            'call_id' => 2,
            'call_number' => 'X',
            'create_datetime' => '2026-01-01 00:00:00',
        ]);

        $this->assertNull($dto->callType);
        $this->assertNull($dto->latitude);
        $this->assertSame(0, $dto->alarmLevel);
        $this->assertSame('', $dto->units);
    }

    public function testGoogleMapsUrlIsBuiltOnlyWhenCoordinatesPresent(): void
    {
        $a = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 2, 'call_number' => 'A',
            'create_datetime' => '2026-01-01 00:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
        $b = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 2, 'call_number' => 'A',
            'create_datetime' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=39.7,-86.1', $a->mapUrl());
        $this->assertNull($b->mapUrl());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter IncidentDtoTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/IncidentDto.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class IncidentDto
{
    private function __construct(
        public readonly int $dbCallId,
        public readonly int $callId,
        public readonly string $callNumber,
        public readonly ?string $callType,
        public readonly ?string $agencyType,
        public readonly ?string $jurisdiction,
        public readonly string $units,
        public readonly ?string $commonName,
        public readonly ?string $fullAddress,
        public readonly ?string $nearestCrossStreets,
        public readonly ?string $policeBeat,
        public readonly ?string $fireQuadrant,
        public readonly ?string $natureOfCall,
        public readonly ?string $narrative,
        public readonly int $alarmLevel,
        public readonly string $createDateTime,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $strOrNull = static fn (string $k): ?string =>
            isset($row[$k]) && $row[$k] !== '' ? (string) $row[$k] : null;
        $floatOrNull = static fn (string $k): ?float =>
            isset($row[$k]) && $row[$k] !== '' ? (float) $row[$k] : null;

        return new self(
            dbCallId: (int) ($row['id'] ?? 0),
            callId: (int) ($row['call_id'] ?? 0),
            callNumber: (string) ($row['call_number'] ?? ''),
            callType: $strOrNull('call_type'),
            agencyType: $strOrNull('agency_type'),
            jurisdiction: $strOrNull('jurisdiction'),
            units: (string) ($row['units'] ?? ''),
            commonName: $strOrNull('common_name'),
            fullAddress: $strOrNull('full_address'),
            nearestCrossStreets: $strOrNull('nearest_cross_streets'),
            policeBeat: $strOrNull('police_beat'),
            fireQuadrant: $strOrNull('fire_quadrant'),
            natureOfCall: $strOrNull('nature_of_call'),
            narrative: $strOrNull('narrative'),
            alarmLevel: (int) ($row['alarm_level'] ?? 0),
            createDateTime: (string) ($row['create_datetime'] ?? ''),
            latitude: $floatOrNull('latitude'),
            longitude: $floatOrNull('longitude'),
        );
    }

    public function mapUrl(): ?string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }
        return sprintf(
            'https://www.google.com/maps/dir/?api=1&destination=%s,%s',
            $this->latitude,
            $this->longitude,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter IncidentDtoTest`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/IncidentDto.php tests/Unit/Notifications/IncidentDtoTest.php
git commit -m "feat(notifications): add IncidentDto with explicit row mapping (no extract())"
```

---

### Task 1.11: Migrations — `notification_channels` and `notification_send_log`

**Files:**
- Modify: `database/mysql/init.sql`
- Modify: `database/postgres/init.sql`
- Modify: `tests/bootstrap.php` (extend `cleanTestDatabase`)
- Test: `tests/Integration/NotificationChannelsTableTest.php`
- Test: `tests/Integration/NotificationSendLogTableTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/NotificationChannelsTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Database
 */
class NotificationChannelsTableTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    public function testTableExistsAndAcceptsRow(): void
    {
        cleanTestDatabase();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('ntfy_primary', 'ntfy', 0, 'https://ntfy.example', '{}')");

        $row = self::$db->query("SELECT name, type, enabled, base_url FROM notification_channels WHERE name='ntfy_primary'")->fetch();

        $this->assertSame('ntfy_primary', $row['name']);
        $this->assertSame('ntfy', $row['type']);
        $this->assertSame('https://ntfy.example', $row['base_url']);
    }

    public function testNameIsUnique(): void
    {
        cleanTestDatabase();
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('dup', 'ntfy', 0, 'u', '{}')");

        $this->expectException(\PDOException::class);
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('dup', 'pushover', 0, 'u', '{}')");
    }
}
```

Create `tests/Integration/NotificationSendLogTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Database
 */
class NotificationSendLogTableTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    public function testTableExistsAndAcceptsRow(): void
    {
        cleanTestDatabase();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('test', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $stmt = self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$channelId, null, 'Created', 'Fire_MCFD_E1', 1, 200, 42, null]);

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCascadeDeletesWithChannel(): void
    {
        cleanTestDatabase();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('cascade', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, ok, duration_ms) VALUES (?, ?, ?)")->execute([$channelId, 1, 0]);

        self::$db->exec("DELETE FROM notification_channels WHERE id={$channelId}");

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(0, $count);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Integration/NotificationChannelsTableTest.php tests/Integration/NotificationSendLogTableTest.php`
Expected: FAIL — table does not exist.

- [ ] **Step 3a: Append MySQL migration**

Append to `database/mysql/init.sql` **before** the `CREATE DATABASE / CREATE USER` footer (i.e., right after the `processed_files` table definition):

```sql
-- ============================================================================
-- NOTIFICATION CHANNELS (added v1.2.0 — nws-endpoints consolidation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS notification_channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL COMMENT 'ntfy | pushover',
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    base_url VARCHAR(512) NOT NULL,
    config_json TEXT NOT NULL,
    last_error_at TIMESTAMP NULL,
    last_error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_notification_channels_name (name),
    INDEX idx_notification_channels_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_send_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    call_id BIGINT UNSIGNED NULL,
    intent VARCHAR(16) NULL COMMENT 'Created|Updated|Closed',
    topic VARCHAR(256) NULL,
    ok BOOLEAN NOT NULL,
    http_status INT NULL,
    duration_ms INT NOT NULL,
    error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (channel_id) REFERENCES notification_channels(id) ON DELETE CASCADE,
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE SET NULL,
    INDEX idx_send_log_channel_created (channel_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3b: Append PostgreSQL migration**

Append to `database/postgres/init.sql` (end of file is fine — Postgres init has no footer):

```sql
-- ============================================================================
-- NOTIFICATION CHANNELS (added v1.2.0 — nws-endpoints consolidation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS notification_channels (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    type VARCHAR(32) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    base_url VARCHAR(512) NOT NULL,
    config_json TEXT NOT NULL,
    last_error_at TIMESTAMP NULL,
    last_error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notification_channels_type ON notification_channels(type);

CREATE TABLE IF NOT EXISTS notification_send_log (
    id BIGSERIAL PRIMARY KEY,
    channel_id BIGINT NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    call_id BIGINT NULL REFERENCES calls(id) ON DELETE SET NULL,
    intent VARCHAR(16) NULL,
    topic VARCHAR(256) NULL,
    ok BOOLEAN NOT NULL,
    http_status INTEGER NULL,
    duration_ms INTEGER NOT NULL,
    error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_send_log_channel_created
    ON notification_send_log(channel_id, created_at DESC);
```

- [ ] **Step 3c: Extend `tests/bootstrap.php` `cleanTestDatabase()`**

Edit `tests/bootstrap.php`. Modify the `$tables` array in `cleanTestDatabase()` so it now starts with the new tables (children-first deletion order):

```php
$tables = [
    'notification_send_log',
    'notification_channels',
    'unit_dispositions', 'unit_logs', 'unit_personnel', 'units',
    'call_dispositions', 'vehicles', 'persons', 'narratives',
    'incidents', 'locations', 'agency_contexts', 'calls', 'processed_files'
];
```

- [ ] **Step 3d: Apply the migration to the test database**

```bash
docker-compose exec mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" nws_cad_test < /docker-entrypoint-initdb.d/init.sql' || \
  echo "If running outside Docker: apply database/mysql/init.sql to nws_cad_test manually"
```

If running outside Docker:

```bash
mysql -u test_user -ptest_pass nws_cad_test < /home/jcleaver/nws-cad/database/mysql/init.sql
```

(Or, for Postgres: `psql -U test_user -d nws_cad_test -f database/postgres/init.sql`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Integration/NotificationChannelsTableTest.php tests/Integration/NotificationSendLogTableTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add database/mysql/init.sql database/postgres/init.sql tests/bootstrap.php \
        tests/Integration/NotificationChannelsTableTest.php \
        tests/Integration/NotificationSendLogTableTest.php
git commit -m "feat(notifications): add notification_channels & notification_send_log tables"
```

---

### Task 1.12: `.env.example` updates

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Skip — config-only change, no test required**

- [ ] **Step 2: Modify `.env.example`**

Append to `.env.example`:

```
# =============================================================================
# Notifications (v1.2.0 — nws-endpoints consolidation)
# =============================================================================
# Delta-time gate: events older than this many seconds are NOT notified.
NOTIFICATION_DELTA_SECONDS=900

# ntfy.sh — required when an ntfy channel is enabled in notification_channels.
# Example: NTFY_AUTH_TOKEN=Bearer tk_yourtokenhere
NTFY_AUTH_TOKEN=
NTFY_BASE_URL=

# Pushover — required when a pushover channel is enabled.
PUSHOVER_TOKEN=
PUSHOVER_USER=
PUSHOVER_BASE_URL=https://api.pushover.net/1/messages.json
```

- [ ] **Step 3: Verify the file parses (smoke check)**

```bash
grep -E '^NOTIFICATION_DELTA_SECONDS=|^NTFY_AUTH_TOKEN=|^PUSHOVER_TOKEN=' /home/jcleaver/nws-cad/.env.example
```

Expected: all three lines printed.

- [ ] **Step 4: Commit**

```bash
git add .env.example
git commit -m "feat(notifications): document notification env vars in .env.example"
```

---

### PR #1 — Final review checkpoint

- [ ] Run full test suite: `composer test`
- [ ] Confirm coverage: `composer test:coverage` (≥ 80 %)
- [ ] Grep for forbidden `extract(`: `grep -r "extract(" src/Notifications src/Logging` — must be empty.
- [ ] Open PR titled "feat: scaffolding for notifications consolidation (PR 1/6)" with body summarizing the new files and tables.

---

## PR #2 — Channels, dispatcher, CLI

**Goal:** Build the runtime: `NotificationChannel` interface, `SendResult`, `NotificationContext`, `ChannelRepository`, `NtfyChannel`, `PushoverChannel`, `NotificationDispatcher`, plus `bin/notifications.php` CLI. Channels remain disabled by default. The parser does not yet fire events (PR #4 wires that up).

**Acceptance criteria:**
- New unit tests pass.
- A manual `bin/notifications.php enable ntfy` then `bin/notifications.php test ntfy` produces a real ntfy push (smoke test by hand; not part of CI).
- Existing test suite green; coverage ≥ 80 %.

### Task 2.1: `SendResult` and `NotificationContext`

**Files:**
- Create: `src/Notifications/SendResult.php`
- Create: `src/Notifications/NotificationContext.php`
- Test: `tests/Unit/Notifications/SendResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\SendResult
 */
class SendResultTest extends TestCase
{
    public function testOkFactory(): void
    {
        $r = SendResult::ok(httpStatus: 200, durationMs: 17, topic: 'Fire_MCFD');

        $this->assertTrue($r->ok);
        $this->assertSame(200, $r->httpStatus);
        $this->assertSame(17, $r->durationMs);
        $this->assertSame('Fire_MCFD', $r->topic);
        $this->assertNull($r->error);
    }

    public function testFailFactory(): void
    {
        $r = SendResult::fail(httpStatus: 502, durationMs: 30, error: 'bad gateway');

        $this->assertFalse($r->ok);
        $this->assertSame(502, $r->httpStatus);
        $this->assertSame('bad gateway', $r->error);
        $this->assertNull($r->topic);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter SendResultTest`
Expected: FAIL.

- [ ] **Step 3: Write the implementations**

Create `src/Notifications/SendResult.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly int $durationMs,
        public readonly ?string $error,
        public readonly ?string $topic,
    ) {
    }

    public static function ok(?int $httpStatus, int $durationMs, ?string $topic = null): self
    {
        return new self(true, $httpStatus, $durationMs, null, $topic);
    }

    public static function fail(?int $httpStatus, int $durationMs, string $error, ?string $topic = null): self
    {
        return new self(false, $httpStatus, $durationMs, $error, $topic);
    }
}
```

Create `src/Notifications/NotificationContext.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class NotificationContext
{
    /**
     * @param string[] $topicsToNotify  Already-sanitized ntfy topic segments (no '|' separators).
     * @param array<string,mixed> $channelConfig
     */
    public function __construct(
        public readonly Intent $intent,
        public readonly bool $resendAll,
        public readonly array $topicsToNotify,
        public readonly array $channelConfig,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter SendResultTest`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/SendResult.php src/Notifications/NotificationContext.php \
        tests/Unit/Notifications/SendResultTest.php
git commit -m "feat(notifications): add SendResult and NotificationContext value objects"
```

---

### Task 2.2: `NotificationChannel` interface

**Files:**
- Create: `src/Notifications/NotificationChannel.php`

- [ ] **Step 1: Skip — interface only; behavior tested via channel impls**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/NotificationChannel.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface NotificationChannel
{
    /**
     * Channel type identifier matching notification_channels.type.
     */
    public static function type(): string;

    /**
     * @return SendResult[]  One result per attempt that produced a permanent
     *                       outcome (one per topic for ntfy, one for pushover).
     */
    public function send(IncidentDto $incident, NotificationContext $context): array;
}
```

- [ ] **Step 4: Verify it parses**

Run: `php -l src/Notifications/NotificationChannel.php`
Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/NotificationChannel.php
git commit -m "feat(notifications): add NotificationChannel interface"
```

---

### Task 2.3: `NtfyChannel`

cURL PUT per topic. Uses `Config::secret(env_name)` based on `config_json.auth_token_env`. Bounded retry (3 attempts: ~1s/3s/9s) on 5xx and network errors. 4xx is permanent. Topics are sanitized + `rawurlencode`d.

**Files:**
- Create: `src/Notifications/Channels/NtfyChannel.php`
- Test: `tests/Unit/Notifications/Channels/NtfyChannelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use Mockery;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Channels\NtfyChannel
 */
class NtfyChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'call_type' => 'Structure Fire', 'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD', 'units' => 'ENGINE1',
            'full_address' => '123 Main', 'alarm_level' => 2,
            'create_datetime' => '2026-05-07 12:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
    }

    public function testSuccessfulSendReturnsOkPerTopic(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->twice()->andReturn(['status' => 200, 'body' => '']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['Fire', 'MCFD'], []),
        );

        $this->assertCount(2, $results);
        foreach ($results as $r) {
            $this->assertTrue($r->ok);
            $this->assertSame(200, $r->httpStatus);
        }
    }

    public function testTopicIsSanitizedAndUrlEncoded(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $capturedUrl = null;
        $http->shouldReceive('put')
            ->once()
            ->with(Mockery::on(function (string $url) use (&$capturedUrl): bool {
                $capturedUrl = $url;
                return true;
            }), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
        );

        $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['Fire/MCFD?x'], []),
        );

        $this->assertSame('https://ntfy.example/Fire_MCFD_x', $capturedUrl);
    }

    public function testRetriesOn5xxAndEventuallySucceeds(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->times(3)->andReturn(
            ['status' => 502, 'body' => 'bad gateway'],
            ['status' => 503, 'body' => 'unavailable'],
            ['status' => 200, 'body' => 'ok'],
        );

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,  // don't actually sleep in tests
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['T'], []),
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->ok);
    }

    public function testDoesNotRetryOn4xx(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->once()->andReturn(['status' => 401, 'body' => 'unauth']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['T'], []),
        );

        $this->assertFalse($results[0]->ok);
        $this->assertSame(401, $results[0]->httpStatus);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter NtfyChannelTest`
Expected: FAIL — `HttpPut` and `NtfyChannel` not found.

- [ ] **Step 3: Write the implementations**

Create `src/Notifications/Channels/HttpPut.php` (a tiny seam so the channel is testable without real network):

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

class HttpPut
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function put(string $url, array $headers, string $body, int $timeoutSec): array
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => $err];
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
```

Create `src/Notifications/Channels/NtfyChannel.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use NwsCad\Logger;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicSanitizer;

final class NtfyChannel implements NotificationChannel
{
    /** @var int[] Backoff between retries, in milliseconds. */
    private const BACKOFF_MS = [1000, 3000, 9000];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authToken,
        /** @var array<string,mixed> */
        private readonly array $config,
        private readonly HttpPut $http = new HttpPut(),
        /** @var callable(int):void */
        private $sleeper = null,
    ) {
        if ($this->sleeper === null) {
            $this->sleeper = static fn (int $ms) => usleep($ms * 1000);
        }
    }

    public static function type(): string
    {
        return 'ntfy';
    }

    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $logger = Logger::getInstance();
        $results = [];
        $tags = $this->buildTags($incident);
        $priority = $this->buildPriority($incident);
        $messageBody = $this->buildBody($incident);
        $title = $this->buildTitle($incident);

        foreach ($context->topicsToNotify as $rawTopic) {
            $sanitized = TopicSanitizer::clean($rawTopic);
            if ($sanitized === null) {
                $logger->info('Skipping ntfy topic (empty after sanitize)', ['raw' => $rawTopic]);
                continue;
            }

            $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($sanitized);
            $headers = [
                'Content-Type' => 'text/plain',
                'Authorization' => $this->authToken,
                'Title' => $title,
                'Tags' => $tags,
                'Priority' => (string) $priority,
            ];
            if ($incident->mapUrl() !== null) {
                $headers['Attach'] = $incident->mapUrl();
            }

            $results[] = $this->sendWithRetry($url, $headers, $messageBody, $sanitized);
        }

        return $results;
    }

    private function sendWithRetry(string $url, array $headers, string $body, string $topic): SendResult
    {
        $logger = Logger::getInstance();
        $start = microtime(true);
        $attempt = 0;
        $lastStatus = null;
        $lastError = '';

        foreach (self::BACKOFF_MS as $i => $delayMs) {
            $attempt = $i + 1;
            $resp = $this->http->put($url, $headers, $body, 10);
            $lastStatus = $resp['status'];

            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $duration = (int) ((microtime(true) - $start) * 1000);
                return SendResult::ok($resp['status'], $duration, $topic);
            }

            $lastError = (string) $resp['body'];

            if ($resp['status'] >= 400 && $resp['status'] < 500) {
                $logger->warning('ntfy permanent failure', [
                    'topic' => $topic, 'http_status' => $resp['status'],
                    'attempt' => $attempt, 'body' => substr($lastError, 0, 500),
                ]);
                $duration = (int) ((microtime(true) - $start) * 1000);
                return SendResult::fail($resp['status'], $duration, $lastError, $topic);
            }

            // Transient — back off and retry, unless this was the last attempt.
            if ($i < count(self::BACKOFF_MS) - 1) {
                ($this->sleeper)($delayMs);
            }
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $logger->error('ntfy retries exhausted', [
            'topic' => $topic, 'http_status' => $lastStatus,
            'attempt' => $attempt, 'error' => substr($lastError, 0, 500),
        ]);
        return SendResult::fail($lastStatus, $duration, $lastError ?: 'retries exhausted', $topic);
    }

    private function buildTags(IncidentDto $i): string
    {
        $map = $this->config['agency_tag_map'] ?? ['Fire' => 'fire_engine', 'Police' => 'police_car'];
        return $map[$i->agencyType ?? ''] ?? 'fire_engine,police_car';
    }

    private function buildPriority(IncidentDto $i): int
    {
        $alarmMap = $this->config['alarm_priority_map'] ?? null;
        if (is_array($alarmMap) && isset($alarmMap[(string) $i->alarmLevel])) {
            $p = (int) $alarmMap[(string) $i->alarmLevel];
        } else {
            $p = $i->alarmLevel + 2;
        }
        return max(1, min(5, $p));
    }

    private function buildTitle(IncidentDto $i): string
    {
        return sprintf('Call: %s %s', $i->callNumber, $i->callType ?? '');
    }

    private function buildBody(IncidentDto $i): string
    {
        return implode("\n", [
            'C-Name: ' . ($i->commonName ?? ''),
            'Loc: ' . ($i->fullAddress ?? ''),
            'Inc: ' . ($i->callType ?? ''),
            'Nature: ' . ($i->natureOfCall ?? ''),
            'Cross Rd: ' . ($i->nearestCrossStreets ?? ''),
            'Beat: ' . ($i->policeBeat ?? ''),
            'Quad: ' . ($i->fireQuadrant ?? ''),
            'Unit: ' . $i->units,
            'Time: ' . $i->createDateTime,
            'Narr: ' . ($i->narrative ?? ''),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter NtfyChannelTest`
Expected: 4 passed.


- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Channels/HttpPut.php src/Notifications/Channels/NtfyChannel.php \
        tests/Unit/Notifications/Channels/NtfyChannelTest.php
git commit -m "feat(notifications): add NtfyChannel with sanitized topics and bounded retry"
```

---

### Task 2.4: `PushoverChannel`

**Files:**
- Create: `src/Notifications/Channels/PushoverChannel.php`
- Create: `src/Notifications/Channels/HttpPost.php`
- Test: `tests/Unit/Notifications/Channels/PushoverChannelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use Mockery;
use NwsCad\Notifications\Channels\HttpPost;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Channels\PushoverChannel
 */
class PushoverChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'call_type' => 'Structure Fire',
            'full_address' => '123 Main', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
    }

    public function testSuccessReturnsOk(): void
    {
        $http = Mockery::mock(HttpPost::class);
        $http->shouldReceive('post')
            ->once()
            ->andReturn(['status' => 200, 'body' => '{"status":1}']);

        $channel = new PushoverChannel(
            baseUrl: 'https://api.pushover.net/1/messages.json',
            token: 'tok',
            user: 'usr',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send($this->dto(), new NotificationContext(Intent::Created, false, [], []));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->ok);
    }

    public function testApiStatusZeroIsTreatedAsFailure(): void
    {
        $http = Mockery::mock(HttpPost::class);
        $http->shouldReceive('post')
            ->times(3)
            ->andReturn(['status' => 200, 'body' => '{"status":0,"errors":["bad"]}']);

        $channel = new PushoverChannel(
            baseUrl: 'u', token: 't', user: 'u',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send($this->dto(), new NotificationContext(Intent::Created, false, [], []));

        $this->assertFalse($results[0]->ok);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PushoverChannelTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the implementations**

Create `src/Notifications/Channels/HttpPost.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

class HttpPost
{
    /**
     * @param array<string,string|int> $fields
     * @return array{status:int, body:string}
     */
    public function post(string $url, array $fields, int $timeoutSec): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => $err];
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
```

Create `src/Notifications/Channels/PushoverChannel.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use NwsCad\Logger;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;

final class PushoverChannel implements NotificationChannel
{
    private const BACKOFF_MS = [1000, 3000, 9000];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $user,
        /** @var array<string,mixed> */
        private readonly array $config,
        private readonly HttpPost $http = new HttpPost(),
        /** @var callable(int):void */
        private $sleeper = null,
    ) {
        if ($this->sleeper === null) {
            $this->sleeper = static fn (int $ms) => usleep($ms * 1000);
        }
    }

    public static function type(): string
    {
        return 'pushover';
    }

    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $logger = Logger::getInstance();
        $start = microtime(true);
        $fields = [
            'token' => $this->token,
            'user' => $this->user,
            'title' => sprintf('Call: %s %s', $incident->callNumber, $incident->callType ?? ''),
            'message' => implode("\n", [
                'Loc: ' . ($incident->fullAddress ?? ''),
                'Inc: ' . ($incident->callType ?? ''),
                'Nature: ' . ($incident->natureOfCall ?? ''),
                'Unit: ' . $incident->units,
                'Time: ' . $incident->createDateTime,
                'Narr: ' . ($incident->narrative ?? ''),
            ]),
            'sound' => $this->config['sound'] ?? 'bike',
            'html' => '1',
        ];
        if ($incident->mapUrl() !== null) {
            $fields['url'] = $incident->mapUrl();
            $fields['url_title'] = 'Driving Directions';
        }

        $lastStatus = null;
        $lastError = '';

        foreach (self::BACKOFF_MS as $i => $delayMs) {
            $resp = $this->http->post($this->baseUrl, $fields, 30);
            $lastStatus = $resp['status'];
            $body = $resp['body'];

            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $payload = json_decode($body, true);
                if (is_array($payload) && ($payload['status'] ?? null) === 1) {
                    $duration = (int) ((microtime(true) - $start) * 1000);
                    return [SendResult::ok($resp['status'], $duration)];
                }
                $lastError = is_array($payload) ? json_encode($payload) : 'invalid JSON';
            } else {
                $lastError = $body;
            }

            if ($resp['status'] >= 400 && $resp['status'] < 500) {
                $logger->warning('pushover permanent failure', [
                    'http_status' => $resp['status'],
                    'attempt' => $i + 1,
                    'body' => substr($lastError, 0, 500),
                ]);
                break;
            }

            if ($i < count(self::BACKOFF_MS) - 1) {
                ($this->sleeper)($delayMs);
            }
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $logger->error('pushover retries exhausted', [
            'http_status' => $lastStatus,
            'error' => substr($lastError, 0, 500),
        ]);
        return [SendResult::fail($lastStatus, $duration, $lastError ?: 'retries exhausted')];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter PushoverChannelTest`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Channels/HttpPost.php src/Notifications/Channels/PushoverChannel.php \
        tests/Unit/Notifications/Channels/PushoverChannelTest.php
git commit -m "feat(notifications): add PushoverChannel with bounded retry"
```

---

### Task 2.5: `ChannelRepository`

Loads enabled channel rows from `notification_channels`, hydrates them via `Config::secret()`. Writes `last_error_at`/`last_error_message` on permanent failure. Writes `notification_send_log` rows with per-channel pruning.

**Files:**
- Create: `src/Notifications/ChannelRepository.php`
- Test: `tests/Integration/ChannelRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/ChannelRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\ChannelRepository
 */
class ChannelRepositoryTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        $_ENV['NTFY_AUTH_TOKEN'] = 'tok-abc';
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example';
    }

    public function testListEnabledReturnsOnlyEnabledChannels(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('on', 'ntfy', 1, 'https://ntfy.example', '{\"auth_token_env\":\"NTFY_AUTH_TOKEN\"}'),
                   ('off', 'ntfy', 0, 'https://ntfy.example', '{}')");

        $repo = new ChannelRepository();
        $rows = $repo->listEnabled();

        $this->assertCount(1, $rows);
        $this->assertSame('on', $rows[0]['name']);
    }

    public function testRecordSendInsertsAndPrunesPerChannel(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('c', 'ntfy', 0, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $repo = new ChannelRepository();
        for ($i = 0; $i < 105; $i++) {
            $repo->recordSend($channelId, 1, 'Created', SendResult::ok(200, 5, "T{$i}"));
        }

        $count = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log WHERE channel_id={$channelId}")->fetchColumn();
        $this->assertSame(100, $count, 'send log should be pruned to 100 rows per channel');
    }

    public function testMarkFailureUpdatesLastErrorFields(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('f', 'ntfy', 1, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $repo = new ChannelRepository();
        $repo->markFailure($channelId, 'HTTP 502 bad gateway');

        $row = self::$db->query("SELECT last_error_at, last_error_message FROM notification_channels WHERE id={$channelId}")->fetch();
        $this->assertNotNull($row['last_error_at']);
        $this->assertSame('HTTP 502 bad gateway', $row['last_error_message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ChannelRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/ChannelRepository.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Database;
use PDO;

final class ChannelRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array<int,array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}>
     */
    public function listEnabled(): array
    {
        $stmt = $this->db->query(
            "SELECT id, name, type, enabled, base_url, config_json
             FROM notification_channels WHERE enabled = TRUE ORDER BY name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notification_send_log
             (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $channelId,
            $callId,
            $intent,
            $result->topic,
            $result->ok ? 1 : 0,
            $result->httpStatus,
            $result->durationMs,
            $result->error,
        ]);
        $this->pruneSendLog($channelId, 100);
    }

    public function markFailure(int $channelId, string $message): void
    {
        $stmt = $this->db->prepare(
            "UPDATE notification_channels
             SET last_error_at = CURRENT_TIMESTAMP, last_error_message = ?
             WHERE id = ?"
        );
        $stmt->execute([$message, $channelId]);
    }

    private function pruneSendLog(int $channelId, int $keep): void
    {
        // Find the cutoff id for the channel (keep the most recent $keep rows).
        $stmt = $this->db->prepare(
            "SELECT id FROM notification_send_log
             WHERE channel_id = ?
             ORDER BY id DESC LIMIT 1 OFFSET ?"
        );
        $stmt->execute([$channelId, $keep]);
        $cutoff = $stmt->fetchColumn();
        if ($cutoff === false) {
            return;
        }
        $del = $this->db->prepare(
            "DELETE FROM notification_send_log WHERE channel_id = ? AND id <= ?"
        );
        $del->execute([$channelId, (int) $cutoff]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter ChannelRepositoryTest`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/ChannelRepository.php tests/Integration/ChannelRepositoryTest.php
git commit -m "feat(notifications): add ChannelRepository with per-channel send-log pruning"
```

---

### Task 2.6: `NotificationDispatcher`

Subscribes to `CallProcessedEvent`. Applies the delta-time gate, computes intent rules (Created → all topics; Updated → all-or-new based on changed fields; Closed → no-op), reads the joined incident row, instantiates channels per type, fans out, records each result.

**Files:**
- Create: `src/Notifications/NotificationDispatcher.php`
- Test: `tests/Unit/Notifications/NotificationDispatcherTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\NotificationDispatcher
 */
class NotificationDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testDeltaTimeGateSkipsOldEvents(): void
    {
        $repo = Mockery::mock(ChannelRepository::class);
        $repo->shouldNotReceive('listEnabled');
        $loader = function (int $id): IncidentDto {
            $this->fail('loader should not be called');
        };
        $factory = fn () => $this->fail('channel factory should not be called');

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: $loader,
            channelFactory: $factory,
            deltaSeconds: 900,
            now: new DateTimeImmutable('2026-05-07 12:30:00'),
        );

        $event = new CallProcessedEvent(
            dbCallId: 1,
            intent: Intent::Created,
            changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),  // 30 min ago
        );

        $dispatcher->handle($event);
        $this->assertTrue(true);  // assertion is the mock expectations above
    }

    public function testClosedIntentDoesNotCallChannels(): void
    {
        $repo = Mockery::mock(ChannelRepository::class);
        $repo->shouldNotReceive('listEnabled');

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $this->fail(),
            deltaSeconds: 900,
            now: new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Closed, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        ));
        $this->assertTrue(true);
    }

    public function testCreatedIntentSendsToAllTopics(): void
    {
        $repo = Mockery::mock(ChannelRepository::class);
        $repo->shouldReceive('listEnabled')->andReturn([
            ['id' => 1, 'name' => 'n', 'type' => 'ntfy', 'enabled' => true,
             'base_url' => 'u', 'config_json' => '{}'],
        ]);
        $repo->shouldReceive('recordSend')->atLeast()->once();

        $channel = Mockery::mock(NtfyChannel::class);
        $channel->shouldReceive('send')
            ->once()
            ->withArgs(function ($dto, $ctx) {
                return $ctx->intent === Intent::Created
                    && $ctx->resendAll === false
                    && in_array('Fire', $ctx->topicsToNotify, true)
                    && in_array('MCFD', $ctx->topicsToNotify, true)
                    && in_array('ENGINE1', $ctx->topicsToNotify, true);
            })
            ->andReturn([SendResult::ok(200, 5, 'Fire')]);

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $channel,
            deltaSeconds: 900,
            now: new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        ));
    }

    public function testUpdatedIntentResendsAllWhenCallTypeChanged(): void
    {
        $repo = Mockery::mock(ChannelRepository::class);
        $repo->shouldReceive('listEnabled')->andReturn([
            ['id' => 1, 'name' => 'n', 'type' => 'ntfy', 'enabled' => true,
             'base_url' => 'u', 'config_json' => '{}'],
        ]);
        $repo->shouldReceive('recordSend')->atLeast()->once();

        $channel = Mockery::mock(NtfyChannel::class);
        $channel->shouldReceive('send')->once()->withArgs(function ($dto, $ctx) {
            return $ctx->resendAll === true;
        })->andReturn([SendResult::ok(200, 5, 'T')]);

        $dispatcher = new NotificationDispatcher(
            channelRepo: $repo,
            incidentLoader: fn () => $this->dto(),
            channelFactory: fn () => $channel,
            deltaSeconds: 900,
            now: new DateTimeImmutable('2026-05-07 12:00:30'),
        );

        $dispatcher->handle(new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Updated, changedFields: ['call_type'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        ));
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C',
            'call_type' => 'Fire',
            'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD',
            'units' => 'ENGINE1',
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter NotificationDispatcherTest`
Expected: FAIL.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/NotificationDispatcher.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use Throwable;

final class NotificationDispatcher
{
    /** @var callable(int):IncidentDto */
    private $incidentLoader;
    /** @var callable(array<string,mixed>):NotificationChannel */
    private $channelFactory;

    private const RESEND_ALL_TRIGGERS = ['call_type', 'full_address', 'alarm_level'];

    public function __construct(
        private readonly ChannelRepository $channelRepo,
        callable $incidentLoader,
        callable $channelFactory,
        private readonly int $deltaSeconds,
        private readonly DateTimeImmutable $now = new DateTimeImmutable(),
    ) {
        $this->incidentLoader = $incidentLoader;
        $this->channelFactory = $channelFactory;
    }

    public function handle(CallProcessedEvent $event): void
    {
        $logger = Logger::getInstance();

        if ($event->intent === Intent::Closed) {
            $logger->info('Notification dispatch: Closed intent, no-op', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $age = $this->now->getTimestamp() - $event->createDateTime->getTimestamp();
        if ($age > $this->deltaSeconds) {
            $logger->info('Notification dispatch: delta-time gate dropped event', [
                'dbCallId' => $event->dbCallId, 'age_seconds' => $age, 'limit' => $this->deltaSeconds,
            ]);
            return;
        }

        try {
            $dto = ($this->incidentLoader)($event->dbCallId);
        } catch (Throwable $t) {
            $logger->error('Notification dispatch: failed to load incident', [
                'dbCallId' => $event->dbCallId, 'error' => $t->getMessage(),
            ]);
            return;
        }

        $resendAll = $event->intent === Intent::Created
            || count(array_intersect(self::RESEND_ALL_TRIGGERS, $event->changedFields)) > 0;

        $topics = $this->buildTopics($dto, $event, $resendAll);

        $context = new NotificationContext(
            intent: $event->intent,
            resendAll: $resendAll,
            topicsToNotify: $topics,
            channelConfig: [],
        );

        foreach ($this->channelRepo->listEnabled() as $row) {
            try {
                $channel = ($this->channelFactory)($row);
            } catch (Throwable $t) {
                $logger->warning('Notification dispatch: channel factory failed', [
                    'channel' => $row['name'], 'error' => $t->getMessage(),
                ]);
                $this->channelRepo->markFailure((int) $row['id'], $t->getMessage());
                continue;
            }

            try {
                $results = $channel->send($dto, $context);
            } catch (Throwable $t) {
                $logger->error('Notification dispatch: channel send threw', [
                    'channel' => $row['name'], 'error' => $t->getMessage(),
                ]);
                $this->channelRepo->markFailure((int) $row['id'], $t->getMessage());
                continue;
            }

            foreach ($results as $r) {
                $this->channelRepo->recordSend(
                    (int) $row['id'],
                    $event->dbCallId,
                    $event->intent->value,
                    $r,
                );
                if (! $r->ok) {
                    $this->channelRepo->markFailure(
                        (int) $row['id'],
                        ($r->httpStatus ? "HTTP {$r->httpStatus}: " : '') . ($r->error ?? 'unknown'),
                    );
                }
            }
        }
    }

    /** @return string[] */
    private function buildTopics(IncidentDto $dto, CallProcessedEvent $event, bool $resendAll): array
    {
        $derived = array_filter([
            $dto->agencyType,
            $dto->jurisdiction,
            ...explode('|', $dto->units),
        ], static fn (?string $v) => $v !== null && $v !== '');

        return array_values(array_unique($derived));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter NotificationDispatcherTest`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/NotificationDispatcher.php tests/Unit/Notifications/NotificationDispatcherTest.php
git commit -m "feat(notifications): add NotificationDispatcher with delta-time gate and intent rules"
```

---

### Task 2.7: `bin/notifications.php` CLI

**Files:**
- Create: `bin/notifications.php`

- [ ] **Step 1: Skip — CLI smoke-tested by hand; PHP linter only**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Write the implementation**

Create `bin/notifications.php`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$cmd = $args[0] ?? 'help';

$db = Database::getConnection();

function help(): never
{
    echo <<<TXT
Usage: php bin/notifications.php <command>

Commands:
  list                                 Show all channels with status.
  enable <type> [--base-url=URL]       Enable (or create) a channel of the given type ('ntfy'|'pushover').
  disable <type>                       Disable all channels of the given type.
  test <type>                          Send a synthetic notification through the first enabled channel of <type>.

Secrets are read from environment (.env). Required env vars per type:
  ntfy:     NTFY_AUTH_TOKEN
  pushover: PUSHOVER_TOKEN, PUSHOVER_USER

TXT;
    exit(0);
}

switch ($cmd) {
    case 'list':
        $rows = $db->query("SELECT id, name, type, enabled, base_url, last_error_at, last_error_message
                            FROM notification_channels ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "(no channels configured)\n";
            exit(0);
        }
        foreach ($rows as $r) {
            $flag = $r['enabled'] ? 'enabled ' : 'disabled';
            $err = $r['last_error_at'] ? "  ⚠ {$r['last_error_at']}: {$r['last_error_message']}" : '';
            echo "  [{$flag}] #{$r['id']} {$r['name']} ({$r['type']}) {$r['base_url']}{$err}\n";
        }
        break;

    case 'enable':
        $type = $args[1] ?? null;
        if (! in_array($type, ['ntfy', 'pushover'], true)) {
            fwrite(STDERR, "enable requires <type> = ntfy | pushover\n");
            exit(1);
        }
        $baseUrl = '';
        foreach ($args as $a) {
            if (str_starts_with($a, '--base-url=')) {
                $baseUrl = substr($a, 11);
            }
        }
        if ($baseUrl === '') {
            $envKey = $type === 'ntfy' ? 'NTFY_BASE_URL' : 'PUSHOVER_BASE_URL';
            $baseUrl = $_ENV[$envKey] ?? getenv($envKey) ?: '';
            if ($baseUrl === '') {
                fwrite(STDERR, "Provide --base-url=URL or set {$envKey} in environment.\n");
                exit(1);
            }
        }

        $defaultConfig = $type === 'ntfy'
            ? '{"auth_token_env":"NTFY_AUTH_TOKEN","alarm_priority_map":{"1":3,"2":4,"3":5}}'
            : '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}';

        $name = "{$type}_primary";
        $stmt = $db->prepare("SELECT id FROM notification_channels WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn()) {
            $db->prepare("UPDATE notification_channels
                          SET enabled=1, base_url=?, updated_at=CURRENT_TIMESTAMP
                          WHERE name=?")->execute([$baseUrl, $name]);
            echo "Re-enabled channel {$name}\n";
        } else {
            $db->prepare("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                          VALUES (?, ?, 1, ?, ?)")->execute([$name, $type, $baseUrl, $defaultConfig]);
            echo "Created and enabled channel {$name}\n";
        }
        break;

    case 'disable':
        $type = $args[1] ?? null;
        if (! in_array($type, ['ntfy', 'pushover'], true)) {
            fwrite(STDERR, "disable requires <type>\n");
            exit(1);
        }
        $db->prepare("UPDATE notification_channels
                      SET enabled=0, updated_at=CURRENT_TIMESTAMP WHERE type=?")->execute([$type]);
        echo "Disabled all '{$type}' channels.\n";
        break;

    case 'test':
        fwrite(STDERR, "test command requires PR #4 (event wiring) to send a synthetic event.\n");
        exit(1);

    case 'help':
    case '-h':
    case '--help':
    default:
        help();
}
```

Make it executable:

```bash
chmod +x /home/jcleaver/nws-cad/bin/notifications.php
```

- [ ] **Step 4: Verify it parses and lists empty**

```bash
php -l /home/jcleaver/nws-cad/bin/notifications.php
docker-compose exec app php /var/www/bin/notifications.php list || \
  php /home/jcleaver/nws-cad/bin/notifications.php list
```

Expected: linter clean; `(no channels configured)` (or list of pre-existing rows).

- [ ] **Step 5: Commit**

```bash
git add bin/notifications.php
git commit -m "feat(notifications): add bin/notifications.php CLI (list/enable/disable)"
```

---

### PR #2 — Final review checkpoint

- [ ] Run full test suite: `composer test`. All green.
- [ ] Manually enable an ntfy channel and confirm CLI list shows it: `php bin/notifications.php enable ntfy --base-url=https://ntfy.example`.
- [ ] Open PR: "feat: notification channels and dispatcher (PR 2/6)".

---

## PR #3 — Read-only dashboard view + API endpoints

**Goal:** Operators can see channel health and recent send results from the dashboard without shell access. Read-only.

**Acceptance criteria:**
- `GET /api/notifications/channels` returns the channel list in `Response::success` shape.
- `GET /api/notifications/log?channel=<id|name>&limit=10` returns up to N recent rows.
- `/notifications` page renders cleanly with empty + populated states.
- New tests pass.

### Task 3.1: `NotificationsController`

**Files:**
- Create: `src/Api/Controllers/NotificationsController.php`
- Test: `tests/Integration/NotificationsApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\NotificationsController
 */
class NotificationsApiTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
    }

    public function testChannelsReturnsEmpty(): void
    {
        $controller = new NotificationsController();
        ob_start();
        $controller->channels();
        $body = (string) ob_get_clean();
        $payload = json_decode($body, true);

        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['items']);
    }

    public function testChannelsReturnsRows(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('one', 'ntfy', 1, 'u', '{}'),
                   ('two', 'pushover', 0, 'u', '{}')");

        $controller = new NotificationsController();
        ob_start();
        $controller->channels();
        $payload = json_decode((string) ob_get_clean(), true);

        $names = array_column($payload['data']['items'], 'name');
        sort($names);
        $this->assertSame(['one', 'two'], $names);
    }

    public function testLogReturnsRecentRowsForChannel(): void
    {
        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
            VALUES ('c', 'ntfy', 1, 'u', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $stmt = self::$db->prepare("INSERT INTO notification_send_log
            (channel_id, intent, topic, ok, http_status, duration_ms) VALUES (?, ?, ?, ?, ?, ?)");
        for ($i = 1; $i <= 3; $i++) {
            $stmt->execute([$channelId, 'Created', "T{$i}", 1, 200, $i * 10]);
        }

        $_GET['channel'] = (string) $channelId;
        $_GET['limit'] = '2';
        $controller = new NotificationsController();
        ob_start();
        $controller->log();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertCount(2, $payload['data']['items']);
        $this->assertSame('T3', $payload['data']['items'][0]['topic']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter NotificationsApiTest`
Expected: FAIL — controller class missing.

- [ ] **Step 3: Write the controller**

Create `src/Api/Controllers/NotificationsController.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Database;
use PDO;
use Exception;

final class NotificationsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /** GET /api/notifications/channels */
    public function channels(): void
    {
        try {
            $rows = $this->db->query(
                "SELECT id, name, type, enabled, base_url,
                        last_error_at, last_error_message,
                        created_at, updated_at
                 FROM notification_channels ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success(['items' => $rows]);
        } catch (Exception $e) {
            Response::error('Failed to list channels: ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/notifications/log?channel=<id|name>&limit=<n> */
    public function log(): void
    {
        try {
            $channel = $_GET['channel'] ?? null;
            $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));

            if ($channel === null || $channel === '') {
                Response::error('channel parameter required', 400);
                return;
            }

            $channelId = $this->resolveChannelId($channel);
            if ($channelId === null) {
                Response::error('Unknown channel', 404);
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT id, channel_id, call_id, intent, topic, ok,
                        http_status, duration_ms, error, created_at
                 FROM notification_send_log
                 WHERE channel_id = ?
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([$channelId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success(['items' => $rows, 'channel_id' => $channelId, 'limit' => $limit]);
        } catch (Exception $e) {
            Response::error('Failed to read send log: ' . $e->getMessage(), 500);
        }
    }

    private function resolveChannelId(string $channel): ?int
    {
        if (ctype_digit($channel)) {
            return (int) $channel;
        }
        $stmt = $this->db->prepare("SELECT id FROM notification_channels WHERE name = ?");
        $stmt->execute([$channel]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter NotificationsApiTest`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Controllers/NotificationsController.php tests/Integration/NotificationsApiTest.php
git commit -m "feat(notifications): add NotificationsController (read-only)"
```

---

### Task 3.2: Register routes in `public/api.php`

**Files:**
- Modify: `public/api.php`

- [ ] **Step 1: Skip — wiring**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Modify `public/api.php`**

Add the use statement near the others:

```php
use NwsCad\Api\Controllers\NotificationsController;
```

Add the routes after the Stats routes block:

```php
// Notifications Controller Routes (read-only)
$router->get('/notifications/channels', [NotificationsController::class, 'channels']);
$router->get('/notifications/log',      [NotificationsController::class, 'log']);
```

Update the `/` API info endpoint to advertise the new endpoint group. Find:

```php
'endpoints' => [
    'calls' => '/api/calls',
    ...
]
```

and add:

```php
'notifications' => '/api/notifications/channels',
```

- [ ] **Step 4: Smoke-test the route**

```bash
docker-compose up -d
curl -s http://localhost:8080/api/notifications/channels | head -c 200
```

Expected: `{"success":true,"data":{"items":[]}}` (or populated list).

- [ ] **Step 5: Commit**

```bash
git add public/api.php
git commit -m "feat(notifications): wire /api/notifications/* routes"
```

---

### Task 3.3: Dashboard view

**Files:**
- Create: `src/Dashboard/Views/notifications.php`
- Create: `src/Dashboard/Views/notifications-mobile.php`
- Modify: `public/index.php` (add `/notifications` route + nav link)

- [ ] **Step 1: Skip — view-only**

- [ ] **Step 2: Skip**

- [ ] **Step 3a: Create the desktop view**

Create `src/Dashboard/Views/notifications.php`:

```php
<?php

declare(strict_types=1);

/** @var bool $isMobile */
?>
<div class="row">
    <div class="col-12">
        <h2 class="mb-3"><i class="bi bi-bell"></i> Notifications</h2>
        <p class="text-muted">Read-only view of notification channels and recent send results. Toggle channels via <code>php bin/notifications.php</code>.</p>
        <div id="notifications-channels-container" class="row g-3"></div>
    </div>
</div>

<template id="channel-card-template">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><strong class="channel-name"></strong>
                  <span class="badge bg-secondary channel-type ms-2"></span></span>
                <span class="badge channel-enabled-badge"></span>
            </div>
            <div class="card-body">
                <p class="mb-1"><small>Base URL: <code class="channel-base-url"></code></small></p>
                <p class="channel-error mb-2 text-danger" hidden>
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="channel-error-time"></span> —
                    <span class="channel-error-message"></span>
                </p>
                <h6 class="mt-3">Recent sends</h6>
                <ul class="list-group list-group-flush channel-log small"></ul>
            </div>
        </div>
    </div>
</template>

<script>
(async function() {
    const apiBase = window.APP_CONFIG.apiBaseUrl;
    const container = document.getElementById('notifications-channels-container');
    const tpl = document.getElementById('channel-card-template');

    const channelsResp = await fetch(`${apiBase}/notifications/channels`).then(r => r.json());
    if (! channelsResp.success || channelsResp.data.items.length === 0) {
        container.innerHTML = '<div class="col-12"><div class="alert alert-info">No channels configured. Run <code>php bin/notifications.php enable ntfy</code> to add one.</div></div>';
        return;
    }

    for (const ch of channelsResp.data.items) {
        const node = tpl.content.cloneNode(true);
        node.querySelector('.channel-name').textContent = ch.name;
        node.querySelector('.channel-type').textContent = ch.type;
        node.querySelector('.channel-base-url').textContent = ch.base_url;

        const flag = node.querySelector('.channel-enabled-badge');
        flag.textContent = ch.enabled ? 'enabled' : 'disabled';
        flag.classList.add(ch.enabled ? 'bg-success' : 'bg-secondary');

        if (ch.last_error_at) {
            const err = node.querySelector('.channel-error');
            err.hidden = false;
            node.querySelector('.channel-error-time').textContent = ch.last_error_at;
            node.querySelector('.channel-error-message').textContent = ch.last_error_message || '';
        }

        const logResp = await fetch(`${apiBase}/notifications/log?channel=${ch.id}&limit=10`).then(r => r.json());
        const logUl = node.querySelector('.channel-log');
        if (logResp.success && logResp.data.items.length) {
            for (const row of logResp.data.items) {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between';
                li.innerHTML = `<span>${row.ok ? '✓' : '✗'} ${row.created_at} ${row.intent ?? ''} ${row.topic ?? ''}</span>` +
                               `<span class="text-muted">${row.http_status ?? ''} (${row.duration_ms}ms)</span>`;
                logUl.appendChild(li);
            }
        } else {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = 'no recent sends';
            logUl.appendChild(li);
        }

        container.appendChild(node);
    }
})();
</script>
```

- [ ] **Step 3b: Create the mobile view**

Create `src/Dashboard/Views/notifications-mobile.php`:

```php
<?php declare(strict_types=1); ?>
<?php include __DIR__ . '/notifications.php'; ?>
```

(Same content for v1.2.0 — mobile-specific layout deferred. The shared script + Bootstrap responsive grid work on both.)

- [ ] **Step 3c: Add route + nav link in `public/index.php`**

Edit the `$routes` array in `public/index.php`:

```php
$routes = [
    '/'              => 'dashboard',
    '/notifications' => 'notifications',
];
```

Add a nav link in the navbar (next to the existing "Dashboard" `<li>`):

```php
<li class="nav-item">
    <a class="nav-link <?= $page === 'notifications' || $page === 'notifications-mobile' ? 'active' : '' ?>" href="/notifications">
        <i class="bi bi-bell"></i> Notifications
    </a>
</li>
```

- [ ] **Step 4: Smoke-test the page**

```bash
docker-compose up -d
xdg-open http://localhost/notifications 2>/dev/null || \
  echo "Open http://localhost/notifications in a browser"
```

Expected: page renders with either an empty-state alert or one card per channel.

- [ ] **Step 5: Commit**

```bash
git add src/Dashboard/Views/notifications.php src/Dashboard/Views/notifications-mobile.php public/index.php
git commit -m "feat(notifications): add read-only /notifications dashboard view"
```

---

### PR #3 — Final review checkpoint

- [ ] `composer test` green; `composer test:coverage` ≥ 80 %.
- [ ] Manual: `/notifications` shows empty state when no channels; populated cards when at least one channel exists.
- [ ] Open PR: "feat: read-only notifications dashboard view (PR 3/6)".

---

## PR #4 — Wire `AegisXmlParser` to fire `CallProcessedEvent`

**Goal:** The parser, after a successful commit, fires an event with the correct `Intent` and `changedFields`. The watcher registers `NotificationDispatcher` as a subscriber. End-to-end pipeline now works.

**Acceptance criteria:**
- New integration test asserts the parser fires the right intent for created / changed-call-type / new-units-only / closed fixtures.
- Behavior identical when no channels are enabled (no events become sends, no test regressions).
- Existing parser tests still green.

### Task 4.1: Add `IntentResolver`

A small pure-PHP component that takes the existing-row snapshot (or null) plus the incoming XML and produces `(Intent, changedFields[])`. Easier to unit-test than an inline parser branch.

**Files:**
- Create: `src/Notifications/IntentResolver.php`
- Test: `tests/Unit/Notifications/IntentResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IntentResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\IntentResolver
 */
class IntentResolverTest extends TestCase
{
    public function testNoExistingRowProducesCreated(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: null,
            incoming: $this->incoming(['closed_flag' => false]),
        );
        $this->assertSame(Intent::Created, $intent);
        $this->assertSame([], $changed);
    }

    public function testClosedFlagTrueProducesClosed(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(),
            incoming: $this->incoming(['closed_flag' => true]),
        );
        $this->assertSame(Intent::Closed, $intent);
        $this->assertSame([], $changed);
    }

    public function testCallTypeChangeProducesUpdatedWithField(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(['call_type' => 'Medical']),
            incoming: $this->incoming(['call_type' => 'Structure Fire']),
        );
        $this->assertSame(Intent::Updated, $intent);
        $this->assertSame(['call_type'], $changed);
    }

    public function testNewUnitProducesUpdatedWithUnitsField(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(['units' => 'ENGINE1']),
            incoming: $this->incoming(['units' => 'ENGINE1|TRUCK1']),
        );
        $this->assertSame(Intent::Updated, $intent);
        $this->assertContains('assigned_units', $changed);
    }

    public function testNoMaterialChangeReturnsNull(): void
    {
        [$intent, $changed] = IntentResolver::resolve(
            existing: $this->existing(),
            incoming: $this->incoming(),
        );
        $this->assertNull($intent);
        $this->assertSame([], $changed);
    }

    /** @return array<string,mixed> */
    private function existing(array $overrides = []): array
    {
        return array_merge([
            'call_type' => 'Fire',
            'full_address' => '123 Main',
            'alarm_level' => 1,
            'units' => 'ENGINE1',
            'jurisdictions' => 'MCFD',
            'agencies' => 'Fire',
        ], $overrides);
    }

    private function incoming(array $overrides = []): array
    {
        return array_merge([
            'call_type' => 'Fire',
            'full_address' => '123 Main',
            'alarm_level' => 1,
            'units' => 'ENGINE1',
            'jurisdictions' => 'MCFD',
            'agencies' => 'Fire',
            'closed_flag' => false,
        ], $overrides);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter IntentResolverTest`
Expected: FAIL.

- [ ] **Step 3: Write the implementation**

Create `src/Notifications/IntentResolver.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class IntentResolver
{
    private const FIELD_MAP = [
        'call_type'    => 'call_type',
        'full_address' => 'full_address',
        'alarm_level'  => 'alarm_level',
        'units'        => 'assigned_units',
        'jurisdictions'=> 'jurisdictions',
        'agencies'     => 'agencies',
    ];

    /**
     * @param array<string,mixed>|null $existing  Snapshot of the row prior to this XML, or null if call_id is new.
     * @param array<string,mixed>      $incoming  Snapshot built from the parsed XML.
     * @return array{0:?Intent,1:string[]}
     */
    public static function resolve(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return [Intent::Created, []];
        }
        if (($incoming['closed_flag'] ?? false) === true) {
            return [Intent::Closed, []];
        }

        $changed = [];
        foreach (self::FIELD_MAP as $key => $reportedAs) {
            $a = $existing[$key] ?? null;
            $b = $incoming[$key] ?? null;
            if ((string) $a !== (string) $b) {
                $changed[] = $reportedAs;
            }
        }

        if ($changed === []) {
            return [null, []];
        }
        return [Intent::Updated, $changed];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter IntentResolverTest`
Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/IntentResolver.php tests/Unit/Notifications/IntentResolverTest.php
git commit -m "feat(notifications): add IntentResolver (Created/Updated/Closed/no-op)"
```

---

### Task 4.2: Modify `AegisXmlParser::processFile()` to fire the event

**Files:**
- Modify: `src/AegisXmlParser.php`
- Test: `tests/Integration/AegisXmlParserDispatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use DateTimeImmutable;
use NwsCad\AegisXmlParser;
use NwsCad\Database;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\AegisXmlParser
 */
class AegisXmlParserDispatchTest extends TestCase
{
    private static \PDO $db;
    private string $xmlPath;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        EventDispatcher::reset();
        $this->xmlPath = sys_get_temp_dir() . '/test-' . uniqid() . '.xml';
    }

    protected function tearDown(): void
    {
        @unlink($this->xmlPath);
    }

    public function testFirstFileEmitsCreated(): void
    {
        $captured = null;
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$captured) {
            $captured = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(callId: 999, callType: 'Medical'));

        $parser = new AegisXmlParser();
        $this->assertTrue($parser->processFile($this->xmlPath));

        $this->assertNotNull($captured);
        $this->assertSame(Intent::Created, $captured->intent);
        $this->assertSame([], $captured->changedFields);
    }

    public function testSecondFileWithCallTypeChangeEmitsUpdated(): void
    {
        $events = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$events) {
            $events[] = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(999, 'Medical'));
        (new AegisXmlParser())->processFile($this->xmlPath);

        $secondPath = $this->xmlPath . '.2';
        file_put_contents($secondPath, $this->minimalXml(999, 'Structure Fire'));
        (new AegisXmlParser())->processFile($secondPath);
        @unlink($secondPath);

        $this->assertCount(2, $events);
        $this->assertSame(Intent::Updated, $events[1]->intent);
        $this->assertContains('call_type', $events[1]->changedFields);
    }

    public function testClosedFlagEmitsClosed(): void
    {
        $events = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$events) {
            $events[] = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(999, 'Medical'));
        (new AegisXmlParser())->processFile($this->xmlPath);

        $secondPath = $this->xmlPath . '.2';
        file_put_contents($secondPath, $this->minimalXml(999, 'Medical', closed: true));
        (new AegisXmlParser())->processFile($secondPath);
        @unlink($secondPath);

        $this->assertSame(Intent::Closed, $events[1]->intent);
    }

    private function minimalXml(int $callId, string $callType, bool $closed = false): string
    {
        $closedFlag = $closed ? 'true' : 'false';
        // Schema-faithful but minimal — only the elements AegisXmlParser actually reads.
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>{$callId}</CallId>
    <CallNumber>TEST-{$callId}</CallNumber>
    <CreateDateTime>2026-05-07T12:00:00</CreateDateTime>
    <ClosedFlag>{$closedFlag}</ClosedFlag>
    <AlarmLevel>1</AlarmLevel>
    <NatureOfCall>Test</NatureOfCall>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>Fire</AgencyType>
            <CallType>{$callType}</CallType>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>123 Main</FullAddress>
    </Location>
    <Incidents>
        <Incident>
            <Jurisdiction>MCFD</Jurisdiction>
        </Incident>
    </Incidents>
    <AssignedUnits>
        <Unit>
            <UnitNumber>ENGINE1</UnitNumber>
        </Unit>
    </AssignedUnits>
</CallExport>
XML;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter AegisXmlParserDispatchTest`
Expected: FAIL — events are not yet captured.

- [ ] **Step 3: Modify `AegisXmlParser`**

In `src/AegisXmlParser.php`, add at the top:

```php
use DateTimeImmutable;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\IntentResolver;
```

Modify `processFile()`. Locate the section that currently reads (approximate; engineer should preserve the existing transactional flow):

```php
$callId = $this->insertCall($xml, $filePath);
// ...
$this->markFileAsProcessed($filename, $filePath, 1);
$this->db->commit();
$this->logger->info("File processed successfully: {$filename} (Call ID: {$callId})");
return true;
```

Replace with:

```php
$existingSnapshot = $this->snapshotExisting((int) $xml->CallId);
$callId = $this->insertCall($xml, $filePath);
$incomingSnapshot = $this->snapshotIncoming($xml);
$this->markFileAsProcessed($filename, $filePath, 1);
$this->db->commit();
$this->logger->info("File processed successfully: {$filename} (Call ID: {$callId})");

[$intent, $changedFields] = IntentResolver::resolve($existingSnapshot, $incomingSnapshot);
if ($intent !== null) {
    EventDispatcher::dispatch(new CallProcessedEvent(
        dbCallId: $callId,
        intent: $intent,
        changedFields: $changedFields,
        createDateTime: new DateTimeImmutable($incomingSnapshot['create_datetime'] ?? 'now'),
    ));
}

return true;
```

Add two private methods at the bottom of the class:

```php
/** @return array<string,mixed>|null */
private function snapshotExisting(int $xmlCallId): ?array
{
    $stmt = $this->db->prepare("SELECT id FROM calls WHERE call_id = ?");
    $stmt->execute([$xmlCallId]);
    $row = $stmt->fetch();
    if (! $row) {
        return null;
    }
    $dbCallId = (int) $row['id'];

    return [
        'call_type' => (string) ($this->db->query(
            "SELECT call_type FROM agency_contexts WHERE call_id={$dbCallId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?? ''),
        'full_address' => (string) ($this->db->query(
            "SELECT full_address FROM locations WHERE call_id={$dbCallId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?? ''),
        'alarm_level' => (int) ($this->db->query(
            "SELECT alarm_level FROM calls WHERE id={$dbCallId}"
        )->fetchColumn() ?? 0),
        'units' => $this->concatColumn(
            "SELECT unit_number FROM units WHERE call_id={$dbCallId} ORDER BY unit_number"
        ),
        'jurisdictions' => $this->concatColumn(
            "SELECT DISTINCT jurisdiction FROM incidents WHERE call_id={$dbCallId} AND jurisdiction IS NOT NULL ORDER BY jurisdiction"
        ),
        'agencies' => $this->concatColumn(
            "SELECT DISTINCT agency_type FROM agency_contexts WHERE call_id={$dbCallId} AND agency_type IS NOT NULL ORDER BY agency_type"
        ),
    ];
}

/** @return array<string,mixed> */
private function snapshotIncoming(SimpleXMLElement $xml): array
{
    $units = [];
    foreach ($xml->AssignedUnits->Unit ?? [] as $u) {
        $n = trim((string) $u->UnitNumber);
        if ($n !== '') $units[] = $n;
    }
    $junctions = [];
    foreach ($xml->Incidents->Incident ?? [] as $inc) {
        $j = trim((string) $inc->Jurisdiction);
        if ($j !== '') $junctions[] = $j;
    }
    $agencies = [];
    $callType = '';
    foreach ($xml->AgencyContexts->AgencyContext ?? [] as $ac) {
        $a = trim((string) $ac->AgencyType);
        if ($a !== '') $agencies[] = $a;
        if ($callType === '') $callType = trim((string) $ac->CallType);
    }
    return [
        'call_type' => $callType,
        'full_address' => trim((string) ($xml->Location->FullAddress ?? '')),
        'alarm_level' => (int) ($xml->AlarmLevel ?? 0),
        'units' => implode('|', array_values(array_unique($units))),
        'jurisdictions' => implode('|', array_values(array_unique($junctions))),
        'agencies' => implode('|', array_values(array_unique($agencies))),
        'closed_flag' => $this->parseBoolean((string) ($xml->ClosedFlag ?? 'false')),
        'create_datetime' => (string) ($xml->CreateDateTime ?? ''),
    ];
}

private function concatColumn(string $sql): string
{
    $rows = $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    return implode('|', $rows ?: []);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter AegisXmlParserDispatchTest`
Expected: 3 passed.

Run full suite: `composer test`. All green.

- [ ] **Step 5: Commit**

```bash
git add src/AegisXmlParser.php tests/Integration/AegisXmlParserDispatchTest.php
git commit -m "feat(notifications): emit CallProcessedEvent from AegisXmlParser after commit"
```

---

### Task 4.3: Register `NotificationDispatcher` as subscriber in `watcher.php`

**Files:**
- Modify: `src/watcher.php`

- [ ] **Step 1: Skip — wiring; covered by manual run**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Modify `src/watcher.php`**

Read the current `watcher.php` first to find the right insertion point. At the top of the file, add:

```php
use DateTimeImmutable;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
```

After the existing `Logger::getInstance()` / database-init setup but **before** the `while` loop that calls `FileWatcher`, add the dispatcher wiring:

```php
$config = Config::getInstance();
$deltaSeconds = (int) ($_ENV['NOTIFICATION_DELTA_SECONDS'] ?? getenv('NOTIFICATION_DELTA_SECONDS') ?: 900);

$incidentLoader = function (int $dbCallId): IncidentDto {
    $db = Database::getConnection();
    $stmt = $db->prepare(
        "SELECT
            c.id, c.call_id, c.call_number, c.alarm_level, c.create_datetime,
            c.nature_of_call,
            ac.call_type, ac.agency_type,
            l.full_address, l.nearest_cross_streets, l.latitude_y AS latitude, l.longitude_x AS longitude,
            (SELECT GROUP_CONCAT(jurisdiction SEPARATOR '|')
               FROM incidents WHERE call_id = c.id) AS jurisdiction,
            (SELECT GROUP_CONCAT(unit_number SEPARATOR '|')
               FROM units WHERE call_id = c.id) AS units,
            (SELECT GROUP_CONCAT(narrative_text SEPARATOR ' ')
               FROM narratives WHERE call_id = c.id) AS narrative
         FROM calls c
         LEFT JOIN agency_contexts ac ON ac.call_id = c.id
         LEFT JOIN locations l ON l.call_id = c.id
         WHERE c.id = ?
         LIMIT 1"
    );
    $stmt->execute([$dbCallId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['id' => $dbCallId];
    return IncidentDto::fromRow($row);
};

$channelFactory = function (array $row) use ($config): NotificationChannel {
    $type = $row['type'];
    $cfg = json_decode($row['config_json'] ?: '{}', true) ?: [];
    if ($type === 'ntfy') {
        $tokenEnv = $cfg['auth_token_env'] ?? 'NTFY_AUTH_TOKEN';
        return new NtfyChannel(
            baseUrl: $row['base_url'],
            authToken: $config->secret($tokenEnv),
            config: $cfg,
        );
    }
    if ($type === 'pushover') {
        $tokenEnv = $cfg['token_env'] ?? 'PUSHOVER_TOKEN';
        $userEnv = $cfg['user_env'] ?? 'PUSHOVER_USER';
        return new PushoverChannel(
            baseUrl: $row['base_url'],
            token: $config->secret($tokenEnv),
            user: $config->secret($userEnv),
            config: $cfg,
        );
    }
    throw new \RuntimeException("Unknown channel type: {$type}");
};

$notificationDispatcher = new NotificationDispatcher(
    channelRepo: new ChannelRepository(),
    incidentLoader: $incidentLoader,
    channelFactory: $channelFactory,
    deltaSeconds: $deltaSeconds,
);

EventDispatcher::subscribe(function (CallProcessedEvent $e) use ($notificationDispatcher): void {
    $notificationDispatcher->handle($e);
});
```

- [ ] **Step 4: Smoke-test**

```bash
docker-compose restart app
docker-compose logs --tail=50 app | grep -i notification
```

Expected: log lines from the dispatcher when an XML is dropped into `watch/`. Drop a sample file:

```bash
cp samples/sample-call.xml watch/  # if such a sample exists; otherwise skip
docker-compose logs --tail=50 app
```

- [ ] **Step 5: Commit**

```bash
git add src/watcher.php
git commit -m "feat(notifications): register NotificationDispatcher in watcher.php"
```

---

### Task 4.4: Security tests — topic injection + secret redaction

**Files:**
- Create: `tests/Security/TopicInjectionTest.php`
- Create: `tests/Security/SecretRedactionTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Security/TopicInjectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Notifications\TopicSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\TopicSanitizer
 */
class TopicInjectionTest extends TestCase
{
    /**
     * @dataProvider injectionVectors
     */
    public function testKnownAttackVectorsAreNeutralized(string $input, ?string $expected): void
    {
        $this->assertSame($expected, TopicSanitizer::clean($input));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function injectionVectors(): array
    {
        return [
            'path traversal'      => ['../etc/passwd', 'etc_passwd'],
            'query string'        => ['Fire?token=abc', 'Fire_token_abc'],
            'CRLF injection'      => ["Fire\r\nX-Header: evil", 'Fire_X-Header_evil'],
            'null byte'           => ["A\0B", 'A_B'],
            'leading dot dot'     => ['..', null],
            'whitespace only'     => ['   ', null],
            'pure punctuation'    => ['???', null],
            'unicode-only'        => ['日本語', null],
            'mixed unicode'       => ['Engine日1', 'Engine_1'],
        ];
    }
}
```

Create `tests/Security/SecretRedactionTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use Monolog\Handler\TestHandler;
use NwsCad\Config;
use NwsCad\Logger;
use NwsCad\Logging\SecretRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Logging\RedactingProcessor
 * @covers \NwsCad\Config
 */
class SecretRedactionTest extends TestCase
{
    public function testSecretsAreScrubbedFromMessageAndContext(): void
    {
        SecretRegistry::reset();
        $_ENV['REDACTION_TEST_TOKEN'] = 'super-secret-abc-123';
        Config::getInstance()->secret('REDACTION_TEST_TOKEN');

        $logger = Logger::getInstance();
        $h = new TestHandler();
        $logger->pushHandler($h);

        $logger->info(
            'sent header Authorization: Bearer super-secret-abc-123',
            ['payload' => ['header' => 'Bearer super-secret-abc-123']],
        );

        $records = $h->getRecords();
        $logger->popHandler();
        $r = end($records);

        $this->assertStringNotContainsString('super-secret-abc-123', $r->message);
        $this->assertStringNotContainsString('super-secret-abc-123', json_encode($r->context));
        $this->assertStringContainsString('***', $r->message);

        unset($_ENV['REDACTION_TEST_TOKEN']);
    }
}
```

- [ ] **Step 2: Run tests to verify they pass already**

Run: `./vendor/bin/phpunit tests/Security/TopicInjectionTest.php tests/Security/SecretRedactionTest.php`
Expected: all green (the implementations from PR #1 already satisfy these — the tests are evidence/regression guards, not gaps).

If the topic-injection vectors uncover a sanitizer bug, fix `TopicSanitizer::clean()` before committing.

- [ ] **Step 3: Skip — implementation already correct**

- [ ] **Step 4: Skip**

- [ ] **Step 5: Commit**

```bash
git add tests/Security/TopicInjectionTest.php tests/Security/SecretRedactionTest.php
git commit -m "test(security): add topic-injection and secret-redaction guards"
```

---

### PR #4 — Final review checkpoint

- [ ] `composer test` green; coverage ≥ 80 %.
- [ ] `grep -rE "\\bextract\\(" src/Notifications` returns empty.
- [ ] Manual end-to-end: enable an ntfy channel via CLI, drop a real XML in `watch/`, observe a notification arrive (and a row in `notification_send_log`).
- [ ] Open PR: "feat: parser dispatches CallProcessedEvent (PR 4/6)".

---

## PR #5 — Documentation

**Goal:** One canonical place for operators and developers to understand the notification pipeline.

### Task 5.1: `docs/NOTIFICATIONS.md`

**Files:**
- Create: `docs/NOTIFICATIONS.md`

- [ ] **Step 1: Skip — docs only**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Write the document**

Create `docs/NOTIFICATIONS.md`:

```markdown
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

## Adding a new channel type

Implement `NwsCad\Notifications\NotificationChannel`, return a fresh `type()` string, and extend the `channelFactory` closure in `src/watcher.php`. Add a unit test under `tests/Unit/Notifications/Channels/`.
```

- [ ] **Step 4: Verify links + spelling**

```bash
grep -nE 'TODO|TBD' /home/jcleaver/nws-cad/docs/NOTIFICATIONS.md
```

Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/NOTIFICATIONS.md
git commit -m "docs: add NOTIFICATIONS.md (operator + developer reference)"
```

---

### Task 5.2: Update `README.md`, `CLAUDE.md`, `CHANGELOG.md`

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Skip — docs**

- [ ] **Step 2: Skip**

- [ ] **Step 3a: Update `README.md`**

In the `Features` table, append a row:

```
| **Notifications** | 📢 ntfy.sh + Pushover, hierarchical topics, delta-time gate, read-only dashboard |
```

In the `Components` table after the API entry, add:

```
| **Notifier** | In-process channels (ntfy, Pushover) dispatched from parser commit | - |
```

In the `Documentation` table, add:

```
| [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md) | Notifications operator + developer reference |
```

Append a "Notifications (v1.2.0)" section near the bottom (before "License") with a one-paragraph summary and a pointer to `docs/NOTIFICATIONS.md`.

- [ ] **Step 3b: Update `CLAUDE.md`**

In the "Common commands" section, append:

```bash
# Notifications
php bin/notifications.php list
php bin/notifications.php enable ntfy --base-url=https://ntfy.example
```

In the "Core classes" table, append rows:

```
| `Notifications\NotificationDispatcher` | Subscriber of `CallProcessedEvent`. Applies delta-time gate + intent rules; fans out to enabled channels. |
| `Notifications\Channels\NtfyChannel` / `PushoverChannel` | Send implementations with cURL + bounded retry. |
| `Logging\RedactingProcessor` | Globally registered Monolog processor scrubbing values from `SecretRegistry`. |
```

Append a new section "Notifications" with a 4-5 line summary and link to `docs/NOTIFICATIONS.md`.

- [ ] **Step 3c: Update `CHANGELOG.md`**

Add at the top:

```markdown
## [1.2.0] — 2026-05-07

### Added
- Notifications module (`NwsCad\Notifications\*`) replacing the standalone `nws-endpoints` repo.
- `notification_channels` and `notification_send_log` tables.
- Read-only `/notifications` dashboard view + `GET /api/notifications/channels`, `GET /api/notifications/log`.
- `bin/notifications.php` CLI for enabling/disabling channels.
- `Config::secret()` and `SecretRegistry`/`RedactingProcessor` for env-var-only secrets and globally-scrubbed logs.
- `NOTIFICATION_DELTA_SECONDS` env var (default 900).

### Changed
- `AegisXmlParser` dispatches `CallProcessedEvent` after commit.

### Security
- Topic sanitizer + URL encoding for ntfy paths.
- Explicit `IncidentDto::fromRow()` mapping (no `extract()`).
- cURL-based ntfy sender with explicit error checking (replaces `@file_get_contents`).

### Deprecated
- `k9barry/nws-endpoints` repository — superseded by this module. See `docs/NOTIFICATIONS.md`.
```

- [ ] **Step 4: Skim diffs**

```bash
git diff README.md CLAUDE.md CHANGELOG.md | head -150
```

- [ ] **Step 5: Commit**

```bash
git add README.md CLAUDE.md CHANGELOG.md
git commit -m "docs: update README/CLAUDE/CHANGELOG for v1.2.0 notifications"
```

---

### PR #5 — Final review checkpoint

- [ ] All four documents updated, internally consistent, no TODO/TBD.
- [ ] Open PR: "docs: notifications operator + developer reference (PR 5/6)".

---

## PR #6 — Retire `nws-endpoints`

**Goal:** Final commit on `nws-endpoints` pointing to `nws-cad`, then archive on GitHub. No code in `nws-cad` changes.

**Acceptance criteria:**
- `nws-endpoints` repo's README clearly states it is superseded; landing point is `nws-cad`.
- GitHub archive flag is set on the repo (manual via web UI or `gh`).

### Task 6.1: Update `nws-endpoints/README.md`

**Files (in `/home/jcleaver/nws-endpoints`):**
- Modify: `README.md`

- [ ] **Step 1: Skip — docs**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Replace the top of `README.md`**

Replace the very top of `nws-endpoints/README.md` (everything above the "Overview" heading) with:

```markdown
# nws-endpoints — DEPRECATED

> **This repository is superseded by [k9barry/nws-cad](https://github.com/k9barry/nws-cad) as of v1.2.0 (2026-05-07).**
> The functionality of this repo (CAD XML → ntfy.sh / Pushover notifications) now lives inside nws-cad as the `NwsCad\Notifications\*` module, sharing a single XML parser, a single watcher process, and the normalized 13-table schema.
> See: https://github.com/k9barry/nws-cad/blob/main/docs/NOTIFICATIONS.md
>
> The historical content below is preserved for reference. **Do not deploy this code for new installs.**

---
```

- [ ] **Step 4: Skim the diff**

```bash
cd /home/jcleaver/nws-endpoints
git diff README.md | head -40
```

- [ ] **Step 5: Commit**

```bash
cd /home/jcleaver/nws-endpoints
git add README.md
git commit -m "docs: mark repository deprecated; superseded by k9barry/nws-cad"
```

(Push to GitHub when ready: `git push`. Do **not** force-push.)

---

### Task 6.2: Archive on GitHub

**Files:** None — GitHub UI / CLI only.

- [ ] **Step 1: Skip**

- [ ] **Step 2: Skip**

- [ ] **Step 3: Confirm with the user, then archive**

Ask the user explicitly to confirm before flipping the archive flag (it's reversible, but visible). Once confirmed:

```bash
gh repo archive k9barry/nws-endpoints --yes
```

If `gh` is unavailable, the user can do this manually: GitHub → repo → Settings → Danger Zone → Archive this repository.

- [ ] **Step 4: Verify archived**

```bash
gh repo view k9barry/nws-endpoints --json isArchived
```

Expected: `{"isArchived":true}`.

- [ ] **Step 5: No commit needed**

GitHub state change only.

---

### PR #6 — Final review checkpoint

- [ ] `nws-endpoints/README.md` clearly deprecated.
- [ ] Repository archived on GitHub (verified via `gh repo view`).
- [ ] Update `nws-cad/README.md` to link the archived repo for history (small follow-up commit if not already done in PR #5).

---

## Final cross-PR verification

After PR #6 is merged and the repo is archived, run this once on `nws-cad`:

- [ ] `composer test` — all four suites green.
- [ ] `composer test:coverage` — ≥ 80 %.
- [ ] `grep -rE "\\bextract\\(" src/Notifications src/Logging` — empty.
- [ ] Manual end-to-end: enable ntfy + Pushover, drop a real XML in `watch/`, confirm both pushes arrive within `NOTIFICATION_DELTA_SECONDS` and a row appears in `notification_send_log` per channel.
- [ ] `/notifications` page renders with both channels and recent sends.
- [ ] Restart the watcher container — confirm no errors at startup, secrets do not appear in `docker-compose logs app`.

If all six pass, the consolidation is complete.
