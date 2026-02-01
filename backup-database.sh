#!/bin/bash

# NWS CAD Database Backup Script
# Creates timestamped backups of MySQL and PostgreSQL databases

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

# Configuration
BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p "$BACKUP_DIR"

echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  NWS CAD Database Backup${NC}"
echo -e "${BLUE}════════════════════════════════════════════════${NC}"
echo ""

# Function to backup MySQL
backup_mysql() {
    echo -e "${YELLOW}Backing up MySQL database...${NC}"
    
    if ! docker ps | grep -q nws-cad-mysql; then
        echo -e "${RED}✗ MySQL container is not running${NC}"
        return 1
    fi
    
    MYSQL_BACKUP_FILE="$BACKUP_DIR/mysql_nws_cad_${DATE}.sql"
    
    if docker exec nws-cad-mysql mysqldump \
        -u "${MYSQL_USER:-nws_user}" \
        -p"${MYSQL_PASSWORD}" \
        nws_cad \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        > "$MYSQL_BACKUP_FILE" 2>/dev/null; then
        
        # Compress the backup
        gzip "$MYSQL_BACKUP_FILE"
        MYSQL_BACKUP_FILE="${MYSQL_BACKUP_FILE}.gz"
        
        SIZE=$(du -h "$MYSQL_BACKUP_FILE" | cut -f1)
        echo -e "${GREEN}✓ MySQL backup created: $MYSQL_BACKUP_FILE ($SIZE)${NC}"
        return 0
    else
        echo -e "${RED}✗ MySQL backup failed${NC}"
        return 1
    fi
}

# Function to backup PostgreSQL
backup_postgres() {
    echo -e "${YELLOW}Backing up PostgreSQL database...${NC}"
    
    if ! docker ps | grep -q nws-cad-postgres; then
        echo -e "${YELLOW}⚠ PostgreSQL container is not running (skipping)${NC}"
        return 0
    fi
    
    POSTGRES_BACKUP_FILE="$BACKUP_DIR/postgres_nws_cad_${DATE}.sql"
    
    if docker exec nws-cad-postgres pg_dump \
        -U "${POSTGRES_USER:-nws_user}" \
        "${POSTGRES_DB:-nws_cad}" \
        > "$POSTGRES_BACKUP_FILE" 2>/dev/null; then
        
        # Compress the backup
        gzip "$POSTGRES_BACKUP_FILE"
        POSTGRES_BACKUP_FILE="${POSTGRES_BACKUP_FILE}.gz"
        
        SIZE=$(du -h "$POSTGRES_BACKUP_FILE" | cut -f1)
        echo -e "${GREEN}✓ PostgreSQL backup created: $POSTGRES_BACKUP_FILE ($SIZE)${NC}"
        return 0
    else
        echo -e "${RED}✗ PostgreSQL backup failed${NC}"
        return 1
    fi
}

# Function to clean old backups
cleanup_old_backups() {
    echo ""
    echo -e "${YELLOW}Cleaning up backups older than ${RETENTION_DAYS} days...${NC}"
    
    OLD_COUNT=$(find "$BACKUP_DIR" -name "*.sql.gz" -type f -mtime +${RETENTION_DAYS} 2>/dev/null | wc -l)
    
    if [ "$OLD_COUNT" -gt 0 ]; then
        find "$BACKUP_DIR" -name "*.sql.gz" -type f -mtime +${RETENTION_DAYS} -delete
        echo -e "${GREEN}✓ Deleted $OLD_COUNT old backup(s)${NC}"
    else
        echo -e "${GREEN}✓ No old backups to delete${NC}"
    fi
}

# Function to list recent backups
list_backups() {
    echo ""
    echo -e "${BLUE}Recent backups:${NC}"
    echo "─────────────────────────────────────────────────"
    
    if [ -d "$BACKUP_DIR" ] && [ "$(ls -A $BACKUP_DIR/*.sql.gz 2>/dev/null)" ]; then
        ls -lh "$BACKUP_DIR"/*.sql.gz | tail -10 | awk '{print $9, "("$5")"}'
    else
        echo "No backups found"
    fi
    echo ""
}

# Main execution
BACKUP_SUCCESS=true

# Backup MySQL
if ! backup_mysql; then
    BACKUP_SUCCESS=false
fi

# Backup PostgreSQL (optional)
if ! backup_postgres; then
    # PostgreSQL backup failure is not critical
    true
fi

# Cleanup old backups
cleanup_old_backups

# List recent backups
list_backups

# Summary
echo -e "${BLUE}════════════════════════════════════════════════${NC}"
if [ "$BACKUP_SUCCESS" = true ]; then
    echo -e "${GREEN}✅ Backup completed successfully${NC}"
    exit 0
else
    echo -e "${RED}❌ Backup completed with errors${NC}"
    exit 1
fi
