#!/usr/bin/env bash
# Start (or check) the user-local dev services this project depends on.
# No sudo, no Docker on the dev box: Dragonfly (Redis-compatible) and Meilisearch
# are single binaries in ~/.local/bin. Usage: scripts/dev-services.sh [start|status|stop]
set -u
BIN="$HOME/.local/bin"; VAR="$HOME/.local/var"; LOG="$VAR/log"
mkdir -p "$VAR/dragonfly" "$VAR/meilisearch" "$LOG"
MEILI_KEY="${MEILISEARCH_KEY:-bp-dev-master-key-0123456789abcdef}"

REVERB_PORT="${REVERB_PORT:-8080}"; START_REVERB="${START_REVERB:-1}"   # START_REVERB=0 skips the WebSocket server
APP="$(cd "$(dirname "$0")/.." && pwd)"

redis_up()  { php -r 'try{$r=new Redis();$r->connect("127.0.0.1",6379,1);exit($r->ping()?0:1);}catch(Throwable $e){exit(1);}' 2>/dev/null; }
meili_up()  { curl -sf -m 2 http://127.0.0.1:7700/health >/dev/null 2>&1; }
reverb_up() { curl -s -m 2 -o /dev/null "http://127.0.0.1:${REVERB_PORT}/" 2>/dev/null; }   # any HTTP answer = listening

start() {
  if redis_up; then echo "dragonfly: already up"; else
    setsid nohup "$BIN/dragonfly" --port 6379 --bind 127.0.0.1 --dir "$VAR/dragonfly" \
      --proactor_threads=2 --maxmemory 1gb --dbfilename dump --logtostderr \
      >"$LOG/dragonfly.log" 2>&1 < /dev/null &
    for i in $(seq 1 20); do redis_up && break; sleep 0.5; done
    redis_up && echo "dragonfly: started" || { echo "dragonfly: FAILED (see $LOG/dragonfly.log)"; }
  fi
  if meili_up; then echo "meilisearch: already up"; else
    setsid nohup "$BIN/meilisearch" --db-path "$VAR/meilisearch" --master-key "$MEILI_KEY" \
      --http-addr 127.0.0.1:7700 --no-analytics --env development \
      >"$LOG/meilisearch.log" 2>&1 < /dev/null &
    for i in $(seq 1 30); do meili_up && break; sleep 0.5; done
    meili_up && echo "meilisearch: started" || { echo "meilisearch: FAILED (see $LOG/meilisearch.log)"; }
  fi
  # Reverb (WebSockets for the live queue / desk; ARCHITECTURE §4.8). Optional: START_REVERB=0 to skip.
  if [ "$START_REVERB" = "1" ]; then
    if reverb_up; then echo "reverb: already up"; else
      setsid nohup php "$APP/artisan" reverb:start --host=127.0.0.1 --port="$REVERB_PORT" \
        >"$LOG/reverb.log" 2>&1 < /dev/null &
      for i in $(seq 1 20); do reverb_up && break; sleep 0.5; done
      reverb_up && echo "reverb: started (port $REVERB_PORT)" || { echo "reverb: FAILED (see $LOG/reverb.log)"; }
    fi
  fi
}
status() {
  redis_up && echo "dragonfly: up" || echo "dragonfly: down"
  meili_up && echo "meilisearch: up" || echo "meilisearch: down"
  reverb_up && echo "reverb: up (port $REVERB_PORT)" || echo "reverb: down"
}
stop() {
  pkill -f "$BIN/dragonfly" && echo "dragonfly: stopped"
  pkill -f "$BIN/meilisearch" && echo "meilisearch: stopped"
  pkill -f "artisan reverb:start" && echo "reverb: stopped"
}
# Prefer the systemd --user units when they are installed: they survive this shell exiting
# and restart on failure. Fall back to detached processes otherwise.
UNITS="bp-dragonfly bp-meilisearch bp-reverb"
have_units() { systemctl --user list-unit-files bp-dragonfly.service >/dev/null 2>&1 && systemctl --user cat bp-dragonfly >/dev/null 2>&1; }
if have_units; then
  case "${1:-start}" in
    start)  systemctl --user start $UNITS; sleep 2; status;;
    stop)   systemctl --user stop $UNITS;;
    status) for u in $UNITS; do printf "%-16s %s\n" "$u" "$(systemctl --user is-active $u)"; done; status;;
    *) echo "usage: $0 [start|stop|status]"; exit 2;;
  esac
  exit 0
fi
case "${1:-start}" in start) start;; status) status;; stop) stop;; *) echo "usage: $0 [start|status|stop]"; exit 2;; esac
