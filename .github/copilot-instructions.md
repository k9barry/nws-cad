# Copilot Instructions for NWS CAD Project

## Project Overview
NWS CAD (New World Systems Computer-Aided Dispatch) is a comprehensive system for monitoring, parsing, storing, and visualizing CAD XML data with multi-database support (MySQL/PostgreSQL).

## Core Features
1. **File Watcher Service** - Monitors and processes XML files
2. **REST API** - 19 endpoints for data access
3. **Web Dashboard** - Visual interface with maps and charts
4. **Comprehensive Testing** - Unit, integration, performance, security
5. **CI/CD Pipeline** - Automated testing and deployment
6. **Logging & Monitoring** - Complete observability

## Key Instructions

### Always Remember
- Use PHP 8.3 with strict types
- All database queries use prepared statements
- Follow PSR-4 autoloading (namespace: `NwsCad\*`)
- Include PHPDoc blocks for all classes/methods
- Update CHANGELOG.md for all changes
- Write tests for all new features
- Use semantic versioning (MAJOR.MINOR.PATCH)

### Database Schema (13 Tables)
calls, agency_contexts, locations, incidents, units, unit_personnel, unit_logs, narratives, persons, vehicles, call_dispositions, unit_dispositions, processed_files

### Security Requirements
- XXE protection in XML parsing
- SQL injection prevention (prepared statements)
- XSS prevention (escape outputs)
- CSRF tokens for forms
- Input validation and sanitization

### Testing Requirements
- Minimum 80% code coverage
- Unit tests for all classes
- Integration tests for API endpoints
- Performance tests for database queries
- Security tests for vulnerabilities

### CI/CD Workflow
- Run tests on all PRs
- Update CHANGELOG.md on merge to main
- Auto-version using semantic versioning
- Deploy to staging after tests pass

## File Structure
```
/src/ - Application code
  /Api/ - REST API
  /Dashboard/ - Web dashboard
  /Models/ - Data models
/tests/ - All tests
/public/ - Web entry points
/database/ - Schema files
/docs/ - Documentation
/.github/ - CI workflows
```

## Common Patterns

### API Response Format
```json
{
  "success": true,
  "data": {...}
}
```

### Pagination
`?page=1&per_page=30`

### Filtering
`?field=value&date_from=2022-12-01`

### Sorting  
`?sort=field&order=asc`

For complete details, see `/home/runner/work/nws-cad/nws-cad/.github/copilot-instructions.md`
