# Channel Registry & WebhookChannel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded `['ntfy','pushover']` allowlist sprinkled across `ChannelFactory`, `NotificationsController`, and `bin/notifications.php` with a static `ChannelRegistry`, and ship `WebhookChannel` as the first consumer of the new abstraction.

**Architecture:** Static `ChannelRegistry` holds one `ChannelDescriptor` per channel type. Each `NotificationChannel` implementer exposes a `descriptor()` static method that returns its registration record (type, label, env defaults, factory closure). A single `registerChannels.php` include wires the registry at boot in three sites: `src/watcher.php`, `src/bootstrap.php`, and `bin/notifications.php`. Schema is unchanged.

**Tech Stack:** PHP 8.3 (strict types, readonly properties, first-class callables), PHPUnit 10.5 (with strict coverage metadata), Composer PSR-4 (`NwsCad\` from `src/`), MySQL 8 / PostgreSQL 16, Monolog logging.

**Spec reference:** `docs/superpowers/specs/2026-05-12-channel-registry-design.md`

**Ordering invariants:**
- Tasks 1–3 are pure additions: no production code path changes.
- Task 4 wires the registry at boot but leaves the old factory match-statement intact.
- Task 5 cuts over `ChannelFactory` to the registry — first task that changes behavior.
- Tasks 6–7 cut over the API and CLI; each commit leaves the system working.
- Task 8 removes the now-unused `type()` interface method (back-compat cleanup).
- Tasks 9–12 add `WebhookChannel`.
- Task 13 closes out docs and CHANGELOG.

---

### Task 1: ChannelDescriptor value object

**Files:**
- Create: `src/Notifications/ChannelDescriptor.php`
- Create: `tests/Unit/Notifications/ChannelDescriptorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/ChannelDescriptorTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use Closure;
use NwsCad\Notifications\ChannelDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDescriptor::class)]
final class ChannelDescriptorTest extends TestCase
{
    public function testReadonlyPropertiesExposeConstructorArgs(): void
    {
        $factory = static fn (array $r, $cfg) => new \stdClass();

        $d = new ChannelDescriptor(
            type:          'demo',
            label:         'Demo Channel',
            baseUrlEnv:    'DEMO_BASE_URL',
            requiredEnvs:  ['DEMO_TOKEN'],
            defaultConfig: ['key' => 'value'],
            factory:       $factory,
        );

        $this->assertSame('demo', $d->type);
        $this->assertSame('Demo Channel', $d->label);
        $this->assertSame('DEMO_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['DEMO_TOKEN'], $d->requiredEnvs);
        $this->assertSame(['key' => 'value'], $d->defaultConfig);
        $this->assertInstanceOf(Closure::class, $d->factory);
    }

    public function testFactoryClosureIsInvokable(): void
    {
        $marker = new \stdClass();
        $factory = static fn (array $row, $cfg): object => $marker;

        $d = new ChannelDescriptor(
            type: 't', label: 'l', baseUrlEnv: 'E',
            requiredEnvs: [], defaultConfig: [],
            factory: $factory,
        );

        $result = ($d->factory)(['type' => 't'], null);
        $this->assertSame($marker, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelDescriptorTest.php`
Expected: FAIL with `Class "NwsCad\Notifications\ChannelDescriptor" not found`.

- [ ] **Step 3: Create the value object**

Create `src/Notifications/ChannelDescriptor.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use Closure;

/**
 * Immutable registration record for a notification channel type.
 *
 * Each channel class exposes a static `descriptor()` returning one of these;
 * `registerChannels.php` registers them all at boot, and ChannelRegistry holds
 * them for the rest of the request/process lifetime.
 */
final class ChannelDescriptor
{
    /**
     * @param string[]            $requiredEnvs Env-var names that must be present when the channel is enabled.
     * @param array<string,mixed> $defaultConfig Becomes notification_channels.config_json on first enable.
     * @param Closure(array<string,mixed>, \NwsCad\Config): NotificationChannel $factory
     *        Builds an instance of the channel from a DB row and the current Config.
     */
    public function __construct(
        public readonly string  $type,
        public readonly string  $label,
        public readonly string  $baseUrlEnv,
        public readonly array   $requiredEnvs,
        public readonly array   $defaultConfig,
        public readonly Closure $factory,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelDescriptorTest.php`
Expected: PASS (2 tests, 6 assertions).

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/ChannelDescriptor.php tests/Unit/Notifications/ChannelDescriptorTest.php
git commit -m "feat(notifications): add ChannelDescriptor value object"
```

---

### Task 2: ChannelRegistry static container

**Files:**
- Create: `src/Notifications/ChannelRegistry.php`
- Create: `tests/Unit/Notifications/ChannelRegistryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/ChannelRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
final class ChannelRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    private function descriptor(string $type): ChannelDescriptor
    {
        return new ChannelDescriptor(
            type: $type, label: ucfirst($type),
            baseUrlEnv: strtoupper($type) . '_BASE_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static fn (array $r, $c) => new \stdClass(),
        );
    }

    public function testEmptyRegistry(): void
    {
        $this->assertSame([], ChannelRegistry::types());
        $this->assertSame([], ChannelRegistry::all());
        $this->assertFalse(ChannelRegistry::has('ntfy'));
        $this->assertNull(ChannelRegistry::get('ntfy'));
    }

    public function testRegisterAndRetrieve(): void
    {
        $d = $this->descriptor('demo');
        ChannelRegistry::register($d);

        $this->assertTrue(ChannelRegistry::has('demo'));
        $this->assertSame($d, ChannelRegistry::get('demo'));
        $this->assertSame(['demo'], ChannelRegistry::types());
        $this->assertSame(['demo' => $d], ChannelRegistry::all());
    }

    public function testDuplicateTypeOverwrites(): void
    {
        $first  = $this->descriptor('demo');
        $second = $this->descriptor('demo');
        ChannelRegistry::register($first);
        ChannelRegistry::register($second);

        $this->assertSame($second, ChannelRegistry::get('demo'));
        $this->assertCount(1, ChannelRegistry::all());
    }

    public function testClearWipesState(): void
    {
        ChannelRegistry::register($this->descriptor('a'));
        ChannelRegistry::register($this->descriptor('b'));
        ChannelRegistry::clear();

        $this->assertSame([], ChannelRegistry::types());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelRegistryTest.php`
Expected: FAIL with `Class "NwsCad\Notifications\ChannelRegistry" not found`.

- [ ] **Step 3: Create the registry**

Create `src/Notifications/ChannelRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

/**
 * Static registry of channel descriptors. Populated by registerChannels.php
 * at boot of every entry point (watcher, HTTP, CLI). Queried by
 * ChannelFactory at dispatch time and by the API/CLI for validation and help.
 *
 * Static state is intentional given PHP's per-request lifecycle; tests MUST
 * call `clear()` in tearDown to prevent state leak between tests.
 */
final class ChannelRegistry
{
    /** @var array<string, ChannelDescriptor> */
    private static array $byType = [];

    public static function register(ChannelDescriptor $d): void
    {
        self::$byType[$d->type] = $d;
    }

    public static function get(string $type): ?ChannelDescriptor
    {
        return self::$byType[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return isset(self::$byType[$type]);
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::$byType);
    }

    /** @return array<string, ChannelDescriptor> */
    public static function all(): array
    {
        return self::$byType;
    }

    public static function clear(): void
    {
        self::$byType = [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelRegistryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/ChannelRegistry.php tests/Unit/Notifications/ChannelRegistryTest.php
git commit -m "feat(notifications): add ChannelRegistry static container"
```

---

### Task 3: Add `descriptor()` to NtfyChannel and PushoverChannel

The interface still has `type()` at this point. We add `descriptor()` as a new static on the concrete classes WITHOUT touching the interface yet — keeps the system working while we wire things up. Task 8 removes the now-unused `type()` after all consumers have migrated.

**Files:**
- Modify: `src/Notifications/Channels/NtfyChannel.php` (add `descriptor()` static)
- Modify: `src/Notifications/Channels/PushoverChannel.php` (add `descriptor()` static)
- Create: `tests/Unit/Notifications/Channels/NtfyChannelDescriptorTest.php`
- Create: `tests/Unit/Notifications/Channels/PushoverChannelDescriptorTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Notifications/Channels/NtfyChannelDescriptorTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\Channels\NtfyChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NtfyChannel::class)]
#[UsesClass(ChannelDescriptor::class)]
final class NtfyChannelDescriptorTest extends TestCase
{
    public function testDescriptorReportsTypeAndDefaults(): void
    {
        $d = NtfyChannel::descriptor();

        $this->assertInstanceOf(ChannelDescriptor::class, $d);
        $this->assertSame('ntfy', $d->type);
        $this->assertSame('ntfy.sh', $d->label);
        $this->assertSame('NTFY_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['NTFY_AUTH_TOKEN'], $d->requiredEnvs);
        $this->assertSame(['auth_token_env' => 'NTFY_AUTH_TOKEN'], $d->defaultConfig);
    }
}
```

Create `tests/Unit/Notifications/Channels/PushoverChannelDescriptorTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\Channels\PushoverChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushoverChannel::class)]
#[UsesClass(ChannelDescriptor::class)]
final class PushoverChannelDescriptorTest extends TestCase
{
    public function testDescriptorReportsTypeAndDefaults(): void
    {
        $d = PushoverChannel::descriptor();

        $this->assertInstanceOf(ChannelDescriptor::class, $d);
        $this->assertSame('pushover', $d->type);
        $this->assertSame('Pushover', $d->label);
        $this->assertSame('PUSHOVER_BASE_URL', $d->baseUrlEnv);
        $this->assertSame(['PUSHOVER_TOKEN', 'PUSHOVER_USER'], $d->requiredEnvs);
        $this->assertSame(
            ['token_env' => 'PUSHOVER_TOKEN', 'user_env' => 'PUSHOVER_USER'],
            $d->defaultConfig,
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/NtfyChannelDescriptorTest.php tests/Unit/Notifications/Channels/PushoverChannelDescriptorTest.php`
Expected: FAIL with `Call to undefined method ... descriptor()`.

- [ ] **Step 3: Add `descriptor()` to NtfyChannel**

In `src/Notifications/Channels/NtfyChannel.php`, locate the existing `public static function type(): string` (around line ~25) and add this method immediately after it. Also add the `use NwsCad\Config;` and `use NwsCad\Notifications\ChannelDescriptor;` and `use NwsCad\Notifications\NotificationChannel;` use-statements at the top if missing.

```php
public static function descriptor(): ChannelDescriptor
{
    return new ChannelDescriptor(
        type:          'ntfy',
        label:         'ntfy.sh',
        baseUrlEnv:    'NTFY_BASE_URL',
        requiredEnvs:  ['NTFY_AUTH_TOKEN'],
        defaultConfig: ['auth_token_env' => 'NTFY_AUTH_TOKEN'],
        factory: static function (array $row, Config $cfg): NotificationChannel {
            $raw    = $row['config_json'] ?? '';
            $config = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            return new self(
                baseUrl:   (string) $row['base_url'],
                authToken: $cfg->secret($config['auth_token_env'] ?? 'NTFY_AUTH_TOKEN'),
                config:    $config,
            );
        },
    );
}
```

- [ ] **Step 4: Add `descriptor()` to PushoverChannel**

Same pattern in `src/Notifications/Channels/PushoverChannel.php` (add use-statements + method):

```php
public static function descriptor(): ChannelDescriptor
{
    return new ChannelDescriptor(
        type:          'pushover',
        label:         'Pushover',
        baseUrlEnv:    'PUSHOVER_BASE_URL',
        requiredEnvs:  ['PUSHOVER_TOKEN', 'PUSHOVER_USER'],
        defaultConfig: ['token_env' => 'PUSHOVER_TOKEN', 'user_env' => 'PUSHOVER_USER'],
        factory: static function (array $row, Config $cfg): NotificationChannel {
            $raw    = $row['config_json'] ?? '';
            $config = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            return new self(
                baseUrl: (string) $row['base_url'],
                token:   $cfg->secret($config['token_env'] ?? 'PUSHOVER_TOKEN'),
                user:    $cfg->secret($config['user_env']  ?? 'PUSHOVER_USER'),
                config:  $config,
            );
        },
    );
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/`
Expected: PASS for both descriptor tests; all existing channel tests still pass.

- [ ] **Step 6: Commit**

```bash
git add src/Notifications/Channels/NtfyChannel.php \
        src/Notifications/Channels/PushoverChannel.php \
        tests/Unit/Notifications/Channels/NtfyChannelDescriptorTest.php \
        tests/Unit/Notifications/Channels/PushoverChannelDescriptorTest.php
git commit -m "feat(notifications): add descriptor() to NtfyChannel and PushoverChannel"
```

---

### Task 4: registerChannels.php boot file + boot test

Creates the canonical channel-registration file and wires it into all three boot sites. Does NOT yet remove the hardcoded match in `ChannelFactory` — that's Task 5.

**Files:**
- Create: `src/Notifications/registerChannels.php`
- Create: `tests/Unit/Notifications/RegisterChannelsBootTest.php`
- Modify: `src/watcher.php` (add include)
- Modify: `src/bootstrap.php` (add include)
- Modify: `bin/notifications.php` (add include)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/RegisterChannelsBootTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(NtfyChannel::class)]
#[UsesClass(PushoverChannel::class)]
final class RegisterChannelsBootTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testIncludePopulatesRegistryWithNtfyAndPushover(): void
    {
        require __DIR__ . '/../../../src/Notifications/registerChannels.php';

        $this->assertEqualsCanonicalizing(
            ['ntfy', 'pushover'],
            ChannelRegistry::types(),
            'registerChannels.php must register the built-in channel types',
        );
    }
}
```

(Task 11 will add `webhook` to this assertion. We assert exact membership so accidental drops fail the test.)

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/RegisterChannelsBootTest.php`
Expected: FAIL with `Failed to open required ... registerChannels.php`.

- [ ] **Step 3: Create registerChannels.php**

Create `src/Notifications/registerChannels.php`:

```php
<?php

declare(strict_types=1);

use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;

ChannelRegistry::clear();
ChannelRegistry::register(NtfyChannel::descriptor());
ChannelRegistry::register(PushoverChannel::descriptor());
```

Note: deliberately not using `require_once` semantics — `clear()` makes re-inclusion safe and idempotent.

- [ ] **Step 4: Wire into the three boot sites**

In `src/watcher.php`, near the top after the autoload and Config initialization:

```php
require_once __DIR__ . '/Notifications/registerChannels.php';
```

In `src/bootstrap.php`, inside the existing IIFE after `Config::getInstance()`:

```php
require_once __DIR__ . '/Notifications/registerChannels.php';
```

In `bin/notifications.php`, after the autoload include:

```php
require_once __DIR__ . '/../src/Notifications/registerChannels.php';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/RegisterChannelsBootTest.php`
Expected: PASS.

Also run the full unit suite to confirm no regression:
Run: `composer test:unit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Notifications/registerChannels.php \
        src/watcher.php src/bootstrap.php bin/notifications.php \
        tests/Unit/Notifications/RegisterChannelsBootTest.php
git commit -m "feat(notifications): wire registerChannels.php into boot sites"
```

---

### Task 5: Refactor ChannelFactory to use the registry

First behavior change. The hardcoded `match` goes away; instantiation is delegated to the descriptor's factory closure.

**Files:**
- Modify: `src/Notifications/ChannelFactory.php`
- Modify: `tests/Unit/Notifications/ChannelFactoryTest.php` (if it exists — refactor to use registry)

- [ ] **Step 1: Inspect or create the existing factory test**

Run: `ls tests/Unit/Notifications/ChannelFactoryTest.php`
If it exists, read it to learn how the factory is tested. If it doesn't exist, create the test with this content:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\NotificationChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelFactory::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
final class ChannelFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testUnknownTypeThrows(): void
    {
        $factory = new ChannelFactory(Config::getInstance());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown channel type: bogus');

        $factory->create(['type' => 'bogus', 'base_url' => '', 'config_json' => '']);
    }

    public function testRegistryDispatchInvokesDescriptorFactory(): void
    {
        $stub = $this->createStub(NotificationChannel::class);

        ChannelRegistry::register(new ChannelDescriptor(
            type: 'demo', label: 'Demo', baseUrlEnv: 'DEMO_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static fn (array $row, Config $cfg): NotificationChannel => $stub,
        ));

        $factory = new ChannelFactory(Config::getInstance());
        $result  = $factory->create([
            'type'        => 'demo',
            'base_url'    => 'http://example.test',
            'config_json' => '{}',
        ]);

        $this->assertSame($stub, $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail (or pass for old paths)**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelFactoryTest.php`
Expected: `testUnknownTypeThrows` may currently pass with a different message; `testRegistryDispatchInvokesDescriptorFactory` FAILS because the factory still uses the hardcoded match (it won't find 'demo').

- [ ] **Step 3: Refactor ChannelFactory::create()**

Replace the entire body of `src/Notifications/ChannelFactory.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use InvalidArgumentException;
use NwsCad\Config;

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
        $type = $row['type'] ?? '';
        $d = ChannelRegistry::get($type)
            ?? throw new InvalidArgumentException("Unknown channel type: {$type}");

        return ($d->factory)($row, $this->config);
    }
}
```

Remove the now-unused `use NwsCad\Notifications\Channels\NtfyChannel;` and `use NwsCad\Notifications\Channels\PushoverChannel;` imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/ChannelFactoryTest.php`
Expected: PASS.

Also run the broader notification suite:
Run: `./vendor/bin/phpunit tests/Unit/Notifications/`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/ChannelFactory.php tests/Unit/Notifications/ChannelFactoryTest.php
git commit -m "refactor(notifications): ChannelFactory dispatches via ChannelRegistry"
```

---

### Task 6: Refactor NotificationsController to use the registry

**Files:**
- Modify: `src/Api/Controllers/NotificationsController.php`
- Modify: `tests/Integration/NotificationsApiTest.php` (add coverage for registry-driven validation)

- [ ] **Step 1: Write the failing test**

Open `tests/Integration/NotificationsApiTest.php` and add this new test (also add `@uses` for `ChannelRegistry` and `ChannelDescriptor` at the class level — typical PHPUnit attribute form):

```php
public function testEnableUnknownTypeReturnsAvailableTypes(): void
{
    \NwsCad\Notifications\ChannelRegistry::clear();
    \NwsCad\Notifications\ChannelRegistry::register(\NwsCad\Notifications\Channels\NtfyChannel::descriptor());
    \NwsCad\Notifications\ChannelRegistry::register(\NwsCad\Notifications\Channels\PushoverChannel::descriptor());

    $_SERVER['REQUEST_METHOD'] = 'POST';

    $controller = new \NwsCad\Api\Controllers\NotificationsController();
    $controller->enable('badtype');

    $response = $this->captureLastJsonResponse();
    $this->assertSame(400, http_response_code());
    $this->assertFalse($response['success']);
    $this->assertSame(['ntfy', 'pushover'], $response['errors']['available_types']);
}
```

If `captureLastJsonResponse()` doesn't already exist as a helper, add it: use `Response::resetForTesting()` in `setUp()` and `ob_start`/`ob_get_clean()` around the controller call to capture the JSON body, then `json_decode(..., true)`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testEnableUnknownTypeReturnsAvailableTypes tests/Integration/NotificationsApiTest.php`
Expected: FAIL — the response body won't yet contain `available_types`.

- [ ] **Step 3: Refactor the controller**

In `src/Api/Controllers/NotificationsController.php`:

(a) Add use-statements near the top:
```php
use NwsCad\Notifications\ChannelRegistry;
```

(b) Replace the `validateType()` private method:
```php
private function validateType(string $type): bool
{
    return ChannelRegistry::has($type);
}
```

(c) Replace any error response in `enable()`/`disable()`/`test()`/`clearChannelError()` that currently reads `'Invalid channel type'` so it includes the available list. The pattern:
```php
if (! $this->validateType($type)) {
    Response::error('Invalid channel type', 400, [
        'available_types' => ChannelRegistry::types(),
    ]);
    return;
}
```

(d) Replace the hardcoded `defaultConfig` branch in `enable()`. Locate the block that currently looks like:
```php
$defaultConfig = $type === 'ntfy'
    ? ['auth_token_env' => 'NTFY_AUTH_TOKEN']
    : ['token_env' => 'PUSHOVER_TOKEN', 'user_env' => 'PUSHOVER_USER'];
```

Replace with:
```php
$descriptor    = ChannelRegistry::get($type);
$defaultConfig = $descriptor->defaultConfig;
```

(The null check is unnecessary here — `validateType()` already returned true above.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Integration/NotificationsApiTest.php`
Expected: all existing tests + the new test pass.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Controllers/NotificationsController.php tests/Integration/NotificationsApiTest.php
git commit -m "refactor(notifications): API controller dispatches type validation via registry"
```

---

### Task 7: Refactor bin/notifications.php

**Files:**
- Modify: `bin/notifications.php`

This file has no unit tests today (it's an exec CLI). We rely on manual verification + the fact that Task 4 already added the `require_once registerChannels.php` line.

- [ ] **Step 1: Replace the validation list (3 occurrences)**

Open `bin/notifications.php`. Find all three occurrences of:
```php
if (! in_array($type, ['ntfy', 'pushover'], true)) {
```
Replace each with:
```php
if (! \NwsCad\Notifications\ChannelRegistry::has($type)) {
```
And replace the corresponding error message:
```php
fwrite(STDERR, "Unknown channel type: {$type}. Available: " . implode(', ', \NwsCad\Notifications\ChannelRegistry::types()) . "\n");
```

- [ ] **Step 2: Replace the base-URL env var ternary**

Find:
```php
$envKey = $type === 'ntfy' ? 'NTFY_BASE_URL' : 'PUSHOVER_BASE_URL';
```

Replace with:
```php
$envKey = \NwsCad\Notifications\ChannelRegistry::get($type)->baseUrlEnv;
```

- [ ] **Step 3: Replace the default-config ternary**

Find:
```php
$defaultConfig = $type === 'ntfy'
    ? ['auth_token_env' => 'NTFY_AUTH_TOKEN']
    : ['token_env' => 'PUSHOVER_TOKEN', 'user_env' => 'PUSHOVER_USER'];
```

Replace with:
```php
$defaultConfig = \NwsCad\Notifications\ChannelRegistry::get($type)->defaultConfig;
```

- [ ] **Step 4: Update the help text generator**

Locate the help/usage block at the top of the file (around lines 20–30 — `enable <type> ... ('ntfy'|'pushover')` etc.). Replace the static channel list in help text with a dynamic one generated from the registry. After the autoload + registerChannels include but before the help block, build a dynamic line:

```php
$availableTypes = implode('|', \NwsCad\Notifications\ChannelRegistry::types());
```

Then use `{$availableTypes}` in place of the literal `'ntfy'|'pushover'` strings in the help text.

For the "environment variables" block (lines ~26–27 currently), iterate the registry:

```php
$envBlock = '';
foreach (\NwsCad\Notifications\ChannelRegistry::all() as $d) {
    $envBlock .= "  {$d->type}: " . implode(', ', $d->requiredEnvs) . "\n";
}
```

And echo `$envBlock` in the help text where the static list was.

- [ ] **Step 5: Manual verification**

Run:
```bash
php bin/notifications.php list
php bin/notifications.php enable badtype 2>&1 | grep "Available:"
php bin/notifications.php
```

Expected:
- `list` works unchanged.
- `enable badtype` prints "Unknown channel type: badtype. Available: ntfy, pushover".
- Help text shows the dynamic type list.

- [ ] **Step 6: Commit**

```bash
git add bin/notifications.php
git commit -m "refactor(notifications): CLI dispatches type validation via registry"
```

---

### Task 8: Remove unused NotificationChannel::type()

The interface method has zero callers (verified by grep). With all consumers migrated to `descriptor()`, we drop the redundant method.

**Files:**
- Modify: `src/Notifications/NotificationChannel.php`
- Modify: `src/Notifications/Channels/NtfyChannel.php` (remove `type()` impl)
- Modify: `src/Notifications/Channels/PushoverChannel.php` (remove `type()` impl)

- [ ] **Step 1: Verify zero callers (defensive check)**

Run:
```bash
grep -rn '::type()' src/ tests/ bin/ | grep -v 'ChannelType\|ChannelRegistry\|->type\b\|\$d->type\|\$descriptor->type\|\.swp'
```
Expected: no matches.

- [ ] **Step 2: Remove from the interface**

In `src/Notifications/NotificationChannel.php`, delete the `public static function type(): string;` line. The interface should now read:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface NotificationChannel
{
    public static function descriptor(): ChannelDescriptor;

    /**
     * @return SendResult[]  One result per attempt that produced a permanent
     *                       outcome (one per topic for ntfy, one per send
     *                       for pushover/webhook).
     */
    public function send(IncidentDto $incident, NotificationContext $context): array;
}
```

- [ ] **Step 3: Remove the concrete implementations**

In both `NtfyChannel.php` and `PushoverChannel.php`, delete the `public static function type(): string { return '...'; }` method.

- [ ] **Step 4: Run full unit suite**

Run: `composer test:unit`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/NotificationChannel.php \
        src/Notifications/Channels/NtfyChannel.php \
        src/Notifications/Channels/PushoverChannel.php
git commit -m "refactor(notifications): drop unused NotificationChannel::type()"
```

---

### Task 9: HttpPost::postJson() method

WebhookChannel needs a JSON-body POST. `HttpPost` currently only does form-fields POST. Add a sibling method.

**Files:**
- Modify: `src/Notifications/Channels/HttpPost.php`
- Create: `tests/Unit/Notifications/Channels/HttpPostJsonTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/Channels/HttpPostJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\Channels\HttpPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpPost::class)]
final class HttpPostJsonTest extends TestCase
{
    public function testPostJsonHits200OnLocalServer(): void
    {
        $server = $this->startEchoServer();
        try {
            $http   = new HttpPost();
            $result = $http->postJson(
                url: "http://127.0.0.1:{$server['port']}/",
                payload: ['intent' => 'Created', 'topics' => ['A', 'B']],
                timeoutSec: 5,
                headers: ['Authorization' => 'Bearer test-token'],
            );

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('Created', $result['body']);
            $this->assertStringContainsString('Bearer test-token', $result['body']);
            $this->assertStringContainsString('application/json', $result['body']);
        } finally {
            $this->stopEchoServer($server);
        }
    }

    /**
     * @return array{proc:resource,port:int,scriptPath:string}
     */
    private function startEchoServer(): array
    {
        $port = $this->findFreePort();
        $script = tempnam(sys_get_temp_dir(), 'echo') . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$body = file_get_contents('php://input') ?: '';
$ct   = $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: text/plain');
echo "body=$body|ct=$ct|auth=$auth";
PHP);
        $cmd = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($script));
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($proc)) {
            $this->fail('failed to start local echo server');
        }
        // Wait up to 3s for the port to be listening.
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) { fclose($sock); break; }
            usleep(50_000);
        }
        return ['proc' => $proc, 'port' => $port, 'scriptPath' => $script];
    }

    private function stopEchoServer(array $server): void
    {
        if (isset($server['proc']) && is_resource($server['proc'])) {
            proc_terminate($server['proc']);
            proc_close($server['proc']);
        }
        if (isset($server['scriptPath']) && file_exists($server['scriptPath'])) {
            @unlink($server['scriptPath']);
        }
    }

    private function findFreePort(): int
    {
        $sock = socket_create_listen(0);
        if ($sock === false) { $this->fail('socket_create_listen failed'); }
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return (int) $port;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/HttpPostJsonTest.php`
Expected: FAIL with `Call to undefined method ... postJson()`.

- [ ] **Step 3: Add postJson() to HttpPost**

In `src/Notifications/Channels/HttpPost.php`, add this method below the existing `post()`:

```php
/**
 * POST a JSON-encoded body and return the status + body.
 *
 * @param array<mixed>          $payload Encoded with JSON_THROW_ON_ERROR.
 * @param array<string,string>  $headers Additional headers (Content-Type is added automatically).
 *
 * @return array{status:int, body:string}
 */
public function postJson(string $url, array $payload, int $timeoutSec, array $headers = []): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ['status' => 0, 'body' => 'curl_init failed'];
    }

    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $headerLines = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        // Skip an explicit Content-Type so we don't emit two.
        if (strcasecmp($k, 'Content-Type') === 0) {
            $headerLines[0] = "Content-Type: {$v}";
            continue;
        }
        $headerLines[] = "{$k}: {$v}";
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headerLines,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR    => false,
    ]);

    $responseBody = curl_exec($ch);
    $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        return ['status' => $status, 'body' => "curl error: {$error}"];
    }

    return ['status' => $status, 'body' => (string) $responseBody];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/HttpPostJsonTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Channels/HttpPost.php tests/Unit/Notifications/Channels/HttpPostJsonTest.php
git commit -m "feat(notifications): HttpPost::postJson() for JSON-body POST"
```

---

### Task 10: WebhookChannel — substitution logic + unit tests

**Files:**
- Create: `src/Notifications/Channels/WebhookChannel.php`
- Create: `tests/Unit/Notifications/Channels/WebhookChannelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Notifications/Channels/WebhookChannelTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use DateTimeImmutable;
use NwsCad\Notifications\Channels\WebhookChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookChannel::class)]
final class WebhookChannelTest extends TestCase
{
    private function dto(): IncidentDto
    {
        return new IncidentDto(
            dbCallId: 1,
            callId: 42,
            callNumber: 'CN-100',
            callType: 'STRUCT FIRE',
            agencyType: 'FIRE',
            jurisdiction: 'CityA|CityB',
            units: 'E1|L2',
            commonName: null,
            fullAddress: '123 Main St',
            nearestCrossStreets: null,
            policeBeat: null,
            fireQuadrant: null,
            natureOfCall: null,
            narrative: 'large flames visible',
            alarmLevel: 2,
            createDateTime: '2026-05-12T10:00:00Z',
            latitude: null,
            longitude: null,
        );
    }

    private function context(): NotificationContext
    {
        return new NotificationContext(
            intent: Intent::Created,
            resendAll: true,
            topicsToNotify: ['FIRE', 'E1', 'L2'],
            channelConfig: [],
        );
    }

    public function testStringPlaceholderSubstitution(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['text' => '{intent}: {call_type} at {full_address}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertSame(
            '{"text":"Created: STRUCT FIRE at 123 Main St"}',
            $payload,
        );
    }

    public function testRawArrayPlaceholderSubstitution(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['topics' => '${topics}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertSame(
            '{"topics":["FIRE","E1","L2"]}',
            $payload,
        );
    }

    public function testUnknownPlaceholderPassesThrough(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['text' => 'hi {unknown_thing}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertStringContainsString('{unknown_thing}', $payload);
    }

    public function testRawUnitsAndJurisdictionSplit(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['u' => '${units}', 'j' => '${jurisdiction}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $decoded = json_decode($payload, true);
        $this->assertSame(['E1', 'L2'], $decoded['u']);
        $this->assertSame(['CityA', 'CityB'], $decoded['j']);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('webhook: template required');

        new WebhookChannel(
            baseUrl: 'https://example.test/hook',
            config: ['template' => null],
        );
    }

    public function testCrLfInAuthTokenRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR/LF');

        new WebhookChannel(
            baseUrl: 'https://example.test/hook',
            config: ['template' => ['text' => 'x']],
            authToken: "ok\r\nInjected: header",
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/WebhookChannelTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement WebhookChannel**

Create `src/Notifications/Channels/WebhookChannel.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;

final class WebhookChannel implements NotificationChannel
{
    private const DEFAULT_TIMEOUT_SEC = 10;
    private const RETRY_DELAYS_SEC    = [1, 3, 9];

    /** @var array<mixed> */
    private readonly array $template;
    private readonly ?string $authHeader;
    private readonly ?string $authToken;
    private readonly int $timeoutSec;
    private readonly HttpPost $http;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        public readonly string $baseUrl,
        array $config,
        ?string $authToken = null,
        ?HttpPost $http = null,
    ) {
        $template = $config['template'] ?? null;
        if (! is_array($template) || $template === []) {
            throw new InvalidArgumentException('webhook: template required');
        }
        $this->template = $template;

        $this->authHeader = isset($config['auth_header']) ? (string) $config['auth_header'] : null;

        if ($authToken !== null && preg_match('/[\r\n]/', $authToken) === 1) {
            throw new InvalidArgumentException('webhook: auth token contains CR/LF');
        }
        $this->authToken = $authToken;

        $this->timeoutSec = isset($config['timeout_sec']) ? max(1, (int) $config['timeout_sec']) : self::DEFAULT_TIMEOUT_SEC;
        $this->http       = $http ?? new HttpPost();
    }

    public static function descriptor(): ChannelDescriptor
    {
        return new ChannelDescriptor(
            type:          'webhook',
            label:         'Generic webhook',
            baseUrlEnv:    'WEBHOOK_BASE_URL',
            requiredEnvs:  [],   // varies by config; template-driven
            defaultConfig: [
                'template'    => ['text' => '{intent}: {call_type} at {full_address}', 'topics' => '${topics}'],
                'timeout_sec' => self::DEFAULT_TIMEOUT_SEC,
            ],
            factory: static function (array $row, Config $cfg): NotificationChannel {
                $raw    = $row['config_json'] ?? '';
                $config = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
                $token  = null;
                if (! empty($config['auth_token_env'])) {
                    $token = $cfg->secret((string) $config['auth_token_env']);
                }
                return new self(
                    baseUrl: (string) $row['base_url'],
                    config:  $config,
                    authToken: $token,
                );
            },
        );
    }

    /**
     * @return SendResult[]
     */
    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $body = self::buildPayload($this->template, $incident, $context);

        // buildPayload's output is canonical JSON; decode for postJson.
        $payload = json_decode($body, true);
        if ($payload === null) {
            return [SendResult::fail(
                httpStatus: 0,
                durationMs: 0,
                error: 'webhook: substituted template is not valid JSON',
            )];
        }

        $headers = [];
        if ($this->authHeader !== null && $this->authToken !== null) {
            $headers[$this->authHeader] = $this->authToken;
        }

        $lastStatus = 0;
        $lastError  = null;
        $startedAt  = microtime(true);

        foreach ([0, ...self::RETRY_DELAYS_SEC] as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }

            $attemptStart = microtime(true);
            $result       = $this->http->postJson($this->baseUrl, $payload, $this->timeoutSec, $headers);
            $lastStatus   = $result['status'];
            $lastError    = $result['body'];
            $durationMs   = (int) ((microtime(true) - $attemptStart) * 1000);

            if ($lastStatus >= 200 && $lastStatus < 300) {
                return [SendResult::ok(httpStatus: $lastStatus, durationMs: $durationMs)];
            }
            if ($lastStatus >= 400 && $lastStatus < 500) {
                break;   // permanent
            }
            // 5xx and 0 (network) → retry
        }

        return [SendResult::fail(
            httpStatus: $lastStatus,
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            error: $lastError ?? 'unknown',
        )];
    }

    /**
     * Two-pass template substitution. Public + static for unit-testing without
     * needing to construct a full WebhookChannel.
     *
     * @param array<mixed> $template
     */
    public static function buildPayload(array $template, IncidentDto $dto, NotificationContext $context): string
    {
        $strVars = [
            '{intent}'          => $context->intent->value,
            '{call_id}'         => (string) $dto->callId,
            '{call_number}'     => $dto->callNumber,
            '{call_type}'       => (string) ($dto->callType ?? ''),
            '{full_address}'    => (string) ($dto->fullAddress ?? ''),
            '{create_datetime}' => $dto->createDateTime,
            '{alarm_level}'     => (string) $dto->alarmLevel,
            '{narrative}'       => (string) ($dto->narrative ?? ''),
            '{agency_type}'     => (string) ($dto->agencyType ?? ''),
            '{jurisdiction}'    => (string) ($dto->jurisdiction ?? ''),
            '{units}'           => $dto->units,
            '{topics}'          => implode(', ', $context->topicsToNotify),
        ];

        $walked = self::walk($template, $strVars);
        $json   = json_encode($walked, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('webhook: failed to encode template');
        }

        $rawVars = [
            '"${topics}"'       => json_encode($context->topicsToNotify, JSON_UNESCAPED_SLASHES),
            '"${units}"'        => json_encode(self::splitPipe($dto->units), JSON_UNESCAPED_SLASHES),
            '"${jurisdiction}"' => json_encode(self::splitPipe($dto->jurisdiction ?? ''), JSON_UNESCAPED_SLASHES),
        ];
        return strtr($json, $rawVars);
    }

    /**
     * @param array<mixed> $node
     * @param array<string,string> $vars
     * @return array<mixed>
     */
    private static function walk(array $node, array $vars): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::walk($v, $vars);
            } elseif (is_string($v)) {
                $out[$k] = strtr($v, $vars);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
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

Run: `./vendor/bin/phpunit tests/Unit/Notifications/Channels/WebhookChannelTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/Channels/WebhookChannel.php tests/Unit/Notifications/Channels/WebhookChannelTest.php
git commit -m "feat(notifications): WebhookChannel with templated JSON payloads"
```

---

### Task 11: Register WebhookChannel + update boot test

**Files:**
- Modify: `src/Notifications/registerChannels.php`
- Modify: `tests/Unit/Notifications/RegisterChannelsBootTest.php`

- [ ] **Step 1: Update the boot test to expect `webhook`**

In `tests/Unit/Notifications/RegisterChannelsBootTest.php`, change the assertion:

```php
$this->assertEqualsCanonicalizing(
    ['ntfy', 'pushover', 'webhook'],
    ChannelRegistry::types(),
);
```

Also add `#[UsesClass(\NwsCad\Notifications\Channels\WebhookChannel::class)]` to the class-level attributes.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/RegisterChannelsBootTest.php`
Expected: FAIL (registry only contains ntfy + pushover).

- [ ] **Step 3: Register WebhookChannel in the boot file**

In `src/Notifications/registerChannels.php`, add the use-statement and register call:

```php
use NwsCad\Notifications\Channels\WebhookChannel;

// (after the two existing register() calls)
ChannelRegistry::register(WebhookChannel::descriptor());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Notifications/RegisterChannelsBootTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications/registerChannels.php tests/Unit/Notifications/RegisterChannelsBootTest.php
git commit -m "feat(notifications): register WebhookChannel at boot"
```

---

### Task 12: Webhook end-to-end integration test

**Files:**
- Create: `tests/Integration/Notifications/WebhookEndToEndTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Notifications/WebhookEndToEndTest.php`:

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\WebhookChannel;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(WebhookChannel::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(ChannelFactory::class)]
#[UsesClass(NotificationDispatcher::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(SendResult::class)]
final class WebhookEndToEndTest extends TestCase
{
    public function testDispatcherDeliversToWebhookOnce(): void
    {
        ChannelRegistry::clear();
        ChannelRegistry::register(WebhookChannel::descriptor());

        $server = $this->startCaptureServer();
        try {
            $row = [
                'id'          => 1,
                'name'        => 'test',
                'type'        => 'webhook',
                'enabled'     => 1,
                'base_url'    => "http://127.0.0.1:{$server['port']}/",
                'config_json' => json_encode([
                    'template' => [
                        'intent'  => '{intent}',
                        'address' => '{full_address}',
                        'topics'  => '${topics}',
                    ],
                ]),
                'last_error'  => null,
            ];

            $repo = $this->channelRepoWithRows([$row]);
            $factory = new ChannelFactory(\NwsCad\Config::getInstance());

            $dispatcher = new NotificationDispatcher(
                channelRepo: $repo,
                incidentLoader: fn (int $id): IncidentDto => new IncidentDto(
                    dbCallId: $id, callId: $id, callNumber: 'CN-1',
                    callType: 'EMS', agencyType: 'EMS', jurisdiction: 'CityA',
                    units: 'M1', commonName: null, fullAddress: '5 Oak Lane',
                    nearestCrossStreets: null, policeBeat: null,
                    fireQuadrant: null, natureOfCall: null, narrative: '',
                    alarmLevel: 1, createDateTime: '2026-05-12T11:00:00Z',
                    latitude: null, longitude: null,
                ),
                channelFactory: fn (array $r) => $factory->create($r),
                deltaSeconds: 9999,
            );

            $event = new CallProcessedEvent(
                dbCallId: 1, intent: Intent::Created,
                createDateTime: new DateTimeImmutable(),
                changedFields: [], addedTopics: [],
            );
            $dispatcher->handle($event);

            $captured = $this->readCapture($server['capturePath']);
            $this->assertCount(1, $captured, 'webhook should be POSTed exactly once');
            $decoded = json_decode($captured[0], true);
            $this->assertSame('Created', $decoded['intent']);
            $this->assertSame('5 Oak Lane', $decoded['address']);
            $this->assertSame(['EMS', 'CityA', 'M1'], $decoded['topics']);
        } finally {
            $this->stopCaptureServer($server);
            ChannelRegistry::clear();
        }
    }

    // Implement startCaptureServer / stopCaptureServer / channelRepoWithRows /
    // readCapture helpers. The capture server writes each POST body to a
    // tempfile, one line per request (similar pattern to HttpPostJsonTest's
    // echo server but writing to disk instead of stdout).

    private function startCaptureServer(): array
    {
        $port        = $this->findFreePort();
        $capturePath = tempnam(sys_get_temp_dir(), 'capture');
        $script      = tempnam(sys_get_temp_dir(), 'cap_php') . '.php';
        file_put_contents($script, "<?php\nfile_put_contents("
            . var_export($capturePath, true)
            . ", file_get_contents('php://input') . \"\\n\", FILE_APPEND);\n"
            . "header('Content-Type: text/plain'); echo 'OK';\n");

        $proc = proc_open(
            sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($script)),
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes,
        );

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) { fclose($sock); break; }
            usleep(50_000);
        }
        return ['proc' => $proc, 'port' => $port, 'capturePath' => $capturePath, 'scriptPath' => $script];
    }

    private function stopCaptureServer(array $s): void
    {
        if (is_resource($s['proc'] ?? null)) {
            proc_terminate($s['proc']);
            proc_close($s['proc']);
        }
        foreach (['capturePath', 'scriptPath'] as $k) {
            if (! empty($s[$k]) && file_exists($s[$k])) {
                @unlink($s[$k]);
            }
        }
    }

    /** @return string[] */
    private function readCapture(string $path): array
    {
        $raw = @file_get_contents($path) ?: '';
        return array_values(array_filter(explode("\n", $raw)));
    }

    private function findFreePort(): int
    {
        $sock = socket_create_listen(0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return (int) $port;
    }

    private function channelRepoWithRows(array $rows): \NwsCad\Notifications\ChannelRepositoryInterface
    {
        return new class($rows) implements \NwsCad\Notifications\ChannelRepositoryInterface {
            public function __construct(private array $rows) {}
            public function listEnabled(): array { return $this->rows; }
            public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void {}
            public function markFailure(int $channelId, string $message): void {}
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Integration/Notifications/WebhookEndToEndTest.php`
Expected: FAIL until WebhookChannel + registry are wired (they are after Task 11, so this should pass after Task 11). If it fails for harness reasons (e.g., `ChannelRepositoryInterface` signature mismatch), align the anonymous-class methods with the real interface.

- [ ] **Step 3: Verify the test passes**

Run: `./vendor/bin/phpunit tests/Integration/Notifications/WebhookEndToEndTest.php`
Expected: PASS — exactly one POST captured with the expected substituted body.

- [ ] **Step 4: Run the full integration suite**

Run: `composer test:integration`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/Notifications/WebhookEndToEndTest.php
git commit -m "test(notifications): webhook end-to-end via local capture server"
```

---

### Task 13: Documentation and changelog

**Files:**
- Modify: `docs/NOTIFICATIONS.md`
- Modify: `CHANGELOG.md`
- Modify: `.env.example` (add `WEBHOOK_BASE_URL` reference under the security/notifications section)

- [ ] **Step 1: Add "Webhook channel" section to docs/NOTIFICATIONS.md**

Open `docs/NOTIFICATIONS.md` and add a new section after the Pushover section. Cover:

1. **What it is** — one HTTP POST per CallProcessedEvent with a JSON payload built from a template stored in `notification_channels.config_json`.
2. **Enable it** — sample CLI and API commands:
   ```bash
   php bin/notifications.php enable webhook --base-url=https://hooks.slack.com/services/T.../B.../...
   ```
3. **Template syntax** — list of `{string}` placeholders and `${array}` placeholders (copy from spec section 2).
4. **Slack example** — full `config_json.template` for Slack incoming webhook.
5. **Discord example** — Discord-shaped template.
6. **Authentication** — `auth_header` + `auth_token_env` pattern.

- [ ] **Step 2: Update CHANGELOG.md**

Under a new `## [Unreleased]` section (or whichever section is current per the project convention), add:

```markdown
### Added
- `ChannelRegistry` and `ChannelDescriptor` for plug-in channel registration.
- `WebhookChannel` — generic HTTP POST channel with JSON-template payload
  configuration (covers Slack, Discord, Mattermost, Home Assistant by config).
- `HttpPost::postJson()` helper for JSON-body HTTP POST.

### Changed
- `ChannelFactory::create()` now dispatches via `ChannelRegistry` instead of
  a hardcoded `match` on channel type.
- `NotificationsController` and `bin/notifications.php` query the registry
  for validation, help text, and default config_json.

### Removed
- Unused `NotificationChannel::type()` interface method (descriptor() replaces it).
```

- [ ] **Step 3: Optional .env.example mention**

Under the Security/Notifications block of `.env.example`, add a note:

```
# Webhook channels (enable via `bin/notifications.php enable webhook ...`)
# Each webhook channel's auth uses notification_channels.config_json:
#   auth_header / auth_token_env. No global env var required.
```

- [ ] **Step 4: Sanity-check all tests still pass**

Run: `composer test`
Expected: all suites green.

- [ ] **Step 5: Commit**

```bash
git add docs/NOTIFICATIONS.md CHANGELOG.md .env.example
git commit -m "docs(notifications): document ChannelRegistry and WebhookChannel"
```

---

## Final code-quality review

After Task 13 commits, dispatch a final code-review subagent for the whole branch (spec compliance + code quality across all 13 commits) before opening the PR. Then follow the `superpowers:finishing-a-development-branch` flow.

## Spec coverage check

| Spec requirement | Task(s) |
|---|---|
| `ChannelDescriptor` value object | 1 |
| `ChannelRegistry` static container | 2 |
| `descriptor()` static on every channel | 3, 10 |
| `registerChannels.php` includes wired into watcher/HTTP/CLI | 4 |
| `ChannelFactory::create()` registry-driven | 5 |
| `NotificationsController` registry-driven | 6 |
| `bin/notifications.php` registry-driven | 7 |
| `NotificationChannel::type()` removed | 8 |
| `HttpPost::postJson()` | 9 |
| `WebhookChannel` with two-pass template substitution | 10 |
| `WebhookChannel` registered at boot | 11 |
| End-to-end integration test | 12 |
| Docs + changelog | 13 |
| `tests/Support/RegistryTestCase.php` test-base for clear() in tearDown | Spec mentioned; each test in tasks above includes its own setUp/tearDown clear(). The base class is optional and can be added in a follow-up if the duplication becomes painful. |
