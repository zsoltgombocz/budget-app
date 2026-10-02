#!/bin/sh
set -e

cd /var/www/html

# Re-sync the built public/ into the shared volume Caddy serves from.
if [ -d public-image ] && [ -w public ]; then
    rsync -a --delete public-image/ public/

    # The dev stack gets the blue icons and the "Budget DEV" manifest.
    if grep -q '^APP_ENV=staging' .env 2>/dev/null; then
        cp -r docker/dev-public/. public/
    fi
fi

# Cache config, routes, views and events against the mounted .env.
php artisan optimize --no-interaction >/dev/null

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
