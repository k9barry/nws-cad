# NWS CAD API Controllers

Comprehensive PHP API controllers for the NWS CAD database API.

## Controllers Overview

### 1. CallsController
**Location:** `/src/Api/Controllers/CallsController.php`

Handles all call-related endpoints with full support for pagination, filtering, and sorting.

#### Endpoints

##### `GET /api/calls` - List Calls
List all calls with pagination, filtering, and sorting support.

**Query Parameters:**
- `page` (int) - Page number (default: 1)
- `per_page` (int) - Items per page (default: 30, max: 100)
- `sort` - Sort field: `call_id`, `call_number`, `create_datetime`, `close_datetime`, `nature_of_call`
- `order` - Sort order: `asc`, `desc` (default: desc)
- `status` - Filter by agency status
- `agency_type` - Filter by agency type (Police, Fire, EMS)
- `closed_flag` - Filter by closed status (true/false)
- `canceled_flag` - Filter by canceled status (true/false)
- `call_number` - Filter by exact call number
- `created_by` - Filter by creator username
- `date_from` - Filter calls created after this date (ISO 8601)
- `date_to` - Filter calls created before this date (ISO 8601)
- `nature_of_call` - Search in nature of call (partial match)

**Example:**
```
GET /api/calls?page=1&per_page=50&agency_type=Police&closed_flag=false&date_from=2022-01-01
```

##### `GET /api/calls/:id` - Get Call Details
Get complete details for a single call including location, agency contexts, and incidents.

**Response includes:**
- Complete call information
- Location details with coordinates
- Agency contexts
- Incidents
- Counts of units, narratives, and persons

##### `GET /api/calls/:id/units` - Get Call Units
Get all units dispatched to a specific call.

**Returns:** Array of units with timestamps, personnel count, and log count.

##### `GET /api/calls/:id/narratives` - Get Call Narratives
Get all narrative entries for a call (paginated).

##### `GET /api/calls/:id/persons` - Get Call Persons
Get all persons associated with a call.

##### `GET /api/calls/:id/location` - Get Call Location
Get detailed location information for a call.

##### `GET /api/calls/:id/incidents` - Get Call Incidents
Get all incidents associated with a call.

##### `GET /api/calls/:id/dispositions` - Get Call Dispositions
Get disposition information for a call.

---

### 2. UnitsController
**Location:** `/src/Api/Controllers/UnitsController.php`

Handles unit-related endpoints including personnel, logs, and dispositions.

#### Endpoints

##### `GET /api/units` - List Units
List all units with filtering support.

**Query Parameters:**
- `page`, `per_page` - Pagination
- `sort` - Sort field: `unit_number`, `unit_type`, `assigned_datetime`, `clear_datetime`
- `order` - Sort order
- `unit_number` - Filter by unit number
- `unit_type` - Filter by unit type
- `jurisdiction` - Filter by jurisdiction
- `is_primary` - Filter primary units (true/false)
- `call_id` - Filter by specific call
- `date_from`, `date_to` - Date range filter

**Example:**
```
GET /api/units?unit_type=Patrol&jurisdiction=APD&date_from=2022-01-01
```

##### `GET /api/units/:id` - Get Unit Details
Get complete unit details including call information and timestamps.

##### `GET /api/units/:id/logs` - Get Unit Logs
Get status change history for a unit (chronological).

##### `GET /api/units/:id/personnel` - Get Unit Personnel
Get all personnel assigned to a unit.

**Returns:** Formatted personnel with full names and role information.

##### `GET /api/units/:id/dispositions` - Get Unit Dispositions
Get disposition information for a unit.

---

### 3. SearchController
**Location:** `/src/Api/Controllers/SearchController.php`

Handles advanced search capabilities across the database.

#### Endpoints

##### `GET /api/search/calls` - Search Calls
Comprehensive call search with multiple criteria.

**Query Parameters:**
- `q` or `search` - General search (searches call number, nature, caller name/phone, address)
- `call_number` - Exact call number
- `nature_of_call` - Search in nature (partial)
- `caller_name` - Search caller name (partial)
- `caller_phone` - Search caller phone (partial)
- `address` - Search address (partial)
- `city` - Filter by city
- `agency_type`, `call_type`, `status` - Agency filters
- `unit_number` - Find calls with specific unit
- `incident_number` - Find calls with specific incident
- `person_name` - Find calls with specific person
- `date_from`, `date_to` - Date range
- `closed_flag`, `canceled_flag` - Status filters

**Example:**
```
GET /api/search/calls?q=accident&agency_type=Police&date_from=2022-01-01
GET /api/search/calls?unit_number=103&date_from=2022-01-01
```

##### `GET /api/search/location` - Search by Location
Search calls by location criteria including radius search.

**Query Parameters:**
- `address` - Search address (partial)
- `city`, `state`, `zip` - Location filters
- `police_beat`, `ems_district`, `fire_quadrant` - Response zone filters
- `lat`, `lng` - Coordinates for radius search
- `radius` - Search radius in kilometers (default: 1.0, requires lat/lng)
- `date_from`, `date_to` - Date range

**Example:**
```
GET /api/search/location?city=Oklahoma City&police_beat=123
GET /api/search/location?lat=35.4676&lng=-97.5164&radius=5
```

##### `GET /api/search/units` - Search Units
Search units by various criteria.

**Query Parameters:**
- `q` or `search` - Search unit number
- `unit_number`, `unit_type`, `jurisdiction` - Unit filters
- `personnel_name` - Search by personnel name (partial)
- `personnel_id` - Search by employee ID
- `shield_number` - Search by badge/shield number
- `date_from`, `date_to` - Date range

**Example:**
```
GET /api/search/units?personnel_name=Smith&date_from=2022-01-01
```

---

### 4. StatsController
**Location:** `/src/Api/Controllers/StatsController.php`

Provides comprehensive statistics and analytics.

#### Endpoints

##### `GET /api/stats/calls` - Call Statistics
Get aggregated call statistics.

**Query Parameters:**
- `date_from`, `date_to` - Date range
- `agency_type` - Filter by agency
- `jurisdiction` - Filter by jurisdiction

**Returns:**
- Total calls
- Calls by status (Open, Closed, Canceled)
- Calls by agency type
- Top 10 call types
- Calls by hour of day
- Calls by day of week
- Daily call volume
- Average call duration

**Example:**
```json
{
  "success": true,
  "data": {
    "total_calls": 1523,
    "by_status": {
      "Open": 45,
      "Closed": 1450,
      "Canceled": 28
    },
    "by_agency_type": {
      "Police": 856,
      "Fire": 423,
      "EMS": 244
    },
    "by_hour": {
      "0": 23,
      "1": 15,
      ...
    },
    "average_duration_minutes": 42.35
  }
}
```

##### `GET /api/stats/units` - Unit Statistics
Get unit utilization and performance statistics.

**Query Parameters:**
- `date_from`, `date_to` - Date range
- `unit_type` - Filter by unit type
- `jurisdiction` - Filter by jurisdiction

**Returns:**
- Total dispatches
- Dispatches by unit type
- Dispatches by jurisdiction
- Top 20 most active units
- Unit role distribution (Primary vs Backup)
- Average response times
- Average enroute times
- Average on-scene times

##### `GET /api/stats/response-times` - Response Time Analytics
Get detailed response time analysis.

**Query Parameters:**
- `date_from`, `date_to` - Date range
- `agency_type` - Filter by agency
- `unit_type` - Filter by unit type
- `jurisdiction` - Filter by jurisdiction
- `priority` - Filter by priority level

**Returns:**
- Overall statistics (avg, min, max, stddev)
- Response time percentiles (50th, 75th, 90th, 95th)
- Response times by unit type
- Response times by jurisdiction
- Time breakdown:
  - Call to assigned
  - Assigned to dispatch
  - Dispatch to enroute
  - Enroute to arrive

**Example:**
```json
{
  "success": true,
  "data": {
    "overall": {
      "total_responses": 1234,
      "average_minutes": 8.45,
      "min_minutes": 2.1,
      "max_minutes": 45.3,
      "stddev_minutes": 5.2
    },
    "percentiles": {
      "50th": 7.5,
      "75th": 11.2,
      "90th": 15.8,
      "95th": 20.3
    },
    "time_breakdown": {
      "call_to_assigned_seconds": 45.2,
      "assigned_to_dispatch_seconds": 32.8,
      "dispatch_to_enroute_seconds": 58.3,
      "enroute_to_arrive_minutes": 6.5
    }
  }
}
```

---

## Common Response Formats

### Success Response (Single Item)
```json
{
  "success": true,
  "data": { ... }
}
```

### Success Response (Paginated)
```json
{
  "success": true,
  "data": {
    "items": [ ... ],
    "pagination": {
      "total": 1234,
      "per_page": 30,
      "current_page": 1,
      "total_pages": 42,
      "has_more": true
    }
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message"
}
```

### Not Found Response
```json
{
  "success": false,
  "error": "Resource not found"
}
```

---

## Technical Details

### Security Features
- All queries use prepared statements to prevent SQL injection
- Input validation on all parameters
- Proper escaping of user input
- Pagination limits enforced (max 100 items per page)

### Database Support
- Compatible with both MySQL and PostgreSQL
- Uses PDO for database abstraction
- Optimized queries with proper JOINs and indexes

### Performance Optimizations
- Efficient GROUP BY queries
- Limited result sets with pagination
- Index-aware sorting
- Aggregate queries for statistics

### Error Handling
- Try-catch blocks on all endpoints
- Proper HTTP status codes
- Descriptive error messages
- Graceful handling of missing resources

---

## Usage Examples

### Get Recent Open Police Calls
```
GET /api/calls?agency_type=Police&closed_flag=false&date_from=2022-12-01&sort=create_datetime&order=desc
```

### Search for Traffic Accidents
```
GET /api/search/calls?nature_of_call=accident&agency_type=Police
```

### Find Calls in Specific Area
```
GET /api/search/location?police_beat=123&date_from=2022-01-01&date_to=2022-12-31
```

### Get Response Time Stats for Fire Department
```
GET /api/stats/response-times?agency_type=Fire&date_from=2022-01-01
```

### Find All Dispatches for a Unit
```
GET /api/units?unit_number=103&date_from=2022-01-01
```

---

## Integration Requirements

### Required Classes
- `NwsCad\Database` - Database connection manager
- `NwsCad\Api\Request` - HTTP request parser
- `NwsCad\Api\Response` - HTTP response formatter

### Database Schema
Controllers work with the following tables:
- `calls` - Main call records
- `agency_contexts` - Agency-specific data
- `locations` - Location details
- `incidents` - Incident records
- `units` - Unit dispatches
- `unit_personnel` - Personnel assignments
- `unit_logs` - Unit status logs
- `narratives` - Call narratives
- `persons` - Person records
- `vehicles` - Vehicle records
- `call_dispositions` - Call disposition data
- `unit_dispositions` - Unit disposition data
- `processed_files` - File processing metadata

---

## Notes

- All datetime fields should be in ISO 8601 format
- Coordinate search uses Haversine formula for accurate distance calculation
- Response time calculations exclude records with NULL timestamps
- Statistics queries may be resource-intensive on large datasets - use date ranges to limit scope
- GROUP_CONCAT is used for aggregating multiple values (MySQL/MariaDB)
