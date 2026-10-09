#!/bin/sh
set -eu
# The storage volume persists compiled Blade files across image updates.
# Clear only view cache before the FPM instance starts; never run migrations here.
if [ "${1:-}" = "php-fpm" ]; then
    php artisan view:clear
fi
exec docker-php-entrypoint "$@"
