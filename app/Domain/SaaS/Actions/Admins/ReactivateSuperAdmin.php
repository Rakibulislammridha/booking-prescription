<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\SuperAdmin;

/** Back on. Nothing else changes: the password, the second factor and the recovery codes are as they were left. */
final class ReactivateSuperAdmin
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(SuperAdmin $admin, SuperAdmin $by): SuperAdmin
    {
        if ($admin->is_active) {
            return $admin;
        }

        $admin->forceFill(['is_active' => true])->save();

        $this->audit->record(CentralAuditAction::Reactivate, null, $admin, ['is_active' => false], ['is_active' => true], $by->id);

        return $admin;
    }
}
