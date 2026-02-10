#!/bin/bash

# Scout Startup Script

echo "========================================"
echo "           Scout - Starting             "
echo "========================================"
echo ""

# Check for PHP 8+
echo "Checking PHP version..."
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null)

if [ -z "$PHP_VERSION" ]; then
    echo "❌ Error: PHP is not installed!"
    echo "Please install PHP 8.0 or higher."
    exit 1
fi

if [ "$PHP_VERSION" -lt 8 ]; then
    echo "❌ Error: PHP version $PHP_VERSION is too old!"
    echo "Please upgrade to PHP 8.0 or higher."
    exit 1
fi

echo "✅ PHP version check passed (PHP $PHP_VERSION)"
echo ""

# Create db directory if missing
DB_DIR="$(dirname "$0")/../../db"
if [ ! -d "$DB_DIR" ]; then
    echo "Creating database directory..."
    mkdir -p "$DB_DIR"
    echo "✅ Database directory created"
else
    echo "✅ Database directory exists"
fi
echo ""

# Get the project root directory (two levels up from this script)
PROJECT_ROOT="$(dirname "$0")/../.."
PUBLIC_DIR="$PROJECT_ROOT/public"

# Start PHP built-in server
echo "Starting PHP development server..."
echo "----------------------------------------"
echo "📍 URL: http://localhost:8080"
echo "📂 Document root: $PUBLIC_DIR"
echo "----------------------------------------"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

# Create router file for PHP built-in server
ROUTER_FILE="$PROJECT_ROOT/router.php"

# Start the server
cd "$PROJECT_ROOT"
php -S localhost:8080 -t public/ router.php