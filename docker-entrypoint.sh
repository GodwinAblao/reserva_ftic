#!/bin/bash
set -e

# Railway injects PORT (default 8080); Apache image listens on 80 by default
if [ -n "${PORT:-}" ] && [ "${PORT}" != "80" ]; then
  echo "Configuring Apache to listen on port ${PORT}..."
  sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
  sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
fi

# PostgreSQL (Railway/Render): MySQL migrations fail — bootstrap schema once if empty
echo "Running database setup..."
if echo "${DATABASE_URL:-}" | grep -q '^postgresql'; then
  TABLE_COUNT=$(php bin/console dbal:run-sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'" --env=prod -q 2>/dev/null | tr -d '[:space:]' || echo "0")
  if [ "${TABLE_COUNT:-0}" -lt 5 ]; then
    echo "PostgreSQL: initializing schema (first deploy)..."
    php bin/console doctrine:schema:create --env=prod --no-interaction || true
    php bin/console doctrine:migrations:sync-metadata-storage --no-interaction --env=prod || true
    php bin/console doctrine:migrations:version --add --all --no-interaction --env=prod || true
  else
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true
  fi
else
  php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true
fi

# Clear and warm up cache
echo "Clearing cache..."
php bin/console cache:clear --env=prod || true

# Install assets / importmap (in case build step was skipped)
echo "Installing assets..."
php bin/console importmap:install --env=prod --no-interaction || true
php bin/console assets:install --env=prod --no-interaction || true

# Start Apache
echo "Starting Apache..."
exec apache2-foreground
