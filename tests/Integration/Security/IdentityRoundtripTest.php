<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Security;

use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Security\Identity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Security\Identity
 * @uses \NwsCad\Api\Controllers\NotificationsController
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\ChannelFactory
 * @uses \NwsCad\Notifications\ChannelRepository
 * @uses \NwsCad\Security\UrlValidator
 * @uses \NwsCad\Security\InputValidator
 */
class IdentityRoundtripTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        Response::resetForTesting();
        unset($GLOBALS['__identity']);
        unset($_SERVER['HTTP_X_AUTH_USER']);
    }

    public function testEnableRecordsExtractedIdentity(): void
    {
        $_SERVER['HTTP_X_AUTH_USER'] = 'k9barry';
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example.com';
        putenv('NTFY_BASE_URL=https://ntfy.example.com');

        $GLOBALS['__identity'] = Identity::extract(Config::getInstance());

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        ob_get_clean();

        $row = self::$db->query(
            "SELECT last_updated_actor FROM notification_channels WHERE name = 'ntfy_primary'"
        )->fetch();
        $this->assertSame('k9barry', $row['last_updated_actor']);
    }

    public function testEnableRecordsNullWhenHeaderAbsent(): void
    {
        // No HTTP_X_AUTH_USER set.
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example.com';
        putenv('NTFY_BASE_URL=https://ntfy.example.com');

        $GLOBALS['__identity'] = Identity::extract(Config::getInstance());

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        ob_get_clean();

        $row = self::$db->query(
            "SELECT last_updated_actor FROM notification_channels WHERE name = 'ntfy_primary'"
        )->fetch();
        $this->assertNull($row['last_updated_actor']);
    }

    public function testForgedIdentityIsRejected(): void
    {
        // A malformed header (CRLF, oversize, or invalid chars) must yield
        // null identity, so the audit column records null rather than the
        // attacker-controlled payload.
        $_SERVER['HTTP_X_AUTH_USER'] = "admin\r\nX-Forwarded-For: evil";
        $_ENV['NTFY_BASE_URL'] = 'https://ntfy.example.com';
        putenv('NTFY_BASE_URL=https://ntfy.example.com');

        $GLOBALS['__identity'] = Identity::extract(Config::getInstance());

        $controller = new NotificationsController();
        ob_start();
        $controller->enable('ntfy');
        ob_get_clean();

        $row = self::$db->query(
            "SELECT last_updated_actor FROM notification_channels WHERE name = 'ntfy_primary'"
        )->fetch();
        $this->assertNull($row['last_updated_actor']);
    }
}
