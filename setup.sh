#!/bin/bash

# NWS CAD System Setup Script
# This script helps with initial setup and common tasks

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}  NWS CAD System Setup${NC}"
echo -e "${GREEN}======================================${NC}"
echo ""

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed${NC}"
    echo "Please install Docker from https://docs.docker.com/get-docker/"
    exit 1sh 
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo -e "${RED}Error: Docker Compose is not installed${NC}"
    echo "Please install Docker Compose from https://docs.docker.com/compose/install/"
    exit 1
fi

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo -e "${YELLOW}Creating .env file from .env.example...${NC}"
    cp .env.example .env
    
    # Generate random passwords
    MYSQL_PASS=$(openssl rand -base64 16 2>/dev/null || cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 16 | head -n 1)
    POSTGRES_PASS=$(openssl rand -base64 16 2>/dev/null || cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 16 | head -n 1)
    MYSQL_ROOT_PASS=$(openssl rand -base64 16 2>/dev/null || cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 16 | head -n 1)
    
    # Replace passwords in .env
    sed -i.bak "s/MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}/" .env
    sed -i.bak "s/MYSQL_PASSWORD=.*/MYSQL_PASSWORD=${MYSQL_PASS}/" .env
    sed -i.bak "s/POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=${POSTGRES_PASS}/" .env
    rm -f .env.bak
    
    echo -e "${GREEN}✓ .env file created with random passwords${NC}"
else
    echo -e "${GREEN}✓ .env file already exists${NC}"
fi

# Ask user for database preference
echo ""
echo "Which database would you like to use?"
echo "1) MySQL (default)"
echo "2) PostgreSQL"
read -p "Enter choice [1-2]: " db_choice

if [ "$db_choice" == "2" ]; then
    sed -i.bak "s/DB_TYPE=.*/DB_TYPE=pgsql/" .env
    rm -f .env.bak
    echo -e "${GREEN}✓ Database set to PostgreSQL${NC}"
else
    sed -i.bak "s/DB_TYPE=.*/DB_TYPE=mysql/" .env
    rm -f .env.bak
    echo -e "${GREEN}✓ Database set to MySQL${NC}"
fi

# Create necessary directories
echo ""
echo -e "${YELLOW}Creating directories...${NC}"
mkdir -p watch/processed watch/failed logs data/mysql data/postgres tmp
echo -e "${GREEN}✓ Directories created${NC}"

# Build and start containers
echo ""
echo -e "${YELLOW}Building and starting Docker containers...${NC}"
echo "This may take a few minutes on first run..."

if docker compose version &> /dev/null; then
    docker compose up -d --build
else
    docker-compose up -d --build
fi

echo -e "${GREEN}✓ Containers started${NC}"

# Wait for database to be ready
echo ""
echo -e "${YELLOW}Waiting for database to be ready...${NC}"
sleep 10

# Show status
echo ""
echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}  Setup Complete!${NC}"
echo -e "${GREEN}======================================${NC}"
echo ""
echo "System Status:"

if docker compose version &> /dev/null; then
    docker compose ps
else
    docker-compose ps
fi

echo ""
echo -e "${GREEN}Next Steps:${NC}"
echo ""
echo "1. View logs:"
if docker compose version &> /dev/null; then
    echo "   docker compose logs -f app"
else
    echo "   docker-compose logs -f app"
fi
echo ""
echo "2. Add XML files to watch folder:"
echo "   cp config/example-cad-events.xml watch/"
echo ""
echo "3. Check processed files:"
echo "   ls -la watch/processed/"
echo ""
echo "4. Stop services:"
if docker compose version &> /dev/null; then
    echo "   docker compose down"
else
    echo "   docker-compose down"
fi
echo ""
echo -e "${YELLOW}For more information, see README.md${NC}"
