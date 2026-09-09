#!/usr/bin/env bash
#
# First-start hook for the compose `postgres` service (mounted at /docker-entrypoint-initdb.d/). The official image
# runs it ONCE, when the data volume is empty, as the superuser named by POSTGRES_USER. It applies
# docker/postgres/pg-roles.sql (mounted at /bp/pg-roles.sql) with the role names and passwords the application's
# own .env declares, so the app and the database never disagree about credentials.
#
# On a later start (volume already initialised) nothing here runs — rotate a password or add a role by running
# pg-roles.sql by hand (docs/OPERATIONS.md §3).
set -euo pipefail

: "${DB_DATABASE:?DB_DATABASE must be set (env_file .env)}"
: "${DB_USERNAME:?DB_USERNAME must be set}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"
: "${CATALOG_DB_DATABASE:?CATALOG_DB_DATABASE must be set}"
: "${CATALOG_DB_USERNAME:?CATALOG_DB_USERNAME must be set}"
: "${CATALOG_DB_PASSWORD:?CATALOG_DB_PASSWORD must be set}"
: "${CATALOG_ADMIN_DB_USERNAME:?CATALOG_ADMIN_DB_USERNAME must be set}"
: "${CATALOG_ADMIN_DB_PASSWORD:?CATALOG_ADMIN_DB_PASSWORD must be set}"

if [ "$CATALOG_DB_USERNAME" = "$CATALOG_ADMIN_DB_USERNAME" ]; then
  echo "CATALOG_DB_USERNAME and CATALOG_ADMIN_DB_USERNAME are the same role: the runtime catalog connection would not be SELECT-only (BRIEF §3)." >&2
  exit 1
fi

if [ "$DB_USERNAME" = "$POSTGRES_USER" ] || [ "$CATALOG_ADMIN_DB_USERNAME" = "$POSTGRES_USER" ] || [ "$CATALOG_DB_USERNAME" = "$POSTGRES_USER" ]; then
  echo "The application must not run as the postgres superuser (POSTGRES_USER=$POSTGRES_USER); pick dedicated role names." >&2
  exit 1
fi

echo "bp: creating roles/databases ${DB_DATABASE} (owner ${DB_USERNAME}), ${CATALOG_DB_DATABASE} (owner ${CATALOG_ADMIN_DB_USERNAME}, reader ${CATALOG_DB_USERNAME})"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres --quiet \
  -v booking_db="$DB_DATABASE" \
  -v app_user="$DB_USERNAME" \
  -v app_pass="$DB_PASSWORD" \
  -v catalog_db="$CATALOG_DB_DATABASE" \
  -v catalog_admin="$CATALOG_ADMIN_DB_USERNAME" \
  -v catalog_admin_pass="$CATALOG_ADMIN_DB_PASSWORD" \
  -v catalog_ro="$CATALOG_DB_USERNAME" \
  -v catalog_ro_pass="$CATALOG_DB_PASSWORD" \
  -f /bp/pg-roles.sql

echo "bp: roles and databases ready"
