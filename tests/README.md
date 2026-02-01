# Test Suite

This directory contains all automated tests for the NWS CAD project.

## Structure

```
tests/
├── bootstrap.php           # Test bootstrap and helpers
├── Unit/                   # Unit tests
│   ├── ConfigTest.php
│   ├── DatabaseTest.php
│   ├── LoggerTest.php
│   ├── AegisXmlParserTest.php
│   ├── ApiRouterTest.php
│   ├── ApiRequestTest.php
│   └── ApiResponseTest.php
├── Integration/            # Integration tests
│   ├── ApiCallsTest.php
│   ├── ApiUnitsTest.php
│   ├── ApiSearchTest.php
│   ├── ApiStatsTest.php
│   └── ApiFilteringTest.php  ⭐ NEW - Comprehensive filter tests
├── Performance/            # Performance tests
│   ├── DatabaseQueryTest.php
│   └── ApiEndpointTest.php
└── Security/               # Security tests
    ├── SqlInjectionTest.php
    ├── XssTest.php
    └── XxeTest.php
```

## 🆕 New: Comprehensive Filter Testing

The new `ApiFilteringTest.php` provides exhaustive testing of all filter parameters:

### What's Tested
- ✅ Date range filtering (date_from, date_to)
- ✅ Status filtering (active/closed via closed_flag)
- ✅ Agency type filtering (Police, Fire, EMS)
- ✅ Jurisdiction filtering
- ✅ Combined filter scenarios (2-3 filters at once)
- ✅ Search functionality (LIKE queries)
- ✅ SQL injection protection in all filter params
- ✅ NULL value handling
- ✅ Empty result sets
- ✅ Case sensitivity
- ✅ Pagination with filters
- ✅ Performance with multiple filters (<100ms)

### Running Filter Tests
```bash
# Run all filtering tests
composer run-script test:integration -- --filter ApiFilteringTest

# Run specific filter test
./vendor/bin/phpunit tests/Integration/ApiFilteringTest.php --filter testFilterByDateRange

# Run with coverage
composer run-script test:coverage -- --filter ApiFilteringTest
```

### Filter Test Data
Tests use seeded data:
- 7 calls spanning 30 days
- 3 agency types (Police, Fire, EMS)
- 3 jurisdictions (Anderson, Elwood, Alexandria)
- Mix of active/closed calls

## Quick Start

### Run All Tests
```bash
composer test
```

### Run Specific Test Suite
```bash
composer test:unit           # Unit tests
composer test:integration    # Integration tests
composer test:performance    # Performance tests
composer test:security       # Security tests
```

### Run with Coverage
```bash
composer test:coverage
```

## Test Statistics

- **Total Test Files**: 16
- **Total Test Classes**: 16
- **Coverage Target**: 80%
- **Test Suites**: 4 (Unit, Integration, Performance, Security)

## Requirements

- PHP 8.3+
- PHPUnit 10.5+
- MySQL 8.0+ (for integration/performance tests)
- Xdebug (for coverage reports)

## Writing Tests

See [TESTING.md](../docs/TESTING.md) for:
- Test writing guidelines
- Best practices
- Templates
- CI/CD integration

## Test Database

Integration and performance tests require a test database:

```bash
# Local setup
mysql -u root -p -e "CREATE DATABASE nws_cad_test;"
mysql -u root -p -e "GRANT ALL ON nws_cad_test.* TO 'test_user'@'localhost' IDENTIFIED BY 'test_pass';"
mysql -u test_user -p nws_cad_test < database/schema.sql
```

Environment variables (set in phpunit.xml):
- `MYSQL_HOST=127.0.0.1`
- `MYSQL_DATABASE=nws_cad_test`
- `MYSQL_USER=test_user`
- `MYSQL_PASSWORD=test_pass`

## Helper Functions

Available in `bootstrap.php`:

```php
// Get test database connection
$pdo = getTestDbConnection();

// Clean all test tables
cleanTestDatabase();
```

## CI/CD

Tests run automatically on:
- Pull requests
- Pushes to main/develop
- Daily security scans

See `.github/workflows/tests.yml` for details.

## Coverage Reports

After running `composer test:coverage`:

```bash
# View HTML report
open coverage/html/index.html

# View text summary
cat coverage/coverage.txt
```

## Troubleshooting

### Database Connection Failed
```bash
# Check MySQL is running
sudo service mysql status

# Test connection
mysql -h 127.0.0.1 -u test_user -ptest_pass -e "SELECT 1"
```

### Coverage Not Generated
```bash
# Install Xdebug
pecl install xdebug

# Verify installation
php -v | grep Xdebug
```

### Tests Skipped
Some tests are skipped if:
- Database is not available
- Required extensions are missing
- Test environment is not configured

## Contributing

When adding new features:
1. Write tests first (TDD)
2. Ensure >80% coverage
3. Run full test suite before PR
4. Update test documentation

## License

Same as main project (MIT)
