<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Config
 */
class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Config::getInstance();
    }

    public function testGetInstance(): void
    {
        $instance1 = Config::getInstance();
        $instance2 = Config::getInstance();
        
        $this->assertInstanceOf(Config::class, $instance1);
        $this->assertSame($instance1, $instance2, 'Config should be singleton');
    }

    public function testGetWithExistingKey(): void
    {
        $dbType = $this->config->get('db.type');
        
        $this->assertNotNull($dbType);
        $this->assertIsString($dbType);
        $this->assertContains($dbType, ['mysql', 'pgsql']);
    }

    public function testGetWithNonExistingKey(): void
    {
        $value = $this->config->get('non.existing.key', 'default_value');
        
        $this->assertEquals('default_value', $value);
    }

    public function testGetWithoutDefault(): void
    {
        $value = $this->config->get('non.existing.key');
        
        $this->assertNull($value);
    }

    public function testGetNestedConfiguration(): void
    {
        $mysqlHost = $this->config->get('db.mysql.host');
        
        $this->assertNotNull($mysqlHost);
        $this->assertIsString($mysqlHost);
    }

    public function testGetDbConfig(): void
    {
        $dbConfig = $this->config->getDbConfig();
        
        $this->assertIsArray($dbConfig);
        $this->assertArrayHasKey('type', $dbConfig);
        $this->assertArrayHasKey('host', $dbConfig);
        $this->assertArrayHasKey('port', $dbConfig);
        $this->assertArrayHasKey('database', $dbConfig);
        $this->assertArrayHasKey('username', $dbConfig);
        $this->assertArrayHasKey('password', $dbConfig);
    }

    public function testGetDbConfigForMysql(): void
    {
        $_ENV['DB_TYPE'] = 'mysql';
        
        // Create new instance to reload config
        $reflection = new \ReflectionClass(Config::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        $config = Config::getInstance();
        $dbConfig = $config->getDbConfig();
        
        $this->assertEquals('mysql', $dbConfig['type']);
        $this->assertArrayHasKey('charset', $dbConfig);
    }

    public function testGetAppEnvironment(): void
    {
        $env = $this->config->get('app.env');
        
        $this->assertNotNull($env);
        $this->assertIsString($env);
    }

    public function testGetWatcherConfiguration(): void
    {
        $watchFolder = $this->config->get('watcher.folder');
        $interval = $this->config->get('watcher.interval');
        $pattern = $this->config->get('watcher.file_pattern');
        
        $this->assertNotNull($watchFolder);
        $this->assertNotNull($interval);
        $this->assertNotNull($pattern);
        $this->assertIsInt($interval);
    }

    public function testGetPaths(): void
    {
        $logsPath = $this->config->get('paths.logs');

        $this->assertNotNull($logsPath);
        $this->assertIsString($logsPath);
        // The default runtime path lives under var/ (var/log). Only assert that
        // when LOG_DIR is not overriding it, so the test is not flaky in
        // environments that set LOG_DIR to a custom location.
        if (getenv('LOG_DIR') === false && !isset($_ENV['LOG_DIR'])) {
            $this->assertStringContainsString('var', $logsPath);
        }
    }

    public function testCsvHelperSplitsAndTrims(): void
    {
        $this->assertSame([], \NwsCad\Config::csv(''));
        $this->assertSame(['a'], \NwsCad\Config::csv('a'));
        $this->assertSame(['a', 'b', 'c'], \NwsCad\Config::csv('a,b,c'));
        $this->assertSame(['a', 'b'], \NwsCad\Config::csv(' a , b '));
        $this->assertSame(['a', 'b'], \NwsCad\Config::csv('a,,b,'));
    }

    public function testCorsAllowedOriginsDefaultsEmpty(): void
    {
        unset($_ENV['ALLOWED_ORIGINS']);
        putenv('ALLOWED_ORIGINS');
        $reflection = new \ReflectionClass(\NwsCad\Config::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $cfg = \NwsCad\Config::getInstance();
        $this->assertSame([], $cfg->get('cors.allowed_origins'));
    }

    public function testTrustedProxyCidrsDefaultIncludesLoopback(): void
    {
        // Empty-string assignment defeats Config's loadEnvFile() re-load
        // (which would silently pull a value back in from a developer's .env)
        // while still triggering env()'s falsy-default branch.
        $_ENV['TRUSTED_PROXY_CIDRS'] = '';
        putenv('TRUSTED_PROXY_CIDRS=');
        $reflection = new \ReflectionClass(\NwsCad\Config::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $cfg = \NwsCad\Config::getInstance();
        $cidrs = $cfg->get('proxy.trusted_cidrs');
        $this->assertContains('127.0.0.1/32', $cidrs);
        $this->assertContains('::1/128', $cidrs);
    }

    public function testIdentityHeaderDefaultsToXAuthUser(): void
    {
        $_ENV['PROXY_IDENTITY_HEADER'] = '';
        putenv('PROXY_IDENTITY_HEADER=');
        $reflection = new \ReflectionClass(\NwsCad\Config::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $cfg = \NwsCad\Config::getInstance();
        $this->assertSame('X-Auth-User', $cfg->get('proxy.identity_header'));
    }

    public function testNotificationsAllowHttpPrivateDefaultsFalse(): void
    {
        unset($_ENV['NOTIFICATION_ALLOW_HTTP_PRIVATE']);
        putenv('NOTIFICATION_ALLOW_HTTP_PRIVATE');
        $reflection = new \ReflectionClass(\NwsCad\Config::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $cfg = \NwsCad\Config::getInstance();
        $this->assertFalse($cfg->get('notifications.allow_http_for_private'));
    }
}
