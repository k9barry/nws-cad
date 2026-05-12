# Notification Outbox + Async Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple notification fan-out from the parser by persisting per-channel intents to a transactional outbox table that the watcher loop drains asynchronously.

**Architecture:** New `notification_outbox` table; `OutboxWriter` subscribes to `CallProcessedEvent` and inserts one row per `(event, enabled_channel)`; `OutboxProcessor::tick()` runs from `FileWatcher`'s loop after the file scan, claiming a batch of rows by setting `status='in_flight'` + `claimed_by=$workerId`, processing each through the existing channel-send path, and marking each row `done`/`failed` or scheduling a retry. `NotificationDispatcher` is deleted; its responsibilities are split between the writer (gates + channel fan-out) and the processor (per-row send + record). Per-channel rows mean a single failing channel retries independently.

**Tech Stack:** PHP 8.3 strict types; PDO (MySQL + Postgres parity via `DbHelper`); Monolog via `Logger::getInstance()`; PHPUnit 10.5 with `#[CoversClass]`/`#[UsesClass]` attributes (strict coverage metadata); Mockery for mocked dependencies.

**Spec:** `docs/superpowers/specs/2026-05-12-outbox-async-worker-design.md` — read it first if you've lost context.

**Conventions reminder (from CLAUDE.md):**
- All PHP files start with `declare(strict_types=1);`
- Prepared statements only; identifier interpolation goes through `DbHelper::IDENTIFIER_PATTERN` checks
- Three schema files must move together: `database/mysql/init.sql`, `database/postgres/init.sql`, `database/schema.sql`
- Tests calling controllers reset `Response::resetForTesting()` in `setUp()`
- All test classes need `#[CoversClass]` or `#[CoversNothing]`; transitively executed classes need `#[UsesClass]`

---

## File Map

**New files:**

| Path | Purpose |
|---|---|
| `src/Notifications/TopicResolver.php` | Pure topic-mode + topic-list logic extracted from old dispatcher |
| `src/Notifications/Outbox/WorkerId.php` | Process-stable `host:pid:starttimestamp` identifier |
| `src/Notifications/Outbox/OutboxRepositoryInterface.php` | DB-access boundary (mockable) |
| `src/Notifications/Outbox/OutboxRepository.php` | PDO implementation: insert, prune, resetOrphans, claim, markDone, markRetry, markFailed |
| `src/Notifications/Outbox/OutboxWriter.php` | `CallProcessedEvent` subscriber — gates + per-channel inserts |
| `src/Notifications/Outbox/OutboxProcessor.php` | `tick()` orchestration + `processRow()` per-row send |
| `database/migrations/2026-05-12-notification-outbox.sql` | Operator migration (MySQL) |
| `database/migrations/2026-05-12-notification-outbox.pgsql.sql` | Operator migration (Postgres) |
| `tests/Unit/Notifications/TopicResolverTest.php` | Resolver unit tests |
| `tests/Unit/Notifications/Outbox/WorkerIdTest.php` | WorkerId unit tests |
| `tests/Unit/Notifications/Outbox/OutboxWriterTest.php` | Writer unit tests with mocked repo |
| `tests/Unit/Notifications/Outbox/OutboxProcessorTest.php` | Processor unit tests with mocked repo + factory |
| `tests/Integration/Notifications/OutboxRepositoryTest.php` | Real-DB integration test for repository |
| `tests/Integration/Notifications/OutboxEndToEndTest.php` | Producer → consumer end-to-end with stub channel |

**Modified files:**

| Path | Change |
|---|---|
| `database/mysql/init.sql` | + `notification_outbox` table |
| `database/postgres/init.sql` | + `notification_outbox` table |
| `database/schema.sql` | + `notification_outbox` table (CI seed) |
| `src/Notifications/ChannelRepositoryInterface.php` | + `findById(int $id): ?array` |
| `src/Notifications/ChannelRepository.php` | implements new `findById` |
| `src/FileWatcher.php` | + `setOnTick(callable)`; loop invokes the callback after the scan |
| `src/watcher.php` | Replace `NotificationDispatcher` wiring with `OutboxWriter` subscription + `OutboxProcessor` tick callback |
| `tests/bootstrap.php` | Add `notification_outbox` to `cleanTestDatabase()` list (must precede `notification_send_log` for FK) |
| `tests/Integration/Notifications/WebhookEndToEndTest.php` | Drive new producer→consumer flow instead of `NotificationDispatcher::handle()` |
| `CHANGELOG.md` | Mention outbox in Unreleased section |
| `docs/NOTIFICATIONS.md` | Describe new async flow + outbox table |

**Deleted files:**

| Path | Reason |
|---|---|
| `src/Notifications/NotificationDispatcher.php` | Functionality split between `OutboxWriter` and `OutboxProcessor` |
| `tests/Unit/Notifications/NotificationDispatcherTest.php` | Logic now exercised by `OutboxWriterTest` + `OutboxProcessorTest` |

---

## Task ordering

TDD ordered: schema first (others need it), then pure helpers (TopicResolver, WorkerId), then DB access (OutboxRepository), then the consumers (OutboxWriter, OutboxProcessor), then wiring (FileWatcher, watcher.php), then integration test + cleanup.

---

### Task 1: Schema — `notification_outbox` table in all SQL files

**Files:**
- Modify: `database/mysql/init.sql` — append new CREATE TABLE after the existing `notification_send_log` block
- Modify: `database/postgres/init.sql` — append new CREATE TABLE after the existing `notification_send_log` block
- Modify: `database/schema.sql` — append new CREATE TABLE after the existing `notification_send_log` block
- Create: `database/migrations/2026-05-12-notification-outbox.sql` (MySQL operator migration)
- Create: `database/migrations/2026-05-12-notification-outbox.pgsql.sql` (Postgres operator migration)
- Modify: `tests/bootstrap.php:98-104` — prepend `'notification_outbox'` to the `$tables` array (must come before `notification_send_log` since both reference `notification_channels` and we DELETE in array order)

- [ ] **Step 1: Add MySQL table to `database/mysql/init.sql`**

Append at end (after `notification_send_log` block, before any final pragma lines):

```sql
-- Notification outbox: per-channel delivery intents queued by the parser,
-- consumed by FileWatcher's outbox tick. One row per (CallProcessedEvent,
-- enabled_channel). See docs/superpowers/specs/2026-05-12-outbox-async-worker-design.md.
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    db_call_id          INT NOT NULL,
    channel_id          INT NOT NULL,
    intent              VARCHAR(16) NOT NULL,
    resend_all          TINYINT NOT NULL DEFAULT 0,
    added_topics_json   TEXT NOT NULL,
    create_datetime     DATETIME NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts            INT NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME NULL,
    claimed_at          DATETIME NULL,
    claimed_by          VARCHAR(64) NULL,
    last_error          TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notification_outbox_status_next (status, next_attempt_at),
    INDEX idx_notification_outbox_call (db_call_id),
    FOREIGN KEY (db_call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES notification_channels(id) ON DELETE CASCADE
);
```

- [ ] **Step 2: Add Postgres table to `database/postgres/init.sql`**

Append at end (after `notification_send_log` block):

```sql
-- Notification outbox: per-channel delivery intents queued by the parser,
-- consumed by FileWatcher's outbox tick. See spec.
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                  BIGSERIAL PRIMARY KEY,
    db_call_id          INTEGER NOT NULL REFERENCES calls(id) ON DELETE CASCADE,
    channel_id          BIGINT NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    intent              VARCHAR(16) NOT NULL,
    resend_all          BOOLEAN NOT NULL DEFAULT FALSE,
    added_topics_json   TEXT NOT NULL,
    create_datetime     TIMESTAMP NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts            INTEGER NOT NULL DEFAULT 0,
    next_attempt_at     TIMESTAMP NULL,
    claimed_at          TIMESTAMP NULL,
    claimed_by          VARCHAR(64) NULL,
    last_error          TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notification_outbox_status_next
    ON notification_outbox(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_notification_outbox_call
    ON notification_outbox(db_call_id);
```

Note: Postgres has no native `ON UPDATE CURRENT_TIMESTAMP`. The repository sets `updated_at = CURRENT_TIMESTAMP` manually in every UPDATE statement.

- [ ] **Step 3: Add the same table to `database/schema.sql`**

Use the MySQL form (this file is the CI seed and CI uses MySQL). Append after the `notification_send_log` block.

```sql
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    db_call_id          INT NOT NULL,
    channel_id          INT NOT NULL,
    intent              VARCHAR(16) NOT NULL,
    resend_all          TINYINT NOT NULL DEFAULT 0,
    added_topics_json   TEXT NOT NULL,
    create_datetime     DATETIME NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts            INT NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME NULL,
    claimed_at          DATETIME NULL,
    claimed_by          VARCHAR(64) NULL,
    last_error          TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notification_outbox_status_next (status, next_attempt_at),
    INDEX idx_notification_outbox_call (db_call_id),
    FOREIGN KEY (db_call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES notification_channels(id) ON DELETE CASCADE
);
```

- [ ] **Step 4: Create MySQL operator migration**

Create `database/migrations/2026-05-12-notification-outbox.sql` with exactly the `CREATE TABLE IF NOT EXISTS notification_outbox (...)` block from Step 1 (so operators can `mysql < migration.sql` against an existing DB).

- [ ] **Step 5: Create Postgres operator migration**

Create `database/migrations/2026-05-12-notification-outbox.pgsql.sql` with the table + indexes from Step 2.

- [ ] **Step 6: Update `tests/bootstrap.php`**

Modify the `$tables` array in `cleanTestDatabase()` so `notification_outbox` is deleted before `notification_send_log` (both FK to `notification_channels` and `notification_outbox` also FKs `calls`; ordering keeps the DELETE deterministic):

```php
$tables = [
    'notification_outbox',
    'notification_send_log',
    'notification_channels',
    'unit_dispositions', 'unit_logs', 'unit_personnel', 'units',
    'call_dispositions', 'vehicles', 'persons', 'narratives',
    'incidents', 'locations', 'agency_contexts', 'calls', 'processed_files'
];
```

- [ ] **Step 7: Apply migration in dev container to make the table exist now**

Run: `docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" nws_cad < /var/lib/mysql-files/notification-outbox.sql' 2>&1` — wait, the container won't see the file. Use heredoc:

```bash
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" nws_cad' < database/migrations/2026-05-12-notification-outbox.sql
```

Expected: no output (or `Database changed`). Verify with:

```bash
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" nws_cad -e 'SHOW TABLES LIKE "notification_outbox"'
```

Expected: one row listing `notification_outbox`.

Also create the table in the test database:

```bash
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" nws_cad_test' < database/migrations/2026-05-12-notification-outbox.sql
```

- [ ] **Step 8: Commit**

```bash
git add database/mysql/init.sql database/postgres/init.sql database/schema.sql \
        database/migrations/2026-05-12-notification-outbox.sql \
        database/migrations/2026-05-12-notification-outbox.pgsql.sql \
        tests/bootstrap.php
git commit -m "feat(notifications): notification_outbox table schema + migrations"
```

---

### Task 2: `TopicResolver` — pure logic helper

**Files:**
- Create: `src/Notifications/TopicResolver.php`
- Create: `tests/Unit/Notifications/TopicResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/TopicResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(Intent::class)]
final class TopicResolverTest extends TestCase
{
    public function testShouldResendAllOnCreated(): void
    {
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Created, []));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Created, ['anything']));
    }

    public function testShouldResendAllOnUpdatedWithTriggerField(): void
    {
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['call_type']));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['full_address', 'nature_of_call']));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['alarm_level']));
    }

    public function testShouldNotResendAllOnUpdatedWithoutTrigger(): void
    {
        $this->assertFalse(TopicResolver::shouldResendAll(Intent::Updated, []));
        $this->assertFalse(TopicResolver::shouldResendAll(Intent::Updated, ['nature_of_call', 'narrative']));
    }

    public function testResolveTopicsReturnsAllDerivedWhenResendAll(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => 'Fire',
            'jurisdiction' => 'IN048|IN049|IN048',
            'units' => 'E1|L2|E1',
            'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $topics = TopicResolver::resolveTopics($dto, true, ['ignored']);
        $this->assertSame(['Fire', 'IN048', 'IN049', 'E1', 'L2'], $topics);
    }

    public function testResolveTopicsReturnsAddedTopicsWhenNotResendAll(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => 'Fire', 'jurisdiction' => 'IN048',
            'units' => 'E1', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $topics = TopicResolver::resolveTopics($dto, false, ['E2', '', null, 'E2']);
        $this->assertSame(['E2'], $topics);
    }

    public function testResolveTopicsReturnsEmptyWhenAllInputsBlank(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => null, 'jurisdiction' => '',
            'units' => '', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $this->assertSame([], TopicResolver::resolveTopics($dto, true, []));
        $this->assertSame([], TopicResolver::resolveTopics($dto, false, []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/TopicResolverTest.php
```

Expected: FAIL — `Class NwsCad\Notifications\TopicResolver does not exist`.

- [ ] **Step 3: Implement `TopicResolver`**

Create `src/Notifications/TopicResolver.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class TopicResolver
{
    private const RESEND_ALL_TRIGGERS = ['call_type', 'full_address', 'alarm_level'];

    /** @param string[] $changedFields */
    public static function shouldResendAll(Intent $intent, array $changedFields): bool
    {
        if ($intent === Intent::Created) {
            return true;
        }
        if ($intent === Intent::Updated) {
            return count(array_intersect(self::RESEND_ALL_TRIGGERS, $changedFields)) > 0;
        }
        return false;
    }

    /**
     * @param string[] $addedTopics
     * @return string[]
     */
    public static function resolveTopics(IncidentDto $dto, bool $resendAll, array $addedTopics): array
    {
        if ($resendAll) {
            return self::buildAllTopics($dto);
        }
        return array_values(array_unique(array_filter(
            $addedTopics,
            static fn ($v): bool => $v !== null && $v !== '',
        )));
    }

    /** @return string[] */
    private static function buildAllTopics(IncidentDto $dto): array
    {
        $parts = [];
        if ($dto->agencyType !== null && $dto->agencyType !== '') {
            $parts[] = $dto->agencyType;
        }
        foreach (self::splitPipe($dto->jurisdiction ?? '') as $j) {
            $parts[] = $j;
        }
        foreach (self::splitPipe($dto->units) as $u) {
            $parts[] = $u;
        }
        return array_values(array_unique($parts));
    }

    /** @return string[] */
    private static function splitPipe(string $value): array
    {
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/TopicResolverTest.php
```

Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/TopicResolver.php tests/Unit/Notifications/TopicResolverTest.php
git commit -m "feat(notifications): TopicResolver — extract topic-mode + topic-list logic"
```

---

### Task 3: `WorkerId` — process-stable identifier

**Files:**
- Create: `src/Notifications/Outbox/WorkerId.php`
- Create: `tests/Unit/Notifications/Outbox/WorkerIdTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/Outbox/WorkerIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use NwsCad\Notifications\Outbox\WorkerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerId::class)]
final class WorkerIdTest extends TestCase
{
    protected function setUp(): void
    {
        WorkerId::reset();
    }

    protected function tearDown(): void
    {
        WorkerId::reset();
    }

    public function testCurrentIsStableAcrossCalls(): void
    {
        $first  = WorkerId::current();
        $second = WorkerId::current();
        $this->assertSame($first, $second);
    }

    public function testFormatIsHostColonPidColonTimestamp(): void
    {
        $id = WorkerId::current();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+:[0-9]+:[0-9]+$/', $id);
    }

    public function testResetProducesNewTimestampOnNextCall(): void
    {
        $first = WorkerId::current();
        WorkerId::reset();
        sleep(1);
        $second = WorkerId::current();
        $this->assertNotSame($first, $second);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/WorkerIdTest.php
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement `WorkerId`**

Create `src/Notifications/Outbox/WorkerId.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

final class WorkerId
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached === null) {
            $host = gethostname() ?: 'unknown';
            $pid  = getmypid() ?: 0;
            $ts   = time();
            self::$cached = "{$host}:{$pid}:{$ts}";
        }
        return self::$cached;
    }

    /** Test-only: clear the memoized value. */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/WorkerIdTest.php
```

Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/WorkerId.php tests/Unit/Notifications/Outbox/WorkerIdTest.php
git commit -m "feat(notifications): WorkerId — host:pid:start identifier for outbox workers"
```

---

### Task 4: `ChannelRepositoryInterface::findById`

**Files:**
- Modify: `src/Notifications/ChannelRepositoryInterface.php`
- Modify: `src/Notifications/ChannelRepository.php`
- Modify: `tests/Integration/ChannelRepositoryTest.php` — add test for `findById`

- [ ] **Step 1: Find existing test file and add a failing test**

Open `tests/Integration/ChannelRepositoryTest.php`. Add the following test method to the class (preserve existing structure):

```php
public function testFindByIdReturnsRow(): void
{
    self::$db->exec(
        "INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
         VALUES ('ntfy_primary', 'ntfy', 1, 'https://ntfy.example', '{}')"
    );
    $id = (int) self::$db->lastInsertId();

    $repo = new \NwsCad\Notifications\ChannelRepository(self::$db);
    $row = $repo->findById($id);

    $this->assertNotNull($row);
    $this->assertSame('ntfy_primary', $row['name']);
    $this->assertSame('ntfy', $row['type']);
    $this->assertSame('https://ntfy.example', $row['base_url']);
}

public function testFindByIdReturnsNullForMissing(): void
{
    $repo = new \NwsCad\Notifications\ChannelRepository(self::$db);
    $this->assertNull($repo->findById(999999));
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/ChannelRepositoryTest.php --filter findById
```

Expected: FAIL — `Call to undefined method NwsCad\Notifications\ChannelRepository::findById`.

- [ ] **Step 3: Add method to interface**

Edit `src/Notifications/ChannelRepositoryInterface.php`, replace the entire interface body with:

```php
interface ChannelRepositoryInterface
{
    /**
     * @return array<int,array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}>
     */
    public function listEnabled(): array;

    /**
     * @return array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}|null
     */
    public function findById(int $id): ?array;

    public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void;

    public function markFailure(int $channelId, string $message): void;
}
```

- [ ] **Step 4: Add implementation to concrete repository**

Edit `src/Notifications/ChannelRepository.php`. Insert this method between `listEnabled()` and `recordSend()`:

```php
public function findById(int $id): ?array
{
    return $this->exec(function (PDO $db) use ($id): ?array {
        $stmt = $db->prepare(
            "SELECT id, name, type, enabled, base_url, config_json
             FROM notification_channels WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    });
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/ChannelRepositoryTest.php
```

Expected: PASS — all existing tests + 2 new ones.

- [ ] **Step 6: Commit**

```bash
git add src/Notifications/ChannelRepositoryInterface.php \
        src/Notifications/ChannelRepository.php \
        tests/Integration/ChannelRepositoryTest.php
git commit -m "feat(notifications): ChannelRepository::findById for outbox consumer lookup"
```

---

### Task 5: `OutboxRepositoryInterface` + `OutboxRepository` skeleton + `insert`

**Files:**
- Create: `src/Notifications/Outbox/OutboxRepositoryInterface.php`
- Create: `src/Notifications/Outbox/OutboxRepository.php`
- Create: `tests/Integration/Notifications/OutboxRepositoryTest.php`

- [ ] **Step 1: Write the failing test for `insert`**

Create `tests/Integration/Notifications/OutboxRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxRepository::class)]
#[UsesClass(Intent::class)]
final class OutboxRepositoryTest extends TestCase
{
    private static PDO $db;
    private OutboxRepository $repo;
    private int $callId;
    private int $channelId;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        $this->repo = new OutboxRepository(self::$db);

        self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'C-1', '2026-05-07 12:00:00')");
        $this->callId = (int) self::$db->lastInsertId();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json) VALUES ('ntfy_primary', 'ntfy', 1, 'https://x', '{}')");
        $this->channelId = (int) self::$db->lastInsertId();
    }

    public function testInsertWritesRowWithExpectedFields(): void
    {
        $id = $this->repo->insert(
            callId:        $this->callId,
            channelId:     $this->channelId,
            intent:        Intent::Created,
            resendAll:     true,
            addedTopics:   ['IN048', 'E1'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = self::$db->query("SELECT * FROM notification_outbox WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->callId, (int) $row['db_call_id']);
        $this->assertSame($this->channelId, (int) $row['channel_id']);
        $this->assertSame('Created', $row['intent']);
        $this->assertSame(1, (int) $row['resend_all']);
        $this->assertSame(['IN048', 'E1'], json_decode($row['added_topics_json'], true));
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['next_attempt_at']);
        $this->assertNull($row['claimed_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['last_error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: FAIL — `OutboxRepository` class does not exist.

- [ ] **Step 3: Create interface**

Create `src/Notifications/Outbox/OutboxRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Notifications\Events\Intent;

interface OutboxRepositoryInterface
{
    /**
     * @param string[] $addedTopics
     */
    public function insert(
        int $callId,
        int $channelId,
        Intent $intent,
        bool $resendAll,
        array $addedTopics,
        DateTimeImmutable $createDateTime,
    ): int;

    /** @return int rows deleted */
    public function prune(int $olderThanSeconds): int;

    /** @return int rows reset */
    public function resetOrphans(string $currentWorkerId): int;

    /**
     * Atomically claim up to $batchSize pending rows for $workerId, return the claimed rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array;

    public function markDone(int $rowId): void;

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void;

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void;
}
```

- [ ] **Step 4: Create concrete repository with `insert` implementation**

Create `src/Notifications/Outbox/OutboxRepository.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use PDO;

final class OutboxRepository implements OutboxRepositoryInterface
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    private function exec(callable $fn): mixed
    {
        if ($this->db !== null) {
            return $fn($this->db);
        }
        return Database::run($fn);
    }

    public function insert(
        int $callId,
        int $channelId,
        Intent $intent,
        bool $resendAll,
        array $addedTopics,
        DateTimeImmutable $createDateTime,
    ): int {
        return $this->exec(function (PDO $db) use ($callId, $channelId, $intent, $resendAll, $addedTopics, $createDateTime): int {
            $stmt = $db->prepare(
                "INSERT INTO notification_outbox
                 (db_call_id, channel_id, intent, resend_all, added_topics_json, create_datetime)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $callId,
                $channelId,
                $intent->value,
                $resendAll ? 1 : 0,
                json_encode(array_values($addedTopics), JSON_UNESCAPED_SLASHES),
                $createDateTime->format('Y-m-d H:i:s'),
            ]);
            return (int) $db->lastInsertId();
        });
    }

    public function prune(int $olderThanSeconds): int
    {
        throw new \LogicException('not implemented');
    }

    public function resetOrphans(string $currentWorkerId): int
    {
        throw new \LogicException('not implemented');
    }

    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array
    {
        throw new \LogicException('not implemented');
    }

    public function markDone(int $rowId): void
    {
        throw new \LogicException('not implemented');
    }

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void {
        throw new \LogicException('not implemented');
    }

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void
    {
        throw new \LogicException('not implemented');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: PASS — 1 test.

- [ ] **Step 6: Commit**

```bash
git add src/Notifications/Outbox/OutboxRepositoryInterface.php \
        src/Notifications/Outbox/OutboxRepository.php \
        tests/Integration/Notifications/OutboxRepositoryTest.php
git commit -m "feat(notifications): OutboxRepository skeleton + insert"
```

---

### Task 6: `OutboxRepository::markDone` / `markRetry` / `markFailed`

**Files:**
- Modify: `src/Notifications/Outbox/OutboxRepository.php`
- Modify: `tests/Integration/Notifications/OutboxRepositoryTest.php`

- [ ] **Step 1: Add failing tests**

Append to the existing test class (inside the closing brace):

```php
public function testMarkDone(): void
{
    $id = $this->insertPending();
    $this->repo->markDone($id);

    $row = self::$db->query("SELECT status, last_error FROM notification_outbox WHERE id = {$id}")
        ->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('done', $row['status']);
    $this->assertNull($row['last_error']);
}

public function testMarkRetry(): void
{
    $id = $this->insertPending();
    $this->repo->markRetry(
        rowId:         $id,
        attempts:      2,
        nextAttemptAt: new DateTimeImmutable('2026-05-07 13:00:00'),
        errorMessage:  'HTTP 503',
    );

    $row = self::$db->query("SELECT status, attempts, next_attempt_at, claimed_by, claimed_at, last_error
                              FROM notification_outbox WHERE id = {$id}")
        ->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('pending', $row['status']);
    $this->assertSame(2, (int) $row['attempts']);
    $this->assertSame('2026-05-07 13:00:00', $row['next_attempt_at']);
    $this->assertNull($row['claimed_by']);
    $this->assertNull($row['claimed_at']);
    $this->assertSame('HTTP 503', $row['last_error']);
}

public function testMarkFailed(): void
{
    $id = $this->insertPending();
    $this->repo->markFailed($id, 5, 'retries exhausted');

    $row = self::$db->query("SELECT status, attempts, last_error FROM notification_outbox WHERE id = {$id}")
        ->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('failed', $row['status']);
    $this->assertSame(5, (int) $row['attempts']);
    $this->assertSame('retries exhausted', $row['last_error']);
}

private function insertPending(): int
{
    return $this->repo->insert(
        callId:         $this->callId,
        channelId:      $this->channelId,
        intent:         Intent::Created,
        resendAll:      true,
        addedTopics:    [],
        createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: FAIL — `LogicException: not implemented` for each new test.

- [ ] **Step 3: Replace the three stubs with implementations**

In `src/Notifications/Outbox/OutboxRepository.php`, replace the three `throw new \LogicException('not implemented');` bodies for `markDone`, `markRetry`, `markFailed`:

```php
public function markDone(int $rowId): void
{
    $this->exec(function (PDO $db) use ($rowId): void {
        $stmt = $db->prepare(
            "UPDATE notification_outbox
             SET status = 'done', last_error = NULL,
                 claimed_at = NULL, claimed_by = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$rowId]);
    });
}

public function markRetry(
    int $rowId,
    int $attempts,
    DateTimeImmutable $nextAttemptAt,
    string $errorMessage,
): void {
    $this->exec(function (PDO $db) use ($rowId, $attempts, $nextAttemptAt, $errorMessage): void {
        $stmt = $db->prepare(
            "UPDATE notification_outbox
             SET status = 'pending',
                 attempts = ?,
                 next_attempt_at = ?,
                 claimed_at = NULL,
                 claimed_by = NULL,
                 last_error = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([
            $attempts,
            $nextAttemptAt->format('Y-m-d H:i:s'),
            $errorMessage,
            $rowId,
        ]);
    });
}

public function markFailed(int $rowId, int $attempts, string $errorMessage): void
{
    $this->exec(function (PDO $db) use ($rowId, $attempts, $errorMessage): void {
        $stmt = $db->prepare(
            "UPDATE notification_outbox
             SET status = 'failed',
                 attempts = ?,
                 last_error = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$attempts, $errorMessage, $rowId]);
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxRepository.php tests/Integration/Notifications/OutboxRepositoryTest.php
git commit -m "feat(notifications): OutboxRepository markDone/markRetry/markFailed"
```

---

### Task 7: `OutboxRepository::prune` and `resetOrphans`

**Files:**
- Modify: `src/Notifications/Outbox/OutboxRepository.php`
- Modify: `tests/Integration/Notifications/OutboxRepositoryTest.php`

- [ ] **Step 1: Add failing tests**

Append to the test class:

```php
public function testPruneDeletesDoneRowsOlderThanThreshold(): void
{
    $oldDoneId = $this->insertPending();
    $this->repo->markDone($oldDoneId);
    // Backdate updated_at so prune picks it up.
    self::$db->exec("UPDATE notification_outbox SET updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$oldDoneId}");

    $recentDoneId = $this->insertPending();
    $this->repo->markDone($recentDoneId);

    $pendingId = $this->insertPending();
    self::$db->exec("UPDATE notification_outbox SET updated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id = {$pendingId}");

    $deleted = $this->repo->prune(7 * 86400);

    $this->assertSame(1, $deleted);
    $remaining = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox WHERE id = {$oldDoneId}")->fetchColumn();
    $this->assertSame(0, $remaining);
    $this->assertNotFalse(self::$db->query("SELECT id FROM notification_outbox WHERE id = {$recentDoneId}")->fetch());
    $this->assertNotFalse(self::$db->query("SELECT id FROM notification_outbox WHERE id = {$pendingId}")->fetch());
}

public function testResetOrphansClaimsForOtherWorkers(): void
{
    $mineId  = $this->insertPending();
    $otherId = $this->insertPending();

    self::$db->exec(
        "UPDATE notification_outbox SET status='in_flight', claimed_by='me:1:111', claimed_at=NOW() WHERE id={$mineId}"
    );
    self::$db->exec(
        "UPDATE notification_outbox SET status='in_flight', claimed_by='other:2:222', claimed_at=NOW() WHERE id={$otherId}"
    );

    $reset = $this->repo->resetOrphans('me:1:111');

    $this->assertSame(1, $reset);
    $mine  = self::$db->query("SELECT status FROM notification_outbox WHERE id={$mineId}")->fetchColumn();
    $other = self::$db->query("SELECT status, claimed_by FROM notification_outbox WHERE id={$otherId}")->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('in_flight', $mine);
    $this->assertSame('pending', $other['status']);
    $this->assertNull($other['claimed_by']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php --filter 'Prune|Orphan'
```

Expected: FAIL — `LogicException: not implemented`.

- [ ] **Step 3: Implement `prune` and `resetOrphans`**

Replace the two stubs in `OutboxRepository.php`:

```php
public function prune(int $olderThanSeconds): int
{
    return $this->exec(function (PDO $db) use ($olderThanSeconds): int {
        // Date subtraction differs between MySQL and Postgres; build the cutoff in PHP.
        $cutoff = (new DateTimeImmutable())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "DELETE FROM notification_outbox
             WHERE status = 'done' AND updated_at < ?"
        );
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    });
}

public function resetOrphans(string $currentWorkerId): int
{
    return $this->exec(function (PDO $db) use ($currentWorkerId): int {
        $stmt = $db->prepare(
            "UPDATE notification_outbox
             SET status = 'pending',
                 claimed_at = NULL,
                 claimed_by = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE status = 'in_flight' AND (claimed_by IS NULL OR claimed_by <> ?)"
        );
        $stmt->execute([$currentWorkerId]);
        return $stmt->rowCount();
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: PASS — 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxRepository.php tests/Integration/Notifications/OutboxRepositoryTest.php
git commit -m "feat(notifications): OutboxRepository prune + resetOrphans"
```

---

### Task 8: `OutboxRepository::claim`

**Files:**
- Modify: `src/Notifications/Outbox/OutboxRepository.php`
- Modify: `tests/Integration/Notifications/OutboxRepositoryTest.php`

- [ ] **Step 1: Add failing tests**

Append to the test class:

```php
public function testClaimReturnsPendingRowsAndTransitionsThem(): void
{
    $id1 = $this->insertPending();
    $id2 = $this->insertPending();

    $now = new DateTimeImmutable('2026-05-07 14:00:00');
    $claimed = $this->repo->claim('me:1:111', 10, $now);

    $this->assertCount(2, $claimed);
    $this->assertSame([$id1, $id2], array_map(static fn ($r) => (int) $r['id'], $claimed));
    foreach ([$id1, $id2] as $id) {
        $row = self::$db->query("SELECT status, claimed_by, claimed_at FROM notification_outbox WHERE id={$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('in_flight', $row['status']);
        $this->assertSame('me:1:111', $row['claimed_by']);
        $this->assertSame('2026-05-07 14:00:00', $row['claimed_at']);
    }
}

public function testClaimRespectsBatchSize(): void
{
    for ($i = 0; $i < 5; $i++) {
        $this->insertPending();
    }
    $claimed = $this->repo->claim('me:1:111', 2, new DateTimeImmutable('2026-05-07 14:00:00'));
    $this->assertCount(2, $claimed);
}

public function testClaimRespectsNextAttemptAt(): void
{
    $ready   = $this->insertPending();
    $waiting = $this->insertPending();
    self::$db->exec(
        "UPDATE notification_outbox SET next_attempt_at = '2026-05-07 15:00:00' WHERE id = {$waiting}"
    );

    $claimed = $this->repo->claim('me:1:111', 10, new DateTimeImmutable('2026-05-07 14:00:00'));

    $this->assertCount(1, $claimed);
    $this->assertSame($ready, (int) $claimed[0]['id']);
}

public function testClaimReturnsEmptyWhenNoPending(): void
{
    $claimed = $this->repo->claim('me:1:111', 10, new DateTimeImmutable());
    $this->assertSame([], $claimed);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php --filter Claim
```

Expected: FAIL — `LogicException: not implemented`.

- [ ] **Step 3: Implement `claim`**

Replace the `claim` stub in `OutboxRepository.php`:

```php
public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array
{
    return $this->exec(function (PDO $db) use ($workerId, $batchSize, $now): array {
        $nowStr = $now->format('Y-m-d H:i:s');

        // 1) SELECT candidate ids
        $sel = $db->prepare(
            "SELECT id FROM notification_outbox
             WHERE status = 'pending'
               AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY id ASC
             LIMIT :limit"
        );
        $sel->bindValue(1, $nowStr);
        $sel->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $sel->execute();
        $ids = array_map(static fn ($r): int => (int) $r['id'], $sel->fetchAll(PDO::FETCH_ASSOC));
        if ($ids === []) {
            return [];
        }

        // 2) UPDATE in one batch, guarded by status='pending' to avoid claiming
        //    rows another process (e.g., a leftover orphan) already grabbed.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $db->prepare(
            "UPDATE notification_outbox
             SET status = 'in_flight',
                 claimed_at = ?,
                 claimed_by = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $upd->execute([$nowStr, $workerId, ...$ids]);

        // 3) Re-SELECT only what we actually claimed.
        $reSel = $db->prepare(
            "SELECT * FROM notification_outbox
             WHERE id IN ({$placeholders}) AND claimed_by = ? AND status = 'in_flight'
             ORDER BY id ASC"
        );
        $reSel->execute([...$ids, $workerId]);
        return $reSel->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxRepositoryTest.php
```

Expected: PASS — 10 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxRepository.php tests/Integration/Notifications/OutboxRepositoryTest.php
git commit -m "feat(notifications): OutboxRepository::claim — batch claim with worker_id stamping"
```

---

### Task 9: `OutboxWriter`

**Files:**
- Create: `src/Notifications/Outbox/OutboxWriter.php`
- Create: `tests/Unit/Notifications/Outbox/OutboxWriterTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Notifications/Outbox/OutboxWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepositoryInterface;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxWriter::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(CallProcessedEvent::class)]
#[UsesClass(Intent::class)]
final class OutboxWriterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function writer(
        OutboxRepositoryInterface $repo,
        ChannelRepositoryInterface $channelRepo,
        int $deltaSeconds = 900,
        ?DateTimeImmutable $now = null,
    ): OutboxWriter {
        return new OutboxWriter(
            $repo,
            $channelRepo,
            $deltaSeconds,
            static fn (): DateTimeImmutable => $now ?? new DateTimeImmutable('2026-05-07 12:05:00'),
        );
    }

    public function testClosedIntentIsNoop(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldNotReceive('listEnabled');

        $event = new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Closed, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
        $this->assertTrue(true);
    }

    public function testDeltaTimeGateSkipsOldEvents(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldNotReceive('listEnabled');

        $event = new CallProcessedEvent(
            dbCallId: 1, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 11:00:00'),
        );
        $this->writer($repo, $channels, 900, new DateTimeImmutable('2026-05-07 12:30:00'))->handle($event);
        $this->assertTrue(true);
    }

    public function testCreatedInsertsOneRowPerEnabledChannelWithResendAllTrue(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7,  'name' => 'ntfy_primary',     'type' => 'ntfy',     'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
            ['id' => 11, 'name' => 'pushover_primary', 'type' => 'pushover', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Created, true, ['IN048'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);
        $repo->shouldReceive('insert')->once()
            ->with(42, 11, Intent::Created, true, ['IN048'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(102);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Created, changedFields: [],
            addedTopics: ['IN048'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testUpdatedWithoutTriggerFieldSetsResendAllFalse(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Updated, false, ['E2'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Updated, changedFields: ['narrative'],
            addedTopics: ['E2'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testUpdatedWithTriggerFieldSetsResendAllTrue(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([
            ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'],
        ]);

        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('insert')->once()
            ->with(42, 7, Intent::Updated, true, ['E2'], Mockery::type(DateTimeImmutable::class))
            ->andReturn(101);

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Updated, changedFields: ['call_type'],
            addedTopics: ['E2'],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
    }

    public function testNoEnabledChannelsIsNoop(): void
    {
        $channels = Mockery::mock(ChannelRepositoryInterface::class);
        $channels->shouldReceive('listEnabled')->once()->andReturn([]);
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldNotReceive('insert');

        $event = new CallProcessedEvent(
            dbCallId: 42, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $this->writer($repo, $channels)->handle($event);
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxWriterTest.php
```

Expected: FAIL — `OutboxWriter` does not exist.

- [ ] **Step 3: Implement `OutboxWriter`**

Create `src/Notifications/Outbox/OutboxWriter.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\TopicResolver;

final class OutboxWriter
{
    /** @var callable():DateTimeImmutable */
    private $clock;

    public function __construct(
        private readonly OutboxRepositoryInterface $repo,
        private readonly ChannelRepositoryInterface $channelRepo,
        private readonly int $deltaSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function handle(CallProcessedEvent $event): void
    {
        $logger = Logger::getInstance();

        if ($event->intent === Intent::Closed) {
            $logger->info('Outbox writer: Closed intent, no-op', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $now = ($this->clock)();
        $age = $now->getTimestamp() - $event->createDateTime->getTimestamp();
        if ($age > $this->deltaSeconds) {
            $logger->info('Outbox writer: delta-time gate dropped event', [
                'dbCallId' => $event->dbCallId, 'age_seconds' => $age, 'limit' => $this->deltaSeconds,
            ]);
            return;
        }

        $channels = $this->channelRepo->listEnabled();
        if ($channels === []) {
            $logger->info('Outbox writer: no enabled channels', ['dbCallId' => $event->dbCallId]);
            return;
        }

        $resendAll = TopicResolver::shouldResendAll($event->intent, $event->changedFields);
        $inserted  = 0;
        foreach ($channels as $row) {
            $this->repo->insert(
                callId:         $event->dbCallId,
                channelId:      (int) $row['id'],
                intent:         $event->intent,
                resendAll:      $resendAll,
                addedTopics:    $event->addedTopics,
                createDateTime: $event->createDateTime,
            );
            $inserted++;
        }
        $logger->info('Outbox writer: queued', [
            'dbCallId' => $event->dbCallId,
            'intent'   => $event->intent->value,
            'rows'     => $inserted,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxWriterTest.php
```

Expected: PASS — 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxWriter.php tests/Unit/Notifications/Outbox/OutboxWriterTest.php
git commit -m "feat(notifications): OutboxWriter — subscribe to CallProcessedEvent, INSERT per channel"
```

---

### Task 10: `OutboxProcessor::tick` orchestration shell

**Files:**
- Create: `src/Notifications/Outbox/OutboxProcessor.php`
- Create: `tests/Unit/Notifications/Outbox/OutboxProcessorTest.php`

This task implements `tick()` orchestration only — `processRow()` is stubbed to throw. Task 11 adds `processRow()`.

- [ ] **Step 1: Write failing test for `tick` orchestration**

Create `tests/Unit/Notifications/Outbox/OutboxProcessorTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Outbox;

use DateTimeImmutable;
use Mockery;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepositoryInterface;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxProcessor::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(NotificationContext::class)]
#[UsesClass(SendResult::class)]
#[UsesClass(Intent::class)]
final class OutboxProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testTickRunsHousekeepingThenClaimsAndProcesses(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once()->with(7 * 86400);
        $repo->shouldReceive('resetOrphans')->once()->with('me:1:111');
        $repo->shouldReceive('claim')->once()->andReturn([]);

        $factory  = Mockery::mock(ChannelFactoryInterface::class);
        $channels = Mockery::mock(ChannelRepositoryInterface::class);

        $processor = new OutboxProcessor(
            $repo,
            $factory,
            $channels,
            static fn (int $id): IncidentDto => self::fail('loader should not be called when no rows claimed'),
            batchSize:    10,
            maxAttempts:  5,
            workerId:     'me:1:111',
            clock:        static fn () => new DateTimeImmutable('2026-05-07 12:00:00'),
        );

        $processor->tick();
        $this->assertTrue(true);
    }

    public function testTickIsolatedFromHousekeepingFailure(): void
    {
        $repo = Mockery::mock(OutboxRepositoryInterface::class);
        $repo->shouldReceive('prune')->once()->andThrow(new \RuntimeException('db down briefly'));
        $repo->shouldReceive('resetOrphans')->never();
        $repo->shouldReceive('claim')->once()->andReturn([]);

        $factory  = Mockery::mock(ChannelFactoryInterface::class);
        $channels = Mockery::mock(ChannelRepositoryInterface::class);

        $processor = new OutboxProcessor(
            $repo,
            $factory,
            $channels,
            static fn (int $id): IncidentDto => self::fail('loader should not be called'),
            batchSize:    10,
            maxAttempts:  5,
            workerId:     'me:1:111',
        );

        $processor->tick();
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxProcessorTest.php
```

Expected: FAIL — `OutboxProcessor` class does not exist.

- [ ] **Step 3: Implement processor shell**

Create `src/Notifications/Outbox/OutboxProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Logger;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRepositoryInterface;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\TopicResolver;
use Throwable;

final class OutboxProcessor
{
    public const PRUNE_OLDER_THAN_SECONDS = 7 * 86400;
    /** @var int[] indexed by attempts-1 */
    public const BACKOFF_SECONDS = [30, 120, 600, 1800, 7200];

    /** @var callable():DateTimeImmutable */
    private $clock;
    /** @var callable(int):IncidentDto */
    private $incidentLoader;

    public function __construct(
        private readonly OutboxRepositoryInterface $repo,
        private readonly ChannelFactoryInterface $factory,
        private readonly ChannelRepositoryInterface $channelRepo,
        callable $incidentLoader,
        private readonly int $batchSize,
        private readonly int $maxAttempts,
        private readonly string $workerId,
        ?callable $clock = null,
    ) {
        $this->incidentLoader = $incidentLoader;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function tick(): void
    {
        $logger = Logger::getInstance();
        try {
            $this->repo->prune(self::PRUNE_OLDER_THAN_SECONDS);
            $this->repo->resetOrphans($this->workerId);
        } catch (Throwable $t) {
            $logger->error('Outbox tick: housekeeping failed', ['error' => $t->getMessage()]);
        }

        $now     = ($this->clock)();
        $claimed = $this->repo->claim($this->workerId, $this->batchSize, $now);
        foreach ($claimed as $row) {
            try {
                $this->processRow($row);
            } catch (Throwable $t) {
                $logger->error('Outbox tick: processRow threw', [
                    'outboxId' => $row['id'], 'error' => $t->getMessage(),
                ]);
                $this->markRetryOrFail($row, $t->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function processRow(array $row): void
    {
        throw new \LogicException('processRow: implemented in Task 11');
    }

    /** @param array<string,mixed> $row */
    private function markRetryOrFail(array $row, string $errorMessage): void
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= $this->maxAttempts) {
            $this->repo->markFailed((int) $row['id'], $attempts, $errorMessage);
            return;
        }
        $delaySec    = self::BACKOFF_SECONDS[$attempts - 1] ?? self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1];
        $nextAttempt = ($this->clock)()->modify("+{$delaySec} seconds");
        $this->repo->markRetry((int) $row['id'], $attempts, $nextAttempt, $errorMessage);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxProcessorTest.php
```

Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxProcessor.php tests/Unit/Notifications/Outbox/OutboxProcessorTest.php
git commit -m "feat(notifications): OutboxProcessor::tick orchestration shell (prune+resetOrphans+claim)"
```

---

### Task 11: `OutboxProcessor::processRow`

**Files:**
- Modify: `src/Notifications/Outbox/OutboxProcessor.php`
- Modify: `tests/Unit/Notifications/Outbox/OutboxProcessorTest.php`

- [ ] **Step 1: Add failing tests for processRow paths**

Append to `OutboxProcessorTest`:

```php
private function row(array $overrides = []): array
{
    return $overrides + [
        'id'                 => 1,
        'db_call_id'         => 100,
        'channel_id'         => 7,
        'intent'             => 'Created',
        'resend_all'         => 1,
        'added_topics_json'  => '[]',
        'create_datetime'    => '2026-05-07 12:00:00',
        'status'             => 'in_flight',
        'attempts'           => 0,
        'next_attempt_at'    => null,
        'claimed_at'         => '2026-05-07 12:05:00',
        'claimed_by'         => 'me:1:111',
        'last_error'         => null,
    ];
}

private function dto(): IncidentDto
{
    return IncidentDto::fromRow([
        'id' => 100, 'call_id' => 100, 'call_number' => 'C-100',
        'agency_type' => 'Fire', 'jurisdiction' => 'IN048',
        'units' => 'E1', 'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
    ]);
}

private function channelRow(): array
{
    return ['id' => 7, 'name' => 'ntfy_primary', 'type' => 'ntfy', 'enabled' => true, 'base_url' => 'u', 'config_json' => '{}'];
}

public function testProcessRowMarksDoneWhenAllResultsOk(): void
{
    $row = $this->row();
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markDone')->once()->with(1);
    $repo->shouldReceive('markRetry')->never();
    $repo->shouldReceive('markFailed')->never();

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->once()->with(7)->andReturn($this->channelRow());
    $channels->shouldReceive('recordSend')->once()
        ->with(7, 100, 'Created', Mockery::on(static fn (SendResult $r) => $r->ok));

    $channel = Mockery::mock(NotificationChannel::class);
    $channel->shouldReceive('send')->once()->andReturn([SendResult::ok(200, 10, 'IN048')]);

    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->once()->andReturn($channel);

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}

public function testProcessRowMarksRetryWhenAllResultsFail(): void
{
    $row = $this->row(['attempts' => 1]);
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markDone')->never();
    $repo->shouldReceive('markRetry')->once()
        ->with(1, 2, Mockery::type(DateTimeImmutable::class), Mockery::pattern('/HTTP 503/'));

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->once()->with(7)->andReturn($this->channelRow());
    $channels->shouldReceive('recordSend')->once();
    $channels->shouldReceive('markFailure')->once();

    $channel = Mockery::mock(NotificationChannel::class);
    $channel->shouldReceive('send')->once()->andReturn([SendResult::fail(503, 9, 'Service Unavailable', 'IN048')]);

    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->once()->andReturn($channel);

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}

public function testProcessRowMarksFailedAtMaxAttempts(): void
{
    $row = $this->row(['attempts' => 4]);  // attempts+1 = 5 = maxAttempts
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markFailed')->once()->with(1, 5, Mockery::pattern('/HTTP 503/'));
    $repo->shouldReceive('markRetry')->never();
    $repo->shouldReceive('markDone')->never();

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->once()->andReturn($this->channelRow());
    $channels->shouldReceive('recordSend')->once();
    $channels->shouldReceive('markFailure')->once();

    $channel = Mockery::mock(NotificationChannel::class);
    $channel->shouldReceive('send')->once()->andReturn([SendResult::fail(503, 9, 'Service Unavailable', 'IN048')]);

    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->once()->andReturn($channel);

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}

public function testProcessRowMarksDoneWhenAnyResultOk(): void
{
    $row = $this->row();
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markDone')->once()->with(1);

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->once()->andReturn($this->channelRow());
    $channels->shouldReceive('recordSend')->twice();
    $channels->shouldReceive('markFailure')->once();

    $channel = Mockery::mock(NotificationChannel::class);
    $channel->shouldReceive('send')->once()->andReturn([
        SendResult::fail(503, 9, 'fail', 'IN048'),
        SendResult::ok(200, 10, 'E1'),
    ]);

    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->once()->andReturn($channel);

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}

public function testProcessRowMarksDoneWhenNoTopicsResolved(): void
{
    $row = $this->row([
        'resend_all'        => 0,
        'added_topics_json' => '[]',
    ]);
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markDone')->once()->with(1);

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->never();
    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->never();

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}

public function testProcessRowMarksFailedWhenChannelMissing(): void
{
    $row = $this->row();
    $repo = Mockery::mock(OutboxRepositoryInterface::class);
    $repo->shouldReceive('prune')->once();
    $repo->shouldReceive('resetOrphans')->once();
    $repo->shouldReceive('claim')->once()->andReturn([$row]);
    $repo->shouldReceive('markFailed')->once()->with(1, 1, Mockery::pattern('/missing/'));

    $channels = Mockery::mock(ChannelRepositoryInterface::class);
    $channels->shouldReceive('findById')->once()->andReturn(null);
    $factory = Mockery::mock(ChannelFactoryInterface::class);
    $factory->shouldReceive('create')->never();

    (new OutboxProcessor(
        $repo, $factory, $channels,
        fn (int $id): IncidentDto => $this->dto(),
        batchSize: 10, maxAttempts: 5, workerId: 'me:1:111',
        clock: static fn () => new DateTimeImmutable('2026-05-07 12:05:00'),
    ))->tick();
}
```

Add these `use` statements at the top of the test file:

```php
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\SendResult;
```

(These are already present in the imports from Step 1 of Task 10 — verify, and if missing add them.)

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxProcessorTest.php --filter ProcessRow
```

Expected: FAIL — `LogicException: processRow: implemented in Task 11`.

- [ ] **Step 3: Replace the `processRow` stub with implementation**

In `src/Notifications/Outbox/OutboxProcessor.php`, replace the `processRow` body:

```php
/** @param array<string,mixed> $row */
private function processRow(array $row): void
{
    $logger      = Logger::getInstance();
    $outboxId    = (int) $row['id'];
    $callId      = (int) $row['db_call_id'];
    $channelId   = (int) $row['channel_id'];
    $intent      = Intent::from((string) $row['intent']);
    $resendAll   = (bool) (int) $row['resend_all'];
    $addedTopics = json_decode((string) $row['added_topics_json'], true) ?: [];

    $dto    = ($this->incidentLoader)($callId);
    $topics = TopicResolver::resolveTopics($dto, $resendAll, $addedTopics);

    if ($topics === []) {
        $logger->info('Outbox processRow: no topics, marking done', ['outboxId' => $outboxId]);
        $this->repo->markDone($outboxId);
        return;
    }

    $channelRow = $this->channelRepo->findById($channelId);
    if ($channelRow === null) {
        $logger->warning('Outbox processRow: channel missing', [
            'outboxId' => $outboxId, 'channelId' => $channelId,
        ]);
        $attempts = (int) $row['attempts'] + 1;
        $this->repo->markFailed($outboxId, $attempts, "Channel #{$channelId} missing");
        return;
    }

    $channel = $this->factory->create($channelRow);
    $context = new NotificationContext(
        intent:         $intent,
        resendAll:      $resendAll,
        topicsToNotify: $topics,
        channelConfig:  [],
    );

    $results = $channel->send($dto, $context);

    if ($results === []) {
        $logger->info('Outbox processRow: channel returned no results, marking done', ['outboxId' => $outboxId]);
        $this->repo->markDone($outboxId);
        return;
    }

    $anyOk    = false;
    $firstErr = '';
    foreach ($results as $r) {
        $this->channelRepo->recordSend($channelId, $callId, $intent->value, $r);
        if ($r->ok) {
            $anyOk = true;
        } else {
            $msg = ($r->httpStatus ? "HTTP {$r->httpStatus}: " : '') . ($r->error ?? 'unknown');
            $this->channelRepo->markFailure($channelId, $msg);
            if ($firstErr === '') {
                $firstErr = $msg;
            }
        }
    }

    if ($anyOk) {
        $this->repo->markDone($outboxId);
        return;
    }
    $this->markRetryOrFail($row, $firstErr);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/Notifications/Outbox/OutboxProcessorTest.php
```

Expected: PASS — 8 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Outbox/OutboxProcessor.php tests/Unit/Notifications/Outbox/OutboxProcessorTest.php
git commit -m "feat(notifications): OutboxProcessor::processRow — per-channel send + record + done/retry/fail"
```

---

### Task 12: `FileWatcher::setOnTick`

**Files:**
- Modify: `src/FileWatcher.php`
- Create: `tests/Unit/FileWatcherSetOnTickTest.php` (focused test — doesn't try to run the full daemon)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/FileWatcherSetOnTickTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\FileWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileWatcher::class)]
#[UsesClass(\NwsCad\Config::class)]
#[UsesClass(\NwsCad\Logger::class)]
#[UsesClass(\NwsCad\Logging\RedactingProcessor::class)]
#[UsesClass(\NwsCad\Logging\SecretRegistry::class)]
final class FileWatcherSetOnTickTest extends TestCase
{
    public function testSetOnTickAcceptsCallableAndCanBeInspected(): void
    {
        $watcher = new FileWatcher();

        $count   = 0;
        $watcher->setOnTick(static function () use (&$count): void { $count++; });

        // Use reflection to invoke the protected callback directly so the test
        // doesn't need to run the watcher's infinite loop.
        $refl = new \ReflectionClass($watcher);
        $prop = $refl->getProperty('onTick');
        $prop->setAccessible(true);
        $cb = $prop->getValue($watcher);
        $this->assertIsCallable($cb);
        $cb();
        $cb();
        $this->assertSame(2, $count);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/FileWatcherSetOnTickTest.php
```

Expected: FAIL — `setOnTick` undefined / `onTick` property undefined.

- [ ] **Step 3: Modify `FileWatcher`**

Edit `src/FileWatcher.php`. Two changes:

(a) Add a property near the top of the class (after `private int $interval;`):

```php
/** @var (callable():void)|null */
private $onTick = null;
```

(b) Add a public setter (anywhere above `start()`):

```php
public function setOnTick(?callable $cb): void
{
    $this->onTick = $cb;
}
```

(c) Inside `start()`, just after `$this->checkForNewFiles();` (around line 114), insert:

```php
if ($this->onTick !== null) {
    try {
        ($this->onTick)();
    } catch (\Throwable $t) {
        $this->logger->error("onTick callback failed: " . $t->getMessage());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Unit/FileWatcherSetOnTickTest.php
```

Expected: PASS — 1 test.

- [ ] **Step 5: Commit**

```bash
git add src/FileWatcher.php tests/Unit/FileWatcherSetOnTickTest.php
git commit -m "feat(watcher): setOnTick callback — invoked once per loop iteration after file scan"
```

---

### Task 13: Wire-up in `src/watcher.php` + remove `NotificationDispatcher`

**Files:**
- Modify: `src/watcher.php`
- Delete: `src/Notifications/NotificationDispatcher.php`
- Delete: `tests/Unit/Notifications/NotificationDispatcherTest.php`

- [ ] **Step 1: Update `src/watcher.php`**

Replace the imports and wiring block. The full file should become:

```php
#!/usr/bin/env php
<?php

/**
 * File Watcher Daemon
 * Entry point for the file watching service
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\FileWatcher;
use NwsCad\Logger;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\Outbox\WorkerId;
use NwsCad\Notifications\Events\CallProcessedEvent;

$logger = Logger::getInstance();
$config = Config::getInstance();
require_once __DIR__ . '/Notifications/registerChannels.php';

try {
    $logLevel = strtoupper($config->get('app.log_level', 'INFO'));
    $logger->info("Starting NWS CAD File Watcher Service");
    $logger->info("Log level: {$logLevel}");

    $deltaSeconds = (int) $config->get('notifications.delta_seconds', 900);
    $batchSize    = (int) ($_ENV['OUTBOX_BATCH_SIZE'] ?? getenv('OUTBOX_BATCH_SIZE') ?: 10);
    $maxAttempts  = (int) ($_ENV['OUTBOX_MAX_ATTEMPTS'] ?? getenv('OUTBOX_MAX_ATTEMPTS') ?: 5);

    $incidentLoader = function (int $dbCallId): IncidentDto {
        $db = Database::getConnection();
        $jurisdictionAgg = \NwsCad\Api\DbHelper::groupConcat('jurisdiction', '|');
        $unitsAgg        = \NwsCad\Api\DbHelper::groupConcat('unit_number', '|');
        $narrativeAgg    = \NwsCad\Api\DbHelper::groupConcat('text', ' ');
        $stmt = $db->prepare(
            "SELECT
                c.id, c.call_id, c.call_number, c.alarm_level, c.create_datetime,
                c.nature_of_call,
                ac.call_type, ac.agency_type,
                l.full_address, l.nearest_cross_streets,
                l.common_name, l.police_beat, l.fire_quadrant,
                l.latitude_y AS latitude, l.longitude_x AS longitude,
                (SELECT {$jurisdictionAgg} FROM incidents WHERE call_id = c.id) AS jurisdiction,
                (SELECT {$unitsAgg}        FROM units     WHERE call_id = c.id) AS units,
                (SELECT {$narrativeAgg}    FROM narratives WHERE call_id = c.id) AS narrative
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

    $outboxRepo      = new OutboxRepository();
    $channelRepo     = new ChannelRepository();
    $channelFactory  = new ChannelFactory($config);
    $outboxWriter    = new OutboxWriter($outboxRepo, $channelRepo, $deltaSeconds);
    $outboxProcessor = new OutboxProcessor(
        $outboxRepo,
        $channelFactory,
        $channelRepo,
        $incidentLoader,
        batchSize:    $batchSize,
        maxAttempts:  $maxAttempts,
        workerId:     WorkerId::current(),
    );

    EventDispatcher::subscribe(static function (CallProcessedEvent $e) use ($outboxWriter): void {
        $outboxWriter->handle($e);
    });

    $watcher = new FileWatcher();
    $watcher->setOnTick(static function () use ($outboxProcessor): void {
        $outboxProcessor->tick();
    });
    $watcher->start();

} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    $logger->error("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
```

- [ ] **Step 2: Lint the modified watcher**

```bash
docker compose exec -T app php -l src/watcher.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Delete `NotificationDispatcher` and its unit test**

```bash
rm src/Notifications/NotificationDispatcher.php
rm tests/Unit/Notifications/NotificationDispatcherTest.php
```

- [ ] **Step 4: Verify nothing else references `NotificationDispatcher`**

```bash
grep -rn 'NotificationDispatcher' src/ tests/ bin/ public/ 2>/dev/null | grep -v 'docs/' | grep -v '.md'
```

Expected: no output. If anything appears, update it to use `OutboxWriter`/`OutboxProcessor` as appropriate.

- [ ] **Step 5: Run the whole test suite to surface any fallout**

```bash
docker compose exec -T app ./vendor/bin/phpunit 2>&1 | tail -30
```

Expected: All tests pass *except* the WebhookEndToEndTest (which is updated in Task 14) and the pre-existing socket extension errors. If anything else fails, fix it before committing — typically by adding `notification_outbox` cleanup to a test, or adjusting a class that referenced the dispatcher.

- [ ] **Step 6: Commit**

```bash
git add src/watcher.php
git rm src/Notifications/NotificationDispatcher.php tests/Unit/Notifications/NotificationDispatcherTest.php
git commit -m "feat(notifications): wire OutboxWriter + OutboxProcessor; remove NotificationDispatcher"
```

---

### Task 14: Update `WebhookEndToEndTest`

**Files:**
- Modify: `tests/Integration/Notifications/WebhookEndToEndTest.php`

This test previously drove `NotificationDispatcher::handle()`. It now needs to:
1. Dispatch a `CallProcessedEvent` to `OutboxWriter::handle()` (or insert an outbox row directly)
2. Call `OutboxProcessor::tick()` to drain it
3. Assert the webhook was hit and the outbox row is `done`

- [ ] **Step 1: Read the existing test and identify the dispatch site**

```bash
docker compose exec -T app cat tests/Integration/Notifications/WebhookEndToEndTest.php
```

Note the test setup (capture-server fixture) and the call to `NotificationDispatcher::handle()`.

- [ ] **Step 2: Replace dispatcher invocation with outbox flow**

In `WebhookEndToEndTest.php`:

(a) Update `use` block — remove `NotificationDispatcher`, add:

```php
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxWriter;
```

(b) Update `#[UsesClass]` attributes — remove `NotificationDispatcher::class`, add:

```php
#[UsesClass(OutboxWriter::class)]
#[UsesClass(OutboxProcessor::class)]
#[UsesClass(OutboxRepository::class)]
#[UsesClass(ChannelRepository::class)]
#[UsesClass(TopicResolver::class)]
```

(c) In the test body where `NotificationDispatcher` is instantiated and `handle()` is called, replace with:

```php
$pdo            = \NwsCad\Database::getConnection();
$outboxRepo     = new OutboxRepository($pdo);
$channelRepo    = new ChannelRepository($pdo);
$factory        = new \NwsCad\Notifications\ChannelFactory(\NwsCad\Config::getInstance());

$writer = new OutboxWriter($outboxRepo, $channelRepo, 900);
$writer->handle($event);

$processor = new OutboxProcessor(
    $outboxRepo, $factory, $channelRepo,
    $incidentLoader,
    batchSize:   10,
    maxAttempts: 5,
    workerId:    'test:1:1',
);
$processor->tick();
```

The exact existing test layout will dictate the precise edits — preserve the capture-server lifecycle, the channel-row insert, and the assertions about the captured request.

- [ ] **Step 3: Run the test to verify it passes**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/WebhookEndToEndTest.php
```

Expected: PASS (or the pre-existing `socket_create_listen` skip if the sockets ext is missing locally — that's a container limitation, not a test failure).

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Notifications/WebhookEndToEndTest.php
git commit -m "test(notifications): drive Webhook end-to-end through OutboxWriter + OutboxProcessor"
```

---

### Task 15: End-to-end producer→consumer integration test

**Files:**
- Create: `tests/Integration/Notifications/OutboxEndToEndTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Integration/Notifications/OutboxEndToEndTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicResolver;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(OutboxWriter::class)]
#[UsesClass(OutboxProcessor::class)]
#[UsesClass(OutboxRepository::class)]
#[UsesClass(ChannelRepository::class)]
#[UsesClass(ChannelFactory::class)]
#[UsesClass(ChannelFactoryInterface::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(NotificationContext::class)]
#[UsesClass(SendResult::class)]
#[UsesClass(Intent::class)]
#[UsesClass(CallProcessedEvent::class)]
#[UsesClass(Config::class)]
final class OutboxEndToEndTest extends TestCase
{
    private static PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        ChannelRegistry::clear();
        ChannelRegistry::register(new ChannelDescriptor(
            type: 'stub', label: 'stub', baseUrlEnv: 'STUB_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static function (array $row, Config $cfg): NotificationChannel {
                return new class implements NotificationChannel {
                    public static array $received = [];
                    public static function descriptor(): ChannelDescriptor
                    {
                        return new ChannelDescriptor(
                            type: 'stub', label: 'stub', baseUrlEnv: 'STUB_URL',
                            requiredEnvs: [], defaultConfig: [],
                            factory: static fn (array $r, Config $c) => throw new \LogicException('test stub'),
                        );
                    }
                    public function send(IncidentDto $dto, NotificationContext $ctx): array
                    {
                        self::$received[] = ['dto' => $dto, 'ctx' => $ctx];
                        return [SendResult::ok(200, 5, $ctx->topicsToNotify[0] ?? null)];
                    }
                };
            },
        ));
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testProducerThenConsumerDeliversAndMarksDone(): void
    {
        self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'C-1', '2026-05-07 12:00:00')");
        $callId = (int) self::$db->lastInsertId();
        self::$db->exec("INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES ({$callId}, 'Fire', 'STRUCT')");
        self::$db->exec("INSERT INTO incidents (call_id, jurisdiction) VALUES ({$callId}, 'IN048')");
        self::$db->exec("INSERT INTO units (call_id, unit_number) VALUES ({$callId}, 'E1')");

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                         VALUES ('stub_primary', 'stub', 1, 'https://stub.example', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $outboxRepo  = new OutboxRepository(self::$db);
        $channelRepo = new ChannelRepository(self::$db);
        $factory     = new ChannelFactory(Config::getInstance());

        $writer = new OutboxWriter($outboxRepo, $channelRepo, 900,
            static fn () => new DateTimeImmutable('2026-05-07 12:01:00'));
        $event = new CallProcessedEvent(
            dbCallId: $callId, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $writer->handle($event);

        $outboxRow = self::$db->query("SELECT * FROM notification_outbox")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($outboxRow);
        $this->assertSame('pending', $outboxRow['status']);
        $this->assertSame($channelId, (int) $outboxRow['channel_id']);

        $incidentLoader = static fn (int $id): IncidentDto => IncidentDto::fromRow([
            'id' => $id, 'call_id' => 1, 'call_number' => 'C-1',
            'agency_type' => 'Fire', 'jurisdiction' => 'IN048', 'units' => 'E1',
            'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
        ]);

        $processor = new OutboxProcessor(
            $outboxRepo, $factory, $channelRepo,
            $incidentLoader,
            batchSize: 10, maxAttempts: 5, workerId: 'test:1:1',
        );
        $processor->tick();

        $after = self::$db->query("SELECT status FROM notification_outbox")->fetchColumn();
        $this->assertSame('done', $after);

        $logCount = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log")->fetchColumn();
        $this->assertSame(1, $logCount);
    }
}
```

- [ ] **Step 2: Run the test**

```bash
docker compose exec -T app ./vendor/bin/phpunit tests/Integration/Notifications/OutboxEndToEndTest.php
```

Expected: PASS — 1 test.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Notifications/OutboxEndToEndTest.php
git commit -m "test(notifications): outbox end-to-end producer→consumer with stub channel"
```

---

### Task 16: Documentation

**Files:**
- Modify: `docs/NOTIFICATIONS.md`
- Modify: `CLAUDE.md` (one new bullet under "Key environment variables" for `OUTBOX_BATCH_SIZE` + `OUTBOX_MAX_ATTEMPTS`)

- [ ] **Step 1: Update `docs/NOTIFICATIONS.md`**

Open the file and locate the section that describes the dispatch flow. Replace the description of synchronous dispatch with a new "Outbox flow" subsection. Add this prose block:

```markdown
## Outbox flow (since 2026-05-12)

After `AegisXmlParser::processFile()` commits, it dispatches a `CallProcessedEvent`. The `OutboxWriter` subscriber filters Closed intents and the delta-time gate, then inserts one row into `notification_outbox` per enabled channel. Each row carries the intent, the topic-resolution mode (`resend_all`), the `addedTopics` list, and the call's `create_datetime`.

The watcher's main loop calls `OutboxProcessor::tick()` once per iteration after the file scan. Each tick:

1. Prunes `status='done'` rows older than 7 days
2. Resets `status='in_flight'` rows whose `claimed_by` does not match the current worker (recovers orphaned rows from a previous process)
3. Claims up to `OUTBOX_BATCH_SIZE` (default 10) pending rows whose `next_attempt_at` is null or in the past
4. For each claimed row: loads the `IncidentDto`, resolves topics, invokes the channel's `send()`, records each `SendResult` in `notification_send_log`, and marks the outbox row `done` (any success) or schedules a retry (all failures)
5. Retries use backoff `[30s, 2m, 10m, 30m, 2h]` indexed by `attempts - 1`; after `OUTBOX_MAX_ATTEMPTS` (default 5), the row is marked `failed`

Operators inspect the queue via:

```sql
SELECT id, db_call_id, channel_id, intent, status, attempts, next_attempt_at, last_error, updated_at
FROM notification_outbox
WHERE status IN ('pending', 'in_flight', 'failed')
ORDER BY id DESC;
```

A `failed` row is retained indefinitely for operator review. To clear failures after fix:

```sql
DELETE FROM notification_outbox WHERE status = 'failed' AND id = <id>;
```

Or to retry, set it back to pending:

```sql
UPDATE notification_outbox SET status='pending', attempts=0, next_attempt_at=NULL, last_error=NULL WHERE id=<id>;
```
```

- [ ] **Step 2: Update `CLAUDE.md`**

Find the "Key environment variables" table and add two rows:

```markdown
| `OUTBOX_BATCH_SIZE` (default 10) | Max outbox rows the watcher claims per tick |
| `OUTBOX_MAX_ATTEMPTS` (default 5) | Permanent-failure threshold for outbox rows |
```

Also add a short bullet under "Notifications" describing the outbox:

```markdown
After the parser commits, `OutboxWriter` queues one `notification_outbox` row per enabled channel. The `FileWatcher` loop drives `OutboxProcessor::tick()` to drain the queue with bounded retries (`[30s, 2m, 10m, 30m, 2h]`, capped at `OUTBOX_MAX_ATTEMPTS`).
```

- [ ] **Step 3: Commit**

```bash
git add docs/NOTIFICATIONS.md CLAUDE.md
git commit -m "docs(notifications): outbox flow + new env vars"
```

---

### Task 17: Final verification + push + PR

- [ ] **Step 1: Run the full local test suite**

```bash
docker compose exec -T app ./vendor/bin/phpunit 2>&1 | tail -30
```

Expected: All tests pass except the pre-existing `socket_create_listen` extension misses and the `ConfigTest::testTrustedProxyCidrsDefaultIncludesLoopback` (which depends on `.env` state). Any other failures must be addressed.

- [ ] **Step 2: Verify the diff against `main`**

```bash
git fetch origin
git diff --stat main..HEAD
```

Expected stat: ~20 files changed, mostly under `src/Notifications/Outbox/`, `tests/...`, and the three schema files.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/notification-outbox
gh pr create --title "feat(notifications): transactional outbox + async worker" --body "$(cat <<'EOF'
## Summary
Phase-1 outbox pattern for notification delivery: per-channel rows queued by the parser, drained by an async tick from the watcher loop, with bounded backoff retries.

- New \`notification_outbox\` table; one row per (CallProcessedEvent, enabled_channel).
- New \`OutboxWriter\` subscribes to \`CallProcessedEvent\` and INSERTs the per-channel rows after applying the Closed + delta-time gates.
- New \`OutboxProcessor::tick()\` runs from \`FileWatcher\`'s loop: prunes \`done\` rows >7d, resets orphaned \`in_flight\` rows, claims a batch, sends, marks done/retry/failed.
- \`NotificationDispatcher\` is deleted; its responsibilities split between writer and processor.
- New env vars: \`OUTBOX_BATCH_SIZE\` (default 10), \`OUTBOX_MAX_ATTEMPTS\` (default 5).
- Backoff: \`[30s, 2m, 10m, 30m, 2h]\`.

See [spec](docs/superpowers/specs/2026-05-12-outbox-async-worker-design.md) for the full design.

## Test plan
- [x] Unit tests for \`TopicResolver\`, \`WorkerId\`, \`OutboxWriter\`, \`OutboxProcessor\` (mocked deps).
- [x] Integration tests for \`OutboxRepository\` (real DB) and \`OutboxEndToEndTest\` (real DB + stub channel).
- [x] \`WebhookEndToEndTest\` updated to drive the new producer→consumer flow.
- [ ] CI Tests workflow on push.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 4: Watch CI**

```bash
gh pr checks
gh run watch $(gh run list --branch feat/notification-outbox --workflow=tests.yml --limit 1 --json databaseId --jq '.[0].databaseId') --exit-status --interval 15
```

- [ ] **Step 5: Address any CI failure**

If CI fails:
1. View the run with `gh run view <id> --log` and grep for `FAIL\|Error\|Risky`.
2. Common likely culprits: `notification_outbox` not in CI schema (Task 1 step 3 — verify), missing `#[UsesClass]` attribute (add it), table not created in test DB (CI seeds from `database/schema.sql` so step 3 of Task 1 is critical).
3. Push fix commit; CI retriggers.

- [ ] **Step 6: Merge**

```bash
gh pr merge --squash --delete-branch
```

---

## Self-Review

(Author check — not a subagent dispatch.)

**1. Spec coverage check:**

| Spec section | Task |
|---|---|
| Problem statement (parser-blocking, crash-loss) | Addressed by Task 9 (writer queues) + Task 13 (processor drains) |
| Schema (`notification_outbox`) | Task 1 |
| `OutboxWriter` | Task 9 |
| `OutboxProcessor::tick` + `processRow` | Tasks 10, 11 |
| `OutboxRepository` | Tasks 5–8 |
| `TopicResolver` | Task 2 |
| `WorkerId` | Task 3 |
| `FileWatcher::setOnTick` | Task 12 |
| `NotificationDispatcher` deletion | Task 13 |
| Migration files | Task 1 (steps 4, 5) |
| End-to-end test | Task 15 |
| `WebhookEndToEndTest` update | Task 14 |
| Docs | Task 16 |
| Env vars `OUTBOX_BATCH_SIZE` / `OUTBOX_MAX_ATTEMPTS` | Task 13 (wiring) + Task 16 (docs) |

All covered.

**2. Placeholder scan:** No "TBD" or "implement later" in the plan. All code blocks are concrete.

**3. Type/method-name consistency:**
- `OutboxRepositoryInterface::insert($callId, $channelId, Intent $intent, $resendAll, array $addedTopics, DateTimeImmutable $createDateTime): int` — used identically in writer (Task 9), end-to-end test (Task 15), and repo tests (Tasks 5, 6).
- `OutboxRepositoryInterface::claim($workerId, $batchSize, DateTimeImmutable $now): array` — consistent across processor (Task 10) and repo (Task 8).
- `markRetry($rowId, $attempts, DateTimeImmutable $nextAttemptAt, $errorMessage)` — consistent.
- `markFailed($rowId, $attempts, $errorMessage)` — consistent.
- `ChannelRepositoryInterface::findById(int $id): ?array` — defined Task 4, used in processor (Task 11) and end-to-end test (Task 15).
- `Intent::from((string) $row['intent'])` — the existing `Intent` enum is a string-backed enum (`Intent::Created`, `Intent::Updated`, `Intent::Closed` exist per the original `NotificationDispatcher` source). Verify with `grep -n "case " src/Notifications/Events/Intent.php` before Task 11 if you want to be paranoid — but the existing `$intent->value` usage confirms it.
- `FileWatcher::setOnTick(callable)` + `$onTick` property — consistent across Tasks 12, 13.
- Backoff array `[30, 120, 600, 1800, 7200]` (5 entries) matches `maxAttempts=5` default — last attempt's "next attempt" never fires because we transition to `failed` instead.
