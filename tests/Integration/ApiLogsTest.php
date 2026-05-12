<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Config;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Logs API endpoints
 * @covers \NwsCad\Api\Controllers\LogsController
 * @uses \NwsCad\Api\Request
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Security\Identity
 * @uses \NwsCad\Security\InputValidator
 */
class ApiLogsTest extends TestCase
{
    private string $testLogPath;
    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test needs a fresh response state — Response::json() in
        // testing mode short-circuits subsequent calls within a request, so
        // without a reset every test after the first would silently no-op.
        \NwsCad\Api\Response::resetForTesting();

        // Enable logs for testing
        putenv('APP_LOGS_ENABLED=true');
        putenv('APP_ENV=testing');

        $config = Config::getInstance();
        $this->testLogPath = $config->get('paths.logs');
        
        // Ensure logs directory exists
        if (!is_dir($this->testLogPath)) {
            mkdir($this->testLogPath, 0755, true);
        }
        
        // Create a test log file
        $this->testLogFile = 'test-' . time() . '.log';
        $this->createTestLogFile();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test log file
        $filePath = $this->testLogPath . '/' . $this->testLogFile;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function createTestLogFile(): void
    {
        $filePath = $this->testLogPath . '/' . $this->testLogFile;
        $content = <<<EOL
[2024-01-26 10:00:00] nws-cad.DEBUG: Test debug message {}
[2024-01-26 10:01:00] nws-cad.INFO: Test info message {}
[2024-01-26 10:02:00] nws-cad.WARNING: Test warning message {}
[2024-01-26 10:03:00] nws-cad.ERROR: Test error message {}
[2024-01-26 10:04:00] nws-cad.CRITICAL: Test critical message {}
EOL;
        file_put_contents($filePath, $content);
    }

    public function testCanListLogFiles(): void
    {
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->index();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('files', $data['data']);
        $this->assertIsArray($data['data']['files']);
    }

    public function testCanViewLogFile(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show($this->testLogFile);
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
        $this->assertGreaterThan(0, count($data['data']['items']));
    }

    public function testCanFilterLogsByLevel(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        $_GET['level'] = 'ERROR';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show($this->testLogFile);
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        
        // Check that filtered results contain ERROR level
        foreach ($data['data']['items'] as $item) {
            $this->assertStringContainsStringIgnoringCase('ERROR', $item['raw']);
        }
    }

    public function testRejectsDirectoryTraversal(): void
    {
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show('../etc/passwd');
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertFalse($data['success']);
        $this->assertEquals(400, http_response_code());
    }

    public function testCanGetRecentLogs(): void
    {
        // Create app.log for recent logs test
        $appLogPath = $this->testLogPath . '/app.log';
        file_put_contents($appLogPath, "[2024-01-26 12:00:00] nws-cad.INFO: Recent log entry {}\n");
        
        $_GET['lines'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->recent();
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('entries', $data['data']);
        $this->assertIsArray($data['data']['entries']);
        
        // Clean up
        if (file_exists($appLogPath)) {
            unlink($appLogPath);
        }
    }

    public function testReturnsNotFoundForNonexistentFile(): void
    {
        $_GET['page'] = '1';
        $_GET['per_page'] = '10';
        
        $controller = new \NwsCad\Api\Controllers\LogsController();
        
        ob_start();
        $controller->show('nonexistent-file.log');
        $output = ob_get_clean();
        
        $data = json_decode($output, true);
        
        $this->assertFalse($data['success']);
    }

    public function testDeniesAccessWhenDisabled(): void
    {
        $this->withProdEnv(logsEnabled: false, allowlist: '', identityUser: null, scenario: function () {
            $controller = new \NwsCad\Api\Controllers\LogsController();
            ob_start();
            $controller->index();
            $data = json_decode((string) ob_get_clean(), true);
            $this->assertIsArray($data, 'Controller should have emitted a JSON response');
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('disabled in production', $data['error']);
        });
    }

    public function testDeniesAccessInProdWithEmptyAllowlist(): void
    {
        $this->withProdEnv(logsEnabled: true, allowlist: '', identityUser: 'k9barry', scenario: function () {
            $controller = new \NwsCad\Api\Controllers\LogsController();
            ob_start();
            $controller->index();
            $data = json_decode((string) ob_get_clean(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('admin identity', $data['error']);
        });
    }

    public function testDeniesAccessInProdWhenUserNotInAllowlist(): void
    {
        $this->withProdEnv(logsEnabled: true, allowlist: 'alice,bob', identityUser: 'mallory', scenario: function () {
            $controller = new \NwsCad\Api\Controllers\LogsController();
            ob_start();
            $controller->index();
            $data = json_decode((string) ob_get_clean(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('admin identity', $data['error']);
        });
    }

    public function testAllowsAccessInProdWhenUserInAllowlist(): void
    {
        $this->withProdEnv(logsEnabled: true, allowlist: 'alice,bob,k9barry', identityUser: 'k9barry', scenario: function () {
            $controller = new \NwsCad\Api\Controllers\LogsController();
            ob_start();
            $controller->index();
            $data = json_decode((string) ob_get_clean(), true);
            $this->assertTrue($data['success'], 'k9barry on the allowlist should pass the gate');
            $this->assertArrayHasKey('files', $data['data']);
        });
    }

    public function testDeniesAccessInProdWhenIdentityIsUnknown(): void
    {
        $this->withProdEnv(logsEnabled: true, allowlist: 'alice', identityUser: null, scenario: function () {
            $controller = new \NwsCad\Api\Controllers\LogsController();
            ob_start();
            $controller->index();
            $data = json_decode((string) ob_get_clean(), true);
            $this->assertFalse($data['success']);
        });
    }

    /**
     * Run the given scenario with a production-mode Config singleton, the
     * specified logs-enabled + allowlist values, and Identity::current()
     * resolved from the provided user (or unset for "no identity").
     * Restores env state on return.
     */
    private function withProdEnv(bool $logsEnabled, string $allowlist, ?string $identityUser, \Closure $scenario): void
    {
        $prev = [
            'APP_ENV'           => $_ENV['APP_ENV']           ?? null,
            'APP_LOGS_ENABLED'  => $_ENV['APP_LOGS_ENABLED']  ?? null,
            'LOGS_ADMIN_USERS'  => $_ENV['LOGS_ADMIN_USERS']  ?? null,
            'HTTP_X_AUTH_USER'  => $_SERVER['HTTP_X_AUTH_USER'] ?? null,
            'identity'          => $GLOBALS['__identity']     ?? null,
        ];
        try {
            $_ENV['APP_ENV']          = 'production';
            $_ENV['APP_LOGS_ENABLED'] = $logsEnabled ? 'true' : 'false';
            $_ENV['LOGS_ADMIN_USERS'] = $allowlist;
            putenv('APP_ENV=production');
            putenv('APP_LOGS_ENABLED=' . ($logsEnabled ? 'true' : 'false'));
            putenv("LOGS_ADMIN_USERS={$allowlist}");

            $this->resetConfig();
            \NwsCad\Api\Response::resetForTesting();

            if ($identityUser !== null) {
                $_SERVER['HTTP_X_AUTH_USER'] = $identityUser;
                $GLOBALS['__identity'] = \NwsCad\Security\Identity::extract(Config::getInstance());
            } else {
                unset($_SERVER['HTTP_X_AUTH_USER']);
                $GLOBALS['__identity'] = \NwsCad\Security\Identity::extract(Config::getInstance());
            }

            $scenario();
        } finally {
            foreach (['APP_ENV', 'APP_LOGS_ENABLED', 'LOGS_ADMIN_USERS'] as $k) {
                if ($prev[$k] === null) {
                    unset($_ENV[$k]);
                    putenv("$k");
                } else {
                    $_ENV[$k] = $prev[$k];
                    putenv("$k=" . $prev[$k]);
                }
            }
            if ($prev['HTTP_X_AUTH_USER'] === null) {
                unset($_SERVER['HTTP_X_AUTH_USER']);
            } else {
                $_SERVER['HTTP_X_AUTH_USER'] = $prev['HTTP_X_AUTH_USER'];
            }
            if ($prev['identity'] === null) {
                unset($GLOBALS['__identity']);
            } else {
                $GLOBALS['__identity'] = $prev['identity'];
            }
            $this->resetConfig();
            putenv('APP_LOGS_ENABLED=true');
            putenv('APP_ENV=testing');
        }
    }

    private function resetConfig(): void
    {
        $refl = new \ReflectionClass(Config::class);
        $prop = $refl->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
