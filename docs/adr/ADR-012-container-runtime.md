# ADR-012 — Container runtime for the self-hosted Compose stack

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-002 (#3)
- **Decision inputs:** ARCHITECTURE.md §11.1 (minimal self-hosted topology), API_SPEC.md §13 (health routes), DECIDED-010 (Linux amd64/arm64 Docker Compose target)

## Decision

1. **One application image, served by Apache + mod_php** from the official `php:8.3.35-apache-trixie` image. It runs as `www-data` and listens on 8080.
   - *Why:* it is one process with no supervisor, uses only official images, and is the most widely understood option for self-host operators. Laravel's `public/.htaccess` works unchanged.
   - *Alternatives:* nginx + PHP-FPM needs two processes (a supervisor) or two containers with shared assets, which is more moving parts for a small-team product. FrankenPHP is fast and modern, but it adds a less familiar runtime. Either can replace this later without changing the app, because the image contract is only "HTTP on 8080". Revisit if load testing in production readiness shows mod_php is a bottleneck.
2. **Multi-stage build:** production Composer dependencies come from `composer.lock` (`--no-dev`), and assets are built in `node:22.23.3-trixie-slim` from `package-lock.json`. The runtime image contains no Node, no dev dependencies and no Composer binary.
3. **PostgreSQL `postgres:16.15-trixie`.** The glibc (Debian) variant is used rather than Alpine, so locale-aware collation behaves the same as on typical hosts, which matters for Arabic text ordering. The database is not published on the host.
4. **Migrations are explicit** (`docker compose run --rm app php artisan migrate --force`), never in the entrypoint, so concurrent container starts cannot race (ARCHITECTURE.md §11.1). The entrypoint only checks `APP_KEY` and runs `php artisan optimize`.
5. **Compose pins topology values** (`APP_ENV=production`, `APP_DEBUG=false`, DB host and port, stderr logging) over `.env`. A copied development `.env` therefore cannot switch on debug mode in a self-hosted deployment. `DB_PASSWORD` is required at interpolation time.
6. **Reverse proxy is an optional example** (Caddy `2.11.7-alpine`, Compose profile `proxy`), not a hard dependency. The app port binds to `127.0.0.1` by default. Trusted proxies come from `TRUSTED_PROXIES` (`config/trustedproxy.php`), and Compose defaults it to the private ranges. That is safe only while the port stays on loopback, which is documented in `docs/SELF_HOSTING.md`.
7. **Health:** `/health/live` (process) and `/health/ready` (DB `select 1` + writable storage) are registered outside the `web` middleware group, so they never create sessions. The framework default `/up` route is removed, so only the API_SPEC contract exists.
8. **Persistent state** lives in named volumes: `postgres-data` and `app-storage` (`storage/app`). Framework caches, compiled views and logs are ephemeral by design.

## Consequences

- Queue worker and scheduler services (same image, separate process) are added by FND-007. Until then, `QUEUE_CONNECTION=database` jobs are queued but not processed in the Compose stack.
- Image publishing and versioned tags are GH-008 (release packaging). Compose builds the image locally as `sn3-app:local`.
- Base image tags are exact. Dependabot (`docker` and `docker-compose` ecosystems) proposes patch updates against `dev`.
- `opcache.validate_timestamps=0`: code changes require rebuilding the image, which is the intended deployment model.
