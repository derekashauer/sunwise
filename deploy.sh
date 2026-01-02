#!/bin/bash
# Sunwise Deployment Script
# Run this on the server after git pull

set -e

echo "🌱 Deploying Sunwise..."

# Navigate to project root
cd "$(dirname "$0")"

# Pull latest changes
echo "📥 Pulling latest changes..."
git pull origin main

# Install frontend dependencies and build
echo "📦 Building frontend..."
cd frontend
npm ci --production=false
npm run build

# Create public directory structure if needed
mkdir -p ../public_html

# Copy built files to public directory
echo "📂 Copying files to public_html..."
cp -r dist/* ../public_html/

# Copy API files
echo "🔧 Setting up API..."
cp -r ../api ../public_html/

# Copy htaccess
cp ../.htaccess ../public_html/

# Copy uploads directory structure
mkdir -p ../public_html/uploads/plants
cp ../uploads/plants/.gitkeep ../public_html/uploads/plants/ 2>/dev/null || true

# Create data directory
mkdir -p ../public_html/data

# Set permissions
echo "🔒 Setting permissions..."
chmod -R 755 ../public_html
chmod -R 777 ../public_html/uploads
chmod -R 777 ../public_html/data

# Run migrations (creates DB if not exists)
echo "🗄️ Running migrations..."
cd ../public_html/api
php -r "require 'config/config.php'; require 'config/database.php'; Database::runMigrations();"

echo "✅ Deployment complete!"
