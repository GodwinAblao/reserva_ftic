#!/bin/bash
# Create PHP configuration for file uploads
cat > /tmp/php-uploads.ini << 'EOF'
upload_max_filesize = 20M
post_max_size = 50M
max_file_uploads = 20
memory_limit = 512M
max_execution_time = 120
EOF

PHP="php -c /tmp/php-uploads.ini"

# Setup directories
mkdir -p var/sessions/prod

# Run migrations and setup
$PHP bin/console doctrine:migrations:version --add --all --no-interaction --env=prod
$PHP bin/console asset-map:compile --env=prod
$PHP bin/console doctrine:migrations:migrate --no-interaction --env=prod 2>/dev/null || true
$PHP bin/console app:import-dummy-reservations --env=prod 2>/dev/null || true

# Start the PHP server with custom config
$PHP -S 0.0.0.0:$PORT -t public public/router.php
