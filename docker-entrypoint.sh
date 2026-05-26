#!/bin/bash
set -e

PORT="${PORT:-8080}"

configure_apache_port() {
  echo "Configuring Apache to listen on port ${PORT}..."
  grep -q "^ServerName " /etc/apache2/apache2.conf || echo "ServerName localhost" >> /etc/apache2/apache2.conf
  sed -i "s/^Listen .*/Listen 0.0.0.0:${PORT}/" /etc/apache2/ports.conf
  sed -i "s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
  if [ -f /etc/apache2/sites-enabled/000-default.conf ]; then
    sed -i "s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
  fi

  apache2ctl -t
}

configure_apache_port

# Run DB/cache setup after Apache starts (nohup survives exec)
if [ "${SKIP_DB_SETUP:-0}" != "1" ]; then
  nohup bash -c '
    echo "Running database setup (background)..."
    if echo "${DATABASE_URL:-}" | grep -q "^postgresql"; then
      TABLE_COUNT="$(php bin/console dbal:run-sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '"'"'public'"'"' AND table_type = '"'"'BASE TABLE'"'"'" --env=prod -q 2>/dev/null | tr -cd "0-9" || true)"
      TABLE_COUNT="${TABLE_COUNT:-0}"
      if [ "${TABLE_COUNT}" -lt 5 ]; then
        php bin/console doctrine:schema:create --env=prod --no-interaction || true
        php bin/console doctrine:migrations:sync-metadata-storage --no-interaction --env=prod || true
        php bin/console doctrine:migrations:version --add --all --no-interaction --env=prod || true
      else
        php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true
      fi
    else
      php bin/console doctrine:migrations:migrate --no-interaction --env=prod || true
    fi
    php bin/console cache:clear --env=prod --no-interaction || true
    echo "Database setup finished."
  ' >> /var/log/db-setup.log 2>&1 &
fi

echo "Starting Apache on port ${PORT}..."
exec "$@"
