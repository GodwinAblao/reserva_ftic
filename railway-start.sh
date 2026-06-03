#!/bin/bash
# Run migrations and import dummy data on startup
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:import-dummy-reservations

# Start the PHP server
php -S 0.0.0.0:$PORT -t public
