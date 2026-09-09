# syntax=docker/dockerfile:1.7
#
# Booking to Prescription — production image (docs/DEPLOYMENT.md §3).
#
# One image, four roles, selected by the first argument to docker/entrypoint.sh:
#   app        Octane on FrankenPHP (worker mode), port 8000        — HTTP for every surface
#   horizon    Horizon master supervisor (queues: critical, default, notifications, pdf, search, reports, backups)
#   scheduler  `schedule:work` (ARCHITECTURE §4.7)
#   reverb     Reverb WebSocket server, port 8080
#   init       one-shot: migrate → catalog:migrate → tenants:migrate --seed → tenants:sync-search-settings
#
# Stages:
#   assets   node:22 — `npm ci` + the REAL `npm run build` (two Vite passes: site, then panel — vite.config.ts),
#            then `npm prune --omit=dev` so the runtime gets the production node_modules Browsershot needs (puppeteer).
#   base     dunglas/frankenphp (official FrankenPHP image, PHP 8.4) + the PHP extensions this app uses + headless
#            Chromium + Noto Sans/Serif Bengali system fonts (the PDF pipeline, ARCHITECTURE §8.5) + PostgreSQL 16
#            client tools (tenants:backup/restore shell out to pg_dump/pg_restore/psql) + poppler (pdftotext) + Node.
#   vendor   composer install --no-dev on top of base (so platform requirements are checked against the REAL PHP).
#   final    base + vendor + built assets + pruned node_modules, non-root user, entrypoint.
#
# Pins are exact on purpose. Bump them deliberately, in one commit, with the upgrade noted in docs/OPERATIONS.md.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.12.7-php8.4-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim
ARG COMPOSER_IMAGE=composer:2.8

# The composer binary only; it runs inside `base` so platform requirements are checked against the real PHP.
FROM ${COMPOSER_IMAGE} AS composer-bin

# ----------------------------------------------------------------------------------------------------------------
# assets: Vite build
# ----------------------------------------------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app

# System Chromium is used at runtime (CHROME_PATH); puppeteer must not download its own 150 MB browser here.
ENV PUPPETEER_SKIP_DOWNLOAD=1 \
    NODE_ENV=development

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

# Only what the two Vite passes read (vite.config.ts, tsconfig.json, resources/**, public/** for the PWA plugin).
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public

# `npm run build` = `BP_SURFACE=site vite build && BP_SURFACE=panel vite build`. The panel pass merges the site's
# manifest entries back so Laravel sees one public/build/manifest.json (vite.config.ts). Never substitute a single
# pass: the site bundle must be the preact one.
RUN npm run build \
 && test -f public/build/manifest.json \
 && test -f public/sw.js \
 && test -f public/panel.webmanifest

# Runtime Node dependencies only (puppeteer for Browsershot). Frontend libraries stay in this list because they are
# declared under "dependencies" in package.json; pruning further would mean hand-picking puppeteer's tree.
RUN npm prune --omit=dev --no-audit --no-fund

# ----------------------------------------------------------------------------------------------------------------
# base: runtime platform
# ----------------------------------------------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

ARG APP_UID=1000
ARG APP_GID=1000

ENV DEBIAN_FRONTEND=noninteractive

# PostgreSQL 16 client (bookworm ships 15; pg_dump must be >= the server's major, and the server is 16 —
# BRIEF §2 / ARCHITECTURE §0). The PGDG key + list are the project's documented install recipe.
RUN set -eux; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        unzip \
        libstdc++6 \
        procps \
        postgresql-client-16 \
        poppler-utils \
        chromium \
        fontconfig \
        fonts-noto-core \
    ; \
    rm -rf /var/lib/apt/lists/*; \
    # The prescription PDF depends on these two families being installed system-wide (ARCHITECTURE §0, §8.5).
    # Fail the build, not a doctor's print job, if the package ever stops shipping them.
    fc-cache -f; \
    fc-list | grep -qi "Noto Sans Bengali"; \
    fc-list | grep -qi "Noto Serif Bengali"; \
    test -x /usr/bin/chromium; \
    test -x /usr/bin/pg_dump; \
    test -x /usr/bin/pdftotext; \
    pg_dump --version | grep -q " 16\."

# PHP extensions. Derived from `composer check-platform-reqs --no-dev` (ctype curl dom fileinfo filter hash iconv
# json libxml mbstring openssl pcntl pcre posix session simplexml tokenizer — all built into the official image)
# plus what the code actually uses: pdo_pgsql/pgsql (both databases), redis (phpredis; config/database.php
# auto-detects it and falls back to predis), intl (Laravel Number/validation), gd (image validation, QR PNG
# fallback), zip (tenants:export writes a ZipArchive), bcmath (money maths in vendor), sodium (BackupCipher —
# built in), pcntl/posix (Horizon/Octane signals), opcache. imagick is deliberately NOT installed: an audit found
# nothing that uses it.
RUN set -eux; \
    install-php-extensions pdo_pgsql pgsql redis intl gd zip bcmath opcache pcntl; \
    for ext in pdo_pgsql pgsql redis intl gd zip bcmath sodium pcntl posix mbstring opcache ctype curl dom fileinfo iconv openssl simplexml tokenizer xml; do \
        php -m | grep -qix "$ext" || { echo "missing PHP extension: $ext" >&2; exit 1; }; \
    done

# Production php.ini + this app's overrides (memory for pg_dump streaming and PDF rendering, upload limits that
# match config/patients.php `documents.max_kb`, opcache tuned for a worker that never re-reads files).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/zz-bp.ini "$PHP_INI_DIR/conf.d/zz-bp.ini"

# Node runtime for Browsershot (spatie/browsershot shells out to `node vendor/spatie/browsershot/bin/browser.cjs`
# and resolves `puppeteer` from the app's node_modules; it also runs `npm root -g`, so npm ships too).
COPY --from=assets /usr/local/bin/node /usr/local/bin/node
COPY --from=assets /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && node --version && npm --version

# Non-root runtime user. FrankenPHP (Caddy) keeps its autosave/config under XDG_CONFIG_HOME=/config and its data
# under XDG_DATA_HOME=/data (set by the base image); Octane binds 8000, so no privileged port is needed.
RUN set -eux; \
    groupadd -g "${APP_GID}" app; \
    useradd -u "${APP_UID}" -g app -m -s /bin/bash app; \
    mkdir -p /config/caddy /data/caddy /app; \
    chown -R app:app /config /data /app

# Container-specific defaults. Anything here is overridable from the compose env_file / environment
# (compose precedence: environment > env_file > image ENV).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    OCTANE_SERVER=frankenphp \
    OCTANE_STATE_FILE=/app/storage/framework/octane-server-state.json \
    REVERB_SERVER_HOST=0.0.0.0 \
    REVERB_SERVER_PORT=8080 \
    CHROME_PATH=/usr/bin/chromium \
    NODE_BINARY=/usr/local/bin/node \
    NPM_BINARY=/usr/local/bin/npm \
    PATIENTS_PDFTOTEXT_BIN=/usr/bin/pdftotext \
    PUPPETEER_SKIP_DOWNLOAD=1 \
    PUPPETEER_CACHE_DIR=/tmp/puppeteer

WORKDIR /app

# ----------------------------------------------------------------------------------------------------------------
# vendor: composer install --no-dev, checked against the real PHP 8.4 + extensions above
# ----------------------------------------------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# 1. Dependencies only (cached until composer.lock changes). --no-scripts: `post-autoload-dump` runs
#    `artisan package:discover`, which needs the application code that arrives in step 2.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# 2. Application code, then the optimised autoloader. `dump-autoload` fires post-autoload-dump, i.e.
#    `package:discover` → bootstrap/cache/packages.php + services.php (built here, shipped in the image).
COPY . .
RUN --mount=type=cache,target=/root/.composer/cache \
    composer dump-autoload --no-dev --optimize --no-interaction \
 && test -f bootstrap/cache/packages.php \
 && test -f bootstrap/cache/services.php

# ----------------------------------------------------------------------------------------------------------------
# final
# ----------------------------------------------------------------------------------------------------------------
FROM base AS final

COPY --chown=app:app --from=vendor /app /app
# Built assets + PWA files (public/build, public/sw.js, public/panel.webmanifest, public/workbox-*.js) and the
# production node_modules. Assets are BAKED here on purpose: Octane caches the Vite manifest in a static for the
# life of a worker, so `public/build` must be complete before the server starts and must never be a volume that
# is refreshed underneath a running container (ARCHITECTURE §7.2).
COPY --chown=app:app --from=assets /app/public /app/public
COPY --chown=app:app --from=assets /app/node_modules /app/node_modules

RUN set -eux; \
    # Octane's FrankenPHP worker entry (gitignored locally; Octane copies it on first start, but public/ is not
    # writable at runtime, so ship it).
    cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php; \
    # `php artisan storage:link` without booting the app: public/storage → storage/app/public. The `public` disk is
    # where clinic logos live (App\Domain\Clinic\Services\ClinicUploads::brandingLogo), served at APP_URL/storage/…;
    # compose mounts a named volume on /app/storage/app/public so they survive a redeploy.
    ln -sfn /app/storage/app/public /app/public/storage; \
    mkdir -p storage/app/private/uploads storage/app/private/pdfs storage/app/private/backups storage/app/public \
             storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing \
             storage/logs bootstrap/cache; \
    chmod +x docker/entrypoint.sh; \
    chown -R app:app storage bootstrap/cache public/frankenphp-worker.php; \
    # The image must be self-sufficient: every binary the app shells out to, present and executable.
    test -x "$CHROME_PATH"; test -x "$NODE_BINARY"; test -x "$NPM_BINARY"; test -x "$PATIENTS_PDFTOTEXT_BIN"; \
    test -d node_modules/puppeteer; \
    php -r 'require "vendor/autoload.php"; echo "autoload ok\n";'

USER app

EXPOSE 8000 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["app"]
