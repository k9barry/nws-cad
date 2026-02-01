#!/bin/bash

# NWS CAD Database Restore Script
# Restores database from a backup file

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
else
    echo -e "${RED}✗ .env file not found${NC}"
    exit 1
fi

BACKUP_DIR="${BACKUP_DIR:-./backups}"

echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  NWS CAD Database Restore${NC}"
echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo ""

# Check if backup directory exists
if [ ! -d "$BACKUP_DIR" ]; then
    echo -e "${RED}✗ Backup directory not found: $BACKUP_DIR${NC}"
    exit 1
fi

# List available backups
echo -e "${YELLOW}Available backups:${NC}"
echo "─────────────────────────────────────────────────"

if [ "$(ls -A $BACKUP_DIR/*.sql.gz 2>/dev/null)" ]; then
    BACKUPS=($(ls -t "$BACKUP_DIR"/*.sql.gz))
    
    for i in "${!BACKUPS[@]}"; do
        SIZE=$(du -h "${BACKUPS[$i]}" | cut -f1)
        DATE=$(stat -c %y "${BACKUPS[$i]}" | cut -d' ' -f1,2 | cut -d'.' -f1)
        echo "$((i+1)). $(basename ${BACKUPS[$i]}) - $SIZE - $DATE"
    done
else
    echo -e "${RED}No backup files found${NC}"
    exit 1
fi

echo ""
read -r -p "Enter the number of the backup to restore (or 'q' to quit): " selection

if [ "$selection" = "q" ] || [ "$selection" = "Q" ]; then
    echo "Cancelled"
    exit 0
fi

# Validate selection
if ! [[ "$selection" =~ ^[0-9]+$ ]] || [ "$selection" -lt 1 ] || [ "$selection" -gt "${#BACKUPS[@]}" ]; then
    echo -e "${RED}✗ Invalid selection${NC}"
    exit 1
fi

BACKUP_FILE="${BACKUPS[$((selection-1))]}"
echo ""
echo -e "${YELLOW}Selected backup: $(basename $BACKUP_FILE)${NC}"

# Determine database type from filename
if [[ "$BACKUP_FILE" == *"mysql"* ]]; then
    DB_TYPE="mysql"
    CONTAINER="nws-cad-mysql"
elif [[ "$BACKUP_FILE" == *"postgres"* ]]; then
    DB_TYPE="postgres"
    CONTAINER="nws-cad-postgres"
else
    echo -e "${RED}✗ Cannot determine database type from filename${NC}"
    exit 1
fi

# Warning
echo ""
echo -e "${RED}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${RED}║  ⚠️  WARNING: DATABASE RESTORE  ⚠️                         ║${NC}"
echo -e "${RED}║                                                            ║${NC}"
echo -e "${RED}║  This will REPLACE the current database with the backup!  ║${NC}"
echo -e "${RED}║  All current data will be LOST!                           ║${NC}"
echo -e "${RED}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

read -r -p "Type 'RESTORE' to confirm: " confirm

if [ "$confirm" != "RESTORE" ]; then
    echo "Cancelled"
    exit 0
fi

# Check if container is running
if ! docker ps | grep -q "$CONTAINER"; then
    echo -e "${RED}✗ Database container is not running${NC}"
    exit 1
fi

# Create a backup of current database before restore
echo ""
echo -e "${YELLOW}Creating safety backup of current database...${NC}"
SAFETY_BACKUP="$BACKUP_DIR/pre_restore_$(date +%Y%m%d_%H%M%S).sql"

if [ "$DB_TYPE" = "mysql" ]; then
    docker exec "$CONTAINER" mysqldump \
        -u "${MYSQL_USER:-nws_user}" \
        -p"${MYSQL_PASSWORD}" \
        nws_cad > "$SAFETY_BACKUP" 2>/dev/null
else
    docker exec "$CONTAINER" pg_dump \
        -U "${POSTGRES_USER:-nws_user}" \
        "${POSTGRES_DB:-nws_cad}" > "$SAFETY_BACKUP" 2>/dev/null
fi

gzip "$SAFETY_BACKUP"
echo -e "${GREEN}✓ Safety backup created: ${SAFETY_BACKUP}.gz${NC}"

# Restore database
echo ""
echo -e "${YELLOW}Restoring database from backup...${NC}"

if [ "$DB_TYPE" = "mysql" ]; then
    # Decompress and restore MySQL
    gunzip -c "$BACKUP_FILE" | docker exec -i "$CONTAINER" mysql \
        -u "${MYSQL_USER:-nws_user}" \
        -p"${MYSQL_PASSWORD}" \
        nws_cad
else
    # Decompress and restore PostgreSQL
    gunzip -c "$BACKUP_FILE" | docker exec -i "$CONTAINER" psql \
        -U "${POSTGRES_USER:-nws_user}" \
        -d "${POSTGRES_DB:-nws_cad}"
fi

echo ""
echo -e "${GREEN}════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Database restored successfully${NC}"
echo -e "${GREEN}════════════════════════════════════════════════${NC}"
echo ""
echo "Restored from: $(basename $BACKUP_FILE)"
echo "Safety backup: ${SAFETY_BACKUP}.gz"
echo ""
