# Testing Guide

## Overview

NWS CAD includes 142+ automated tests across 4 test suites with 80% minimum code coverage requirement.

## Test Suites

| Suite | Files | Tests | Purpose |
|-------|-------|-------|---------|
| Unit | 7 | 69+ | Core class testing in isolation |
| Integration | 4 | 25+ | API endpoint testing with database |
| Performance | 2 | 14+ | Query and API benchmarks |
| Security | 3 | 34+ | Vulnerability prevention |

## Running Tests

### All Tests

```bash
composer test
```

### Individual Suites

```bash
composer test:unit           # Unit tests only
composer test:integration    # Integration tests
composer test:performance    # Performance tests
composer test:security       # Security tests
```

### With Coverage

```bash
composer test:coverage
```

Coverage reports generated in `coverage/`:
- `coverage/html/index.html` - HTML report
- `coverage/clover.xml` - Clover XML
- `coverage/coverage.txt` - Text format

### Specific Tests

```bash
# Single test file
./vendor/bin/phpunit tests/Unit/ConfigTest.php

# Single test method
./vendor/bin/phpunit --filter testGetInstance tests/Unit/ConfigTest.php
```

## Test Files

### Unit Tests (`tests/Unit/`)

| File | Description |
|------|-------------|
| ConfigTest.php | Configuration management |
| DatabaseTest.php | Database connections |
| LoggerTest.php | Logging functionality |
| AegisXmlParserTest.php | XML parsing + XXE protection |
| ApiRouterTest.php | API routing |
| ApiRequestTest.php | Request parsing |
| ApiResponseTest.php | Response formatting |

### Integration Tests (`tests/Integration/`)

| File | Description |
|------|-------------|
| ApiCallsTest.php | Calls API endpoints |
| ApiUnitsTest.php | Units API endpoints |
| ApiSearchTest.php | Search functionality |
| ApiStatsTest.php | Statistics aggregation |

### Performance Tests (`tests/Performance/`)

| File | Description |
|------|-------------|
| DatabaseQueryTest.php | Query performance (<100ms) |
| ApiEndpointTest.php | API response times (<200ms) |

### Security Tests (`tests/Security/`)

| File | Description |
|------|-------------|
| SqlInjectionTest.php | SQL injection prevention |
| XssTest.php | Cross-site scripting prevention |
| XxeTest.php | XXE attack prevention |

## Test Database Setup

### Local Setup

```bash
# Create test database (MySQL)
mysql -u root -p -e "CREATE DATABASE nws_cad_test;"
mysql -u root -p -e "GRANT ALL ON nws_cad_test.* TO 'test_user'@'localhost' IDENTIFIED BY 'test_pass';"
mysql -u test_user -ptest_pass nws_cad_test < database/mysql/init.sql

# Docker
docker-compose exec mysql mysql -u root -proot_password -e "CREATE DATABASE nws_cad_test;"
```

### Configuration

Test settings in `phpunit.xml`:

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

## Writing Tests

### Template

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
    }

    public function testMethodReturnsExpectedValue(): void
    {
        // Arrange
        $instance = new YourClass();
        
        // Act
        $result = $instance->method();
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Best Practices

1. **Naming**: `test{Method}{Scenario}` - e.g., `testGetConfigReturnsDefault`
2. **Pattern**: Arrange-Act-Assert
3. **Isolation**: One assertion per test when possible
4. **Type Hints**: Always use `: void` return type
5. **Cleanup**: Use `tearDown()` to clean resources

## CI/CD Pipeline

### Test Workflow (`.github/workflows/tests.yml`)

**Triggers:** PRs and pushes to `main`/`develop`

**Steps:**
1. Setup PHP 8.3
2. Install dependencies
3. Start MySQL service
4. Run all test suites
5. Generate coverage
6. Verify 80% threshold
7. Upload artifacts

### Security Workflow (`.github/workflows/security.yml`)

**Triggers:** Daily at 2 AM UTC + PRs

**Steps:**
1. CodeQL analysis
2. Dependency scanning
3. SAST analysis
4. Container security

## Troubleshooting

### Tests Failing Locally

```bash
# Check database connection
mysql -h 127.0.0.1 -u test_user -ptest_pass nws_cad_test -e "SHOW TABLES;"

# Check PHP extensions
php -m | grep -E "pdo|mysql|xml|simplexml"

# Verbose output
./vendor/bin/phpunit --verbose tests/Unit/ConfigTest.php
```

### Coverage Not Generated

```bash
# Check Xdebug
php -v | grep Xdebug

# Install if missing
pecl install xdebug
```

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
