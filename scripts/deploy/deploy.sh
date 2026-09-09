#!/usr/bin/env bash
#
# scripts/deploy/deploy.sh — release a new image to the compose stack on the VPS (docs/DEPLOYMENT.md §10).
#
#   BP_TAG=1.4.0 scripts/deploy/deploy.sh            # pull ghcr image :1.4.0 (BP_IMAGE from .env) and roll it out
#   scripts/deploy/deploy.sh --build                  # build the image locally from this checkout instead of pulling
#   scripts/deploy/deploy.sh --simple                 # plain recreate of `app` (a few seconds of held requests)
#
# Sequence (each step is safe to repeat):
#   1. pull (or build) the image
#   2. `init` one-shot: migrate → catalog:migrate → tenants:migrate --seed → tenants:sync-search-settings
#      (idempotent; serialised on a host-wide lock — docker/entrypoint.sh)
#   3. app: ROLLING by default — start a second `app` container from the new image, wait until its healthcheck
#      passes, stop the old one. Caddy resolves `app:8000` through Docker DNS on every dial and retries for 20 s
#      (lb_try_duration), so browsers see no error while the switch happens. --simple skips the overlap.
#   4. horizon / scheduler / reverb / tls-ask: recreate. Horizon gets SIGTERM and finishes its current jobs first
#      (stop_grace_period 180 s in compose.yaml); Reverb clients reconnect (REALTIME.md §6 polling fallback covers
#      the gap); the scheduler simply resumes on the next minute.
#   5. `octane:status`, `horizon:status`, and a `/up` probe through Caddy.
#
# Requires: docker compose v2 on the VPS, this repo (or at least compose.yaml + docker/ + .env) in $PWD.
# NOT executed on the development box (no Docker there): reviewed and `bash -n`-checked only — read every step
# once before the first real run.
set -euo pipefail

cd "$(dirname "$0")/../.."

MODE="rolling"
BUILD=0
for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --simple) MODE="simple" ;;
    --rolling) MODE="rolling" ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "deploy: unknown option $arg" >&2; exit 2 ;;
  esac
done

[ -f .env ] || { echo "deploy: no .env in $(pwd) — copy docker/.env.production.example first" >&2; exit 1; }
[ -f compose.yaml ] || { echo "deploy: no compose.yaml in $(pwd)" >&2; exit 1; }

COMPOSE="docker compose"
if [ -n "${COMPOSE_FILE:-}" ]; then
  echo "deploy: using COMPOSE_FILE=${COMPOSE_FILE}"
fi

log() { printf '\n==> %s\n' "$*"; }

# ------------------------------------------------------------------------------------------------ 1. image
if [ "$BUILD" = 1 ]; then
  log "building image from $(git rev-parse --short HEAD 2>/dev/null || echo 'this checkout')"
  $COMPOSE build --pull app
else
  log "pulling image (BP_IMAGE/BP_TAG from .env${BP_TAG:+, BP_TAG=$BP_TAG})"
  $COMPOSE pull --quiet app
fi

# ------------------------------------------------------------------------------------------------ 2. data
log "data services"
$COMPOSE up -d --wait postgres valkey meilisearch
if $COMPOSE config --services 2>/dev/null | grep -qx minio; then
  $COMPOSE up -d --wait minio
  $COMPOSE run --rm minio-init
fi

log "init (migrations, tenant migrations, search settings)"
$COMPOSE run --rm init

# ------------------------------------------------------------------------------------------------ 3. app
rolling_app() {
  local old new image_ref old_ids new_id i status
  image_ref="$($COMPOSE config --images app | head -n 1)"
  [ -n "$image_ref" ] || { echo "deploy: could not resolve the app image from compose config" >&2; exit 1; }
  new_id="$(docker image inspect --format '{{.Id}}' "$image_ref")"
  old_ids="$($COMPOSE ps -q app || true)"

  if [ -z "$old_ids" ]; then
    log "no running app container; starting one"
    $COMPOSE up -d --no-deps --wait app
    return
  fi

  local unchanged=1
  for old in $old_ids; do
    if [ "$(docker inspect --format '{{.Image}}' "$old")" != "$new_id" ]; then unchanged=0; fi
  done
  if [ "$unchanged" = 1 ]; then
    log "app already runs image ${new_id:7:12}; recreating to pick up .env/compose changes"
    $COMPOSE up -d --no-deps --wait app
    return
  fi

  log "rolling: starting a second app container from ${new_id:7:12}"
  $COMPOSE up -d --no-deps --no-recreate --scale app=2 app

  new=""
  for i in $($COMPOSE ps -q app); do
    if [ "$(docker inspect --format '{{.Image}}' "$i")" = "$new_id" ]; then new="$i"; fi
  done
  [ -n "$new" ] || { echo "deploy: could not find the new app container" >&2; exit 1; }

  log "waiting for the new container to pass its healthcheck"
  for i in $(seq 1 60); do
    status="$(docker inspect --format '{{.State.Health.Status}}' "$new" 2>/dev/null || echo unknown)"
    [ "$status" = "healthy" ] && break
    sleep 2
  done
  if [ "$status" != "healthy" ]; then
    echo "deploy: new app container is '$status' after 120 s — leaving BOTH running; inspect with: docker logs $new" >&2
    exit 1
  fi

  log "stopping the previous app container(s)"
  for old in $old_ids; do
    docker stop -t 30 "$old" >/dev/null
    docker rm "$old" >/dev/null
  done
  $COMPOSE up -d --no-deps --no-recreate --scale app=1 app
}

case "$MODE" in
  rolling) rolling_app ;;
  simple)
    log "recreating app (Caddy holds requests up to 20 s)"
    $COMPOSE up -d --no-deps --wait app
    ;;
esac

# ------------------------------------------------------------------------------------------------ 4. workers
log "horizon (graceful: current jobs finish first), scheduler, reverb, tls-ask"
$COMPOSE up -d --no-deps --wait horizon scheduler reverb tls-ask
$COMPOSE up -d --no-deps caddy

# ------------------------------------------------------------------------------------------------ 5. verify
log "status"
$COMPOSE exec -T app php artisan octane:status || true
$COMPOSE exec -T horizon php artisan horizon:status || true
$COMPOSE ps
central="$(grep -E '^APP_CENTRAL_DOMAIN=' .env | cut -d= -f2- | tr -d '"' || true)"
if [ -n "$central" ]; then
  if curl -fsS -m 10 -o /dev/null "https://${central}/up"; then
    echo "https://${central}/up → OK"
  else
    echo "https://${central}/up did not answer 200 — check \`$COMPOSE logs caddy app\`" >&2
  fi
fi
log "deployed"
