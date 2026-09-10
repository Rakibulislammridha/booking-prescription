<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Search\MeilisearchIndexes;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Exceptions\TenantExportRequired;
use App\Domain\SaaS\Exceptions\TenantHasUnsettledInvoices;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Models\Central\Domain;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The end of a clinic: drop its schema and soft-delete its row.
 *
 * It is the only action in the product that destroys clinical records, so it is guarded three times before it
 * touches anything, and the guards are the ACTION's — a controller cannot skip them:
 *
 *   1. money — an issued or overdue platform invoice with a balance refuses the deletion; you do not erase the
 *      records a dispute is about, and `CancelSubscription` exists for the case where the clinic simply leaves;
 *   2. the export — a COMPLETED churn archive (BRIEF §5.N, `tenant_backups.type = export`) finished within the
 *      last `EXPORT_MAX_AGE_HOURS` must exist. Not "an export was requested": a queued job that failed is not a
 *      copy of anyone's data. The console's step one is that export; this is step two;
 *   3. the typed slug — checked by the request, because it is a UI contract, not a business rule.
 *
 * What survives: the soft-deleted `tenants` row (its slug stays claimed — `ProvisionTenant` checks with trashed
 * rows, so nobody inherits the old address), the `tenant_backups` registry with the export's object key, and the
 * central audit trail. What goes: the schema (and with it every table), the search indexes, the `domains` rows
 * (the hostnames are released), and the live subscription (cancelled, not deleted, for the ledger).
 */
final class DeleteTenant
{
    public const EXPORT_MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly FeatureFlagCache $flags,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{export: TenantBackup, schema: string} */
    public function handle(Tenant $tenant, ?int $superAdminId = null): array
    {
        $unsettled = $this->unsettledInvoices($tenant);

        if ($unsettled > 0) {
            throw new TenantHasUnsettledInvoices($unsettled);
        }

        $export = self::freshExport($tenant) ?? throw new TenantExportRequired;

        if (Tenancy::check()) {
            Tenancy::end();
        }

        $before = ['slug' => $tenant->slug, 'name' => $tenant->name, 'schema_name' => $tenant->schema_name, 'status' => $tenant->status->value];
        $schema = $tenant->schema_name;
        $now = CarbonImmutable::now();

        $this->dropSearchIndexes($tenant);

        DB::connection('pgsql')->transaction(function () use ($tenant, $now): void {
            Subscription::query()->where('tenant_id', $tenant->id)
                ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
                ->get()
                ->each(fn (Subscription $s) => $s->forceFill([
                    'status' => SubscriptionStatus::Cancelled,
                    'cancelled_at' => $now,
                    'cancel_reason' => 'saas.tenants.deleted',
                    'auto_renew' => false,
                    'cancel_at_period_end' => false,
                    'grace_until' => null,
                ])->save());

            // Model deletes, one by one: `Domain::deleted` is what drops each host from the resolver cache.
            Domain::query()->where('tenant_id', $tenant->id)->get()->each(fn (Domain $d) => $d->delete());

            $tenant->forceFill(['status' => TenantStatus::Cancelled, 'suspended_at' => $tenant->suspended_at ?? $now])->save();
            $tenant->delete();
        });

        DB::connection('pgsql')->statement("drop schema if exists \"{$schema}\" cascade");
        $this->flags->forTenant($tenant);

        $this->audit->record(
            CentralAuditAction::Delete,
            $tenant,
            $tenant,
            $before,
            ['deleted_at' => $now->toIso8601String(), 'schema_dropped' => $schema, 'export_id' => $export->id, 'export_path' => $export->storage_path],
            $superAdminId,
        );

        return ['export' => $export, 'schema' => $schema];
    }

    /** The completed churn archive the deletion rests on, if a fresh one exists. Public so the console can show the gate's state. */
    public static function freshExport(Tenant $tenant): ?TenantBackup
    {
        return TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', BackupType::Export->value)
            ->where('status', BackupStatus::Completed->value)
            ->where('completed_at', '>=', CarbonImmutable::now()->subHours(self::EXPORT_MAX_AGE_HOURS))
            ->orderByDesc('id')
            ->first();
    }

    public function unsettledInvoices(Tenant $tenant): int
    {
        return SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereColumn('paid_paisa', '<', 'total_paisa')
            ->count();
    }

    /** Before the schema goes: `deleteTenantIndexes` enters the schema to read `searchableAs()`. Best effort — an index nobody can reach is litter, not a leak. */
    private function dropSearchIndexes(Tenant $tenant): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        try {
            app(MeilisearchIndexes::class)->deleteTenantIndexes($tenant);
        } catch (Throwable $e) {
            Log::warning('saas.tenants.delete.indexes_failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        } finally {
            Tenancy::check() && Tenancy::end();
        }
    }
}
