#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

# Ang app (API) lang ang nagma-migrate; ang scheduler ay may RUN_MIGRATIONS=false.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Ang mga command na pinatakbo bilang root (hal. migrate) ay puwedeng gumawa ng
# storage/logs/laravel.log na hindi masulatan ng web server. Ibalik sa www-data.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
