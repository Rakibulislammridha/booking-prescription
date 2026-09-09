#!/usr/bin/env bash
#
# scripts/deploy/restore-drill.sh — prove a tenant backup restores (docs/DEPLOYMENT.md §9.3, OPERATIONS.md §4).
#
#   scripts/deploy/restore-drill.sh <tenant-slug>              # list completed backups for the tenant
#   scripts/deploy/restore-drill.sh <tenant-slug> <backup-id>  # restore that backup (swap), then report
#
# What `tenants:restore` does (App\Domain\SaaS\Actions\Tenants\RestoreTenantBackup, ARCHITECTURE §8.8): streams the
# object off the `backups` disk, decrypts it with BP_BACKUP_KEY when its magic says it is encrypted, verifies the
# plaintext SHA-256 against tenant_backups.checksum_sha256, rebuilds it into `tenant_<id>_restore`, checks that
# schema has tables, then renames the two schemas past each other in ONE transaction. The displaced live schema is
# kept as `tenant_<id>_replaced_<timestamp>` for a human to drop. The live clinic is never the restore target.
#
# A drill is therefore a real restore: run it on a STAGING stack (same image, a copy of the backups bucket, the
# same BP_BACKUP_KEY), or on production only for a tenant you are prepared to swap — e.g. the `demo` tenant.
# Run it monthly; a backup nobody has restored is a hope, not a backup.
#
# Runs inside the compose stack (`docker compose exec app …`). Set COMPOSE_FILE / -p yourself for staging.
# NOT executed on the development box (no Docker): `bash -n`-checked only.
set -euo pipefail

cd "$(dirname "$0")/../.."

SLUG="${1:-}"
BACKUP_ID="${2:-}"
[ -n "$SLUG" ] || { sed -n '2,20p' "$0"; exit 2; }

COMPOSE="docker compose"
artisan() { $COMPOSE exec -T app php artisan "$@"; }
sql() { $COMPOSE exec -T postgres psql -v ON_ERROR_STOP=1 -U postgres -d "${DB_DATABASE:-booking}" -tA -c "$1"; }

echo "==> tenant"
artisan tenants:list | grep -E "^\|? *[0-9]+ *\|? *${SLUG} " || artisan tenants:list | grep -F "$SLUG" || { echo "no tenant with slug ${SLUG}" >&2; exit 1; }

TENANT_ID="$(sql "select id from public.tenants where slug = '${SLUG}' and deleted_at is null")"
SCHEMA="$(sql "select schema_name from public.tenants where id = ${TENANT_ID}")"

echo "==> completed backups for #${TENANT_ID} (${SCHEMA}); newest first"
sql "select id, type, encryption, size_bytes, storage_path, completed_at from public.tenant_backups
     where tenant_id = ${TENANT_ID} and status = 'completed' and type in ('daily','manual')
     order by completed_at desc limit 15" | column -t -s '|'

if [ -z "$BACKUP_ID" ]; then
  echo
  echo "Re-run with a backup id to restore it: $0 ${SLUG} <backup-id>"
  exit 0
fi

echo
echo "==> baseline row counts (live schema ${SCHEMA})"
BEFORE="$(sql "select 'patients='||(select count(*) from ${SCHEMA}.patients)||' serials='||(select count(*) from ${SCHEMA}.serials)||' prescriptions='||(select count(*) from ${SCHEMA}.prescriptions)")"
echo "$BEFORE"

echo
echo "!!! About to restore backup #${BACKUP_ID} over tenant '${SLUG}' (#${TENANT_ID}). The current schema is kept as"
echo "!!! ${SCHEMA}_replaced_<timestamp> — nothing is deleted — but the clinic will SEE THE RESTORED DATA immediately."
read -r -p "Type the slug to continue: " confirm
[ "$confirm" = "$SLUG" ] || { echo "aborted"; exit 1; }

echo "==> tenants:restore ${BACKUP_ID} --force"
artisan tenants:restore "$BACKUP_ID" --force

echo
echo "==> row counts after restore"
AFTER="$(sql "select 'patients='||(select count(*) from ${SCHEMA}.patients)||' serials='||(select count(*) from ${SCHEMA}.serials)||' prescriptions='||(select count(*) from ${SCHEMA}.prescriptions)")"
echo "before: ${BEFORE}"
echo "after:  ${AFTER}"

echo
echo "==> displaced schema(s) — drop when the clinic has confirmed the restore:"
sql "select nspname from pg_namespace where nspname like '${SCHEMA}\\_replaced\\_%' order by nspname"
echo "    docker compose exec postgres psql -U postgres -d ${DB_DATABASE:-booking} -c 'drop schema \"${SCHEMA}_replaced_<ts>\" cascade'"
echo
echo "==> audit: public.audit_logs_central has a 'restore' row for this tenant (super console → tenant → activity)."
echo "drill complete"
