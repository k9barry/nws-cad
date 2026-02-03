#!/bin/bash
# Apply performance optimization indexes to the database
# Run this script to add the new composite indexes

set -e

echo "=== NWS CAD Performance Index Application ==="
echo ""

# Load database configuration
if [ -f .env ]; then
    source .env
elif [ -f config/.env ]; then
    source config/.env
else
    echo "ERROR: .env file not found"
    exit 1
fi

# Detect database type
DB_TYPE=${DB_TYPE:-mysql}

echo "Database Type: $DB_TYPE"
echo "Applying performance indexes..."
echo ""

if [ "$DB_TYPE" = "mysql" ]; then
    # MySQL
    mysql -h"${DB_HOST:-localhost}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < database/performance-indexes.sql
    echo "✓ MySQL indexes applied successfully"
elif [ "$DB_TYPE" = "pgsql" ] || [ "$DB_TYPE" = "postgres" ]; then
    # PostgreSQL - need to convert MySQL syntax
    echo "Converting indexes for PostgreSQL..."
    cat database/performance-indexes.sql | \
        sed 's/CREATE INDEX IF NOT EXISTS/CREATE INDEX CONCURRENTLY IF NOT EXISTS/g' | \
        psql -h "${DB_HOST:-localhost}" -U "${DB_USER}" -d "${DB_NAME}"
    echo "✓ PostgreSQL indexes applied successfully"
else
    echo "ERROR: Unsupported database type: $DB_TYPE"
    exit 1
fi

echo ""
echo "=== Index Application Complete ==="
echo ""
echo "Performance improvements applied:"
echo "  - Composite indexes for common filter patterns"
echo "  - Covering indexes to reduce table lookups"
echo "  - Optimized indexes for GROUP BY operations"
echo ""
echo "Note: Large tables may take time to build indexes."
echo "You can monitor index creation with:"
if [ "$DB_TYPE" = "mysql" ]; then
    echo "  SHOW PROCESSLIST;"
else
    echo "  SELECT * FROM pg_stat_progress_create_index;"
fi
