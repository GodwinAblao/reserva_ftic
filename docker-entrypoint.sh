#!/bin/bash
set -e

# Run database migrations
echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true

# Clear and warm up cache
echo "Clearing cache..."
php bin/console cache:clear --env=prod || true

# Install assets
echo "Installing assets..."
php bin/console assets:install --env=prod || true

# Start Apache
echo "Starting Apache..."
exec apache2-foreground
