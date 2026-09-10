<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Data\SuperAdminData;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Name and email of another operator, and optionally a new password. A password set by a colleague ends every
 * session the account holds: the person who typed it is not the person who was signed in.
 */
final class UpdateSuperAdmin
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(SuperAdmin $admin, SuperAdminData $data, SuperAdmin $by): SuperAdmin
    {
        return DB::connection('pgsql')->transaction(function () use ($admin, $data, $by): SuperAdmin {
            $before = ['name' => $admin->name, 'email' => $admin->email];
            $after = ['name' => $data->name, 'email' => $data->email];

            $admin->forceFill($after);

            if (! $data->sendsLink()) {
                $admin->forceFill(['password' => (string) $data->password]);
            }

            $changed = array_keys($admin->getDirty());
            $admin->save();

            if ($changed === []) {
                return $admin;
            }

            if (in_array('password', $changed, true)) {
                $this->sessions->revokeAll($admin);
                $this->audit->record(CentralAuditAction::PasswordChange, null, $admin, null, ['by' => 'admin', 'sessions_revoked' => true], $by->id);
            }

            $diff = array_intersect_key($after, array_flip(array_intersect($changed, ['name', 'email'])));

            if ($diff !== []) {
                $this->audit->record(CentralAuditAction::Update, null, $admin, array_intersect_key($before, $diff), $diff, $by->id);
            }

            return $admin;
        });
    }
}
