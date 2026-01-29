# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Agency and Jurisdiction filters to Analytics page
- Call counts to Call Distribution chart labels
- Dynamic calculation of busiest hour from actual call data
- Dynamic calculation of most active unit from actual units data
- "Incidents by Jurisdiction" chart replacing "Call Volume Over Time"
- Unique constraints on units table for (call_id, unit_number)
- Unique constraints on unit_logs table for (unit_id, log_datetime, status, location)
- Unique constraints on narratives table for (call_id, create_datetime, create_user, text)
- Location field to unit_logs table to store log location data

### Fixed
- Analytics page stats calculation using correct data sources
- SQL GROUP BY compatibility with MySQL strict mode
- API jurisdiction filtering to use incidents table instead of agency_contexts
- XML file processing now appends new data instead of replacing existing records
- Unit logs and narratives are now preserved when processing updated XML files
- Units are now updated (UPSERT) rather than deleted and recreated

### Changed
- XML parser now uses INSERT IGNORE for cumulative child records (narratives, unit_logs)
- XML parser now uses UPSERT for units to update timestamps without losing child records
- Removed deleteChildRecords() method that was deleting all child data on updates
- Database schema updated to support idempotent XML imports

## [1.1.0] - 2026-01-25

### Added
- Dashboard main page with live data refresh
- Units tracking page with real-time status
- Analytics page with comprehensive reporting
- Auto-detection of GitHub Codespaces environment for API URLs
- Comprehensive JavaScript logging for debugging

### Fixed
- Dashboard API connection issues in GitHub Codespaces
- Async/await initialization in dashboard JavaScript
- API base URL configuration for both local and Codespaces environments
- Field name mapping to match database schema

### Changed
- Improved error handling in all dashboard pages
- Enhanced logging throughout JavaScript modules
- Updated APP_CONFIG to auto-detect environment

## [1.0.0] - 2025-01-18

### Added
- Initial release
- XML file parsing and processing
- REST API with 19 endpoints
- MySQL and PostgreSQL support
- Comprehensive test suite
- CI/CD pipeline
- Documentation
