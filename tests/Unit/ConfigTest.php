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
        $tmpPath = $this->config->get('paths.tmp');
        
        $this->assertNotNull($logsPath);
        $this->assertNotNull($tmpPath);
        $this->assertIsString($logsPath);
        $this->assertIsString($tmpPath);
    }
}
