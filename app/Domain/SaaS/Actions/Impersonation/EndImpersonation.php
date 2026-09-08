<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Impersonation;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Events\ImpersonationEnded;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Tenant;

/**
 * The exit half of the trail. ARCHITECTURE §6.5 asks for `impersonate` on entry and `impersonate_end` on leaving,
 * so a reviewer can bound the session in time: what the super admin did inside it is in the TENANT's `audit_logs`,
 * every row stamped with `impersonator_super_admin_id` by `AuditRecorder`, between these two central rows.
 */
final class EndImpersonation
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(Tenant $tenant, int $superAdminId, int $userId): void
    {
        $this->audit->record(
            CentralAuditAction::ImpersonateEnd,
            $tenant,
            $tenant,
            null,
            ['user_id' => $userId],
            $superAdminId,
        );

        ImpersonationEnded::dispatch($tenant->id, $superAdminId, $userId);
    }
}
