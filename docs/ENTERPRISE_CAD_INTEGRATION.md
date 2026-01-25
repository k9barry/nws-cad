# Enterprise CAD Standard Implementation Guide

## Overview

This document outlines how to integrate the Enterprise CAD Standard Exporter schema with the NWS CAD system.

## Typical Enterprise CAD XML Structure

Based on common CAD systems, the XML structure typically includes:

### Call/Incident Information
- Call Number (unique identifier)
- Call DateTime
- Nature/Type of Call
- Priority Level
- Status
- Disposition

### Location Information
- Address (Street, City, State, Zip)
- Coordinates (Latitude, Longitude)
- Location Type
- Cross Streets
- Map Page/Grid

### Units/Resources
- Unit ID
- Unit Type
- Dispatch Time
- Arrival Time
- Clear Time
- Status

### Personnel
- Name
- Badge/ID Number
- Role

### Comments/Narrative
- Timestamp
- User
- Comment Text

## Integration Steps

1. **Place Sample Files**: Add the PDF and XML files to:
   - `docs/Enterprise_CAD_Standard_Exporter_Fact_Sheet.pdf`
   - `samples/260_2022120307164448.xml`
   - `samples/261_2022120307162437.xml`
   - `samples/285_2022120307195970.xml`
   - `samples/287_2022120307210477.xml`

2. **Review Schema**: Examine the PDF to understand the exact field mappings

3. **Update Database Schema**: Modify `database/mysql/init.sql` and `database/postgres/init.sql`

4. **Update Parser**: Modify `src/XmlParser.php` to handle the Enterprise CAD XML structure

5. **Test**: Run the sample files through the system

## Database Schema Extensions

The current schema will need to be extended to include additional tables:

- `incidents` - Main incident/call information
- `incident_units` - Units assigned to incidents
- `incident_personnel` - Personnel involved
- `incident_comments` - Comments and updates
- `locations` - Location details

## Next Steps

Once the files are available in the repository:
1. Parse the PDF to extract exact field definitions
2. Analyze sample XML files to understand structure
3. Create comprehensive database schema
4. Update XML parser with specific field mappings
5. Test with all 4 sample files
