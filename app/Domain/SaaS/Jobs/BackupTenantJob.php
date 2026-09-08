<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Jobs;

use App\Domain\SaaS\Actions\Tenants\BackupTenant;
use App\Domain\SaaS\Enums\BackupType;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** An on-demand `pg_dump` a super admin asked for, off the request thread. Central; no tenancy is initialised. */
final class BackupTenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public readonly int $tenantId, public readonly ?int $superAdminId = null)
    {
        $this->onQueue('backups');
    }

    public function handle(BackupTenant $backup): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null) {
            $backup->handle($tenant, BackupType::Manual, $this->superAdminId);
        }
    }
}
