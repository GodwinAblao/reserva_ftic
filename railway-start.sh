#!/bin/bash
# Create PHP configuration for file uploads
cat > /tmp/php-uploads.ini << 'EOF'
upload_max_filesize = 20M
post_max_size = 50M
max_file_uploads = 20
memory_limit = 512M
max_execution_time = 120
EOF

# Run migrations and import dummy data on startup
php -c /tmp/php-uploads.ini bin/console doctrine:migrations:migrate --no-interaction
php -c /tmp/php-uploads.ini bin/console app:import-dummy-reservations

# Start the PHP server with custom config
php -c /tmp/php-uploads.ini -S 0.0.0.0:$PORT -t public
