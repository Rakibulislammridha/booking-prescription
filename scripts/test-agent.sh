#!/usr/bin/env bash
# Per-engineer test isolation (CONVENTIONS §6.1): scripts/test-agent.sh N [phpunit args…]
# Engineer N (1–16) gets booking_test_N / catalog_test_N, Meilisearch prefix testN_ and Redis db N.
set -euo pipefail
N="${1:?usage: scripts/test-agent.sh N [phpunit args...]}"; shift
case "$N" in ''|*[!0-9]*) echo "N must be an integer 1-16" >&2; exit 2;; esac
cd "$(dirname "$0")/.."
export DB_DATABASE="booking_test_${N}" CATALOG_DB_DATABASE="catalog_test_${N}" SCOUT_PREFIX="test${N}_" REDIS_DB="${N}"
exec php artisan test "$@"
