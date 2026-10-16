#!/bin/sh
# SN3 container entrypoint.
# - Serving (default CMD): require APP_KEY, cache config/routes/views/events, then start Apache.
# - Any other command (e.g. `php artisan migrate --force`) runs as given.
# Migrations are never run automatically, so several containers starting at once cannot race (ARCHITECTURE.md §11.1).
set -eu

if [ "${1:-}" = "apache2-foreground" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "sn3: APP_KEY is not set. Generate one with:" >&2
        echo "  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
        echo "then set APP_KEY in .env and start again." >&2
        exit 1
    fi

    php artisan optimize --no-interaction
fi

exec "$@"
