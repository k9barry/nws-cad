#!/bin/bash

# Reset nws-cad repository to fresh clone state
# This script removes all generated files, dependencies, and Docker artifacts

set -e  # Exit on error

echo "🔄 Resetting nws-cad repository to fresh state..."
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get the script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Function to safely remove directory contents
safe_remove() {
    local dir=$1
    local keep_file=$2
    
    if [ -d "$dir" ]; then
        echo -e "${YELLOW}Cleaning $dir...${NC}"
        if [ -n "$keep_file" ] && [ -f "$dir/$keep_file" ]; then
            # Remove everything except .gitkeep
            find "$dir" -mindepth 1 ! -name "$keep_file" -delete
        else
            # Remove directory completely
            rm -rf "$dir"
        fi
        echo -e "${GREEN}✓ Cleaned $dir${NC}"
    fi
}

# Step 1: Stop and remove Docker containers
echo -e "${YELLOW}Step 1: Stopping Docker containers...${NC}"
if [ -f "docker-compose.yml" ]; then
    docker-compose down -v 2>/dev/null || true
    echo -e "${GREEN}✓ Docker containers stopped${NC}"
else
    echo -e "${YELLOW}⚠ No docker-compose.yml found${NC}"
fi
echo ""

# Step 2: Remove vendor directory
echo -e "${YELLOW}Step 2: Removing PHP dependencies...${NC}"
safe_remove "vendor"
echo ""

# Step 3: Clean environment file
echo -e "${YELLOW}Step 3: Handling environment file...${NC}"
if [ -f ".env" ]; then
    echo -e "${YELLOW}An existing .env file was found.${NC}"
    echo ""
    # Handle non-interactive mode (e.g., CI/CD pipelines) - default to keeping .env
    if [ ! -t 0 ]; then
        echo -e "${YELLOW}Non-interactive mode detected, keeping .env file by default${NC}"
        keep_env='y'
    else
        read -r -p "Do you want to keep the existing .env file? (y/n): " keep_env
    fi
    echo ""
    
    if [[ "$keep_env" =~ ^[Yy]$ ]]; then
        echo -e "${GREEN}✓ Keeping existing .env file${NC}"
    else
        rm -f .env
        echo -e "${GREEN}✓ Removed .env file${NC}"
    fi
else
    echo -e "${YELLOW}⚠ No .env file found${NC}"
fi
echo ""

# Step 4: Clean log files
echo -e "${YELLOW}Step 4: Cleaning log files...${NC}"
safe_remove "logs" ".gitkeep"
echo ""

# Step 5: Clean watch directory
echo -e "${YELLOW}Step 5: Cleaning watch directory...${NC}"
safe_remove "watch" ".gitkeep"
echo ""

# Step 6: Clean tmp directory
echo -e "${YELLOW}Step 6: Cleaning temporary files...${NC}"
safe_remove "tmp" ".gitkeep"
safe_remove "tests/tmp"
echo ""

# Step 7: Clean database data directories
echo -e "${YELLOW}Step 7: Cleaning database data...${NC}"
echo ""
echo -e "${RED}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${RED}║  ⚠️  WARNING: DATABASE DELETION  ⚠️                        ║${NC}"
echo -e "${RED}║                                                            ║${NC}"
echo -e "${RED}║  This will PERMANENTLY DELETE ALL DATABASE DATA including: ║${NC}"
echo -e "${RED}║  • All calls, incidents, units, and narratives            ║${NC}"
echo -e "${RED}║  • All processed file history                             ║${NC}"
echo -e "${RED}║  • All historical records                                 ║${NC}"
echo -e "${RED}║                                                            ║${NC}"
echo -e "${RED}║  THIS CANNOT BE UNDONE WITHOUT A BACKUP!                  ║${NC}"
echo -e "${RED}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Handle non-interactive mode - default to NOT deleting database
if [ ! -t 0 ]; then
    echo -e "${YELLOW}Non-interactive mode detected, SKIPPING database cleanup for safety${NC}"
    skip_db='y'
else
    read -r -p "Do you want to DELETE all database data? Type 'DELETE' to confirm (or anything else to skip): " confirm_delete
    echo ""
    
    if [ "$confirm_delete" = "DELETE" ]; then
        skip_db='n'
        echo -e "${YELLOW}Creating backup before deletion...${NC}"
        BACKUP_DIR="backups"
        mkdir -p "$BACKUP_DIR"
        BACKUP_FILE="$BACKUP_DIR/nws_cad_$(date +%Y%m%d_%H%M%S).sql"
        
        # Try to create backup if MySQL is running
        if docker ps | grep -q nws-cad-mysql; then
            if docker exec nws-cad-mysql mysqldump -u nws_user -p'!BmgoQL4Fm4JiyJSoRnG' nws_cad > "$BACKUP_FILE" 2>/dev/null; then
                echo -e "${GREEN}✓ Backup created: $BACKUP_FILE${NC}"
            else
                echo -e "${YELLOW}⚠ Could not create backup (MySQL may not be running)${NC}"
            fi
        else
            echo -e "${YELLOW}⚠ MySQL container not running, skipping backup${NC}"
        fi
    else
        skip_db='y'
    fi
fi

if [[ "$skip_db" =~ ^[Yy]$ ]]; then
    echo -e "${GREEN}✓ Skipping database cleanup (data preserved)${NC}"
else
    safe_remove "data/mysql" ".gitkeep"
    safe_remove "data/postgres" ".gitkeep"
    safe_remove "data/dbeaver" ".gitkeep"
    echo -e "${RED}✓ Database data deleted${NC}"
fi
echo ""

# Step 8: Remove test artifacts
echo -e "${YELLOW}Step 8: Cleaning test artifacts...${NC}"
safe_remove "coverage"
safe_remove ".phpunit.cache"
if [ -f ".phpunit.result.cache" ]; then
    rm -f .phpunit.result.cache
    echo -e "${GREEN}✓ Removed .phpunit.result.cache${NC}"
fi
echo ""

# Step 9: Remove IDE and OS files
echo -e "${YELLOW}Step 9: Cleaning IDE and OS files...${NC}"
safe_remove ".idea"
safe_remove ".vscode"
find . -type f \( -name "*.swp" -o -name "*.swo" -o -name "*~" -o -name ".DS_Store" -o -name "Thumbs.db" \) -delete 2>/dev/null || true
echo -e "${GREEN}✓ Cleaned IDE and OS files${NC}"
echo ""

# Step 10: Remove Docker volumes (optional - uncomment if needed)
echo -e "${YELLOW}Step 10: Docker cleanup...${NC}"
echo -e "${YELLOW}Removing dangling Docker volumes...${NC}"
docker volume prune -f 2>/dev/null || true
echo -e "${GREEN}✓ Docker volumes cleaned${NC}"
echo ""

# Step 11: Recreate .gitkeep files if needed
echo -e "${YELLOW}Step 11: Ensuring .gitkeep files exist...${NC}"
for dir in logs watch tmp data/mysql data/postgres data/dbeaver; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
    fi
    if [ ! -f "$dir/.gitkeep" ]; then
        touch "$dir/.gitkeep"
        echo -e "${GREEN}✓ Created $dir/.gitkeep${NC}"
    fi
done
echo ""

# Summary
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}✅ Repository reset complete!${NC}"
echo -e "${GREEN}================================${NC}"
echo ""
echo "Your repository is now in a fresh state, as if just cloned."
echo ""
echo "Next steps:"
echo "  1. Copy .env.example to .env and configure it"
echo "  2. Run: composer install"
echo "  3. Run: ./setup.sh (if applicable)"
echo "  4. Run: docker-compose up -d"
echo ""
