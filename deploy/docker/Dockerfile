# syntax=docker/dockerfile:1

# SN3 (سنع) application image: Laravel served by Apache + mod_php on port 8080, running as www-data.
# Build from the repository root:  docker compose build   (or)   docker build -f deploy/docker/Dockerfile .
# Base image versions are pinned; see docs/adr/ADR-012-container-runtime.md.

# Image tags are literal (not ARGs) so Dependabot can propose patch updates.
FROM composer:2.10.3 AS composer-bin

# ---------------------------------------------------------------------------
# PHP runtime base: required extensions only, build toolchain removed afterwards.
# pcntl lets the queue worker stop gracefully on SIGTERM and enforce job timeouts (ADR-015).
# ---------------------------------------------------------------------------
FROM php:8.3.35-apache-trixie AS php-base

RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libpq-dev; \
    docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | grep -v '^diversion' | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qi '^pdo_pgsql$'; \
    php -m | grep -qi '^intl$'; \
    php -m | grep -qi '^pcntl$'

# ---------------------------------------------------------------------------
# PHP dependencies (production only), resolved from composer.lock.
# ---------------------------------------------------------------------------
FROM php-base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1
# Composer needs unzip to extract dist archives; this stage is discarded.
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# ---------------------------------------------------------------------------
# Frontend assets, built from package-lock.json.
# ---------------------------------------------------------------------------
FROM node:22.23.3-trixie-slim AS assets

ARG VITE_APP_NAME=SN3
ENV VITE_APP_NAME=${VITE_APP_NAME}
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --include=dev --no-audit --no-fund
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Runtime image.
# ---------------------------------------------------------------------------
FROM php-base AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod rewrite headers; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    rm -f /etc/apache2/sites-enabled/000-default.conf

COPY deploy/docker/php/sn3.ini "$PHP_INI_DIR/conf.d/zz-sn3.ini"
COPY deploy/docker/apache/sn3.conf /etc/apache2/sites-enabled/sn3.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/sn3-entrypoint

WORKDIR /var/www/html

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
# Code stays root-owned and read-only for www-data; only storage/ and bootstrap/cache are writable.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    rm /usr/bin/composer; \
    chmod -R a+rX,go-w .; \
    chown -R www-data:www-data bootstrap/cache storage; \
    chmod +x /usr/local/bin/sn3-entrypoint

USER www-data

EXPOSE 8080

# Web probe. docker-compose.yml overrides it for the worker and scheduler services.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/health/live > /dev/null || exit 1

ENTRYPOINT ["sn3-entrypoint"]
CMD ["apache2-foreground"]
