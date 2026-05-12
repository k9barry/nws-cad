<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Api\Controllers\NotificationsController;
use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Security\TrustedProxy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class DirectAccessForgeryTest extends TestCase
{
    private static \PDO $db;
    private int $initialObLevel = 0;

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
        if (! isset(self::$db)) {
            $this->markTestSkipped('Database not available');
        }
        cleanTestDatabase();
        Response::resetForTesting();
        unset($GLOBALS['__identity']);
        $this->initialObLevel = ob_get_level();
        ob_start();

        // Pin trusted CIDRs to loopback so this test exercises the deny path
        // regardless of the developer's .env (some LAN deployments widen the
        // default to 0.0.0.0/0 for direct access).
        $_ENV['TRUSTED_PROXY_CIDRS'] = '127.0.0.1/32,::1/128';
        putenv('TRUSTED_PROXY_CIDRS=127.0.0.1/32,::1/128');
        $refl = new \ReflectionClass(Config::class);
        $prop = $refl->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
    }

    public function testUntrustedRemoteCannotForgeIdentity(): void
    {
        // Simulate a non-proxied attacker hitting the API directly with a
        // forged X-Auth-User header.
        $_SERVER['REMOTE_ADDR']      = '203.0.113.99';
        $_SERVER['HTTP_X_AUTH_USER'] = 'admin';

        // Run guard — must short-circuit before any controller logic.
        TrustedProxy::guard(Config::getInstance());

        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('Direct access not permitted', $payload['error']);

        // And no row was written:
        $count = (int) self::$db->query(
            "SELECT COUNT(*) FROM notification_channels"
        )->fetchColumn();
        $this->assertSame(0, $count);

        ob_start();   // re-open buffer so tearDown can close it cleanly
    }
}
