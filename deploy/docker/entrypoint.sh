#!/bin/sh
# SN3 container entrypoint.
# - Long-running services (the default CMD `apache2-foreground`, and the worker and scheduler:
#   `php artisan queue:work ...` / `php artisan schedule:work`): require APP_KEY, cache
#   config/routes/views/events, then start the process.
# - Any other command (e.g. `php artisan migrate --force`) runs as given.
# Migrations are never run automatically, so several containers starting at once cannot race (ARCHITECTURE.md §11.1).
# `optimize` writes only to bootstrap/cache and storage/framework, which live in each container's
# own filesystem (only storage/app is a shared volume), so concurrent starts cannot race either.
set -eu

case "$*" in
    "apache2-foreground" | "php artisan queue:work" | "php artisan queue:work "* | "php artisan schedule:work" | "php artisan schedule:work "*)
        if [ -z "${APP_KEY:-}" ]; then
            echo "sn3: APP_KEY is not set. Generate one with:" >&2
            echo "  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
            echo "then set APP_KEY in .env and start again." >&2
            exit 1
        fi

        php artisan optimize --no-interaction
        ;;
esac

exec "$@"
