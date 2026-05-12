<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Api\Response;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\NotificationsController
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\ChannelFactory
 * @uses \NwsCad\Notifications\ChannelRegistry
 * @uses \NwsCad\Notifications\ChannelDescriptor
 * @uses \NwsCad\Notifications\ChannelRepository
 * @uses \NwsCad\Notifications\IncidentDto
 * @uses \NwsCad\Notifications\NotificationContext
 * @uses \NwsCad\Notifications\SendResult
 * @uses \NwsCad\Notifications\Events\Intent
 * @uses \NwsCad\Notifications\Channels\NtfyChannel
 * @uses \NwsCad\Notifications\Channels\PushoverChannel
 * @uses \NwsCad\Security\UrlValidator
 * @uses \NwsCad\Security\Identity
 * @uses \NwsCad\Security\InputValidator
 * @uses \NwsCad\Security\TrustedProxy
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
        // Each test needs a fresh response state — Response::json() in
        // testing mode short-circuits subsequent calls within a request, so
        // without a reset every test after the first would silently no-op.
        Response::resetForTesting();

        // Populate the registry so validateType() works for ntfy/pushover.
        // Tests that probe unknown types may clear/re-register as needed.
        \NwsCad\Notifications\ChannelRegistry::clear();
        \NwsCad\Notifications\ChannelRegistry::register(\NwsCad\Notifications\Channels\NtfyChannel::descriptor());
        \NwsCad\Notifications\ChannelRegistry::register(\NwsCad\Notifications\Channels\PushoverChannel::descriptor());
    }

    protected function tearDown(): void
    {
        \NwsCad\Notifications\ChannelRegistry::clear();
        unset($GLOBALS['__identity']);
        unset($_SERVER['HTTP_X_AUTH_USER']);
        parent::tearDown();
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
            public static function descriptor(): \NwsCad\Notifications\ChannelDescriptor {
                return new \NwsCad\Notifications\ChannelDescriptor(
                    type: 'stub', label: 'stub', baseUrlEnv: 'X',
                    requiredEnvs: [], defaultConfig: [],
                    factory: static fn (array $r, \NwsCad\Config $c) => throw new \LogicException('test stub'),
                );
            }
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
        $this->assertIsInt($payload['data']['log_id']);
        $this->assertGreaterThan(0, $payload['data']['log_id']);

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
            public static function descriptor(): \NwsCad\Notifications\ChannelDescriptor {
                return new \NwsCad\Notifications\ChannelDescriptor(
                    type: 'stub', label: 'stub', baseUrlEnv: 'X',
                    requiredEnvs: [], defaultConfig: [],
                    factory: static fn (array $r, \NwsCad\Config $c) => throw new \LogicException('test stub'),
                );
            }
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

    public function testEnableRejectsHttpUrl(): void
    {
        $_ENV['NTFY_BASE_URL'] = 'http://attacker.example';
        putenv('NTFY_BASE_URL=http://attacker.example');

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Invalid base_url', $payload['error']);
        $this->assertStringContainsString('scheme', $payload['error']);
    }

    public function testEnableRejectsCrLfUrl(): void
    {
        $_ENV['NTFY_BASE_URL'] = "https://a.example\r\nX: y";
        putenv('NTFY_BASE_URL=' . $_ENV['NTFY_BASE_URL']);

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('crlf', $payload['error']);
    }

    public function testEnableRecordsActorFromIdentity(): void
    {
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example.com';
        putenv('NTFY_BASE_URL=https://ntfy.example.com');

        // Drive identity through the same path the bootstrap uses: set the
        // trusted header and let Identity::extract() build the object. This
        // avoids reflecting past the private constructor (which PHP rightly
        // refuses).
        $_SERVER['HTTP_X_AUTH_USER'] = 'k9barry';
        $GLOBALS['__identity'] = \NwsCad\Security\Identity::extract(\NwsCad\Config::getInstance());

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $row = self::$db->query(
            "SELECT last_updated_actor FROM notification_channels WHERE name = 'ntfy_primary'"
        )->fetch();
        $this->assertSame('k9barry', $row['last_updated_actor']);
    }

    public function testEnableUnknownTypeReturnsAvailableTypes(): void
    {
        // Registry already has ntfy + pushover from setUp(); just verify the
        // error response includes the available_types list.
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('badtype');
        $body = (string) ob_get_clean();

        $response = json_decode($body, true);
        $this->assertSame(400, http_response_code());
        $this->assertFalse($response['success']);
        $this->assertSame(['ntfy', 'pushover'], $response['errors']['available_types']);
    }
}
