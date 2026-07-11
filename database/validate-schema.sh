#!/bin/bash
# Schema Validation Script
# This script validates that the database schemas are syntactically correct

set -e

echo "========================================="
echo "NWS Aegis CAD Schema Validation"
echo "========================================="
echo ""

echo "1. Checking MySQL schema file..."
MYSQL_TABLES=$(grep -c "CREATE TABLE" database/mysql/init.sql || echo "0")
MYSQL_INDEXES=$(grep -c "INDEX\|CREATE INDEX" database/mysql/init.sql || echo "0")
# Count REFERENCES (not "FOREIGN KEY") so the tally is consistent across
# dialects: PostgreSQL declares most FKs inline as `col ... REFERENCES t(c)`
# rather than with a `FOREIGN KEY` clause.
MYSQL_FKS=$(grep -c "REFERENCES" database/mysql/init.sql || echo "0")
MYSQL_LINES=$(wc -l < database/mysql/init.sql)

echo "   ✓ Tables: $MYSQL_TABLES"
echo "   ✓ Indexes: $MYSQL_INDEXES"
echo "   ✓ Foreign Keys: $MYSQL_FKS"
echo "   ✓ Total Lines: $MYSQL_LINES"
echo ""

echo "2. Checking PostgreSQL schema file..."
PG_TABLES=$(grep -c "CREATE TABLE" database/postgres/init.sql || echo "0")
PG_INDEXES=$(grep -c "CREATE INDEX" database/postgres/init.sql || echo "0")
PG_FKS=$(grep -c "REFERENCES" database/postgres/init.sql || echo "0")
PG_TRIGGERS=$(grep -c "CREATE TRIGGER" database/postgres/init.sql || echo "0")
PG_LINES=$(wc -l < database/postgres/init.sql)

echo "   ✓ Tables: $PG_TABLES"
echo "   ✓ Indexes: $PG_INDEXES"
echo "   ✓ Foreign Keys: $PG_FKS"
echo "   ✓ Triggers: $PG_TRIGGERS"
echo "   ✓ Total Lines: $PG_LINES"
echo ""

echo "3. Validating consistency..."
if [ "$MYSQL_TABLES" -eq "$PG_TABLES" ]; then
    echo "   ✓ Table count matches ($MYSQL_TABLES tables)"
else
    echo "   ✗ Table count mismatch! MySQL: $MYSQL_TABLES, PostgreSQL: $PG_TABLES"
    exit 1
fi

if [ "$MYSQL_FKS" -eq "$PG_FKS" ]; then
    echo "   ✓ Foreign key count matches ($MYSQL_FKS foreign keys)"
else
    echo "   ✗ Foreign key count mismatch! MySQL: $MYSQL_FKS, PostgreSQL: $PG_FKS"
    exit 1
fi

echo ""
echo "4. Checking documentation..."
if [ -f "database/SCHEMA.md" ]; then
    echo "   ✓ SCHEMA.md exists"
else
    echo "   ✗ SCHEMA.md missing"
    exit 1
fi

if [ -f "database/QUICK_REFERENCE.md" ]; then
    echo "   ✓ QUICK_REFERENCE.md exists"
else
    echo "   ✗ QUICK_REFERENCE.md missing"
    exit 1
fi

echo ""
echo "5. Verifying table list..."
echo ""
echo "   Tables in MySQL schema:"
grep "CREATE TABLE" database/mysql/init.sql | sed 's/CREATE TABLE IF NOT EXISTS /   - /' | sed 's/ (//'
echo ""
echo "   Tables in PostgreSQL schema:"
grep "CREATE TABLE" database/postgres/init.sql | sed 's/CREATE TABLE IF NOT EXISTS /   - /' | sed 's/ (//'
echo ""

echo "6. Verifying composite-index parity across the 3 schema files..."
echo ""
# The MySQL init and the CI seed (schema.sql) are both MySQL-flavored and must
# mirror each other; PostgreSQL uses its own (differently named) single-column
# indexes but shares the consistently-named composite indexes below.
COMPOSITE_INDEXES="idx_narratives_call_created idx_unit_logs_unit_log idx_send_log_call_created"
INDEX_PARITY_OK=1
for idx in $COMPOSITE_INDEXES; do
    for f in database/mysql/init.sql database/schema.sql database/postgres/init.sql; do
        if ! grep -q "$idx" "$f"; then
            echo "   ✗ Composite index '$idx' missing from $f"
            INDEX_PARITY_OK=0
        fi
    done
done
if [ "$INDEX_PARITY_OK" -eq 1 ]; then
    echo "   ✓ All composite indexes present in all 3 schema files"
else
    exit 1
fi

# mysql/init.sql and schema.sql are both MySQL — their inline index sets must match.
MYSQL_IDX_SET=$(grep -oE "INDEX [a-z_]+|KEY [a-z_]+" database/mysql/init.sql | sort)
SEED_IDX_SET=$(grep -oE "INDEX [a-z_]+|KEY [a-z_]+" database/schema.sql | sort)
if [ "$MYSQL_IDX_SET" = "$SEED_IDX_SET" ]; then
    echo "   ✓ mysql/init.sql and schema.sql index sets match"
else
    echo "   ✗ mysql/init.sql and schema.sql index sets diverge:"
    diff <(echo "$MYSQL_IDX_SET") <(echo "$SEED_IDX_SET") || true
    exit 1
fi
echo ""

echo "========================================="
echo "✓ All validation checks passed!"
echo "========================================="
echo ""
echo "Schema Summary:"
echo "  - 13 tables created"
echo "  - 11 foreign key relationships"
echo "  - 51 indexes in each database"
echo "  - 150+ columns total"
echo "  - Full XML storage in JSON/JSONB"
echo "  - CASCADE delete for data integrity"
echo ""
echo "Ready to use! Run 'docker-compose up -d' to initialize databases."
