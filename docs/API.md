# NWS CAD API Documentation

## Base URL
```
http://localhost:8080/api
```

## Quick Start

Test the API:
```bash
# Get API info
curl http://localhost:8080/api/

# List calls
curl http://localhost:8080/api/calls

# Get call details
curl http://localhost:8080/api/calls/1

# Search calls
curl "http://localhost:8080/api/search/calls?call_number=260"

# Get statistics
curl http://localhost:8080/api/stats

# Or get specific statistics
curl http://localhost:8080/api/stats/calls
```

## Complete Documentation

See `/src/Api/Controllers/README.md` for complete API documentation with all 19 endpoints.

## Response Format

All responses follow this format:
```json
{
  "success": true,
  "data": { ... }
}
```

Errors:
```json
{
  "success": false,
  "error": "Error message"
}
```

## Pagination

All list endpoints support pagination:
- `?page=1` - Page number (default: 1)
- `?per_page=30` - Items per page (default: 30, max: 100)

Example:
```bash
curl "http://localhost:8080/api/calls?page=2&per_page=50"
```

## Filtering

Calls can be filtered by:
- `call_number` - Call number
- `status` - closed_flag (1 or 0)
- `date_from` - Start date (YYYY-MM-DD)
- `date_to` - End date (YYYY-MM-DD)

Example:
```bash
curl "http://localhost:8080/api/calls?status=1&date_from=2022-12-01"
```

## Sorting

Use `sort` and `order` parameters:
```bash
curl "http://localhost:8080/api/calls?sort=create_datetime&order=desc"
```

## Main Endpoints

### Calls
- `GET /api/calls` - List all calls
- `GET /api/calls/{id}` - Get call details
- `GET /api/calls/{id}/units` - Get call units
- `GET /api/calls/{id}/narratives` - Get call narratives
- `GET /api/calls/{id}/location` - Get call location

### Units
- `GET /api/units` - List all units
- `GET /api/units/{id}` - Get unit details
- `GET /api/units/{id}/logs` - Get unit status history

### Search
- `GET /api/search/calls` - Advanced call search
- `GET /api/search/location` - Location-based search
- `GET /api/search/units` - Unit search

### Statistics
- `GET /api/stats` - Aggregate statistics (calls, units, response times combined)
- `GET /api/stats/calls` - Call statistics
- `GET /api/stats/units` - Unit statistics
- `GET /api/stats/response-times` - Response time analytics
