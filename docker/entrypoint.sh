#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

# APP_KEY: iisa dapat sa app at scheduler, galing sa .env katabi ng docker-compose.yaml
# (ipinapasa ng docker-compose bilang environment variable).
if [ -n "${APP_KEY:-}" ]; then
    echo "APP_KEY: using the key from the environment (.env next to docker-compose.yaml)."
elif grep -q '^APP_KEY=base64:' .env; then
    echo "APP_KEY: using the key already in the container .env."
elif [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "WARNING: APP_KEY is not set in .env; generating a temporary key for this container only."
    php artisan key:generate --force --no-interaction
else
    # Ang scheduler ay hindi gumagawa ng sariling key; dapat pareho sila ng app.
    echo "ERROR: APP_KEY is not set. Add APP_KEY=base64:... to the .env next to docker-compose.yaml." >&2
    exit 1
fi

# Ang app (API) lang ang nagma-migrate; ang scheduler ay may RUN_MIGRATIONS=false.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Ang mga command na pinatakbo bilang root (hal. migrate) ay puwedeng gumawa ng
# storage/logs/laravel.log na hindi masulatan ng web server. Ibalik sa www-data.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
