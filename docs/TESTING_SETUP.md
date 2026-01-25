# Testing Infrastructure Setup Guide

## 🎯 Overview

This guide walks you through setting up and using the comprehensive testing infrastructure for NWS CAD.

## 📋 Prerequisites

- PHP 8.3 or higher
- Composer 2.x
- MySQL 8.0+ or PostgreSQL 13+
- Git

## 🚀 Quick Setup

### 1. Install Dependencies

```bash
cd /home/runner/work/nws-cad/nws-cad
composer install
```

### 2. Setup Test Database

#### MySQL

```bash
# Create database and user
mysql -u root -p <<EOF
CREATE DATABASE nws_cad_test;
CREATE USER 'test_user'@'localhost' IDENTIFIED BY 'test_pass';
GRANT ALL PRIVILEGES ON nws_cad_test.* TO 'test_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Load schema
mysql -u test_user -ptest_pass nws_cad_test < database/schema.sql
```

#### PostgreSQL

```bash
# Create database and user
sudo -u postgres psql <<EOF
CREATE DATABASE nws_cad_test;
CREATE USER test_user WITH PASSWORD 'test_pass';
GRANT ALL PRIVILEGES ON DATABASE nws_cad_test TO test_user;
EOF

# Load schema
psql -U test_user -d nws_cad_test -f database/schema.sql
```

### 3. Configure Environment

The test environment is pre-configured in `phpunit.xml`. To override:

```bash
# Copy environment template
cp .env.example .env.testing

# Edit test database settings
nano .env.testing
```

### 4. Verify Setup

```bash
# Test database connection
php -r "
\$pdo = new PDO('mysql:host=127.0.0.1;dbname=nws_cad_test', 'test_user', 'test_pass');
echo 'Database connection successful!' . PHP_EOL;
"

# Run a simple test
./vendor/bin/phpunit tests/Unit/ConfigTest.php
```

## 🧪 Running Tests

### All Tests

```bash
composer test
```

Expected output:
```
PHPUnit 10.5.x

Time: XX.XXX seconds, Memory: XX.XX MB

OK (XX tests, XX assertions)
```

### By Test Suite

```bash
# Unit tests (fast, no database required)
composer test:unit

# Integration tests (requires database)
composer test:integration

# Performance tests (requires database, may take longer)
composer test:performance

# Security tests
composer test:security
```

### With Code Coverage

```bash
# Generate coverage reports
composer test:coverage

# View HTML report
open coverage/html/index.html

# View text summary
cat coverage/coverage.txt
```

### Specific Tests

```bash
# Run single test file
./vendor/bin/phpunit tests/Unit/ConfigTest.php

# Run single test method
./vendor/bin/phpunit --filter testGetInstance tests/Unit/ConfigTest.php

# Run with verbose output
./vendor/bin/phpunit --verbose tests/Unit/ConfigTest.php
```

## 📊 Understanding Test Results

### Success Output

```
PHPUnit 10.5.0

...............                                                  15 / 15 (100%)

Time: 00:00.123, Memory: 10.00 MB

OK (15 tests, 45 assertions)
```

✅ All tests passed!

### Failure Output

```
PHPUnit 10.5.0

..F...                                                            6 / 6 (100%)

Time: 00:00.100, Memory: 10.00 MB

There was 1 failure:

1) NwsCad\Tests\Unit\ConfigTest::testGetInstance
Failed asserting that two variables are identical.
--- Expected
+++ Actual
```

❌ Fix the failing test before proceeding.

### Skipped Tests

```
PHPUnit 10.5.0

..S...                                                            6 / 6 (100%)

Time: 00:00.050, Memory: 8.00 MB

OK, but incomplete, skipped, or risky tests!
Tests: 6, Assertions: 12, Skipped: 1.
```

⚠️  Some tests were skipped (usually due to missing dependencies).

## 🔧 Troubleshooting

### Issue: "Database connection failed"

**Solution:**
```bash
# Verify MySQL is running
sudo service mysql status
sudo service mysql start

# Test connection manually
mysql -h 127.0.0.1 -u test_user -ptest_pass nws_cad_test -e "SELECT 1;"

# Check credentials in phpunit.xml
cat phpunit.xml | grep -A 10 "<php>"
```

### Issue: "Class not found"

**Solution:**
```bash
# Regenerate autoloader
composer dump-autoload

# Verify composer.json autoload section
cat composer.json | grep -A 10 "autoload"
```

### Issue: "Coverage not generated"

**Solution:**
```bash
# Install Xdebug
pecl install xdebug

# Verify Xdebug is loaded
php -v | grep Xdebug

# If using PHP-FPM, restart it
sudo service php8.3-fpm restart
```

### Issue: "Tests are slow"

**Solutions:**
- Run unit tests only: `composer test:unit`
- Skip performance tests: `./vendor/bin/phpunit --exclude-group performance`
- Use parallel execution (requires plugin):
  ```bash
  composer require --dev brianium/paratest
  ./vendor/bin/paratest -p4 tests/Unit/
  ```

### Issue: "Memory limit exceeded"

**Solution:**
```bash
# Increase PHP memory limit
php -d memory_limit=512M ./vendor/bin/phpunit
```

## 📈 Code Coverage

### Minimum Requirements

- **Overall**: 80%
- **Unit Tests**: 80%
- **Critical Classes**: 90%

### Viewing Coverage

After running `composer test:coverage`:

```bash
# HTML Report (most detailed)
open coverage/html/index.html

# Text Report
cat coverage/coverage.txt

# Clover XML (for CI tools)
cat coverage/clover.xml
```

### Coverage Tips

1. **Focus on critical code first**
   - Database operations
   - API endpoints
   - Security functions

2. **Don't aim for 100%**
   - Some code isn't worth testing
   - Focus on business logic

3. **Use coverage to find gaps**
   - Red = Not covered
   - Green = Covered
   - Yellow = Partially covered

## 🔄 CI/CD Integration

### GitHub Actions

Tests run automatically on:
- **Pull Requests**: All test suites
- **Push to main**: Tests + Release
- **Daily**: Security scans

### Viewing CI Results

1. Go to GitHub repository
2. Click "Actions" tab
3. Select workflow run
4. View test results and artifacts

### Local CI Simulation

```bash
# Run what CI runs
composer test
composer test:coverage

# Check coverage threshold
php -r "
\$xml = simplexml_load_file('coverage/clover.xml');
\$metrics = \$xml->project->metrics;
\$coverage = (\$metrics['coveredelements'] / \$metrics['elements']) * 100;
echo 'Coverage: ' . number_format(\$coverage, 2) . '%' . PHP_EOL;
exit(\$coverage < 80 ? 1 : 0);
"
```

## ✍️ Writing New Tests

### 1. Choose Test Type

- **Unit Test**: Tests single class/method in isolation
- **Integration Test**: Tests multiple components working together
- **Performance Test**: Tests speed/efficiency
- **Security Test**: Tests for vulnerabilities

### 2. Create Test File

```bash
# Unit test
touch tests/Unit/MyClassTest.php

# Integration test
touch tests/Integration/MyFeatureTest.php
```

### 3. Use Template

```php
<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NwsCad\MyClass;

/**
 * @covers \NwsCad\MyClass
 */
class MyClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup code
    }

    public function testMethodDoesWhatItShould(): void
    {
        // Arrange
        $instance = new MyClass();
        $input = 'test';
        
        // Act
        $result = $instance->method($input);
        
        // Assert
        $this->assertEquals('expected', $result);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Cleanup code
    }
}
```

### 4. Run Your Test

```bash
./vendor/bin/phpunit tests/Unit/MyClassTest.php
```

### 5. Check Coverage

```bash
./vendor/bin/phpunit --coverage-html coverage tests/Unit/MyClassTest.php
open coverage/html/MyClass.php.html
```

## 📚 Additional Resources

- **PHPUnit Docs**: https://phpunit.de/documentation.html
- **Testing Best Practices**: https://github.com/testdouble/contributing-tests/wiki
- **CI/CD Guide**: See `docs/TESTING.md`
- **Project Docs**: See `docs/` directory

## 🆘 Getting Help

1. **Check existing issues**: https://github.com/k9barry/nws-cad/issues
2. **Create new issue**: Label with `testing`
3. **Include**:
   - Test output
   - PHP version: `php -v`
   - PHPUnit version: `./vendor/bin/phpunit --version`
   - Environment: local/CI

## ✅ Checklist

Before committing:
- [ ] All tests pass: `composer test`
- [ ] Coverage ≥ 80%: `composer test:coverage`
- [ ] No syntax errors: `find src -name "*.php" -exec php -l {} \;`
- [ ] New tests added for new features
- [ ] Tests documented if complex

## 🎉 Success!

You're now ready to use the testing infrastructure!

Next steps:
1. Run tests regularly during development
2. Watch coverage increase
3. Catch bugs before they reach production
4. Ship with confidence! 🚀
