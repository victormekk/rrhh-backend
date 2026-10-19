#!/bin/sh
# Arranque del backend en Railway
set -e

php artisan migrate --force
php artisan storage:link --force || true
php artisan optimize

# Railway asigna el puerto en la variable PORT
exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
