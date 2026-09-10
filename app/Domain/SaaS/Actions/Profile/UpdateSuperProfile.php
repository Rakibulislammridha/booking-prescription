<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Profile;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\SuperAdmin;

/** The operator's own name and email. The form request re-asks the password when the email moves. */
final class UpdateSuperProfile
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(SuperAdmin $admin, string $name, string $email): SuperAdmin
    {
        $before = ['name' => $admin->name, 'email' => $admin->email];
        $admin->forceFill(['name' => $name, 'email' => $email]);
        $changed = array_intersect(array_keys($admin->getDirty()), ['name', 'email']);
        $admin->save();

        if ($changed !== []) {
            $keys = array_flip($changed);
            $this->audit->record(CentralAuditAction::Update, null, $admin, array_intersect_key($before, $keys), array_intersect_key(['name' => $name, 'email' => $email], $keys), $admin->id);
        }

        return $admin;
    }
}
