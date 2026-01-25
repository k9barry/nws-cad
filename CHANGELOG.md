# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive testing infrastructure with PHPUnit
- Unit tests for all core classes (Config, Database, Logger, AegisXmlParser, API components)
- Integration tests for API endpoints (Calls, Units, Search, Stats)
- Performance tests for database queries and API endpoints
- Security tests (SQL Injection, XSS, XXE prevention)
- GitHub Actions CI/CD pipeline
- Automated testing on pull requests
- Code coverage tracking (80% minimum threshold)
- Automated changelog generation
- Semantic versioning support
- Security scanning (CodeQL, dependency check, SAST)
- Release automation workflow

### Changed
- Updated composer.json with testing dependencies (PHPUnit, Faker, Mockery)
- Added test bootstrap file with database helpers
- Enhanced phpunit.xml configuration with multiple test suites

### Security
- Added XXE (XML External Entity) attack prevention tests
- Added SQL injection prevention tests
- Added XSS (Cross-Site Scripting) prevention tests
- Automated security scanning in CI pipeline

## [1.0.0] - 2024-01-25

### Added
- Initial release of NWS CAD system
- XML file monitoring and processing
- Multi-database support (MySQL/PostgreSQL)
- REST API with 19 endpoints
- Web dashboard with maps and charts
- Comprehensive logging system
- Docker containerization
- Database schema with 13 tables

### Features
- Real-time CAD XML file processing
- Call tracking and management
- Unit tracking and personnel management
- Location and incident management
- Search and filtering capabilities
- Statistical analysis and reporting
- Comprehensive error handling
- Transaction support for data integrity

[Unreleased]: https://github.com/k9barry/nws-cad/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/k9barry/nws-cad/releases/tag/v1.0.0
