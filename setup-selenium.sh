#!/bin/bash
# Selenium Testing Quick Start Script for NWS CAD

set -e

echo "╔══════════════════════════════════════════════════════════════════╗"
echo "║          Selenium Testing Setup - NWS CAD                        ║"
echo "╚══════════════════════════════════════════════════════════════════╝"
echo ""

# Step 1: Install Panther
echo "📦 Step 1: Installing Symfony Panther..."
docker compose exec app composer require --dev symfony/panther
echo "✓ Panther installed"
echo ""

# Step 2: Create directory structure
echo "📁 Step 2: Creating test directories..."
docker compose exec app mkdir -p tests/Browser/Pages
docker compose exec app mkdir -p var/error-screenshots
docker compose exec app mkdir -p var/screenshots
echo "✓ Directories created"
echo ""

# Step 3: Update docker-compose.yml
echo "🐳 Step 3: Checking Docker Compose configuration..."
if grep -q "selenium:" docker-compose.yml; then
    echo "✓ Selenium service already configured"
else
    echo "⚠ Please add Selenium service to docker-compose.yml manually"
    echo "See SELENIUM_IMPLEMENTATION_GUIDE.md for configuration"
fi
echo ""

# Step 4: Start Selenium
echo "🚀 Step 4: Starting Selenium service..."
docker compose --profile testing up -d selenium
echo "✓ Selenium started (http://localhost:7900 for VNC viewer)"
echo ""

# Step 5: Verify setup
echo "✅ Step 5: Verifying setup..."
if docker compose ps selenium | grep -q "Up"; then
    echo "✓ Selenium container running"
else
    echo "✗ Selenium container not running"
    exit 1
fi
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🎉 Setup Complete!"
echo ""
echo "Next Steps:"
echo "1. Create test files in tests/Browser/"
echo "2. Run tests: docker compose exec app vendor/bin/phpunit --testsuite Browser"
echo "3. View browser: http://localhost:7900 (password: secret)"
echo ""
echo "Example test file: tests/Browser/FilterTest.php"
echo "See SELENIUM_IMPLEMENTATION_GUIDE.md for complete documentation"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
