# REST API Reference

## Base URL

```
http://localhost:8080/api
```

## Response Format

All responses use this structure:

```json
{
  "success": true,
  "data": { ... }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message"
}
```

## Endpoints

### Calls

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/calls` | List calls (paginated) |
| GET | `/calls/{id}` | Get call details |
| GET | `/calls/{id}/units` | Get assigned units |
| GET | `/calls/{id}/narratives` | Get narrative timeline |
| GET | `/calls/{id}/location` | Get location details |

### Units

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/units` | List all units |
| GET | `/units/{id}` | Get unit details |
| GET | `/units/{id}/logs` | Get status history |

### Search

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/search/calls` | Advanced call search |
| GET | `/search/location` | Geographic radius search |

### Statistics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stats` | Aggregated statistics |
| GET | `/stats/calls` | Call statistics |
| GET | `/stats/units` | Unit statistics |
| GET | `/stats/response-times` | Response time analytics |

## Query Parameters

### Pagination

```bash
?page=1&per_page=30
```

- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 30, max: 100)

### Filtering

```bash
# Date range
?date_from=2026-01-01&date_to=2026-12-31

# Status filter
?closed_flag=false    # Active calls only
?closed_flag=true     # Closed calls only

# Other filters
?jurisdiction=MADISON
?agency_type=Police
?priority=1
```

### Sorting

```bash
?sort=create_datetime&order=desc
```

- `sort` - Field to sort by
- `order` - `asc` or `desc`

## Examples

### List Active Calls

```bash
curl "http://localhost:8080/api/calls?closed_flag=false&per_page=10"
```

### Get Call Details

```bash
curl "http://localhost:8080/api/calls/123"
```

### Search by Location

```bash
curl "http://localhost:8080/api/search/location?lat=40.1184&lng=-85.69&radius=5"
```

### Get Statistics

```bash
curl "http://localhost:8080/api/stats?date_from=2026-01-01"
```

## Response Examples

### Call List Response

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 123,
        "call_id": "260_2026020112345678",
        "call_number": "260",
        "create_datetime": "2026-02-01T12:34:56Z",
        "closed_flag": false,
        "location": {
          "full_address": "123 Main St, Anderson, IN"
        }
      }
    ],
    "pagination": {
      "total": 150,
      "per_page": 30,
      "current_page": 1,
      "total_pages": 5
    }
  }
}
```

### Statistics Response

```json
{
  "success": true,
  "data": {
    "total_calls": 1250,
    "active_calls": 45,
    "closed_calls": 1205,
    "average_response_time_minutes": 8.5,
    "calls_by_type": {
      "Traffic Stop": 320,
      "Domestic": 180,
      "Medical": 150
    }
  }
}
```

---

**Version:** 1.1.0 | **Last Updated:** 2026-02-15
