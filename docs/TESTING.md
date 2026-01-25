# Testing Infrastructure Documentation

## Overview

The NWS CAD project includes a comprehensive testing infrastructure built with PHPUnit, providing unit tests, integration tests, performance tests, and security tests with automated CI/CD pipelines.

## Table of Contents

1. [Test Suites](#test-suites)
2. [Running Tests](#running-tests)
3. [Code Coverage](#code-coverage)
4. [CI/CD Pipeline](#cicd-pipeline)
5. [Writing Tests](#writing-tests)
6. [Test Database](#test-database)

## Test Suites

### Unit Tests (`tests/Unit/`)

Tests individual classes and methods in isolation:

- **ConfigTest.php** - Configuration management
- **DatabaseTest.php** - Database connection handling
- **LoggerTest.php** - Logging functionality
- **AegisXmlParserTest.php** - XML parsing with XXE protection
- **ApiRouterTest.php** - API routing logic
- **ApiRequestTest.php** - Request handling and parsing
- **ApiResponseTest.php** - Response formatting

### Integration Tests (`tests/Integration/`)

Tests interactions between components and database:

- **ApiCallsTest.php** - Calls API endpoints
- **ApiUnitsTest.php** - Units API endpoints
- **ApiSearchTest.php** - Search functionality
- **ApiStatsTest.php** - Statistics aggregation

### Performance Tests (`tests/Performance/`)

Tests query and endpoint performance:

- **DatabaseQueryTest.php** - Database query performance (<100ms)
- **ApiEndpointTest.php** - API response times (<200ms)

### Security Tests (`tests/Security/`)

Tests security vulnerabilities:

- **SqlInjectionTest.php** - SQL injection prevention
- **XssTest.php** - Cross-site scripting prevention
- **XxeTest.php** - XML external entity attack prevention

## Running Tests

### All Tests

```bash
composer test
```

### By Test Suite

```bash
# Unit tests only
composer test:unit

# Integration tests only
composer test:integration

# Performance tests only
composer test:performance

# Security tests only
composer test:security
```

### With Coverage

```bash
composer test:coverage
```

Coverage reports are generated in the `coverage/` directory:
- `coverage/html/index.html` - HTML report
- `coverage/clover.xml` - Clover XML format
- `coverage/coverage.txt` - Text format

### Individual Test Files

```bash
./vendor/bin/phpunit tests/Unit/ConfigTest.php
./vendor/bin/phpunit tests/Integration/ApiCallsTest.php
```

### Specific Test Methods

```bash
./vendor/bin/phpunit --filter testGetInstance tests/Unit/ConfigTest.php
```

## Code Coverage

### Minimum Threshold

The project requires a minimum of **80% code coverage** for:
- Unit tests
- Integration tests
- Overall project

### Viewing Coverage

After running tests with coverage:

```bash
# Open HTML report in browser
open coverage/html/index.html

# View text report
cat coverage/coverage.txt
```

### Coverage in CI

The CI pipeline automatically:
1. Runs all tests with coverage
2. Calculates coverage percentage
3. Fails if coverage < 80%
4. Uploads coverage reports as artifacts

## CI/CD Pipeline

### Workflows

#### 1. Tests Workflow (`.github/workflows/tests.yml`)

**Triggers:**
- Pull requests to `main` or `develop`
- Pushes to `main` or `develop`
- Manual workflow dispatch

**Steps:**
1. Setup PHP 8.3 with extensions
2. Install Composer dependencies
3. Start MySQL service
4. Run unit tests
5. Run integration tests
6. Run performance tests
7. Run security tests
8. Generate code coverage
9. Check 80% coverage threshold
10. Upload coverage artifacts

#### 2. Release Workflow (`.github/workflows/release.yml`)

**Triggers:**
- Pushes to `main`
- Manual workflow dispatch with version bump

**Steps:**
1. Calculate next semantic version
2. Generate changelog entry
3. Update VERSION file
4. Update CHANGELOG.md
5. Create Git tag
6. Create GitHub release
7. Deploy to staging (placeholder)

#### 3. Security Workflow (`.github/workflows/security.yml`)

**Triggers:**
- Push/PR to `main` or `develop`
- Daily at 2 AM UTC
- Manual workflow dispatch

**Steps:**
1. CodeQL analysis
2. Dependency vulnerability scanning
3. SAST (Static Application Security Testing)
4. Container security scan (if Dockerfile exists)
5. Generate security summary

### CI Environment Variables

Tests in CI use these environment variables:

```bash
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DATABASE=nws_cad_test
MYSQL_USER=test_user
MYSQL_PASSWORD=test_pass
```

## Writing Tests

### Unit Test Template

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NwsCad\YourClass;

/**
 * @covers \NwsCad\YourClass
 */
class YourClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup code here
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Cleanup code here
    }

    public function testMethodName(): void
    {
        // Arrange
        $instance = new YourClass();
        
        // Act
        $result = $instance->methodName();
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Integration Test Template

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Database;
use PHPUnit\Framework\TestCase;

class YourIntegrationTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        
        if (!getenv('MYSQL_HOST')) {
            self::markTestSkipped('Database not configured');
            return;
        }

        try {
            self::$db = Database::getConnection();
            cleanTestDatabase();
        } catch (\Exception $e) {
            self::markTestSkipped('Database connection failed');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        if (!isset(self::$db)) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testDatabaseOperation(): void
    {
        // Insert test data
        $stmt = self::$db->prepare("INSERT INTO table_name (col) VALUES (?)");
        $stmt->execute(['value']);
        
        // Assert
        $this->assertTrue(true);
    }
}
```

### Best Practices

1. **Naming Conventions**
   - Test classes: `{ClassName}Test.php`
   - Test methods: `test{MethodName}{Scenario}`
   - Example: `testGetInstanceReturnsSingleton`

2. **Arrange-Act-Assert Pattern**
   ```php
   public function testExample(): void
   {
       // Arrange: Set up test data
       $input = 'test';
       
       // Act: Execute the code
       $result = $object->process($input);
       
       // Assert: Verify the result
       $this->assertEquals('expected', $result);
   }
   ```

3. **Use Descriptive Names**
   ```php
   // Good
   public function testGetConfigReturnsDefaultWhenKeyNotFound(): void
   
   // Bad
   public function testGet(): void
   ```

4. **One Assertion Per Test** (when possible)
   ```php
   // Good
   public function testReturnsTrue(): void
   {
       $this->assertTrue($result);
   }
   
   // Acceptable for related assertions
   public function testArrayStructure(): void
   {
       $this->assertIsArray($result);
       $this->assertArrayHasKey('id', $result);
       $this->assertArrayHasKey('name', $result);
   }
   ```

5. **Use Type Hints**
   ```php
   public function testMethod(): void  // Not mixed or omitted
   ```

6. **Clean Up Resources**
   ```php
   protected function tearDown(): void
   {
       parent::tearDown();
       // Clean temp files, reset state, etc.
   }
   ```

## Test Database

### Setup

The test database is automatically created by the CI pipeline. For local testing:

```bash
# Create test database
mysql -u root -p -e "CREATE DATABASE nws_cad_test;"
mysql -u root -p -e "GRANT ALL ON nws_cad_test.* TO 'test_user'@'localhost' IDENTIFIED BY 'test_pass';"

# Load schema
mysql -u test_user -p nws_cad_test < database/schema.sql
```

### Configuration

Test database settings are in `phpunit.xml`:

```xml
<php>
    <env name="DB_TYPE" value="mysql"/>
    <env name="MYSQL_HOST" value="127.0.0.1"/>
    <env name="MYSQL_PORT" value="3306"/>
    <env name="MYSQL_DATABASE" value="nws_cad_test"/>
    <env name="MYSQL_USER" value="test_user"/>
    <env name="MYSQL_PASSWORD" value="test_pass"/>
</php>
```

### Helper Functions

Available in `tests/bootstrap.php`:

```php
// Get test database connection
$pdo = getTestDbConnection();

// Clean all test data
cleanTestDatabase();
```

## Continuous Integration

### Pull Request Workflow

1. Developer creates PR
2. CI automatically runs:
   - All test suites
   - Code coverage check
   - Security scans
3. Results displayed in PR
4. Must pass before merge

### Main Branch Workflow

1. Code merged to `main`
2. Tests run again
3. Release workflow triggers:
   - Version bump
   - Changelog update
   - Tag creation
   - GitHub release
   - Deployment (optional)

## Troubleshooting

### Tests Failing Locally

```bash
# Check database connection
mysql -h 127.0.0.1 -u test_user -ptest_pass -e "SELECT 1"

# Verify schema
mysql -h 127.0.0.1 -u test_user -ptest_pass nws_cad_test -e "SHOW TABLES"

# Check PHP extensions
php -m | grep -E "pdo|mysql|xml|simplexml"

# Run with verbose output
./vendor/bin/phpunit --verbose tests/Unit/ConfigTest.php
```

### Coverage Not Generated

```bash
# Check if Xdebug is installed
php -v | grep Xdebug

# Install Xdebug
pecl install xdebug

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage tests/
```

### CI Pipeline Failing

1. Check GitHub Actions logs
2. Verify database service is running
3. Check for missing dependencies
4. Ensure all migrations are committed

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Semantic Versioning](https://semver.org/)
- [Keep a Changelog](https://keepachangelog.com/)

## Support

For issues or questions:
1. Check existing GitHub issues
2. Create a new issue with `testing` label
3. Include test output and environment details
