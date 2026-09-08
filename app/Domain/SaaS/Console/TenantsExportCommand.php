<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Tenants\ExportTenantData;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * `tenants:export {tenant}` — the churn export (BRIEF §5.N, ARCHITECTURE §8.8): JSON + CSV per table plus the
 * patient documents, in one zip a clinic can open without us.
 */
final class TenantsExportCommand extends Command
{
    protected $signature = 'tenants:export {tenant : Slug or id}';

    protected $description = 'Build the full data export archive for one tenant and register it in public.tenant_backups';

    public function handle(ExportTenantData $export): int
    {
        if (Tenancy::check()) {
            $this->components->error('tenants:export is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('tenant');
        $tenant = Tenant::query()->where('slug', $reference)->orWhere('id', is_numeric($reference) ? (int) $reference : 0)->first();

        if ($tenant === null) {
            $this->components->error("No tenant [{$reference}].");

            return self::FAILURE;
        }

        $backup = $export->handle($tenant);

        $this->components->twoColumnDetail($tenant->slug, (string) $backup->storage_path);
        $this->components->info('Export complete: '.number_format((int) $backup->getAttribute('size_bytes')).' bytes.');

        return self::SUCCESS;
    }
}
