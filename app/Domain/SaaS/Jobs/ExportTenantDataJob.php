<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Jobs;

use App\Domain\SaaS\Actions\Tenants\ExportTenantData;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The churn export on the `backups` queue: it can take minutes for a busy clinic and must never be attempted in
 * a web request.
 *
 * Central by construction — the action enters the tenant schema itself through `Tenancy::run()` and leaves it
 * again, which is the only sanctioned way for control-plane code to read a clinic's tables.
 */
final class ExportTenantDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public readonly int $tenantId, public readonly ?int $superAdminId = null)
    {
        $this->onQueue('backups');
    }

    public function handle(ExportTenantData $export): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null) {
            $export->handle($tenant, $this->superAdminId);
        }
    }
}
