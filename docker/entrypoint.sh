#!/usr/bin/env bash
#
# docker/entrypoint.sh — role dispatcher and startup sequence for the production image (docs/DEPLOYMENT.md §3.3).
#
#   entrypoint.sh app            Octane on FrankenPHP, 0.0.0.0:${OCTANE_PORT:-8000}
#   entrypoint.sh horizon        Horizon master supervisor
#   entrypoint.sh scheduler      schedule:work
#   entrypoint.sh reverb         Reverb WebSocket server, 0.0.0.0:${REVERB_SERVER_PORT:-8080}
#   entrypoint.sh init           one-shot deploy step: migrate → catalog:migrate → tenants:migrate --seed →
#                                tenants:sync-search-settings  (idempotent, serialised on a lock, safe to re-run)
#   entrypoint.sh tls-ask        Caddy on-demand-TLS "ask" endpoint (docker/tls-ask/index.php)
#   entrypoint.sh artisan …      php artisan …   (after the same dependency wait)
#   entrypoint.sh <anything>     exec'd verbatim (e.g. `bash`, `php -v`)
#
# Every long-running role: wait for its dependencies, build this container's config/event/view caches from the
# CURRENT environment (config:cache bakes env vars, so it can only happen at container start, never at image build),
# then exec the process as PID 1 so signals (SIGTERM from `docker stop`, SIGUSR1/2 from horizon:terminate and
# octane:reload) reach it directly.
#
# route:cache is OFF by default (BP_ROUTE_CACHE=1 turns it on): bootstrap/app.php registers the central marketing
# routes twice (once per host: {central} and www.{central}) under the same `central.` name prefix, and Laravel
# refuses to serialise a route collection with duplicate names ("Unable to prepare route [...] for serialization").
# Verified on the dev box with `php artisan route:cache`. Until that group is renamed, routes are compiled on boot.
set -euo pipefail

ROLE="${1:-app}"
if [ "$#" -gt 0 ]; then shift; fi

log()  { printf '[bp %s] %s\n' "$ROLE" "$*" >&2; }
fail() { log "FATAL: $*"; exit 1; }

WAIT_SECONDS="${BP_WAIT_SECONDS:-90}"

wait_tcp() { # host port label
  local host="$1" port="$2" label="$3" i
  for ((i = 1; i <= WAIT_SECONDS; i++)); do
    if (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; then
      log "${label} reachable at ${host}:${port}"
      return 0
    fi
    sleep 1
  done
  fail "${label} not reachable at ${host}:${port} after ${WAIT_SECONDS}s"
}

wait_postgres() { # host port user db label
  local host="$1" port="$2" user="$3" db="$4" label="$5" i
  for ((i = 1; i <= WAIT_SECONDS; i++)); do
    if pg_isready -q -h "$host" -p "$port" -U "$user" -d "$db"; then
      log "${label} accepting connections (${host}:${port}/${db})"
      return 0
    fi
    sleep 1
  done
  fail "${label} not ready at ${host}:${port}/${db} after ${WAIT_SECONDS}s"
}

wait_http() { # url label
  local url="$1" label="$2" i
  for ((i = 1; i <= WAIT_SECONDS; i++)); do
    if curl -fsS -m 3 -o /dev/null "$url"; then
      log "${label} healthy (${url})"
      return 0
    fi
    sleep 1
  done
  fail "${label} not healthy at ${url} after ${WAIT_SECONDS}s"
}

wait_for_databases() {
  wait_postgres "${DB_HOST:-127.0.0.1}" "${DB_PORT:-5432}" "${DB_USERNAME:-root}" "${DB_DATABASE:-booking}" "booking database"
  wait_postgres "${CATALOG_DB_HOST:-127.0.0.1}" "${CATALOG_DB_PORT:-5432}" "${CATALOG_DB_USERNAME:-root}" "${CATALOG_DB_DATABASE:-catalog}" "catalog database"
}

wait_for_redis() {
  wait_tcp "${REDIS_HOST:-127.0.0.1}" "${REDIS_PORT:-6379}" "redis"
}

wait_for_meilisearch() {
  wait_http "${MEILISEARCH_HOST:-http://127.0.0.1:7700}/health" "meilisearch"
}

require_secrets() {
  [ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty — every encrypted column, session and signed URL depends on it (docs/DEPLOYMENT.md §4)"
  if [ "${APP_ENV:-production}" != "local" ] && [ "${APP_ENV:-production}" != "testing" ] && [ -z "${BP_BACKUP_KEY:-}" ]; then
    log "WARNING: BP_BACKUP_KEY is empty — tenants:backup will refuse to run in ${APP_ENV:-production} (config/saas.php)"
  fi
}

build_caches() {
  # bootstrap/cache is container-local and writable by the app user (Dockerfile). Clear first so a container
  # restarted with new env never serves a stale config.php.
  php artisan config:clear --no-ansi -q
  php artisan config:cache --no-ansi -q
  php artisan event:cache --no-ansi -q
  php artisan view:cache --no-ansi -q
  if [ "${BP_ROUTE_CACHE:-0}" = "1" ]; then
    php artisan route:cache --no-ansi -q
  else
    php artisan route:clear --no-ansi -q
  fi
  log "config/event/view caches built (route cache: ${BP_ROUTE_CACHE:-0})"
}

run_init() {
  # Serialised across containers on this host: the init service and a manual `docker compose run --rm init` share
  # /app/storage/logs (named volume), and flock() on a file there is host-wide. A second run waits, then finds
  # nothing left to do — every step below records what it has done (public.migrations, catalog.public.migrations,
  # tenant_<id>.migrations; RolesAndPermissionsSeeder upserts by natural key; index settings are PUT, not appended).
  local lock="/app/storage/logs/.bp-init.lock"
  exec 9>"$lock"
  if ! flock -w "${BP_INIT_LOCK_WAIT:-900}" 9; then
    fail "another init is still holding ${lock} after ${BP_INIT_LOCK_WAIT:-900}s"
  fi

  log "1/4 central migrations (public schema)"
  php artisan migrate --force --no-interaction --no-ansi

  log "2/4 catalog migrations (catalog_admin connection)"
  php artisan catalog:migrate --no-interaction --no-ansi

  log "3/4 tenant migrations + RolesAndPermissionsSeeder for every servable tenant"
  # Default selection is trial|active|past_due. A SUSPENDED tenant is skipped here; after reactivating one run
  # `php artisan tenants:migrate --tenant=<slug>` (docs/OPERATIONS.md §2). The command continues past a failing
  # tenant and exits non-zero at the end, which fails this init and therefore the deploy — on purpose.
  php artisan tenants:migrate --seed --no-interaction --no-ansi

  if [ "${BP_INIT_SKIP_SEARCH:-0}" = "1" ]; then
    log "4/4 tenants:sync-search-settings skipped (BP_INIT_SKIP_SEARCH=1)"
  else
    log "4/4 tenant Meilisearch index settings"
    php artisan tenants:sync-search-settings --no-interaction --no-ansi
  fi

  flock -u 9
  log "init complete"
}

cd /app

case "$ROLE" in
  app)
    require_secrets
    wait_for_databases
    wait_for_redis
    build_caches
    # --host=0.0.0.0 skips Octane's localhost port probe; Caddy's admin API stays on localhost:2019 inside the
    # container (octane:reload / octane:status find it through OCTANE_STATE_FILE).
    exec php artisan octane:start \
      --server=frankenphp \
      --host=0.0.0.0 \
      --port="${OCTANE_PORT:-8000}" \
      --admin-port="${OCTANE_ADMIN_PORT:-2019}" \
      --workers="${OCTANE_WORKERS:-auto}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
      --log-level="${OCTANE_LOG_LEVEL:-WARN}" \
      --no-interaction --no-ansi
    ;;

  horizon)
    require_secrets
    wait_for_databases
    wait_for_redis
    build_caches
    exec php artisan horizon --no-interaction --no-ansi
    ;;

  scheduler)
    require_secrets
    wait_for_databases
    wait_for_redis
    build_caches
    exec php artisan schedule:work --no-interaction --no-ansi
    ;;

  reverb)
    require_secrets
    wait_for_redis
    build_caches
    exec php artisan reverb:start \
      --host="${REVERB_SERVER_HOST:-0.0.0.0}" \
      --port="${REVERB_SERVER_PORT:-8080}" \
      --no-interaction --no-ansi
    ;;

  init)
    require_secrets
    wait_for_databases
    wait_for_redis
    if [ "${BP_INIT_SKIP_SEARCH:-0}" != "1" ]; then wait_for_meilisearch; fi
    build_caches
    run_init
    ;;

  tls-ask)
    wait_for_databases
    # PHP's built-in server is enough: one indexed query per unknown hostname, internal network only.
    exec php -S "0.0.0.0:${BP_TLS_ASK_PORT:-9100}" -t /app/docker/tls-ask /app/docker/tls-ask/index.php
    ;;

  artisan)
    wait_for_databases
    wait_for_redis
    exec php artisan "$@"
    ;;

  *)
    exec "$ROLE" "$@"
    ;;
esac
